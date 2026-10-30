using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Materializes a local code-only delta in a PRIVATE inactive candidate tree.
/// Never writes to the active Web junction, persistent data or database.
/// A delta archive contains delta-manifest.json and ONLY changed managed files.
/// </summary>
public static class LocalDeltaCandidateMaterializer
{
    public const string ArtifactKind = "web-delta-zip-v1";
    private const string DeltaFormat = "sokna-local-web-delta-v1";
    private const long MaxExpandedChangedBytes = 2L * 1024 * 1024 * 1024;
    private const int MaxFiles = 20_000;

    public static void Materialize(string zipPath, string candidateRoot,
        string activeWebRoot, string expectedBaseVersion, string expectedBaseTreeSha256,
        string expectedTargetVersion, string expectedTargetTreeSha256, int expectedTargetFiles)
    {
        var actualRoot = Path.GetFullPath(activeWebRoot);
        var destRoot = Path.GetFullPath(candidateRoot);
        if (string.Equals(actualRoot, destRoot, StringComparison.OrdinalIgnoreCase) ||
            destRoot.StartsWith(actualRoot + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("LOCAL_DELTA_ACTIVE_TREE_TARGET_FORBIDDEN");
        if (!Directory.Exists(actualRoot) || IsReparse(actualRoot) ||
            !File.Exists(Path.Combine(actualRoot, "VERSION.txt")) ||
            File.ReadAllText(Path.Combine(actualRoot, "VERSION.txt")).Trim() != expectedBaseVersion)
            throw new InvalidDataException("LOCAL_DELTA_PHYSICAL_BASE_VERSION_MISMATCH");
        var basePaths = ReadInventory(actualRoot);
        var actualBaseTree = LocalManagedTreeAttestation.Compute(actualRoot, basePaths.Count);
        if (!actualBaseTree.Equals(expectedBaseTreeSha256, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("LOCAL_DELTA_PHYSICAL_BASE_TREE_MISMATCH");
        // R8 active slots also contain a small machine-local overlay that is
        // deliberately absent from the managed release inventory. Do not copy
        // those bytes into the candidate or mistake them for managed drift.
        var realFiles = Directory.EnumerateFiles(actualRoot, "*", SearchOption.AllDirectories)
            .Select(f => Path.GetRelativePath(actualRoot, f).Replace('\\', '/'))
            .Where(static p => p is not (".sokna-installation.json" or
                "public/local-recovery.php" or "public/local-update-runner.php"))
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
        if (!realFiles.SetEquals(basePaths))
            throw new InvalidDataException("LOCAL_DELTA_PHYSICAL_BASE_INVENTORY_MISMATCH");
        if (Directory.EnumerateFiles(actualRoot, "*", SearchOption.AllDirectories).Any(IsReparse) ||
            Directory.EnumerateDirectories(actualRoot, "*", SearchOption.AllDirectories).Any(IsReparse))
            throw new InvalidDataException("LOCAL_DELTA_PHYSICAL_BASE_LINK_FORBIDDEN");

        using var archive = ZipFile.OpenRead(zipPath);
        if (archive.Entries.Count is < 3 or > MaxFiles + 1)
            throw new InvalidDataException("LOCAL_DELTA_ENTRY_COUNT_INVALID");
        var byPath = new Dictionary<string, ZipArchiveEntry>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in archive.Entries)
        {
            var rel = SafePath(entry.FullName);
            var unixKind = (entry.ExternalAttributes >> 16) & 0xF000;
            if (unixKind != 0 && unixKind != 0x8000)
                throw new InvalidDataException("LOCAL_DELTA_LINK_OR_SPECIAL_FILE_FORBIDDEN");
            if (!byPath.TryAdd(rel, entry))
                throw new InvalidDataException("LOCAL_DELTA_DUPLICATE_PATH");
        }
        if (!byPath.TryGetValue("delta-manifest.json", out var manifestEntry) ||
            manifestEntry.Length is <= 0 or > 1024 * 1024)
            throw new InvalidDataException("LOCAL_DELTA_MANIFEST_REQUIRED");
        LocalDeltaManifest manifest;
        using (var input = manifestEntry.Open())
        using (var memory = new MemoryStream())
        {
            input.CopyTo(memory);
            if (memory.Length > 1024 * 1024)
                throw new InvalidDataException("LOCAL_DELTA_MANIFEST_OVERSIZE");
            manifest = JsonSerializer.Deserialize<LocalDeltaManifest>(memory.ToArray(), AtomicFile.JsonOptions)
                ?? throw new InvalidDataException("LOCAL_DELTA_MANIFEST_INVALID");
        }
        if (manifest.Format != DeltaFormat || manifest.SchemaVersion != 1 ||
            manifest.BaseVersion != expectedBaseVersion ||
            !string.Equals(manifest.BaseManagedTreeSha256, expectedBaseTreeSha256, StringComparison.OrdinalIgnoreCase) ||
            manifest.TargetVersion != expectedTargetVersion ||
            !string.Equals(manifest.TargetManagedTreeSha256, expectedTargetTreeSha256, StringComparison.OrdinalIgnoreCase) ||
            manifest.ChangedPaths is null || manifest.DeletedPaths is null ||
            expectedTargetFiles is < 1 or > MaxFiles)
            throw new InvalidDataException("LOCAL_DELTA_SIGNED_TARGET_OR_BASE_MISMATCH");
        var changed = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var deleted = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var path in manifest.ChangedPaths)
        {
            if (SafePath(path) == "delta-manifest.json" || !changed.Add(SafePath(path)))
                throw new InvalidDataException("LOCAL_DELTA_DUPLICATE_CHANGE");
        }
        foreach (var path in manifest.DeletedPaths)
        {
            var rel = SafePath(path);
            if (rel == "delta-manifest.json" || !deleted.Add(rel) || changed.Contains(rel) || !basePaths.Contains(rel))
                throw new InvalidDataException("LOCAL_DELTA_INVALID_DELETION");
        }
        if (!changed.Contains("VERSION.txt") ||
            !changed.Contains("resources/local-managed-files-v1.json") ||
            !byPath.Keys.Where(x => x != "delta-manifest.json").ToHashSet(StringComparer.OrdinalIgnoreCase).SetEquals(changed))
            throw new InvalidDataException("LOCAL_DELTA_CHANGED_ENTRIES_MISMATCH");
        if (Directory.EnumerateFileSystemEntries(destRoot).Any())
            throw new InvalidDataException("LOCAL_DELTA_CANDIDATE_NOT_EMPTY");

        // Source is previously attested and immutable for this preparation
        // window; recheck after the copy to detect concurrent drift.
        foreach (var rel in basePaths.OrderBy(static s => s, StringComparer.Ordinal))
        {
            if (deleted.Contains(rel) || changed.Contains(rel)) continue;
            var source = Path.Combine(actualRoot, rel.Replace('/', Path.DirectorySeparatorChar));
            var dest = Path.Combine(destRoot, rel.Replace('/', Path.DirectorySeparatorChar));
            Directory.CreateDirectory(Path.GetDirectoryName(dest)!);
            File.Copy(source, dest, overwrite: false);
        }
        long total = 0;
        foreach (var rel in changed.OrderBy(static s => s, StringComparer.Ordinal))
        {
            var entry = byPath[rel];
            if (entry.Length < 0 || entry.Length > MaxExpandedChangedBytes - total)
                throw new InvalidDataException("LOCAL_DELTA_EXPANSION_LIMIT");
            total += entry.Length;
            var dest = Path.Combine(destRoot, rel.Replace('/', Path.DirectorySeparatorChar));
            Directory.CreateDirectory(Path.GetDirectoryName(dest)!);
            using var input = entry.Open();
            using var output = new FileStream(dest, FileMode.CreateNew, FileAccess.Write, FileShare.None);
            input.CopyTo(output);
            if (output.Length != entry.Length)
                throw new InvalidDataException("LOCAL_DELTA_CHANGED_SIZE_MISMATCH");
            output.Flush(flushToDisk: true);
        }
        if (!string.Equals(LocalManagedTreeAttestation.Compute(actualRoot, basePaths.Count),
                actualBaseTree, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("LOCAL_DELTA_BASE_CHANGED_DURING_COPY");
        // The caller performs full target inventory, version, SHA256 attestation
        // before recording an inactive candidate or authorizing activation.
    }

    private static HashSet<string> ReadInventory(string root)
    {
        using var doc = JsonDocument.Parse(File.ReadAllBytes(
            Path.Combine(root, "resources", "local-managed-files-v1.json")));
        if (!doc.RootElement.TryGetProperty("format", out var format) ||
            format.GetString() != "sokna-local-managed-files-v1" ||
            !doc.RootElement.TryGetProperty("files", out var files) ||
            files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("LOCAL_DELTA_BASE_INVENTORY_INVALID");
        var names = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var item in files.EnumerateArray())
            if (!names.Add(SafePath(item.GetString() ?? "")))
                throw new InvalidDataException("LOCAL_DELTA_BASE_DUPLICATE_PATH");
        if (names.Count is < 2 or > MaxFiles)
            throw new InvalidDataException("LOCAL_DELTA_BASE_INVENTORY_COUNT_INVALID");
        return names;
    }

    private static string SafePath(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || path.Length > 512 ||
            path.Contains('\0') || path.Contains(':') ||
            path.StartsWith('/') || path.StartsWith('\\') ||
            path.EndsWith('/') || path.EndsWith('\\'))
            throw new InvalidDataException("LOCAL_DELTA_UNSAFE_PATH");
        var parts = path.Replace('\\', '/').Split('/');
        if (parts.Any(static p => p is "" or "." or ".." || p.EndsWith(' ') || p.EndsWith('.')))
            throw new InvalidDataException("LOCAL_DELTA_UNSAFE_PATH");
        var normalized = string.Join('/', parts);
        if (normalized is ".sokna-installation.json" or "config.php" or "install.lock" or
            "public/local-update-runner.php" or "public/local-recovery.php")
            throw new InvalidDataException("LOCAL_DELTA_PROTECTED_PATH");
        return normalized;
    }

    private static bool IsReparse(string path)
        => (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;

    private sealed record LocalDeltaManifest(
        [property: JsonPropertyName("format")] string Format,
        [property: JsonPropertyName("schema_version")] int SchemaVersion,
        [property: JsonPropertyName("base_version")] string BaseVersion,
        [property: JsonPropertyName("base_managed_tree_sha256")] string BaseManagedTreeSha256,
        [property: JsonPropertyName("target_version")] string TargetVersion,
        [property: JsonPropertyName("target_managed_tree_sha256")] string TargetManagedTreeSha256,
        [property: JsonPropertyName("changed_paths")] string[] ChangedPaths,
        [property: JsonPropertyName("deleted_paths")] string[] DeletedPaths);
}
