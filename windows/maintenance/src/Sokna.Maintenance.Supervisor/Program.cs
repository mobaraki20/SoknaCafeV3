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
var stageRestartReconciler = new SupervisorStageRestartReconciler(operations, journal, state);
var localStageRecoveryCoordinator = new SupervisorLocalStageRecoveryCoordinator(paths, operations, journal, state);
var localActivationPlanCoordinator = new SupervisorLocalActivationPlanCoordinator(paths, operations, localStageRecoveryCoordinator, journal, state);
var inactiveCandidateCoordinator = new SupervisorInactiveLocalCandidateCoordinator(paths, operations, localStageRecoveryCoordinator, journal, state);
var inactiveSlotCoordinator = new SupervisorInactiveLocalSlotCoordinator(paths, operations, inactiveCandidateCoordinator, journal, state);
var codeOnlyPreExecution = new SupervisorLocalCodeOnlyPreExecution(
    paths, operations, localStageRecoveryCoordinator);
var inactiveHostInspector = new InactiveLocalHostReadinessInspector(paths, operations);
// No listening/regular update writes until interrupted Stage evidence is quarantined.
_ = stageRestartReconciler.ReconcileStoppedProcess();
var updateEnginePath = Path.Combine(AppContext.BaseDirectory, "SoknaMaintenanceUpdateEngine.exe");
var codeOnlyExecution = new SupervisorLocalCodeOnlyExecutionCoordinator(
    paths, operations, codeOnlyPreExecution, journal, state, updateEnginePath,
    inactiveCandidateCoordinator, localStageRecoveryCoordinator);
var stagingCoordinator = new SupervisorUpdateStagingCoordinator(paths, operations, journal, state, updateEnginePath);
var localPackagePreflightCoordinator = new SupervisorLocalPackagePreflightCoordinator(paths, operations, journal, state);
var localInboxUpload = new LocalInboxUploadCoordinator(paths);
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
builder.Services.AddSingleton(operations);
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

    var isLocalInboxChunk = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/inbox-chunk", StringComparison.Ordinal);
    var isLocalPackagePreflight = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/preflight", StringComparison.Ordinal);
    var isLocalReleaseStage = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/stage", StringComparison.Ordinal);
    var isLocalActivationPlan = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/activation-plan", StringComparison.Ordinal);
    var isInactiveCandidate = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/prepare-inactive", StringComparison.Ordinal);
    var isInactiveSlot = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/prepare-inactive-slot", StringComparison.Ordinal);
    var isLocalStageRecovery = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/reconcile-stage", StringComparison.Ordinal);
    var isLocalCodeOnlyActivation = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/activate-code-only", StringComparison.Ordinal);
    var isLocalCodeOnlyRecovery = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-updates/recover-code-only", StringComparison.Ordinal);
    var isStageMutation = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/update/stage", StringComparison.Ordinal);
    var isLocalSlotMutation = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-slots/home-cycle", StringComparison.Ordinal);
    var isProductionMigration = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-slots/migrate", StringComparison.Ordinal);
    var isProductionRecovery = HttpMethods.IsPost(context.Request.Method) && context.Request.Path.Equals("/v1/local-slots/recover", StringComparison.Ordinal);
    var isMutation = isLocalInboxChunk || isLocalPackagePreflight || isLocalReleaseStage || isLocalActivationPlan || isInactiveCandidate || isInactiveSlot || isLocalStageRecovery || isLocalCodeOnlyActivation || isLocalCodeOnlyRecovery || isStageMutation || isLocalSlotMutation || isProductionMigration || isProductionRecovery;
    if (!HttpMethods.IsGet(context.Request.Method) && !isMutation)
    {
        await WriteEnvelope(context, StatusCodes.Status405MethodNotAllowed, false, "p3_local_slots", "METHOD_NOT_ALLOWED", false,
            new { allowed_methods = new[] { "GET", "POST /v1/local-updates/inbox-chunk", "POST /v1/local-updates/preflight", "POST /v1/local-updates/stage", "POST /v1/local-updates/activation-plan", "POST /v1/local-updates/prepare-inactive", "POST /v1/local-updates/prepare-inactive-slot", "POST /v1/local-updates/reconcile-stage", "POST /v1/local-updates/activate-code-only", "POST /v1/local-updates/recover-code-only", "POST /v1/update/stage", "POST /v1/local-slots/migrate", "POST /v1/local-slots/recover", "POST /v1/local-slots/home-cycle" }, activation_enabled = false, database_mutation_enabled = false });
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

    if ((isLocalInboxChunk || isLocalPackagePreflight || isLocalReleaseStage || isLocalActivationPlan || isInactiveCandidate || isInactiveSlot || isLocalCodeOnlyActivation || isStageMutation) && keyId != "web-adapter")
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
    if ((isLocalStageRecovery || isLocalCodeOnlyRecovery || isProductionRecovery) && keyId != "recovery-console")
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "RECOVERY_CONSOLE_KEY_REQUIRED", true, new { });
        return;
    }

    if ((isLocalInboxChunk || isLocalPackagePreflight || isLocalReleaseStage || isLocalActivationPlan || isInactiveCandidate || isLocalCodeOnlyActivation || isStageMutation || isProductionMigration || (isLocalSlotMutation && keyId == "web-adapter")) && aclEvidence.State != "ready")
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

