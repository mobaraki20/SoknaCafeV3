using System.Diagnostics;
using System.Security.Cryptography;
using System.ServiceProcess;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed partial class ProductionLocalSlotMigrationBridge
{
    private void RestorePhysicalWebAndRequireHealth(
        LocalInstallLayout layout,
        string physicalBackup,
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256)
    {
        try { StopApacheAndProveExit(layout.ApacheExecutable); } catch { }

        if (Directory.Exists(layout.WebRoot))
        {
            var attributes = File.GetAttributes(layout.WebRoot);
            if ((attributes & FileAttributes.ReparsePoint) != 0)
                RemoveJunction(layout.WebRoot);
            else
                throw new IOException("Cannot restore physical Web because stable Web is occupied by a non-junction directory.");
        }

        if (!Directory.Exists(physicalBackup))
            throw new DirectoryNotFoundException("Physical Web backup is unavailable for rollback.");

        Directory.Move(physicalBackup, layout.WebRoot);
        StartApache();
        RequireHttpHealth(expectedBuild, layout.HealthUrl);
        var restored = _discovery.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
        if (restored.WebIsReparsePoint) throw new InvalidDataException("Automatic rollback did not restore the physical Web layout.");
        LocalManagedTreeAttestation.RequireExact(restored.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
    }

    private void ArchiveLegacyPhase3State(List<ProductionLocalSlotMigrationEvent> events)
    {
        if (!File.Exists(_paths.LocalSlotStatePath)) return;
        var historyRoot = Path.Combine(_paths.LocalSlotStateRoot, "history");
        Directory.CreateDirectory(historyRoot);
        var history = Path.Combine(historyRoot, "phase3-state-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssfffZ") + ".json");
        File.Move(_paths.LocalSlotStatePath, history);
        events.Add(new ProductionLocalSlotMigrationEvent("LEGACY_PHASE3_STATE_ARCHIVED", DateTimeOffset.UtcNow, history));
    }

    private static IReadOnlyList<string> ReadManagedFiles(string webRoot, int expectedManagedFiles)
    {
        var inventoryPath = Path.Combine(webRoot, InventoryRelativePath);
        if (!File.Exists(inventoryPath)) throw new FileNotFoundException("Current release managed inventory is missing.", inventoryPath);
        using var document = JsonDocument.Parse(File.ReadAllBytes(inventoryPath));
        var root = document.RootElement;
        if (!root.TryGetProperty("format", out var format) || format.GetString() != "sokna-local-managed-files-v1")
            throw new InvalidDataException("Managed inventory format is invalid.");
        if (!root.TryGetProperty("files", out var files) || files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("Managed inventory files array is invalid.");

        var result = new List<string>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in files.EnumerateArray())
        {
            var rel = (entry.GetString() ?? string.Empty).Replace('/', Path.DirectorySeparatorChar).TrimStart(Path.DirectorySeparatorChar);
            if (string.IsNullOrWhiteSpace(rel) || Path.IsPathRooted(rel) || rel.Split(Path.DirectorySeparatorChar).Any(static p => p is "" or "." or ".."))
                throw new InvalidDataException("Managed inventory contains an unsafe relative path.");
            if (!seen.Add(rel)) throw new InvalidDataException($"Managed inventory contains a duplicate path: {rel}");
            result.Add(rel);
        }
        if (result.Count != expectedManagedFiles)
            throw new InvalidDataException($"Managed inventory count mismatch. Expected {expectedManagedFiles}, found {result.Count}.");
        return result;
    }

    private static void CaptureMachineLocalFiles(string webRoot, string persistentRoot)
    {
        var binding = Path.Combine(webRoot, ".sokna-installation.json");
        if (!File.Exists(binding)) throw new InvalidDataException("Machine-local installation binding is missing before production slot migration.");
        Directory.CreateDirectory(persistentRoot);
        foreach (var rel in MachineLocalOverlayPaths)
        {
            var source = Path.Combine(webRoot, rel);
            var target = Path.Combine(persistentRoot, rel);
            if (File.Exists(source))
            {
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                File.Copy(source, target, overwrite: true);
            }
            else if (File.Exists(target))
            {
                File.Delete(target);
            }
        }
    }

    private static void StageProductionSlot(
        string sourceWebRoot,
        string targetSlot,
        IReadOnlyList<string> managedFiles,
        string persistentRoot,
        string archiveRoot)
    {
        var packageRoot = Path.GetDirectoryName(Path.GetFullPath(targetSlot))
            ?? throw new InvalidDataException("Target Local slot has no package root.");
        if (Directory.Exists(targetSlot))
        {
            RequireProductionSlotPath(targetSlot, packageRoot);
            Directory.CreateDirectory(archiveRoot);
            var archive = Path.Combine(
                archiveRoot,
                "staged-slot-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssfffZ") + "-" + Path.GetFileName(targetSlot));
            Directory.Move(targetSlot, archive);
        }
        else
        {
            var name = Path.GetFileName(targetSlot);
            if (!name.StartsWith(ReleaseSlotPrefix, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("Target Local slot does not use the sibling-release namespace.");
        }

        Directory.CreateDirectory(targetSlot);
        foreach (var rel in managedFiles)
        {
            var source = Path.Combine(sourceWebRoot, rel);
            if (!File.Exists(source)) throw new FileNotFoundException($"Managed Local file is missing: {rel}", source);
            var target = Path.Combine(targetSlot, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: false);
        }

        foreach (var rel in MachineLocalOverlayPaths)
        {
            var source = Path.Combine(persistentRoot, rel);
            var target = Path.Combine(targetSlot, rel);
            if (File.Exists(source))
            {
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                File.Copy(source, target, overwrite: true);
            }
            else if (File.Exists(target))
            {
                File.Delete(target);
            }
        }
    }

    private static void VerifyStagedSlot(
        string targetSlot,
        string persistentRoot,
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256)
    {
        var versionPath = Path.Combine(targetSlot, "VERSION.txt");
        if (!File.Exists(versionPath)) throw new FileNotFoundException("Staged production slot VERSION.txt is missing.", versionPath);
        var build = File.ReadAllText(versionPath).Trim();
        if (!string.Equals(build, expectedBuild, StringComparison.Ordinal))
            throw new InvalidDataException($"Staged production slot build mismatch. Expected '{expectedBuild}', found '{build}'.");
        LocalManagedTreeAttestation.RequireExact(targetSlot, expectedManagedFiles, expectedManagedTreeSha256);
        VerifyMachineLocalOverlay(targetSlot, persistentRoot);
    }

    private static void VerifyMachineLocalOverlay(string slotRoot, string persistentRoot)
    {
        foreach (var rel in MachineLocalOverlayPaths)
        {
            var source = Path.Combine(persistentRoot, rel);
            var target = Path.Combine(slotRoot, rel);
            var sourceExists = File.Exists(source);
            var targetExists = File.Exists(target);
            if (sourceExists != targetExists)
                throw new InvalidDataException($"Machine-local overlay presence mismatch for '{rel}'.");
            if (sourceExists && !FilesEqual(source, target))
                throw new InvalidDataException($"Machine-local overlay hash mismatch for '{rel}'.");
        }
        if (!File.Exists(Path.Combine(slotRoot, ".sokna-installation.json")))
            throw new InvalidDataException("Production Local release slot binding overlay is missing.");
    }

    private static bool FilesEqual(string first, string second)
    {
        var firstInfo = new FileInfo(first);
        var secondInfo = new FileInfo(second);
        if (firstInfo.Length != secondInfo.Length) return false;
        using var left = File.OpenRead(first);
        using var right = File.OpenRead(second);
        var leftHash = SHA256.HashData(left);
        var rightHash = SHA256.HashData(right);
        return CryptographicOperations.FixedTimeEquals(leftHash, rightHash);
    }
}
