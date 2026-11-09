using System.Text.Json;
using Sokna.Maintenance.Core;

var dataRoot = MaintenancePaths.DefaultDataRoot();
var command = "status";
string? operatorId = null, expectedBuild = null, expectedSha = null, idempotencyKey = null;
var explicitlyConfirmed = false;
string? workflowReport = null;
for (var i = 0; i < args.Length; i++)
{
    switch (args[i].ToLowerInvariant())
    {
        case "--data-root" when i + 1 < args.Length:
            dataRoot = Path.GetFullPath(args[++i]); break;
        case "--operator-id" when i + 1 < args.Length:
            operatorId = args[++i]; break;
        case "--expected-build" when i + 1 < args.Length:
            expectedBuild = args[++i]; break;
        case "--expected-proof-sha256" when i + 1 < args.Length:
            expectedSha = args[++i]; break;
        case "--idempotency-key" when i + 1 < args.Length:
            idempotencyKey = args[++i]; break;
        case "--report-file" when i + 1 < args.Length:
            workflowReport = Path.GetFullPath(args[++i]); break;
        case "--confirm-preserve-and-archive":
            explicitlyConfirmed = true; break;
        default:
            if (args[i].StartsWith("--", StringComparison.Ordinal))
                throw new InvalidDataException("RECOVERY_CONSOLE_ARGUMENT_UNKNOWN");
            command = args[i].ToLowerInvariant();
            break;
    }
}
if(command == "finish-r125-recovery")
{
    if(operatorId is not null || expectedBuild is not null || expectedSha is not null || idempotencyKey is not null)
        throw new InvalidDataException("R125_GUIDED_WORKFLOW_ARGUMENT_OVERRIDE_FORBIDDEN");
    var output = workflowReport ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory),
        $"SOKNA-MAINTENANCE-WORKFLOW-{DateTimeOffset.UtcNow:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}.json");
    if(workflowReport is not null &&
       (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" || Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows" ||
        !output.StartsWith(Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP")!) + Path.DirectorySeparatorChar,StringComparison.OrdinalIgnoreCase)))
        throw new InvalidDataException("R125_GUIDED_REPORT_OVERRIDE_ONLY_DISPOSABLE_CI");
    var (code, report) = await R125RecoveryWorkflow.RunAsync(new MaintenancePaths(dataRoot), explicitlyConfirmed);
    var bytes = JsonSerializer.SerializeToUtf8Bytes(report,new JsonSerializerOptions{WriteIndented=true});
    using(var file=new FileStream(output,FileMode.CreateNew,FileAccess.Write,FileShare.None,4096,FileOptions.WriteThrough))
    {file.Write(bytes);file.Flush(true);}
    Console.WriteLine(JsonSerializer.Serialize(report));
    Environment.ExitCode=code;
    if(workflowReport is null){try{System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo("explorer.exe"){
        UseShellExecute=true,ArgumentList={"/select,"+output}});}catch{}}
    return;
}
if(workflowReport is not null)throw new InvalidDataException("REPORT_OUTPUT_ONLY_ALLOWED_FOR_GUIDED_WORKFLOW");

var isReconciliation = command == "reconcile-r125-lifecycle";
if (isReconciliation &&
    (!explicitlyConfirmed ||
     !MaintenanceOperationStore.IsValidIdempotencyKey(operatorId) ||
     !MaintenanceOperationStore.IsValidIdempotencyKey(idempotencyKey) ||
     string.IsNullOrWhiteSpace(expectedBuild) ||
     expectedBuild.Length > 180 ||
     expectedSha is null || expectedSha.Length != 64 ||
     expectedSha.Any(c => !char.IsAsciiHexDigit(c))))
    throw new InvalidDataException("R125_RECOVERY_CONSOLE_EXPLICIT_CONFIRMATION_REQUIRED");
if (!isReconciliation &&
    (explicitlyConfirmed || operatorId is not null || expectedBuild is not null ||
     expectedSha is not null || idempotencyKey is not null))
    throw new InvalidDataException("RECOVERY_CONSOLE_MUTATION_FLAGS_NOT_ALLOWED_FOR_READONLY");

var relativePath = command switch
{
    "status" => "v1/status",
    "health" => "v1/health",
    "components" => "v1/components",
    "recovery" => "v1/recovery",
    "journal" => "v1/journal/head",
    "reconcile-r125-lifecycle" => "v1/local-updates/reconcile-r125-lifecycle",
    _ => throw new InvalidOperationException("Unsupported Recovery Console operation.")
};

var paths = new MaintenancePaths(dataRoot);
if (!File.Exists(paths.DescriptorPath))
{
    WriteFallback(paths, "descriptor_missing");
    return;
}

try
{
    var descriptor = AtomicFile.ReadJson<ControlPlaneDescriptor>(paths.DescriptorPath);
    if (descriptor.Protocol != HmacProtocol.Protocol || descriptor.ReadOnlyP1 is false)
        throw new InvalidDataException("Unsupported control-plane descriptor.");
    if (!Uri.TryCreate(descriptor.BaseUrl, UriKind.Absolute, out var baseUri) || !baseUri.IsLoopback)
        throw new InvalidDataException("Control-plane base URL is not loopback.");

    var secretStore = new SecretStore(paths);
    var key = secretStore.LoadKey("recovery-console");
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(isReconciliation ? 90 : 5) };
    using var request = isReconciliation
        ? HmacProtocol.CreateSignedJson(baseUri, relativePath, "recovery-console", key,
            JsonSerializer.SerializeToUtf8Bytes(new R125LifecycleReconciliationRequest(
                operatorId!, expectedBuild!, expectedSha!.ToLowerInvariant())),
            idempotencyKey!)
        : HmacProtocol.CreateSignedGet(baseUri, relativePath, "recovery-console", key);
    using var response = await client.SendAsync(request);
    var body = await response.Content.ReadAsStringAsync();
    Console.WriteLine(body);
    Environment.ExitCode = response.IsSuccessStatusCode ? 0 : 2;
}
catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or IOException or InvalidDataException)
{
    WriteFallback(paths, ex.GetType().Name);
}

static void WriteFallback(MaintenancePaths paths, string reason)
{
    object? state = null;
    long journalSequence = 0;
    try
    {
        if (File.Exists(paths.StatePath)) state = AtomicFile.ReadJson<SupervisorState>(paths.StatePath);
    }
    catch
    {
        state = null;
    }

    try
    {
        if (File.Exists(paths.JournalPath)) journalSequence = new DurableJournal(paths.JournalPath).LastSequence;
    }
    catch
    {
        journalSequence = -1;
    }

    var payload = new
    {
        ok = state is not null,
        protocol = HmacProtocol.Protocol,
        operation_id = (string?)null,
        state = state is null ? "supervisor_unavailable" : "offline_read_only",
        code = state is null ? "SUPERVISOR_STATE_UNAVAILABLE" : "OFFLINE_READ_ONLY_FALLBACK",
        retryable = true,
        requires_recovery_console = true,
        data = new
        {
            reason,
            authoritative_state_snapshot = state,
            journal_sequence = journalSequence,
            web_required = false,
            mutations_enabled = false
        }
    };
    Console.WriteLine(JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true }));
    Environment.ExitCode = state is null ? 3 : 1;
}

