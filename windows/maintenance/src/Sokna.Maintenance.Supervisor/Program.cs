using System.Diagnostics;
using System.Net;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;
using Sokna.Maintenance.Supervisor;

const int DefaultPort = 17631;
const string ServiceName = "SoknaMaintenanceSupervisor";
const string SignedBodyItem = "sokna.signed_body";
const string AuthKeyItem = "sokna.auth_key_id";

var dataRoot = MaintenancePaths.DefaultDataRoot();
var port = DefaultPort;
for (var i = 0; i < args.Length; i++)
{
    if (string.Equals(args[i], "--data-root", StringComparison.OrdinalIgnoreCase) && i + 1 < args.Length)
    {
        dataRoot = Path.GetFullPath(args[++i]);
    }
    else if (string.Equals(args[i], "--port", StringComparison.OrdinalIgnoreCase) && i + 1 < args.Length && int.TryParse(args[++i], out var parsedPort))
    {
        port = parsedPort;
    }
}

if (port is < 1024 or > 65535)
    throw new InvalidOperationException("Maintenance Supervisor port must be between 1024 and 65535.");

var paths = new MaintenancePaths(dataRoot);
Directory.CreateDirectory(paths.DataRoot);
Directory.CreateDirectory(paths.InboxRoot);
Directory.CreateDirectory(paths.OperationsRoot);
Directory.CreateDirectory(paths.LocalSlotStateRoot);
Directory.CreateDirectory(paths.BootstrapRoot);
using var instanceLock = SingleInstanceLock.Acquire(paths.LockPath);
var secrets = new SecretStore(paths);
secrets.EnsureKeys();
var aclEvidence = WebAdapterAclProvisioner.TryProvision(paths);
var stateStore = new SupervisorStateStore(paths);
var state = stateStore.LoadOrCreate();
stateStore.Save(state);
var journal = new DurableJournal(paths.JournalPath);
var operations = new MaintenanceOperationStore(paths);
var updateEnginePath = Path.Combine(AppContext.BaseDirectory, "SoknaMaintenanceUpdateEngine.exe");
var stagingCoordinator = new SupervisorUpdateStagingCoordinator(paths, operations, journal, state, updateEnginePath);
var localPackagePreflightCoordinator = new SupervisorLocalPackagePreflightCoordinator(paths, operations, journal, state);
var localReleaseStageCoordinator = new SupervisorLocalReleaseStageCoordinator(paths, operations, journal, state);
var localSlotCoordinator = new SupervisorLocalSlotCoordinator(paths, operations, journal, state, updateEnginePath);
var productionMigrationCoordinator = new SupervisorProductionMigrationCoordinator(paths, operations, journal, state, updateEnginePath);
var adoptionQueue = new LocalSlotAdoptionQueue(paths);
journal.Append("supervisor.starting", state.InstanceId, state.InstallGeneration, new Dictionary<string, object?>
{
    ["pid"] = Environment.ProcessId,
    ["port"] = port,
    ["production_local_slot_migration_enabled"] = true,
    ["database_mutation_enabled"] = false,
    ["web_adapter_acl_state"] = aclEvidence.State
});
var replayCache = new ReplayCache();

var builder = WebApplication.CreateSlimBuilder(args);
builder.Host.UseWindowsService(options => options.ServiceName = ServiceName);
builder.WebHost.UseUrls($"http://127.0.0.1:{port}");
builder.Services.AddSingleton(adoptionQueue);
builder.Services.AddSingleton(productionMigrationCoordinator);
builder.Services.AddSingleton<IProductionLocalSlotMigrationCoordinator>(productionMigrationCoordinator);
builder.Services.AddSingleton(journal);
builder.Services.AddSingleton(state);
builder.Services.AddSingleton(LocalSlotAdoptionWorkerOptions.Default);
builder.Services.AddHostedService<LocalSlotAdoptionWorker>();
var app = builder.Build();