// Read-only signed recovery inspection. A durable recovery_required flag never
// means idle and must not permit fresh Stage or Preflight operations.
app.MapGet("/v1/local-updates/recovery-status", async context =>
{
    try
    {
        var status = stageRestartReconciler.Inspect();
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            status.Blocked ? "blocked" : "idle",
            status.StageRecoveryRequiredIds.Length > 0
                ? "STAGE_RECOVERY_REQUIRED" : "OK",
            status.StageRecoveryRequiredIds.Length > 0, status);
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "OPERATION_EVIDENCE_UNAVAILABLE", false,
            new { last_error_type = ex.GetType().Name, panel_update_ready = false });
    }
});

// F1-S7A read-only activation eligibility inspection. It re-verifies every
// Stage artifact and refuses activation until independently qualified services,
// backups, DB and the durable switch/rollback contract exist.
app.MapGet("/v1/local-updates/activation-readiness/{stageOperationId}", async context =>
{
    try
    {
        var id = context.Request.RouteValues["stageOperationId"] as string ?? "";
        var assessment = localStageRecoveryCoordinator.InspectActivationReadiness(id);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "not_ready",
            "FOUNDATION_UPDATE_REQUIRED", false, assessment);
    }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked",
            "UPDATER_OPERATION_BUSY", true, new { panel_update_ready = false, activation_allowed = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false, "rejected",
            "ACTIVATION_STAGE_EVIDENCE_UNPROVEN", false,
            new { panel_update_ready = false, activation_allowed = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        JsonException or System.Security.Cryptography.CryptographicException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "ACTIVATION_EVIDENCE_UNAVAILABLE", true,
            new { panel_update_ready = false, activation_allowed = false, last_error_type = ex.GetType().Name });
    }
});

// Signed metadata-only dry-run. No Activate route is enabled.
app.MapPost("/v1/local-updates/activation-plan", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalActivationPlanRequest>(body, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Activation dry-run request was empty.");
        var outcome = localActivationPlanCoordinator.Create(
            request.StageOperationId, request.OperatorId, idempotencyKey);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "planned_blocked",
            "ACTIVATION_DRY_RUN_BLOCKED", false,
            new { outcome.Plan, outcome.Created, outcome.Reconciled,
                dry_run_only = true, activation_allowed = false,
                panel_update_ready = false, activation_performed = false,
                database_mutation_performed = false, public_mutation_performed = false },
            outcome.Plan.PlanId);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message is
        MachineMutationLease.ConflictCode or MachineMutationLease.UnavailableCode)
    {
        var unavailable = ex.Message == MachineMutationLease.UnavailableCode;
        await WriteEnvelope(context,
            unavailable ? StatusCodes.Status503ServiceUnavailable : StatusCodes.Status409Conflict,
            false, "blocked", ex.Message, true,
            new { dry_run_only = true, panel_update_ready = false });
    }
    catch (InvalidOperationException ex) when (ex.Message is
        "UPDATER_OPERATION_BUSY" or "ACTIVATION_PLAN_IDEMPOTENCY_CONFLICT" or
        "ACTIVATION_PLAN_BASELINE_CHANGED" or "ACTIVATION_PLAN_RECONCILIATION_REQUIRED")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", ex.Message, true,
            new { dry_run_only = true, panel_update_ready = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", "ACTIVATION_PLAN_EVIDENCE_UNPROVEN", false,
            new { dry_run_only = true, panel_update_ready = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        System.Security.Cryptography.CryptographicException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "ACTIVATION_PLAN_STORAGE_UNAVAILABLE", true,
            new { dry_run_only = true, panel_update_ready = false,
                last_error_type = ex.GetType().Name });
    }
});

