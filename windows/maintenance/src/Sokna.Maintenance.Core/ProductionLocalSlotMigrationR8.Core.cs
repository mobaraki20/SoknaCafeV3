using System.Diagnostics;
using System.Security.Cryptography;
using System.ServiceProcess;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed partial class ProductionLocalSlotMigrationBridge
{
    private const string ApacheServiceName = LocalSlotManager.ApacheServiceName;
    private const string InventoryRelativePath = "resources\\local-managed-files-v1.json";
    public const string ReleaseSlotPrefix = "Web.release.";

    private static readonly string[] MachineLocalOverlayPaths =
    {
        ".sokna-installation.json",
        "public\\local-recovery.php",
        "public\\local-update-runner.php"
    };

    private readonly MaintenancePaths _paths;
    private readonly LocalSlotManager _discovery;
    private readonly TimeSpan _serviceTimeout;
    private readonly TimeSpan _healthTimeout;
    private readonly Action<string>? _checkpoint;

    public ProductionLocalSlotMigrationBridge(
        MaintenancePaths paths,
        TimeSpan? serviceTimeout = null,
        TimeSpan? healthTimeout = null,
        Action<string>? checkpoint = null)
    {
        _paths = paths;
        _discovery = new LocalSlotManager(paths, serviceTimeout, healthTimeout);
        _serviceTimeout = serviceTimeout ?? TimeSpan.FromSeconds(35);
        _healthTimeout = healthTimeout ?? TimeSpan.FromSeconds(35);
        _checkpoint = checkpoint;
    }

    public string StatePath => Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");

    public static string BuildProductionSlotPath(string packageRoot, string expectedBuild, string treeSha256)
    {
        packageRoot = Path.GetFullPath(packageRoot);
        treeSha256 = LocalManagedTreeAttestation.NormalizeSha256(treeSha256);
        return Path.Combine(packageRoot, BuildProductionSlotName(expectedBuild, treeSha256));
    }

    public static void RequireProductionSlotPath(string? slot, string packageRoot)
    {
        if (string.IsNullOrWhiteSpace(slot)) throw new InvalidDataException("Active Local slot is empty.");
        var root = Path.GetFullPath(packageRoot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var full = Path.GetFullPath(slot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var parent = Path.GetDirectoryName(full)?.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        if (string.IsNullOrWhiteSpace(parent) || !string.Equals(parent, root, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Active Local slot must be a direct child of the package root.");
        var name = Path.GetFileName(full);
        if (string.IsNullOrWhiteSpace(name) || !name.StartsWith(ReleaseSlotPrefix, StringComparison.OrdinalIgnoreCase) ||
            string.Equals(name, "Web", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Active Local slot does not use the production sibling-release namespace.");
        if (!Directory.Exists(full)) throw new DirectoryNotFoundException("Active Local slot does not exist.");
        if ((File.GetAttributes(full) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("Production Local release slot must be a physical directory, not another reparse point.");
    }

    private static string BuildProductionSlotName(string expectedBuild, string treeSha256)
    {
        var token = Regex.Match(expectedBuild, @"\.(?<v>\d+[a-z]+)(?:-|$)", RegexOptions.IgnoreCase).Groups["v"].Value;
        if (string.IsNullOrWhiteSpace(token)) token = "local";
        token = Regex.Replace(token.ToLowerInvariant(), "[^a-z0-9._-]", "-");
        return $"{ReleaseSlotPrefix}{token}-{treeSha256[..12]}";
    }

    private void Checkpoint(string name)
    {
        _checkpoint?.Invoke(name);
    }

    private static void ValidateManagedCount(int count)
    {
        if (count is < 1 or > 20_000) throw new InvalidDataException("expected_managed_files is invalid.");
    }

    private static string NormalizeBuild(string value)
    {
        value = (value ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(value) || value.Length > 180 || value.Any(char.IsControl))
            throw new InvalidDataException("expected_build is invalid.");
        return value;
    }

    private static bool PathsEqual(string first, string second)
        => string.Equals(
            Path.GetFullPath(first).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar),
            Path.GetFullPath(second).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar),
            StringComparison.OrdinalIgnoreCase);
}