app.Use(async (context, next) =>
{
    if (!context.Request.Path.StartsWithSegments("/v1", StringComparison.Ordinal))
    {
        await WriteEnvelope(context, StatusCodes.Status404NotFound, false, "not_found", "NOT_FOUND", false, new { });
        return;
    }

    var isLocalPackagePreflight = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/preflight", StringComparison.Ordinal);
    var isLocalReleaseStage = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/stage", StringComparison.Ordinal);
    var isStageMutation = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/update/stage", StringComparison.Ordinal);
    var isLocalSlotMutation = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-slots/home-cycle", StringComparison.Ordinal);
    var isProductionMigration = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-slots/migrate", StringComparison.Ordinal);
    var isProductionRecovery = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-slots/recover", StringComparison.Ordinal);
    var isMutation = isLocalPackagePreflight || isLocalReleaseStage || isStageMutation || isLocalSlotMutation || isProductionMigration || isProductionRecovery;
    if (!HttpMethods.IsGet(context.Request.Method) && !isMutation)
    {
        await WriteEnvelope(context, StatusCodes.Status405MethodNotAllowed, false, "p3_local_slots", "METHOD_NOT_ALLOWED", false,
            new { allowed_methods = new[] { "GET", "POST /v1/local-updates/preflight", "POST /v1/local-updates/stage", "POST /v1/update/stage", "POST /v1/local-slots/migrate", "POST /v1/local-slots/recover", "POST /v1/local-slots/home-cycle" }, activation_enabled = true, database_mutation_enabled = false });
        return;
    }

    if (context.Connection.RemoteIpAddress is null || !IPAddress.IsLoopback(context.Connection.RemoteIpAddress))
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "LOOPBACK_REQUIRED", false, new { });
        return;
    }

    if (context.Request.ContentLength is > HmacProtocol.MaxSignedBodyBytes)
    {
        await WriteEnvelope(context, StatusCodes.Status413PayloadTooLarge, false, "rejected", "SIGNED_BODY_TOO_LARGE", false, new { max_bytes = HmacProtocol.MaxSignedBodyBytes });
        return;
    }

    byte[] bodyBytes;
    if (HttpMethods.IsGet(context.Request.Method))
    {
        bodyBytes = Array.Empty<byte>();
    }
    else
    {
        context.Request.EnableBuffering(bufferThreshold: 64 * 1024, bufferLimit: HmacProtocol.MaxSignedBodyBytes + 1L);
        using var memory = new MemoryStream();
        var buffer = new byte[64 * 1024];
        while (true)
        {
            var read = await context.Request.Body.ReadAsync(buffer);
            if (read == 0) break;
            await memory.WriteAsync(buffer.AsMemory(0, read));
            if (memory.Length > HmacProtocol.MaxSignedBodyBytes)
            {
                await WriteEnvelope(context, StatusCodes.Status413PayloadTooLarge, false, "rejected", "SIGNED_BODY_TOO_LARGE", false, new { max_bytes = HmacProtocol.MaxSignedBodyBytes });
                return;
            }
        }
        bodyBytes = memory.ToArray();
        context.Request.Body.Position = 0;
        context.Items[SignedBodyItem] = bodyBytes;
    }

    if (!TrySingleHeader(context, HmacProtocol.HeaderProtocol, out var protocol) || protocol != HmacProtocol.Protocol ||
        !TrySingleHeader(context, HmacProtocol.HeaderTimestamp, out var timestampText) ||
        !TrySingleHeader(context, HmacProtocol.HeaderNonce, out var nonce) ||
        !TrySingleHeader(context, HmacProtocol.HeaderKeyId, out var keyId) ||
        !TrySingleHeader(context, HmacProtocol.HeaderBodySha256, out var bodyHash) ||
        !TrySingleHeader(context, HmacProtocol.HeaderSignature, out var signature))
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_HEADERS_INVALID", false, new { });
        return;
    }

    if ((isLocalPackagePreflight || isLocalReleaseStage || isStageMutation) && keyId != "web-adapter")
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "WEB_ADAPTER_KEY_REQUIRED", false, new { });
        return;
    }
    if (isLocalSlotMutation && keyId is not ("web-adapter" or "recovery-console"))
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "LOCAL_SLOT_AUTHORITY_REQUIRED", false, new { });
        return;
    }
    if (isProductionMigration && keyId != "web-adapter")
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "WEB_ADAPTER_KEY_REQUIRED", false, new { });
        return;
    }
    if (isProductionRecovery && keyId != "recovery-console")
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "RECOVERY_CONSOLE_KEY_REQUIRED", true, new { });
        return;
    }

    if ((isLocalPackagePreflight || isLocalReleaseStage || isStageMutation || isProductionMigration || (isLocalSlotMutation && keyId == "web-adapter")) && aclEvidence.State != "ready")
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false, "acl_pending", "WEB_ADAPTER_ACL_PENDING", false,
            new { acl_state = aclEvidence.State, service_name = aclEvidence.ServiceName });
        return;
    }

    if (!HmacProtocol.TryParseTimestamp(timestampText, out var timestamp) || (DateTimeOffset.UtcNow - timestamp).Duration() > HmacProtocol.AllowedClockSkew)
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_TIMESTAMP_INVALID", false, new { });
        return;
    }

    if (nonce.Length is < 16 or > 128 || nonce.Any(ch => !char.IsAsciiHexDigit(ch)))
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_NONCE_INVALID", false, new { });
        return;
    }

    var actualBodyHash = HmacProtocol.BodySha256(bodyBytes);
    if (!HmacProtocol.FixedTimeEqualsHex(actualBodyHash, bodyHash))
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_BODY_HASH_INVALID", false, new { });
        return;
    }

    byte[] key;
    try
    {
        key = secrets.LoadKey(keyId);
    }
    catch
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_KEY_UNKNOWN", false, new { });
        return;
    }

    var canonical = HmacProtocol.BuildCanonical(context.Request.Method, context.Request.PathBase + context.Request.Path + context.Request.QueryString, protocol, timestampText, nonce, bodyHash);
    var expected = HmacProtocol.Sign(key, canonical);
    if (!HmacProtocol.FixedTimeEqualsHex(expected, signature))
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_SIGNATURE_INVALID", false, new { });
        return;
    }

    if (!replayCache.TryAccept(keyId, nonce, DateTimeOffset.UtcNow))
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "rejected", "AUTH_REPLAY", false, new { });
        return;
    }

    context.Items[AuthKeyItem] = keyId;
    await next();
});

