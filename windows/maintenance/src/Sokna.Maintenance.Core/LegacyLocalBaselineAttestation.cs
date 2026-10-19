using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Runtime.CompilerServices;

[assembly: InternalsVisibleTo("Sokna.Maintenance.LegacyBaselineHarness")]

namespace Sokna.Maintenance.Core;

/// <summary>
/// Strict, read-only recognition of historical physical-Web Local installs.
/// This is an input adapter for the SINGLE signed Maintenance Update Center,
/// never an additional installer or an activation permit. Its immutable
/// digest catalog comes from exact qualified Local source distributions.
/// </summary>
public static class LegacyLocalBaselineAttestation
{
    // A baseline can be added only after a complete source ZIP has been
    // independently hash-qualified. No hostname, customer ID or host path.
    private sealed record Pinned(string Build, int Count, string InventorySha256, string TreeSha256);
    private static readonly Pinned[] Trusted =
    {
        new("3.0.0-g6.2-workspace.231ag-updater-failure-domain-closure",
            496,
            "fb43b69a2c0291e1be11e3ceae9909ef4b9314aaa2e4bb122ea0cc5b68e70f96",
            "c4bf2db91bd0f291a6f4a1c13a25ad7868ba51831eaa2ccd6533f09d07f28904")
    };

    public sealed record Assessment(
        string Format,
        int SchemaVersion,
        string Build,
        string InventoryFormat,
        int ManagedFiles,
        string ManagedTreeSha256,
        bool ExactBaselineVerified,
        bool LegacyToSlotTransitionRequired,
        bool SignedStageVerified,
        bool TargetBootVerified,
        bool ActivationAllowed,
        bool PanelUpdateReady,
        string NextGate);

    public static Assessment RequireExactPhysicalWeb(string webRoot)
    {
        if (string.IsNullOrWhiteSpace(webRoot))
            throw new InvalidDataException("LEGACY_WEB_ROOT_MISSING");
        var root = Path.GetFullPath(webRoot);
        if (!Directory.Exists(root) || IsReparse(root))
            throw new InvalidDataException("LEGACY_PHYSICAL_WEB_REQUIRED");

        var versionFile = Path.Combine(root, "VERSION.txt");
        RequirePlainFile(root, versionFile);
        var build = File.ReadAllText(versionFile, Encoding.UTF8).Trim();
        var pinned = Trusted.SingleOrDefault(p => string.Equals(p.Build, build, StringComparison.Ordinal))
            ?? throw new InvalidDataException("LEGACY_BASELINE_BUILD_NOT_QUALIFIED");
        return RequireAgainstPinned(root, build, pinned);
    }

    // Test only: this has NO signed Stage/Activate authorization and is never
    // called by Supervisor. It allows tiny positive fixture tests without
    // copying customer data or a full 231ag distribution into this repo.
    internal static Assessment RequireFixture(string webRoot, string expectedBuild,
        int count, string inventorySha256, string treeSha256)
    {
        var root = Path.GetFullPath(webRoot);
        if (!Directory.Exists(root) || IsReparse(root))
            throw new InvalidDataException("LEGACY_PHYSICAL_WEB_REQUIRED");
        var versionFile = Path.Combine(root, "VERSION.txt");
        RequirePlainFile(root, versionFile);
        var build = File.ReadAllText(versionFile, Encoding.UTF8).Trim();
        if (!string.Equals(build, expectedBuild, StringComparison.Ordinal))
            throw new InvalidDataException("LEGACY_BASELINE_BUILD_NOT_QUALIFIED");
        return RequireAgainstPinned(root, build,
            new Pinned(expectedBuild, count, inventorySha256, treeSha256));
    }

    private static Assessment RequireAgainstPinned(string root, string build, Pinned pinned)
    {
        var inventoryFile = Path.Combine(root, "resources", "H300_MANAGED_FILES.json");
        RequirePlainFile(root, inventoryFile);
        var inventoryBytes = File.ReadAllBytes(inventoryFile);
        RequireSha(inventoryBytes, pinned.InventorySha256, "LEGACY_INVENTORY_FINGERPRINT_MISMATCH");
        using var document = JsonDocument.Parse(inventoryBytes);
        var d = document.RootElement;
        if (d.ValueKind != JsonValueKind.Object ||
            !d.TryGetProperty("files", out var array) || array.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("LEGACY_INVENTORY_INVALID");

        var paths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var el in array.EnumerateArray())
        {
            if (el.ValueKind != JsonValueKind.String)
                throw new InvalidDataException("LEGACY_INVENTORY_INVALID");
            var rel = (el.GetString() ?? "").Replace('\\', '/');
            if (string.IsNullOrWhiteSpace(rel) || rel.StartsWith('/') ||
                rel.Contains(':') || rel.Split('/').Any(p => p is "" or "." or "..") ||
                !paths.Add(rel))
                throw new InvalidDataException("LEGACY_INVENTORY_PATH_UNSAFE");
        }
        if (paths.Count != pinned.Count)
            throw new InvalidDataException("LEGACY_INVENTORY_COUNT_MISMATCH");

        using var aggregate = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
        foreach (var rel in paths.OrderBy(x => x, StringComparer.Ordinal))
        {
            var path = Path.Combine(root, rel.Replace('/', Path.DirectorySeparatorChar));
            RequirePlainFile(root, path);
            var actualHash = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
            aggregate.AppendData(Encoding.UTF8.GetBytes(rel + "\0" + actualHash + "\n"));
        }
        var actualTree = Convert.ToHexString(aggregate.GetHashAndReset()).ToLowerInvariant();
        if (!ExactHex(actualTree, pinned.TreeSha256))
            throw new InvalidDataException("LEGACY_MANAGED_TREE_MISMATCH");

        // Exact baseline proof is NOT a signed target package, preflight,
        // operational DB proof, staged slot, rollback proof or activation gate.
        return new Assessment("sokna-local-legacy-baseline-assessment-v1", 1,
            build, "H300_MANAGED_FILES", paths.Count, actualTree,
            ExactBaselineVerified: true,
            LegacyToSlotTransitionRequired: true,
            SignedStageVerified: false,
            TargetBootVerified: false,
            ActivationAllowed: false,
            PanelUpdateReady: false,
            NextGate: "SIGNED_LEGACY_TO_SLOT_STAGE_AND_ROLLBACK_UAT_REQUIRED");
    }

    private static void RequirePlainFile(string root, string fullPath)
    {
        var path = Path.GetFullPath(fullPath);
        var rootPrefix = root.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
                         + Path.DirectorySeparatorChar;
        if (!path.StartsWith(rootPrefix, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("LEGACY_PATH_ESCAPE");
        if (!File.Exists(path) || IsReparse(path))
            throw new InvalidDataException("LEGACY_FILE_MISSING_OR_REPARSE: " + Path.GetFileName(path));
        var parent = Path.GetDirectoryName(path);
        while (!string.IsNullOrEmpty(parent) &&
               !string.Equals(parent, root, StringComparison.OrdinalIgnoreCase))
        {
            if (IsReparse(parent)) throw new InvalidDataException("LEGACY_DIRECTORY_REPARSE");
            parent = Path.GetDirectoryName(parent);
        }
    }

    private static bool IsReparse(string path)
        => (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;

    private static void RequireSha(byte[] bytes, string expected, string code)
    {
        var actual = Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
        if (!ExactHex(actual, expected)) throw new InvalidDataException(code);
    }

    private static bool ExactHex(string a, string b)
        => CryptographicOperations.FixedTimeEquals(Convert.FromHexString(a), Convert.FromHexString(b));
}
