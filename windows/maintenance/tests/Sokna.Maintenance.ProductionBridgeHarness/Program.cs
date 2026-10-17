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
        var fault = args[5];
        Action<string>? checkpoint = null;
        if (!string.Equals(fault, "none", StringComparison.OrdinalIgnoreCase))
        {
            var parts = fault.Split(':', 2);
            if (parts.Length != 2) throw new InvalidDataException("Fault mode must be none, throw:<checkpoint>, or failfast:<checkpoint>.");
            checkpoint = name =>
            {
                if (!string.Equals(name, parts[1], StringComparison.Ordinal)) return;
                if (string.Equals(parts[0], "throw", StringComparison.OrdinalIgnoreCase))
                    throw new IOException("Injected migration failure at " + name);
                if (string.Equals(parts[0], "failfast", StringComparison.OrdinalIgnoreCase))
                    Environment.FailFast("Injected abrupt migration termination at " + name);
                throw new InvalidDataException("Unknown fault mode.");
            };
        }
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
    default:
        throw new InvalidDataException("Unknown harness command.");
}