// Signed, read-only capability negotiation. No unimplemented route is advertised.
app.MapGet("/v1/updates/capabilities", async context =>
{
    var capability = UnifiedUpdaterCapabilities.Current();
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "foundation_required", capability.BlockingCode, false, capability);
});

// Signed, non-mutating R8 slot inspection. This cannot approve package activation.
app.MapGet("/v1/local-slots/current", async context =>
{
    try
    {
        var layout = new LocalSlotManager(paths).InspectCurrent();
        if (!layout.WebIsReparsePoint || string.IsNullOrWhiteSpace(layout.ActiveSlotTarget))
        {
            await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked", "LEGACY_SLOT_LAYOUT", false,
                new { current_build = layout.Build, junction_verified = false, http_health_verified = false });
            return;
        }
        var target = Path.GetFullPath(layout.ActiveSlotTarget);
        var parent = Path.GetDirectoryName(target);
        var leaf = Path.GetFileName(target);
        if (!string.Equals(parent, layout.PackageRoot, StringComparison.OrdinalIgnoreCase) ||
            !leaf.StartsWith("Web.release.", StringComparison.OrdinalIgnoreCase))
        {
            await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked", "JUNCTION_TARGET_UNEXPECTED", false,
                new { current_build = layout.Build, junction_verified = false, http_health_verified = false });
            return;
        }
        var tree = LocalManagedTreeAttestation.Compute(layout.WebRoot, layout.ManagedFileCount);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "layout_verified", "OK", false,
            new { current_build = layout.Build, managed_files = layout.ManagedFileCount,
                  managed_tree_sha256 = tree, stable_web_root = layout.WebRoot,
                  active_release = target, junction_verified = true,
                  http_health_verified = false, db_verified = false });
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or
        System.ComponentModel.Win32Exception or InvalidOperationException or PlatformNotSupportedException)
    {
        journal.Append("local_slot.inspect.failed", state.InstanceId, state.InstallGeneration,
            new Dictionary<string, object?> { ["reason_code"] = "LOCAL_SLOT_INSPECTION_FAILED",
                ["error_type"] = ex.GetType().Name });
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false, "unavailable", "LOCAL_SLOT_INSPECTION_FAILED", false,
            new { junction_verified = false, http_health_verified = false });
    }
});

