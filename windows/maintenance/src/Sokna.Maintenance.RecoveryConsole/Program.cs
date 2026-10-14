using System.Text.Json;
using Sokna.Maintenance.Core;

var dataRoot = MaintenancePaths.DefaultDataRoot();
var command = "status";
for (var i = 0; i < args.Length; i++)
{
    if (string.Equals(args[i], "--data-root", StringComparison.OrdinalIgnoreCase) && i + 1 < args.Length)
    {
        dataRoot = Path.GetFullPath(args[++i]);
    }
    else if (!args[i].StartsWith("--", StringComparison.Ordinal))
    {
        command = args[i].ToLowerInvariant();
    }
}

var relativePath = command switch
{
    "status" => "v1/status",
    "health" => "v1/health",
    "components" => "v1/components",
    "recovery" => "v1/recovery",
    "journal" => "v1/journal/head",
    _ => throw new InvalidOperationException("Command must be one of: status, health, components, recovery, journal.")
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
    using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
    using var request = HmacProtocol.CreateSignedGet(baseUri, relativePath, "recovery-console", key);
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