// Read-only exact proof that an already prepared physical Local sibling
// belongs to the signed Stage and the currently installed Local baseline.
// This endpoint does NOT stop Apache or authorize the Update Engine.
app.MapGet("/v1/local-updates/code-only-pre-execution/{stageOperationId}", async context =>
{
    try
    {
        var stageId = context.Request.RouteValues["stageOperationId"] as string ?? "";
        var evidence = codeOnlyPreExecution.Inspect(stageId);
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            "evidence_verified_not_executable", "CODE_ONLY_CUTOVER_EXECUTOR_NOT_ENABLED",
            false, new { evidence, activation_allowed = false,
                execution_authorized = false, panel_update_ready = false,
                activation_performed = false, database_mutation_performed = false,
                public_mutation_performed = false });
    }
    catch (InvalidOperationException ex) when (
        ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", "UPDATER_OPERATION_BUSY", true,
            new { activation_allowed = false, panel_update_ready = false });
    }
    catch (Exception ex) when (ex is InvalidDataException or
        FileNotFoundException or DirectoryNotFoundException or JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "evidence_unproven", "CODE_ONLY_CUTOVER_EVIDENCE_UNPROVEN",
            false, new { activation_allowed = false, panel_update_ready = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException
        or System.Security.Cryptography.CryptographicException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable,
            false, "evidence_unavailable", "CODE_ONLY_CUTOVER_EVIDENCE_UNAVAILABLE",
            true, new { activation_allowed = false, panel_update_ready = false,
                error_type = ex.GetType().Name });
    }
});

// Signed read-only retrieval after caller/browser or Supervisor restart.
// Re-checks the committed plan and its exact signed Stage/installed baseline.
app.MapGet("/v1/local-updates/activation-plans/{planId}", async context =>
{
    try
    {
        var id = context.Request.RouteValues["planId"] as string ?? "";
        var plan = localActivationPlanCoordinator.Inspect(id);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "planned_blocked",
            "ACTIVATION_DRY_RUN_BLOCKED", false,
            new { plan, panel_update_ready = false, activation_allowed = false });
    }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", "UPDATER_OPERATION_BUSY", true,
            new { panel_update_ready = false, activation_allowed = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", "ACTIVATION_PLAN_EVIDENCE_UNPROVEN", false,
            new { panel_update_ready = false, activation_allowed = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        System.Security.Cryptography.CryptographicException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "ACTIVATION_PLAN_STORAGE_UNAVAILABLE", true,
            new { panel_update_ready = false, activation_allowed = false,
                last_error_type = ex.GetType().Name });
    }
});

// Signed, idempotent Local code-only activation. The HTTP request contains
// ONLY Stage ID + operator; Supervisor derives and reattests the exact physical
// slot from the original signed Release Set. Windows Services updater/Public
// are never part of this operation.
app.MapPost("/v1/local-updates/activate-code-only", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var key))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var bytes = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalCodeOnlyActivationRequest>(
            bytes, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Local activation request is empty.");
        var result = codeOnlyExecution.Activate(request.StageOperationId,
            request.OperatorId, key);
        var phase = result.Operation.State;
        await WriteEnvelope(context,
            phase == "committed" ? StatusCodes.Status200OK :
            phase == "recovery_required" ? StatusCodes.Status503ServiceUnavailable :
            StatusCodes.Status409Conflict,
            phase == "committed", phase,
            result.Operation.ResultCode ?? "CODE_ONLY_OPERATION_UNPROVEN",
            phase == "recovery_required",
            new { result.Operation, result.Created, result.Reconciled,
                panel_update_ready = false,
                database_mutation_performed = false, public_mutation_performed = false },
            result.Operation.OperationId);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message is
        "UPDATER_OPERATION_BUSY" or MachineMutationLease.ConflictCode or
        "CODE_ONLY_IDEMPOTENCY_CONFLICT")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", ex.Message, true, new { panel_update_ready = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", "CODE_ONLY_SIGNED_STAGE_OR_SLOT_UNPROVEN",
            false, new { panel_update_ready = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        System.Security.Cryptography.CryptographicException ||
        ex is InvalidOperationException && ex.Message == MachineMutationLease.UnavailableCode)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "unavailable", "CODE_ONLY_EXECUTION_EVIDENCE_UNAVAILABLE", true,
            new { error_type = ex.GetType().Name, panel_update_ready = false });
    }
});