app.MapGet("/v1/health", async context =>
{
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "ready", "OK", false, new
    {
        service = ServiceName,
        supervisor_version = VersionText(),
        instance_id = state.InstanceId,
        install_generation = state.InstallGeneration,
        journal_sequence = journal.LastSequence,
        process_id = Environment.ProcessId,
        loopback_only = true,
        staging_mutation_enabled = true,
        activation_enabled = true,
        local_slot_phase = "production_bridge_r7_durable_adoption",
        database_mutation_enabled = false,
        web_adapter_acl_state = aclEvidence.State,
        web_adapter_service_account = aclEvidence.ServiceAccount,
        web_dependency = "none_for_recovery_console"
    });
});

app.MapGet("/v1/status", async context =>
{
    var current = AtomicFile.ReadJson<SupervisorState>(paths.StatePath);
    object? localSlots = null;
    object? localAdoption = null;
    try { if (File.Exists(paths.LocalSlotStatePath)) localSlots = AtomicFile.ReadJson<LocalSlotPhase3State>(paths.LocalSlotStatePath); } catch { }
    try { localAdoption = adoptionQueue.LoadStatus(); } catch { }
    await WriteEnvelope(context, StatusCodes.Status200OK, true, current.RecoveryRequired ? "recovery_required" : "ready", "OK", current.RecoveryRequired, new
    {
        authoritative_state = current,
        local_release_identity_bound = File.Exists(paths.InstalledReleaseIdentityPath),
        local_slots = localSlots,
        local_adoption = localAdoption,
        inbox_path = paths.InboxRoot,
        web_adapter_acl = aclEvidence,
        activation_enabled = true,
        database_mutation_enabled = false
    });
});

app.MapGet("/v1/components", async context =>
{
    var components = new[]
    {
        InspectCurrentProcess(),
        InspectExecutable("maintenance-update-engine", updateEnginePath),
        InspectService("SoknaRuntime"),
        InspectService("SoknaPrintWorker")
    };
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "ready", "OK", false, new { components });
});

app.MapGet("/v1/operations/{operationId}", async context =>
{
    try
    {
        var operationId = context.Request.RouteValues["operationId"]?.ToString() ?? string.Empty;
        var operation = operations.Get(operationId);
        if (operation is null)
        {
            await WriteEnvelope(context, StatusCodes.Status404NotFound, false, "not_found", "OPERATION_NOT_FOUND", false, new { });
            return;
        }
        await WriteEnvelope(context, StatusCodes.Status200OK, true, operation.State, "OK", operation.State == "recovery_required", operation, operation.OperationId);
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE", false,
            new { last_error_type = ex.GetType().Name });
    }
});

app.MapGet("/v1/operations/by-idempotency/{key}", async context =>
{
    var key = context.Request.RouteValues["key"]?.ToString() ?? string.Empty;
    if (!MaintenanceOperationStore.IsValidIdempotencyKey(key))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "IDEMPOTENCY_KEY_INVALID", false, new { });
        return;
    }
    try
    {
        var operation = operations.GetByIdempotencyKey(key);
        if (operation is null)
        {
            await WriteEnvelope(context, StatusCodes.Status404NotFound, false, "not_found", "OPERATION_NOT_FOUND", false, new { });
            return;
        }
        await WriteEnvelope(context, StatusCodes.Status200OK, true, operation.State, "OK", operation.State == "recovery_required", operation, operation.OperationId);
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE", false,
            new { last_error_type = ex.GetType().Name });
    }
});

