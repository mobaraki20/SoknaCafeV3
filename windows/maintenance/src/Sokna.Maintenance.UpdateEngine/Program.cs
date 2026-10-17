using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (args.Length == 0 || args[0] is "--help" or "-h")
{
    Console.WriteLine("SOKNA Maintenance Update Engine 0.3.2 (P3 R5 health/recovery hardening; DB mutation disabled)");
    Console.WriteLine("verify-stage --package <zip> --identity <json> [--data-root <path>]");
    Console.WriteLine("local-slot-inspect --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-home-cycle --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-activate --slot <path> --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256> [--data-root <path>]");
    return;
}

var command = args[0].ToLowerInvariant();
var options = ParseOptions(args.Skip(1).ToArray());
var dataRoot = options.TryGetValue("--data-root", out var dataRootText)
    ? Path.GetFullPath(dataRootText)
    : MaintenancePaths.DefaultDataRoot();
var paths = new MaintenancePaths(dataRoot);

if (command == "verify-stage")
{
    RequireOption(options, "--package", out var package);
    RequireOption(options, "--identity", out var identity);
    RunVerifyStage(package, identity, paths);
    return;
}

RequireOption(options, "--expected-build", out var expectedBuild);
if (!options.TryGetValue("--expected-managed-files", out var countText) || !int.TryParse(countText, out var expectedManagedFiles) || expectedManagedFiles < 1)
    throw new InvalidDataException("--expected-managed-files must be a positive integer.");
RequireOption(options, "--expected-managed-tree-sha256", out var expectedManagedTreeSha256);
expectedManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(expectedManagedTreeSha256);

var slots = new LocalSlotManager(paths);
var attemptStartedAtUtc = DateTimeOffset.UtcNow;
try
{
    switch (command)
    {
        case "local-slot-inspect":
        {
            var layout = slots.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            var managedTree = LocalManagedTreeAttestation.RequireExact(layout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = true,
                engine_version = "0.3.2",
                phase = "INSPECTED",
                layout,
                managed_tree_sha256 = managedTree,
                activation_performed = false,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false
            }, AtomicFile.JsonOptions));
            break;
        }
        case "local-slot-home-cycle":
        {
            var layout = slots.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            var managedTree = LocalManagedTreeAttestation.RequireExact(layout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            var result = slots.ConvertAndCycleSameBuild(expectedBuild, expectedManagedFiles);
            var finalLayout = slots.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            var finalTree = LocalManagedTreeAttestation.RequireExact(finalLayout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = true,
                engine_version = "0.3.2",
                phase = "COMMITTED",
                result,
                managed_tree_sha256 = finalTree,
                pre_activation_managed_tree_sha256 = managedTree,
                activation_performed = true,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false
            }, AtomicFile.JsonOptions));
            break;
        }
        case "local-slot-activate":
        {
            RequireOption(options, "--slot", out var targetSlot);
            var targetTree = LocalManagedTreeAttestation.RequireExact(targetSlot, expectedManagedFiles, expectedManagedTreeSha256);
            var state = slots.ActivateSlot(targetSlot, expectedBuild, expectedManagedFiles);
            var finalLayout = slots.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            var finalTree = LocalManagedTreeAttestation.RequireExact(finalLayout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = true,
                engine_version = "0.3.2",
                phase = state.Phase,
                state,
                managed_tree_sha256 = finalTree,
                target_managed_tree_sha256 = targetTree,
                activation_performed = true,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false
            }, AtomicFile.JsonOptions));
            break;
        }
        default:
            throw new InvalidDataException("Unknown Update Engine command.");
    }
}
catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or CryptographicException or TimeoutException or AggregateException or System.ComponentModel.Win32Exception or InvalidOperationException)
{
    Console.Error.WriteLine(JsonSerializer.Serialize(new
    {
        ok = false,
        engine_version = "0.3.2",
        phase = "FAILED",
        code = "LOCAL_SLOT_PHASE3_FAILED",
        error = ex.Message,
        activation_performed = ActivationPerformedInAttempt(paths.LocalSlotStatePath, attemptStartedAtUtc),
        database_mutation_performed = false,
        public_mutation_performed = false,
        windows_reboot_required = false,
        expected_managed_tree_sha256 = expectedManagedTreeSha256,
        state_path = paths.LocalSlotStatePath
    }, AtomicFile.JsonOptions));
    Environment.ExitCode = 3;
}

static bool ActivationPerformedInAttempt(string statePath, DateTimeOffset attemptStartedAtUtc)
{
    try
    {
        if (!File.Exists(statePath)) return false;
        var state = AtomicFile.ReadJson<LocalSlotPhase3State>(statePath);
        return state.Events.Any(entry =>
            entry.AtUtc >= attemptStartedAtUtc &&
            string.Equals(entry.State, "POINTER_SWITCHED", StringComparison.Ordinal));
    }
    catch
    {
        return false;
    }
}

static void RunVerifyStage(string package, string identity, MaintenancePaths paths)
{
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

    var engine = new ReleaseSetVerifierStager(paths);
    try
    {
        var receipt = engine.VerifyAndStage(package, installed);
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            ok = true,
            engine_version = "0.3.2",
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
            engine_version = "0.3.2",
            phase = "REJECTED_PRE_MUTATION",
            code = "RELEASE_SET_REJECTED",
            error = ex.Message,
            activation_performed = false,
            database_mutation_performed = false
        }, AtomicFile.JsonOptions));
        Environment.ExitCode = 2;
    }
}

static Dictionary<string, string> ParseOptions(string[] values)
{
    var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
    for (var i = 0; i < values.Length; i++)
    {
        var key = values[i];
        if (!key.StartsWith("--", StringComparison.Ordinal) || i + 1 >= values.Length)
            throw new InvalidDataException($"Unknown or incomplete argument: {key}.");
        result[key] = values[++i];
    }
    return result;
}

static void RequireOption(IReadOnlyDictionary<string, string> options, string key, out string value)
{
    if (!options.TryGetValue(key, out value!) || string.IsNullOrWhiteSpace(value))
        throw new InvalidDataException($"{key} is required.");
}