// Recovery is a separate, signed RECOVERY-CONSOLE operation and cannot be
// invoked from the normal Web adapter. No new update attempt while unproven.
app.MapPost("/v1/local-updates/recover-code-only", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var key))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var bytes = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalCodeOnlyRecoveryRequest>(
            bytes, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Local recovery request is empty.");
        var result = codeOnlyExecution.Recover(request.ActivationOperationId,
            request.OperatorId, key);
        var phase = result.Operation.State;
        await WriteEnvelope(context,
            phase == "committed" ? StatusCodes.Status200OK :
            StatusCodes.Status503ServiceUnavailable,
            phase == "committed", phase,
            result.Operation.ResultCode ?? "CODE_ONLY_RECOVERY_UNPROVEN",
            phase != "committed",
            new { result.Operation, result.Created, result.Reconciled,
                panel_update_ready = false, database_mutation_performed = false,
                public_mutation_performed = false },
            result.Operation.OperationId);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message is
        "UPDATER_OPERATION_BUSY" or MachineMutationLease.ConflictCode or
        "CODE_ONLY_IDEMPOTENCY_CONFLICT")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", ex.Message, true, new { panel_update_ready = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", "CODE_ONLY_RECOVERY_PROOF_INVALID",
            false, new { panel_update_ready = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        System.Security.Cryptography.CryptographicException ||
        ex is InvalidOperationException && ex.Message == MachineMutationLease.UnavailableCode)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "unavailable", "CODE_ONLY_RECOVERY_EVIDENCE_UNAVAILABLE", true,
            new { error_type = ex.GetType().Name, panel_update_ready = false });
    }
});

// F1-S7C: signed private Local Web candidate extraction. Never the live Web
// junction; promotion/activation/DB/Public operations remain disallowed.
app.MapPost("/v1/local-updates/prepare-inactive", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<InactiveLocalCandidateRequest>(body, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Inactive Local request is empty.");
        var result = inactiveCandidateCoordinator.Prepare(request.StageOperationId,
            request.OperatorId, idempotencyKey);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "private_candidate_prepared",
            "PRIVATE_LOCAL_CANDIDATE_NOT_INSTALLABLE", false,
            new { result.Receipt, result.Created, result.Reconciled,
                promotion_qualified = false, activation_enabled = false,
                panel_update_ready = false, database_mutation_performed = false,
                public_mutation_performed = false });
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message is
        "UPDATER_OPERATION_BUSY" or "INACTIVE_CANDIDATE_RECONCILIATION_REQUIRED" or
        "INACTIVE_CANDIDATE_CONFLICT_OR_TAMPER" or MachineMutationLease.ConflictCode)
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked",
            ex.Message, true, new { promotion_qualified = false, activation_enabled = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", "INACTIVE_LOCAL_PAYLOAD_UNQUALIFIED", false,
            new { promotion_qualified = false, activation_enabled = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        System.Security.Cryptography.CryptographicException ||
        ex is InvalidOperationException && ex.Message == MachineMutationLease.UnavailableCode)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "INACTIVE_LOCAL_EVIDENCE_UNAVAILABLE", true,
            new { error_type = ex.GetType().Name, promotion_qualified = false });
    }
});

