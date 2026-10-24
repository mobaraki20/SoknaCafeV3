using System.Reflection;
using System.Security.Cryptography;

namespace Sokna.Maintenance.Core;

/// <summary>Maintenance-owned compatibility entrypoint, never a managed Local
/// code file. Managed-slot POST recovery stays owned by the Windows console.
/// Missing files are provisioned from this assembly only; foreign bytes block.
/// Startup and routine preparation use the same installed-identity contract.</summary>
public static class StableLocalRecoveryOverlayRepair
{
    private const string Resource = "Sokna.Maintenance.Core.StableLocalRecoveryPhp";
    private static readonly string Relative = Path.Combine("public", "local-recovery.php");

    public static string RepairCurrentInstallation(MaintenancePaths paths)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("Stable Local recovery repair requires Windows.");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(paths, "stable-recovery-overlay");
            LocalUpdateOperationGate.RequireNoPendingMutation(new MaintenanceOperationStore(paths));
            // No fabricated identity on an installation not adopted yet.
            if (!File.Exists(paths.InstalledReleaseIdentityPath)) return "not_adopted_no_mutation";
            var layout = new LocalSlotManager(paths).InspectHealthyCurrent();
            RoutineLocalLifecycle.Open(layout.PackageRoot, paths.LocalSlotStateRoot, layout.Build).RequireIdle();
            return EnsureVerifiedInstallation(paths, layout);
        }
    }

    // Kept for older callers; there is no build-specific exception anymore.
    public static string RepairHome231As(MaintenancePaths paths) => RepairCurrentInstallation(paths);

    // Caller holds the common machine lease and has verified the ledger/lifecycle.
    // Internal to prevent use as an unqualified external repair API.
    internal static string EnsureVerifiedInstallation(MaintenancePaths paths, LocalInstallLayout layout)
    {
        if (!layout.WebIsReparsePoint || layout.ActiveSlotTarget is null)
            throw new InvalidDataException("STABLE_RECOVERY_MANAGED_SLOT_REQUIRED");
        var root = Path.GetFullPath(layout.PackageRoot);
        var slot = Path.GetFullPath(layout.ActiveSlotTarget);
        var web = Path.GetFullPath(layout.WebRoot);
        if (!string.Equals(web, Path.Combine(root, "Web"), StringComparison.OrdinalIgnoreCase) ||
            !IsReparse(web) ||
            !string.Equals(new DirectoryInfo(web).ResolveLinkTarget(true)?.FullName, slot, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("STABLE_RECOVERY_ACTIVE_POINTER_MISMATCH");
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(slot, root);
        RequirePlainDirectory(root);
        RequirePlainDirectory(slot);
        var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(paths.InstalledReleaseIdentityPath);
        if (identity.Local is null || identity.Local.Version != layout.Build)
            throw new InvalidDataException("STABLE_RECOVERY_INSTALLED_IDENTITY_REQUIRED");
        LocalManagedTreeAttestation.RequireExact(slot, layout.ManagedFileCount, identity.Local.ManagedTreeSha256);
        var localWeb = Path.Combine(root, "LocalWeb");
        var persistent = Path.Combine(localWeb, "persistent");
        RequirePlainDirectory(localWeb);
        RequirePlainDirectory(persistent);
        RoutineLocalBinding.RequireCurrent(slot, root, layout.Build);
        RoutineLocalBinding.RequireTarget(persistent, slot, root, layout.Build);
        using var asset = typeof(StableLocalRecoveryOverlayRepair).Assembly.GetManifestResourceStream(Resource)
            ?? throw new InvalidDataException("STABLE_RECOVERY_TRUSTED_EMBEDDED_SOURCE_MISSING");
        using var bytes = new MemoryStream(); asset.CopyTo(bytes);
        if (bytes.Length is < 100 or > 65536)
            throw new InvalidDataException("STABLE_RECOVERY_EMBEDDED_SOURCE_SIZE_INVALID");
        var payload = bytes.ToArray(); var expected = SHA256.HashData(payload);
        var files = new[] { Path.Combine(persistent, Relative), Path.Combine(slot, Relative) };
        // Validate BOTH paths before creating either: no overwrite, no redirected
        // parent, no presence-equality shortcut that accepts two absent files.
        foreach (var file in files)
        {
            var directory = Path.GetDirectoryName(file)!;
            if (Directory.Exists(directory)) RequirePlainDirectory(directory);
            else if (File.Exists(directory)) throw new InvalidDataException("STABLE_RECOVERY_OVERLAY_DIRECTORY_INVALID");
            CheckAllowed(file, expected);
        }
        var created = 0;
        foreach (var file in files)
        {
            if (File.Exists(file)) continue;
            Directory.CreateDirectory(Path.GetDirectoryName(file)!);
            var temp = file + ".incoming." + Guid.NewGuid().ToString("N");
            try
            {
                using (var output = new FileStream(temp, FileMode.CreateNew, FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough))
                { output.Write(payload); output.Flush(true); }
                CheckAllowed(temp, expected);
                File.Move(temp, file, overwrite: false);
                created++;
            }
            finally { if (File.Exists(temp)) File.Delete(temp); }
        }
        // A process interruption after the first atomic move is safely resumed:
        // the next call accepts only exact embedded bytes and fills the missing
        // partner. Until then CopyToNewSibling retains its missing-file guard.
        foreach (var file in files)
        {
            if (!File.Exists(file)) throw new InvalidDataException("STABLE_RECOVERY_OVERLAY_READBACK_MISSING");
            CheckAllowed(file, expected);
        }
        LocalManagedTreeAttestation.RequireExact(slot, layout.ManagedFileCount, identity.Local.ManagedTreeSha256);
        return created == 0 ? "verified_unchanged" : "repaired_and_verified";
    }

    private static void RequirePlainDirectory(string path)
    {
        if (!Directory.Exists(path) || IsReparse(path))
            throw new InvalidDataException("STABLE_RECOVERY_DIRECTORY_REDIRECTED");
    }

    private static void CheckAllowed(string file, byte[] expected)
    {
        if (Directory.Exists(file)) throw new InvalidDataException("STABLE_RECOVERY_EXISTING_OVERLAY_NOT_QUALIFIED");
        if (!File.Exists(file)) return;
        if (IsReparse(file) || new FileInfo(file).Length > 65536 || !Equal(SHA256.HashData(File.ReadAllBytes(file)), expected))
            throw new InvalidDataException("STABLE_RECOVERY_EXISTING_OVERLAY_NOT_QUALIFIED");
    }

    private static bool IsReparse(string path) =>
        (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;

    private static bool Equal(byte[] a, byte[] b) =>
        CryptographicOperations.FixedTimeEquals(a, b);
}