app.MapGet("/v1/operations/active", async context =>
{
    try
    {
        var active = operations.ListActive();
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            active.Count == 0 ? "idle" : "in_progress", "OK", false,
            new { active_operations = active, active_count = active.Count,
                  query_by_operation_id = "/v1/operations/{operation_id}",
                  query_by_idempotency = "/v1/operations/by-idempotency/{key}",
                  history_url = "/v1/operations/recent" });
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE", false,
            new { active_count = (int?)null, last_error_type = ex.GetType().Name });
    }
});

app.MapGet("/v1/operations/recent", async context =>
{
    try
    {
        var history = operations.ListRecent(30);
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            "history", "OK", false, new { operations = history, total_returned = history.Count,
               historical_failures_are_not_current_health = true });
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE", false,
            new { last_error_type = ex.GetType().Name });
    }
});

// Signed diagnostic preflight: writes ONLY an operation journal/receipt. It cannot
// stage, activate, update the sequence, or grant the panel ordinary update readiness.
app.MapPost("/v1/local-updates/preflight", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }

    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalPackagePreflightRequest>(body, AtomicFile.JsonOptions)
                      ?? throw new InvalidDataException("Preflight request body is empty.");
        var result = localPackagePreflightCoordinator.Verify(
            request.PackageRef, request.OperatorId, idempotencyKey);
        var success = result.Operation.State == "verified" && result.Receipt is not null;
        await WriteEnvelope(context,
            success ? StatusCodes.Status200OK : StatusCodes.Status422UnprocessableEntity,
            success, result.Operation.State,
            result.Operation.ResultCode ?? "PACKAGE_PREFLIGHT_REJECTED",
            false,
            new { result.Operation, result.Created, result.Reconciled, result.Receipt,
                  activation_enabled = false, panel_update_ready = false,
                  stage_performed = false, database_mutation_performed = false },
            result.Operation.OperationId);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked", "UPDATER_OPERATION_BUSY", false, new { });
    }
    catch (InvalidDataException ex) when (ex.Message.Contains("Idempotency key was already used", StringComparison.Ordinal))
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "rejected", "IDEMPOTENCY_CONFLICT", false, new { });
    }
    catch (InvalidDataException ex) when (ex.Message.Contains("package_ref must", StringComparison.Ordinal) ||
                                           ex.Message.Contains("operator_id is invalid", StringComparison.Ordinal) ||
                                           ex.Message.Contains("Idempotency key is invalid", StringComparison.Ordinal))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "REQUEST_INVALID", false, new { });
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable,
            false, "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE",
            false, new { last_error_type = ex.GetType().Name });
    }
});

// Legacy P2B stage route is explicitly retired from the ordinary operator path.
// It previously accepted a ZIP without a completed versioned Local preflight.
app.MapPost("/v1/update/stage", async context =>
{
    await WriteEnvelope(context, StatusCodes.Status410Gone, false, "retired",
        "LEGACY_STAGE_ROUTE_DISABLED", false,
        new { canonical_path = "/v1/local-updates/stage",
              preflight_operation_id_required = true, panel_update_ready = false });
});