// F1-S7C2: prepare a verified Local Web sibling on the real Apache host.
// Must retain stable active junction, machine persistent, DB and Public.
app.MapPost("/v1/local-updates/prepare-inactive-slot", async context =>
{
    if (!TrySingleHeader(context, HmacProtocol.HeaderIdempotencyKey, out var idempotencyKey))
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "IDEMPOTENCY_KEY_REQUIRED", false, new { });
        return;
    }
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<InactiveLocalSlotRequest>(
            body, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Inactive Local slot request missing.");
        var result = inactiveSlotCoordinator.Prepare(request.StageOperationId,
            request.OperatorId, idempotencyKey, request.PrivateCandidateIdempotencyKey);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "inactive_slot_prepared",
            "INACTIVE_LOCAL_SLOT_NOT_ACTIVATABLE", false,
            new { result.Receipt, result.Created, result.Reconciled,
                activation_allowed = false, panel_update_ready = false,
                database_mutation_performed = false, public_mutation_performed = false });
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message is
        "UPDATER_OPERATION_BUSY" or "INACTIVE_SLOT_RECOVERY_REQUIRED" or
        "INACTIVE_SLOT_EVIDENCE_CONFLICT" or MachineMutationLease.ConflictCode or
        "INACTIVE_CANDIDATE_RECONCILIATION_REQUIRED" or
        "INACTIVE_CANDIDATE_CONFLICT_OR_TAMPER")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", ex.Message, true, new { activation_allowed = false });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", "INACTIVE_SLOT_HOST_OR_SOURCE_UNQUALIFIED", false,
            new { activation_allowed = false, panel_update_ready = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        System.Security.Cryptography.CryptographicException or
        System.ComponentModel.Win32Exception or InvalidOperationException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "INACTIVE_SLOT_PREPARE_UNAVAILABLE", true,
            new { error_type = ex.GetType().Name, activation_allowed = false });
    }
});

// F1-S7C3: read-only host UAT preflight. Never authorizes an update,
// does not mutate Apache, MariaDB, active slot or Public deployment.
app.MapGet("/v1/local-updates/inactive-host-readiness", async context =>
{
    try
    {
        var result = inactiveHostInspector.Inspect();
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "not_ready",
            "HOST_UAT_AND_RELEASE_GATES_REQUIRED", false, result);
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        InvalidDataException or System.Security.Cryptography.CryptographicException or
        System.ComponentModel.Win32Exception or InvalidOperationException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "LOCAL_HOST_EVIDENCE_UNAVAILABLE", true,
            new { panel_update_ready = false, activation_allowed = false,
                last_error_type = ex.GetType().Name });
    }
});

// Signed, read-only capability negotiation. No unimplemented route is advertised.
app.MapGet("/v1/updates/capabilities", async context =>
{
    var capability = UnifiedUpdaterCapabilities.Current();
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "foundation_required", capability.BlockingCode, false, capability);
});

// Signed, read-only LEGACY physical-Web baseline recognition in the existing
// Update Center. This is deliberately not Stage, migration or Activate.
// It confirms a qualified old H300 tree without relaxing any R8 slot guards.
app.MapGet("/v1/local-slots/legacy-assessment", async context =>
{
    try
    {
        var layout = new LocalSlotManager(paths).InspectLegacyPhysicalCurrent();
        var assessed = LegacyLocalBaselineAttestation.RequireExactPhysicalWeb(layout.WebRoot);
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            "legacy_baseline_verified", "LOCAL_LEGACY_LAYOUT_TRANSITION_REQUIRED",
            false, assessed);
    }
    catch (InvalidDataException ex)
    {
        // A nonmatching/corrupt host is NOT allowed to create or activate a slot.
        var code = ex.Message is
            ("LEGACY_PHYSICAL_WEB_REQUIRED" or "LEGACY_WEB_ALREADY_JUNCTION")
                ? "LOCAL_NOT_LEGACY_PHYSICAL_LAYOUT"
                : "LOCAL_LEGACY_BASELINE_NOT_QUALIFIED";
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", code, false,
            new { legacy_baseline_verified = false, activation_allowed = false,
                  panel_update_ready = false, reason = ex.Message });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
        JsonException or System.ComponentModel.Win32Exception or
        InvalidOperationException or PlatformNotSupportedException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "LOCAL_LEGACY_EVIDENCE_UNAVAILABLE", false,
            new { legacy_baseline_verified = false, activation_allowed = false,
                  panel_update_ready = false, error_type = ex.GetType().Name });
    }
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
        var blocking = operations.ListBlocking();
        var requiresRecovery = blocking.Any(static o => o.State == "recovery_required");
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            requiresRecovery ? "recovery_required" : active.Count == 0 ? "idle" : "in_progress",
            requiresRecovery ? "UPDATER_RECOVERY_REQUIRED" : "OK", requiresRecovery,
            new { active_operations = active, active_count = active.Count,
                  blocking_operations = blocking, blocking_count = blocking.Count,
                  requires_recovery = requiresRecovery, panel_update_ready = false,
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

// Signed, Web-adapter-only transport into Windows-owned content-addressed
// Inbox. An uploaded ZIP is NOT yet a signed Stage or an authorized install.
// No arbitrary filesystem path from the HTTP request is ever accepted.
app.MapPost("/v1/local-updates/inbox-chunk", async context =>
{
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalInboxChunkRequest>(body, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("INBOX_UPLOAD_REQUEST_INVALID");
        var outcome = localInboxUpload.Append(request);
        await WriteEnvelope(context, StatusCodes.Status200OK, true,
            outcome.Complete ? "uploaded_untrusted" : "uploading",
            outcome.Complete ? "INBOX_CONTENT_HASH_VERIFIED" : "INBOX_CHUNK_ACCEPTED",
            false, outcome);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "INBOX_CHUNK_JSON_INVALID", false, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message == MachineMutationLease.ConflictCode)
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", MachineMutationLease.ConflictCode, false, new { });
    }
    catch (InvalidDataException ex)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "rejected", ex.Message.StartsWith("INBOX_", StringComparison.Ordinal)
                ? ex.Message : "INBOX_UPLOAD_VALIDATION_FAILED", false, new { });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException ||
        ex is InvalidOperationException && ex.Message == MachineMutationLease.UnavailableCode)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "unavailable", "INBOX_UPLOAD_STORAGE_UNAVAILABLE", false,
            new { error_type = ex.GetType().Name });
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

