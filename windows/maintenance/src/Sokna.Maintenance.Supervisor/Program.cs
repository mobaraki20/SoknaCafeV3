using System.Diagnostics;
using System.Net;
using System.Reflection;
using System.Security.Cryptography;
using Sokna.Maintenance.Core;

const int DefaultPort = 17631;
const string ServiceName = "SoknaMaintenanceSupervisor";

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
{
    throw new InvalidOperationException("Maintenance Supervisor port must be between 1024 and 65535.");
}

var paths = new MaintenancePaths(dataRoot);
Directory.CreateDirectory(paths.DataRoot);
using var instanceLock = SingleInstanceLock.Acquire(paths.LockPath);
var secrets = new SecretStore(paths);
secrets.EnsureKeys();
var stateStore = new SupervisorStateStore(paths);
var state = stateStore.LoadOrCreate();
stateStore.Save(state);
var journal = new DurableJournal(paths.JournalPath);
journal.Append("supervisor.starting", state.InstanceId, state.InstallGeneration, new Dictionary<string, object?>
{
    ["pid"] = Environment.ProcessId,
    ["port"] = port,
    ["read_only_p1"] = true
});
var replayCache = new ReplayCache();

var builder = WebApplication.CreateSlimBuilder(args);
builder.Host.UseWindowsService(options => options.ServiceName = ServiceName);
builder.WebHost.UseUrls($"http://127.0.0.1:{port}");
var app = builder.Build();

app.Use(async (context, next) =>
{
    if (!context.Request.Path.StartsWithSegments("/v1", StringComparison.Ordinal))
    {
        await WriteEnvelope(context, StatusCodes.Status404NotFound, false, "not_found", "NOT_FOUND", false, new { });
        return;
    }

    if (!HttpMethods.IsGet(context.Request.Method))
    {
        await WriteEnvelope(context, StatusCodes.Status405MethodNotAllowed, false, "read_only_p1", "READ_ONLY_P1", false, new { allowed_methods = new[] { "GET" } });
        return;
    }

    if (context.Connection.RemoteIpAddress is null || !IPAddress.IsLoopback(context.Connection.RemoteIpAddress))
    {
        await WriteEnvelope(context, StatusCodes.Status403Forbidden, false, "forbidden", "LOOPBACK_REQUIRED", false, new { });
        return;
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

    if (!HmacProtocol.TryParseTimestamp(timestampText, out var timestamp) || (DateTimeOffset.UtcNow - timestamp).Duration() > HmacProtocol.AllowedClockSkew)
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_TIMESTAMP_INVALID", false, new { });
        return;
    }

    if (nonce.Length is < 16 or > 128 || nonce.Any(ch => !(char.IsAsciiHexDigit(ch))))
    {
        await WriteEnvelope(context, StatusCodes.Status401Unauthorized, false, "unauthorized", "AUTH_NONCE_INVALID", false, new { });
        return;
    }

    var emptyBodyHash = HmacProtocol.BodySha256(ReadOnlySpan<byte>.Empty);
    if (!CryptographicOperations.FixedTimeEquals(Convert.FromHexString(emptyBodyHash), TryHex(bodyHash, out var suppliedBodyHash) ? suppliedBodyHash : Array.Empty<byte>()))
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

    await next();
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
        read_only_p1 = true,
        web_dependency = "none"
    });
});

app.MapGet("/v1/status", async context =>
{
    var current = AtomicFile.ReadJson<SupervisorState>(paths.StatePath);
    await WriteEnvelope(context, StatusCodes.Status200OK, true, current.RecoveryRequired ? "recovery_required" : "ready", "OK", current.RecoveryRequired, new
    {
        authoritative_state = current,
        active_operation = (object?)null,
        local_release = new
        {
            binding_state = current.LocalReleaseBindingState,
            release_id = (string?)null,
            version = (string?)null,
            managed_tree_sha256 = (string?)null,
            schema_migration_head = (string?)null
        },
        read_only_p1 = true
    });
});

app.MapGet("/v1/components", async context =>
{
    var components = new[]
    {
        InspectCurrentProcess(),
        InspectService("SoknaRuntime"),
        InspectService("SoknaPrintWorker")
    };
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "ready", "OK", false, new { components });
});

app.MapGet("/v1/operations/active", async context =>
{
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "idle", "OK", false, new { operation = (object?)null, mutations_enabled = false });
});

app.MapGet("/v1/backups", async context =>
{
    await WriteEnvelope(context, StatusCodes.Status200OK, true, "not_implemented_p1", "P1_READ_ONLY", false, new
    {
        local_backups = Array.Empty<object>(),
        remote_transfer = (object?)null,
        protection_state = "not_implemented_p1"
    });
});

app.MapGet("/v1/recovery", async context =>
{
    var current = AtomicFile.ReadJson<SupervisorState>(paths.StatePath);
    await WriteEnvelope(context, StatusCodes.Status200OK, true, current.RecoveryRequired ? "recovery_required" : "ready", "OK", current.RecoveryRequired, new
    {
        recovery_required = current.RecoveryRequired,
        safe_actions = new[] { "inspect_status", "inspect_components", "inspect_journal_head" },
        mutation_actions_enabled = false,
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
        ReadOnlyP1: true,
        WrittenAtUtc: DateTimeOffset.UtcNow);
    AtomicFile.WriteJson(paths.DescriptorPath, descriptor);
    journal.Append("supervisor.ready", state.InstanceId, state.InstallGeneration, new Dictionary<string, object?> { ["base_url"] = descriptor.BaseUrl });
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
        // Shutdown must continue even if final observability cleanup fails.
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

static bool TryHex(string value, out byte[] bytes)
{
    try
    {
        bytes = Convert.FromHexString(value);
        return true;
    }
    catch (FormatException)
    {
        bytes = Array.Empty<byte>();
        return false;
    }
}

static async Task WriteEnvelope(HttpContext context, int statusCode, bool ok, string state, string code, bool requiresRecoveryConsole, object data)
{
    context.Response.StatusCode = statusCode;
    context.Response.ContentType = "application/json; charset=utf-8";
    await context.Response.WriteAsJsonAsync(new ApiEnvelope(ok, HmacProtocol.Protocol, null, state, code, false, requiresRecoveryConsole, data));
}

static string VersionText()
{
    var assembly = Assembly.GetExecutingAssembly();
    return assembly.GetName().Version?.ToString(3) ?? "0.1.0";
}

static object InspectCurrentProcess()
{
    using var process = Process.GetCurrentProcess();
    return new
    {
        component = "maintenance-supervisor",
        service_name = ServiceName,
        present = true,
        state = "running",
        version = VersionText(),
        process_id = process.Id
    };
}

static object InspectService(string serviceName)
{
    if (!OperatingSystem.IsWindows())
    {
        return new { component = serviceName, service_name = serviceName, present = false, state = "unsupported_platform", version = (string?)null, process_id = (int?)null };
    }

    try
    {
        using var service = new System.ServiceProcess.ServiceController(serviceName);
        var stateText = service.Status.ToString().ToLowerInvariant();
        string? executable = null;
        string? version = null;
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{serviceName}");
            var image = key?.GetValue("ImagePath") as string;
            if (!string.IsNullOrWhiteSpace(image))
            {
                executable = ExtractExecutable(image);
                if (File.Exists(executable)) version = FileVersionInfo.GetVersionInfo(executable).ProductVersion;
            }
        }
        catch
        {
            // Component presence/state remains useful even when metadata cannot be read.
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