// Canonical F1-S2 Stage: signed + durable Preflight ID required. No activation.
app.MapPost("/v1/local-updates/stage", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalReleaseStageRequest>(body, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Stage request body is empty.");
        var result = localReleaseStageCoordinator.Stage(
            request.PreflightOperationId, request.OperatorId, idempotencyKey);
        var success = result.Operation.State == "staged" && result.Receipt is not null;
        var needsRecovery = result.Operation.State == "recovery_required";
        await WriteEnvelope(context,
            success ? StatusCodes.Status200OK :
                needsRecovery ? StatusCodes.Status503ServiceUnavailable :
                StatusCodes.Status422UnprocessableEntity,
            success, result.Operation.State,
            result.Operation.ResultCode ?? "STAGE_NOT_VERIFIED", needsRecovery,
            new { result.Operation, result.PreflightOperationId, result.Receipt,
                  result.Created, result.Reconciled,
                  stage_performed = success, activation_enabled = false,
                  panel_update_ready = false, database_mutation_performed = false },
            result.Operation.OperationId);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", "UPDATER_OPERATION_BUSY", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_RECONCILIATION_REQUIRED")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", "STAGE_OPERATION_RECONCILIATION_REQUIRED", true, new { });
    }
    catch (InvalidDataException ex) when (ex.Message.Contains("Idempotency key was already used", StringComparison.Ordinal))
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "rejected", "IDEMPOTENCY_CONFLICT", false, new { });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity,
            false, "rejected", "STAGE_PREFLIGHT_BINDING_REJECTED", false, new { });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable,
            false, "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE",
            false, new { last_error_type = ex.GetType().Name });
    }
});

app.MapPost("/v1/local-slots/migrate", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<ProductionLocalSlotMigrationRequest>(body, AtomicFile.JsonOptions)
                      ?? throw new InvalidDataException("Production migration request body is empty.");
        var result = productionMigrationCoordinator.Migrate(request.ExpectedBuild, request.ExpectedManagedFiles, request.ExpectedManagedTreeSha256, request.OperatorId, idempotencyKey);
        var operation = result.Operation;
        var success = operation.State == "committed";
        var recoveryRequired = operation.State == "recovery_required";
        var statusCode = success ? StatusCodes.Status200OK : recoveryRequired ? StatusCodes.Status503ServiceUnavailable : operation.State is "running" or "accepted" ? StatusCodes.Status202Accepted : StatusCodes.Status422UnprocessableEntity;
        await WriteEnvelope(context, statusCode, success, operation.State, operation.ResultCode ?? (success ? "LOCAL_SLOT_MIGRATION_COMMITTED" : "OPERATION_RECORDED"), recoveryRequired,
            new { operation, result.Created, result.Reconciled, production_migration = true, database_mutation_enabled = false, public_mutation_enabled = false, windows_reboot_required = false }, operation.OperationId);
    }
    catch (JsonException ex)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "REQUEST_JSON_INVALID", false, new { error = ex.Message });
    }
    catch (InvalidDataException ex)
    {
        var code = ex.Message.Contains("Idempotency key was already used", StringComparison.Ordinal) ? "IDEMPOTENCY_CONFLICT" : "REQUEST_INVALID";
        await WriteEnvelope(context, code == "IDEMPOTENCY_CONFLICT" ? StatusCodes.Status409Conflict : StatusCodes.Status400BadRequest, false, "rejected", code, false, new { error = ex.Message });
    }
});

app.MapPost("/v1/local-slots/recover", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "IDEMPOTENCY_KEY_REQUIRED", true, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<ProductionLocalSlotRecoveryRequest>(body, AtomicFile.JsonOptions)
                      ?? throw new InvalidDataException("Production recovery request body is empty.");
        var result = productionMigrationCoordinator.Recover(request.OperatorId, idempotencyKey);
        var operation = result.Operation;
        var success = operation.State == "committed";
        await WriteEnvelope(context, success ? StatusCodes.Status200OK : StatusCodes.Status503ServiceUnavailable, success, operation.State, operation.ResultCode ?? "LOCAL_SLOT_RECOVERY_FAILED", !success,
            new { operation, result.Created, result.Reconciled, production_recovery = true, database_mutation_enabled = false, public_mutation_enabled = false, windows_reboot_required = false }, operation.OperationId);
    }
    catch (JsonException ex)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "REQUEST_JSON_INVALID", true, new { error = ex.Message });
    }
    catch (InvalidDataException ex)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "REQUEST_INVALID", true, new { error = ex.Message });
    }
});

