using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (args.Length < 1) throw new InvalidDataException("Harness command is required.");
var command = args[0].ToLowerInvariant();

switch (command)
{
    case "digest":
    {
        if (args.Length != 3) throw new InvalidDataException("digest <web-root> <managed-count>");
        var count = int.Parse(args[2], System.Globalization.CultureInfo.InvariantCulture);
        Console.WriteLine(LocalManagedTreeAttestation.Compute(args[1], count));
        break;
    }
    case "migrate":
    {
        if (args.Length < 6) throw new InvalidDataException("migrate <data-root> <build> <managed-count> <sha256> <fault-mode|none>");
        var paths = new MaintenancePaths(Path.GetFullPath(args[1]));
        var count = int.Parse(args[3], System.Globalization.CultureInfo.InvariantCulture);
        var checkpoint = BuildCheckpoint(args[5], null);
        var bridge = new ProductionLocalSlotMigrationBridge(paths, TimeSpan.FromSeconds(8), TimeSpan.FromSeconds(8), checkpoint);
        var result = bridge.Migrate(args[2], count, args[4]);
        Console.WriteLine(JsonSerializer.Serialize(result, AtomicFile.JsonOptions));
        break;
    }
    case "recover":
    {
        if (args.Length != 2) throw new InvalidDataException("recover <data-root>");
        var bridge = new ProductionLocalSlotMigrationBridge(new MaintenancePaths(Path.GetFullPath(args[1])), TimeSpan.FromSeconds(8), TimeSpan.FromSeconds(8));
        var result = bridge.Recover();
        Console.WriteLine(JsonSerializer.Serialize(result, AtomicFile.JsonOptions));
        break;
    }
    case "read-state":
    {
        if (args.Length != 2) throw new InvalidDataException("read-state <data-root>");
        var path = Path.Combine(Path.GetFullPath(args[1]), "local-slots", "migration-state.json");
        var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(path);
        Console.WriteLine(JsonSerializer.Serialize(state, AtomicFile.JsonOptions));
        break;
    }
    case "local-slot-migrate":
    {
        var options = ParseOptions(args.Skip(1).ToArray());
        Require(options, "--data-root", out var dataRoot);
        Require(options, "--expected-build", out var build);
        Require(options, "--expected-managed-files", out var countText);
        Require(options, "--expected-managed-tree-sha256", out var sha256);
        if (!int.TryParse(countText, out var count) || count < 1) throw new InvalidDataException("Invalid managed file count.");
        var paths = new MaintenancePaths(Path.GetFullPath(dataRoot));
        var faultFile = Path.Combine(paths.LocalSlotStateRoot, "test-engine-fault.txt");
        var fault = File.Exists(faultFile) ? File.ReadAllText(faultFile).Trim() : "none";
        var checkpoint = BuildCheckpoint(fault, faultFile);
        var bridge = new ProductionLocalSlotMigrationBridge(paths, TimeSpan.FromSeconds(8), TimeSpan.FromSeconds(8), checkpoint);
        var result = bridge.Migrate(build, count, sha256);
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            ok = result.Ok,
            engine_version = "test-r7-crash-engine",
            phase = result.Phase,
            result,
            managed_tree_sha256 = result.ManagedTreeSha256,
            activation_performed = result.ActivationPerformed,
            database_mutation_performed = false,
            public_mutation_performed = false,
            windows_reboot_required = false,
            recovery_required = result.RecoveryRequired
        }, AtomicFile.JsonOptions));
        break;
    }
    case "local-slot-recover":
    {
        var options = ParseOptions(args.Skip(1).ToArray());
        Require(options, "--data-root", out var dataRoot);
        var bridge = new ProductionLocalSlotMigrationBridge(new MaintenancePaths(Path.GetFullPath(dataRoot)), TimeSpan.FromSeconds(8), TimeSpan.FromSeconds(8));
        var result = bridge.Recover();
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            ok = result.Ok,
            engine_version = "test-r7-crash-engine",
            phase = result.Phase,
            result,
            managed_tree_sha256 = result.ManagedTreeSha256,
            activation_performed = result.ActivationPerformed,
            database_mutation_performed = false,
            public_mutation_performed = false,
            windows_reboot_required = false,
            recovery_required = result.RecoveryRequired
        }, AtomicFile.JsonOptions));
        break;
    }
    case "call-recover":
    {
        var options = ParseOptions(args.Skip(1).ToArray());
        Require(options, "--data-root", out var dataRoot);
        var paths = new MaintenancePaths(Path.GetFullPath(dataRoot));
        if (!File.Exists(paths.DescriptorPath)) throw new FileNotFoundException("Supervisor descriptor missing.", paths.DescriptorPath);
        var descriptor = AtomicFile.ReadJson<ControlPlaneDescriptor>(paths.DescriptorPath);
        if (!Uri.TryCreate(descriptor.BaseUrl, UriKind.Absolute, out var baseUri) || !baseUri.IsLoopback)
            throw new InvalidDataException("Supervisor descriptor is not loopback.");
        var key = new SecretStore(paths).LoadKey("recovery-console");
        var body = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { operator_id = "r7-crash-integration" }, AtomicFile.JsonOptions));
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(45) };
        using var request = HmacProtocol.CreateSignedJson(baseUri, "v1/local-slots/recover", "recovery-console", key, body, "r7-crash-recovery-" + Guid.NewGuid().ToString("N"));
        using var response = await client.SendAsync(request);
        var responseBody = await response.Content.ReadAsStringAsync();
        Console.WriteLine(responseBody);
        Environment.ExitCode = response.IsSuccessStatusCode ? 0 : 5;
        break;
    }
    default:
        throw new InvalidDataException("Unknown harness command.");
}

static Action<string>? BuildCheckpoint(string fault, string? oneShotFaultFile)
{
    if (string.Equals(fault, "none", StringComparison.OrdinalIgnoreCase) || string.IsNullOrWhiteSpace(fault)) return null;
    var parts = fault.Split(':', 2);
    if (parts.Length != 2) throw new InvalidDataException("Fault mode must be none, throw:<checkpoint>, or failfast:<checkpoint>.");
    return name =>
    {
        if (!string.Equals(name, parts[1], StringComparison.Ordinal)) return;
        if (!string.IsNullOrWhiteSpace(oneShotFaultFile))
        {
            try { File.Delete(oneShotFaultFile); } catch { }
        }
        if (string.Equals(parts[0], "throw", StringComparison.OrdinalIgnoreCase))
            throw new IOException("Injected migration failure at " + name);
        if (string.Equals(parts[0], "failfast", StringComparison.OrdinalIgnoreCase))
            Environment.FailFast("Injected abrupt migration termination at " + name);
        throw new InvalidDataException("Unknown fault mode.");
    };
}

static Dictionary<string, string> ParseOptions(string[] values)
{
    var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
    for (var i = 0; i < values.Length; i++)
    {
        if (!values[i].StartsWith("--", StringComparison.Ordinal) || i + 1 >= values.Length)
            throw new InvalidDataException("Invalid harness option list.");
        result[values[i]] = values[++i];
    }
    return result;
}

static void Require(IReadOnlyDictionary<string, string> options, string key, out string value)
{
    if (!options.TryGetValue(key, out value!) || string.IsNullOrWhiteSpace(value))
        throw new InvalidDataException(key + " is required.");
}
