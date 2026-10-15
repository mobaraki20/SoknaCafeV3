using System.Text.Json;
using Sokna.Maintenance.Core;

if (args.Length == 0 || args[0] is "--help" or "-h")
{
    Console.WriteLine("SOKNA Maintenance Update Engine 0.2.0 (P2A staging only)");
    Console.WriteLine("verify-stage --package <zip> --identity <json> [--data-root <path>]");
    return;
}

if (!string.Equals(args[0], "verify-stage", StringComparison.OrdinalIgnoreCase))
{
    throw new InvalidOperationException("P2A supports only verify-stage.");
}

string? package = null;
string? identity = null;
var dataRoot = MaintenancePaths.DefaultDataRoot();
for (var i = 1; i < args.Length; i++)
{
    switch (args[i])
    {
        case "--package" when i + 1 < args.Length:
            package = args[++i];
            break;
        case "--identity" when i + 1 < args.Length:
            identity = args[++i];
            break;
        case "--data-root" when i + 1 < args.Length:
            dataRoot = Path.GetFullPath(args[++i]);
            break;
        default:
            throw new InvalidOperationException($"Unknown or incomplete argument: {args[i]}.");
    }
}

if (string.IsNullOrWhiteSpace(package) || string.IsNullOrWhiteSpace(identity))
    throw new InvalidOperationException("--package and --identity are required.");

var identityPath = Path.GetFullPath(identity);
if (!File.Exists(identityPath)) throw new FileNotFoundException("Installed identity file was not found.", identityPath);
InstalledReleaseIdentity installed;
try
{
    installed = JsonSerializer.Deserialize<InstalledReleaseIdentity>(File.ReadAllBytes(identityPath), AtomicFile.JsonOptions)
                ?? throw new InvalidDataException("Installed identity document is empty.");
}
catch (JsonException ex)
{
    throw new InvalidDataException("Installed identity JSON is invalid.", ex);
}

var paths = new MaintenancePaths(dataRoot);
var engine = new ReleaseSetVerifierStager(paths);
try
{
    var receipt = engine.VerifyAndStage(package, installed);
    Console.WriteLine(JsonSerializer.Serialize(new
    {
        ok = true,
        engine_version = "0.2.0",
        phase = "STAGED",
        release_set_id = receipt.ReleaseSetId,
        sequence = receipt.Sequence,
        receipt,
        activation_performed = false,
        database_mutation_performed = false
    }, AtomicFile.JsonOptions));
}
catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or CryptographicException)
{
    Console.Error.WriteLine(JsonSerializer.Serialize(new
    {
        ok = false,
        engine_version = "0.2.0",
        phase = "REJECTED_PRE_MUTATION",
        code = "RELEASE_SET_REJECTED",
        error = ex.Message,
        activation_performed = false,
        database_mutation_performed = false
    }, AtomicFile.JsonOptions));
    Environment.ExitCode = 2;
}