app.MapPost("/v1/local-slots/home-cycle", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalSlotHomeCycleRequest>(body, AtomicFile.JsonOptions)
                      ?? throw new InvalidDataException("Local-slot request body is empty.");
        var result = localSlotCoordinator.RunHomeCycle(request.ExpectedBuild, request.ExpectedManagedFiles, request.OperatorId, idempotencyKey);
        var operation = result.Operation;
        var success = operation.State == "committed";
        var recoveryRequired = operation.State == "recovery_required";
        var statusCode = success ? StatusCodes.Status200OK : recoveryRequired ? StatusCodes.Status503ServiceUnavailable : operation.State is "running" or "accepted" ? StatusCodes.Status202Accepted : StatusCodes.Status422UnprocessableEntity;
        await WriteEnvelope(context, statusCode, success, operation.State, operation.ResultCode ?? (success ? "LOCAL_SLOT_PHASE3_COMMITTED" : "OPERATION_RECORDED"), recoveryRequired,
            new { operation, result.Created, result.Reconciled, activation_enabled = true, database_mutation_enabled = false, public_mutation_enabled = false, windows_reboot_required = false }, operation.OperationId);
    }
    catch (JsonException ex)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false, "rejected", "REQUEST_JSON_INVALID", false, new { error = ex.Message });
    }
    catch (InvalidDataException ex)
    {
        var code = ex.Message.Contains("Idempotency key was already used", StringComparison.Ordinal) ? "IDEMPOTENCY_CONFLICT" : "REQUEST_INVALID";
        var status = code == "IDEMPOTENCY_CONFLICT" ? StatusCodes.Status409Conflict : StatusCodes.Status400BadRequest;
        await WriteEnvelope(context, status, false, "rejected", code, false, new { error = ex.Message });
    }
});

app.MapGet("/v1/backups", async context =>
{
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "not_implemented_yet", "BACKUP_ENGINE_PENDING", false, new
    {
        local_backups = Array.Empty<object>(), remote_transfer = (object?)null, protection_state = "not_implemented_yet"
    });
});

app.MapGet("/v1/recovery", async context =>
{
    var current = AtomicFile.ReadJson<SupervisorState>(paths.StatePath);
    var slotRecovery = false;
    var productionStatePath = Path.Combine(paths.LocalSlotStateRoot, "migration-state.json");
    try
    {
        if (File.Exists(productionStatePath)) slotRecovery = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(productionStatePath).RecoveryRequired;
        else if (File.Exists(paths.LocalSlotStatePath)) slotRecovery = AtomicFile.ReadJson<LocalSlotPhase3State>(paths.LocalSlotStatePath).RecoveryRequired;
    }
    catch { slotRecovery = true; }
    var recoveryRequired = current.RecoveryRequired || slotRecovery;
    await WriteEnvelope(context, StatusCodes.Status200OK, true, recoveryRequired ? "recovery_required" : "ready", "OK", recoveryRequired, new
    {
        recovery_required = recoveryRequired,
        safe_actions = new[] { "inspect_status", "inspect_components", "inspect_journal_head", "inspect_operation" },
        mutation_actions_enabled = true,
        mutation_scope = "phase3_local_slots_only",
        database_mutation_enabled = false,
        web_required = false
    });
});

app.MapGet("/v1/journal/head", async context =>
{
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "ready", "OK", false, new { last_sequence = journal.LastSequence });
});

app.Lifetime.ApplicationStarted.Register(() =>
{
    var descriptor = new ControlPlaneDescriptor(
        Format: "sokna-maintenance-control-plane-v1",
        SchemaVersion: 1,
        Protocol: HmacProtocol.Protocol,
        BaseUrl: $"http://127.0.0.1:{port}/",
        SupervisorVersion: VersionText(),
        InstanceId: state.InstanceId,
        InstallGeneration: state.InstallGeneration,
        Readiness: "ready",
        AuthScheme: "sokna-hmac-sha256-v1",
        SupportedKeyIds: new[] { "web-adapter", "recovery-console" },
        ReadOnlyP1: false,
        WrittenAtUtc: DateTimeOffset.UtcNow);
    AtomicFile.WriteJson(paths.DescriptorPath, descriptor);

    if (File.Exists(paths.WebAdapterAclEvidencePath))
        aclEvidence = AtomicFile.ReadJson<WebAdapterAclEvidence>(paths.WebAdapterAclEvidencePath);

    journal.Append("supervisor.ready", state.InstanceId, state.InstallGeneration, new Dictionary<string, object?>
    {
        ["base_url"] = descriptor.BaseUrl,
        ["production_local_slot_migration_enabled"] = true,
        ["database_mutation_enabled"] = false,
        ["web_adapter_acl_state"] = aclEvidence.State,
        ["web_adapter_descriptor_read"] = aclEvidence.DescriptorReadGranted
    });
});