// Recovery-console only: full signed package, Stage bytes and sequence evidence
// re-verified. NEVER reruns Stage, activates a slot or changes a database.
app.MapPost("/v1/local-updates/reconcile-stage", async context =>
{
    try
    {
        var body = context.Items[SignedBodyItem] as byte[] ?? Array.Empty<byte>();
        var request = JsonSerializer.Deserialize<LocalStageRecoveryRequest>(body, AtomicFile.JsonOptions)
            ?? throw new InvalidDataException("Recovery request body is empty.");
        var result = localStageRecoveryCoordinator.Reconcile(request.StageOperationId, request.OperatorId);
        await WriteEnvelope(context, StatusCodes.Status200OK, true, "staged",
            result.Recovered ? "STAGE_RECOVERED_FROM_VERIFIED_EVIDENCE" : "STAGE_EVIDENCE_ALREADY_RECONCILED",
            false, new { result.Operation, result.Recovered, result.Reconciled, result.StageReceipt,
                activation_performed = false, database_mutation_performed = false,
                automatic_restage = false, panel_update_ready = false }, result.Operation.OperationId);
    }
    catch (JsonException)
    {
        await WriteEnvelope(context, StatusCodes.Status400BadRequest, false,
            "rejected", "REQUEST_JSON_INVALID", true, new { });
    }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", "UPDATER_OPERATION_BUSY", true, new { });
    }
    catch (InvalidDataException)
    {
        await WriteEnvelope(context, StatusCodes.Status422UnprocessableEntity, false,
            "recovery_required", "STAGE_RECOVERY_EVIDENCE_UNPROVEN", true,
            new { activation_performed = false, panel_update_ready = false });
    }
    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or CryptographicException or InvalidOperationException)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", "STAGE_RECOVERY_EVIDENCE_UNAVAILABLE", true,
            new { last_error_type = ex.GetType().Name, panel_update_ready = false });
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
    catch (InvalidOperationException ex) when (ex.Message == MachineMutationLease.ConflictCode)
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false,
            "blocked", MachineMutationLease.ConflictCode, false,
            new { panel_update_ready = false, stage_performed = false });
    }
    catch (InvalidOperationException ex) when (ex.Message == MachineMutationLease.UnavailableCode)
    {
        await WriteEnvelope(context, StatusCodes.Status503ServiceUnavailable, false,
            "evidence_unavailable", MachineMutationLease.UnavailableCode, false,
            new { panel_update_ready = false, stage_performed = false });
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
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked",
            "UPDATER_OPERATION_BUSY", true,
            new { panel_update_ready = false, activation_enabled = false });
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
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked",
            "UPDATER_OPERATION_BUSY", true,
            new { panel_update_ready = false, activation_enabled = false });
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
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        await WriteEnvelope(context, StatusCodes.Status409Conflict, false, "blocked",
            "UPDATER_OPERATION_BUSY", true,
            new { panel_update_ready = false, activation_enabled = false });
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
