using System.Text.Json;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7C2: only copies verified managed Web files and machine-local overlays
/// to a NEW sibling directory. Never mutates a running Apache pointer, Local
/// lifecycle state, DB, Public, or the persistent machine-local binding.
/// Host-facing runtime and migration catalog gates run before the caller commits.
/// </summary>
public static class ProductionInactiveLocalSlotWriter
{
    private static readonly string[] Overlay =
    [
        ".sokna-installation.json",
        Path.Combine("public", "local-recovery.php"),
        Path.Combine("public", "local-update-runner.php")
    ];

    public static void CopyToNewSibling(
        string verifiedPrivateSource, string incomingSibling, string packageRoot,
        string activeWebRoot, string currentBuild, string targetBuild,
        int targetManagedCount, string targetTreeSha)
    {
        var root = Path.GetFullPath(packageRoot);
        var active = Path.GetFullPath(activeWebRoot);
        var source = Path.GetFullPath(verifiedPrivateSource);
        var incoming = Path.GetFullPath(incomingSibling);
        var persistent = Path.Combine(root, "LocalWeb", "persistent");
        if (!Directory.Exists(source) || HasReparse(source) ||
            !Directory.Exists(persistent) || HasReparse(persistent) ||
            !File.Exists(Path.Combine(persistent, ".sokna-installation.json")) ||
            !Directory.Exists(active) || !HasReparse(active) ||
            !Directory.Exists(root) || HasReparse(root))
            throw new InvalidDataException("INACTIVE_SLOT_SOURCE_OR_BINDING_NOT_QUALIFIED");
        if (!string.Equals(Path.GetDirectoryName(incoming), root, StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(incoming).StartsWith(ProductionLocalSlotMigrationBridge.ReleaseSlotPrefix,
                StringComparison.OrdinalIgnoreCase) ||
            Directory.Exists(incoming) || File.Exists(incoming))
            throw new InvalidDataException("INACTIVE_SLOT_DESTINATION_NOT_NEW_SIBLING");

        var inventoryPath = Path.Combine(source, "resources", "local-managed-files-v1.json");
        if (!File.Exists(inventoryPath) || HasReparse(inventoryPath))
            throw new InvalidDataException("INACTIVE_SLOT_INVENTORY_UNSAFE");
        using var document = JsonDocument.Parse(File.ReadAllBytes(inventoryPath));
        var inv = document.RootElement;
        if (inv.GetProperty("format").GetString() != "sokna-local-managed-files-v1" ||
            !inv.TryGetProperty("files", out var files) ||
            files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("INACTIVE_SLOT_INVENTORY_UNSAFE");

        var relativeFiles = new List<string>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var file in files.EnumerateArray())
        {
            var value = file.GetString() ?? string.Empty;
            var rel = value.Replace('/', Path.DirectorySeparatorChar)
                .Replace('\\', Path.DirectorySeparatorChar);
            if (rel.Length is < 1 or > 512 || Path.IsPathRooted(rel) ||
                rel.Contains(':') || rel.Split(Path.DirectorySeparatorChar).Any(
                    static p => p is "" or "." or ".." || p.EndsWith('.') || p.EndsWith(' ')) ||
                Overlay.Contains(rel, StringComparer.OrdinalIgnoreCase) ||
                !seen.Add(rel))
                throw new InvalidDataException("INACTIVE_SLOT_MANAGED_FILE_PATH_UNSAFE");
            relativeFiles.Add(rel);
        }
        if (relativeFiles.Count != targetManagedCount ||
            !seen.SetEquals(Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories)
                .Select(x => Path.GetRelativePath(source, x))))
            throw new InvalidDataException("INACTIVE_SLOT_INVENTORY_MISMATCH");
        if (Directory.EnumerateDirectories(source, "*", SearchOption.AllDirectories).Any(HasReparse) ||
            Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories).Any(HasReparse))
            throw new InvalidDataException("INACTIVE_SLOT_SOURCE_REPARSE_FORBIDDEN");
        if (!string.Equals(File.ReadAllText(Path.Combine(source, "VERSION.txt")).Trim(),
            targetBuild, StringComparison.Ordinal))
            throw new InvalidDataException("INACTIVE_SLOT_BUILD_MISMATCH");
        LocalManagedTreeAttestation.RequireExact(source, targetManagedCount, targetTreeSha);

        RoutineLocalBinding.RequireCurrent(active, root, currentBuild);
        RoutineLocalBinding.RequireTarget(persistent, active, root, currentBuild);
        foreach (var rel in Overlay)
        {
            var local = Path.Combine(persistent, rel);
            if (File.Exists(local) && HasReparse(local))
                throw new InvalidDataException("INACTIVE_SLOT_OVERLAY_REPARSE_FORBIDDEN");
        }
        Directory.CreateDirectory(incoming);
        foreach (var rel in relativeFiles)
        {
            var from = Path.Combine(source, rel);
            var to = Path.Combine(incoming, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(to)!);
            File.Copy(from, to, overwrite: false);
        }
        foreach (var rel in Overlay.Skip(1))
        {
            var from = Path.Combine(persistent, rel);
            if (!File.Exists(from)) continue;
            var to = Path.Combine(incoming, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(to)!);
            File.Copy(from, to, overwrite: false);
        }
        RoutineLocalBinding.PrepareTarget(persistent, incoming, root, currentBuild, targetBuild);
        LocalManagedTreeAttestation.RequireExact(incoming, targetManagedCount, targetTreeSha);
        RoutineLocalBinding.RequireTarget(persistent, incoming, root, targetBuild);
        foreach (var rel in Overlay.Skip(1))
        {
            var from = Path.Combine(persistent, rel);
            var to = Path.Combine(incoming, rel);
            if (File.Exists(from) != File.Exists(to) ||
                File.Exists(from) &&
                !System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(
                    System.Security.Cryptography.SHA256.HashData(File.ReadAllBytes(from)),
                    System.Security.Cryptography.SHA256.HashData(File.ReadAllBytes(to))))
                throw new InvalidDataException("INACTIVE_SLOT_OVERLAY_MISMATCH");
        }
    }

    private static bool HasReparse(string path) =>
        (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
}