app.Lifetime.ApplicationStopping.Register(() =>
{
    try
    {
        journal.Append("supervisor.stopping", state.InstanceId, state.InstallGeneration);
        if (File.Exists(paths.DescriptorPath)) File.Delete(paths.DescriptorPath);
    }
    catch
    {
    }
});

await app.RunAsync();

static bool TrySingleHeader(HttpContext context, string name, out string value)
{
    value = string.Empty;
    if (!context.Request.Headers.TryGetValue(name, out var values) || values.Count != 1) return false;
    value = values[0] ?? string.Empty;
    return !string.IsNullOrWhiteSpace(value);
}

static async Task WriteEnvelope(HttpContext context, int statusCode, bool ok, string state, string code, bool requiresRecoveryConsole, object data, string? operationId = null)
{
    context.Response.StatusCode = statusCode;
    context.Response.ContentType = "application/json; charset=utf-8";
    await context.Response.WriteAsJsonAsync(new ApiEnvelope(ok, HmacProtocol.Protocol, operationId, state, code, false, requiresRecoveryConsole, data));
}

static string VersionText()
{
    var assembly = Assembly.GetExecutingAssembly();
    return assembly.GetName().Version?.ToString(3) ?? "0.3.0";
}

static object InspectCurrentProcess()
{
    using var process = Process.GetCurrentProcess();
    return new { component = "maintenance-supervisor", service_name = ServiceName, present = true, state = "running", version = VersionText(), process_id = process.Id };
}

static object InspectExecutable(string component, string executable)
{
    if (!File.Exists(executable)) return new { component, service_name = (string?)null, present = false, state = "not_installed", version = (string?)null, process_id = (int?)null };
    var info = FileVersionInfo.GetVersionInfo(executable);
    return new { component, service_name = (string?)null, present = true, state = "available", version = info.ProductVersion ?? info.FileVersion, process_id = (int?)null };
}

static object InspectService(string serviceName)
{
    if (!OperatingSystem.IsWindows())
        return new { component = serviceName, service_name = serviceName, present = false, state = "unsupported_platform", version = (string?)null, process_id = (int?)null };

    try
    {
        using var service = new System.ServiceProcess.ServiceController(serviceName);
        var stateText = service.Status.ToString().ToLowerInvariant();
        string? version = null;
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{serviceName}");
            var image = key?.GetValue("ImagePath") as string;
            if (!string.IsNullOrWhiteSpace(image))
            {
                var executable = ExtractExecutable(image);
                if (File.Exists(executable)) version = FileVersionInfo.GetVersionInfo(executable).ProductVersion;
            }
        }
        catch
        {
        }
        return new { component = serviceName, service_name = serviceName, present = true, state = stateText, version, process_id = (int?)null };
    }
    catch (InvalidOperationException)
    {
        return new { component = serviceName, service_name = serviceName, present = false, state = "not_installed", version = (string?)null, process_id = (int?)null };
    }
}

static string ExtractExecutable(string imagePath)
{
    var text = Environment.ExpandEnvironmentVariables(imagePath.Trim());
    if (text.StartsWith('"'))
    {
        var end = text.IndexOf('"', 1);
        if (end > 1) return text[1..end];
    }
    var exe = text.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
    return exe >= 0 ? text[..(exe + 4)].Trim() : text.Split(' ', 2)[0];
}
