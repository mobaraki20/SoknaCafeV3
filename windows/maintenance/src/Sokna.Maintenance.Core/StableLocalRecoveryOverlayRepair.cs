using System.Reflection;
using System.Security.Cryptography;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Repairs ONLY the machine-owned, unmanaged Local recovery PHP overlay for
/// the exact installed Home 231as R8 slot. This is never a Local code release,
/// signing operation, installed-release identity rebind, or schema mutation.
/// A different existing file is left untouched and causes an explicit block.
/// </summary>
public static class StableLocalRecoveryOverlayRepair
{
    private const string Resource = "Sokna.Maintenance.Core.StableLocalRecoveryPhp";
    private const string Relative = "public\\local-recovery.php";

    public static string RepairHome231As(MaintenancePaths paths)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("Stable Local recovery repair requires Windows.");

        LocalInstallLayout layout;
        try { layout = new LocalSlotManager(paths).InspectHealthyCurrent(); }
        catch (System.ServiceProcess.InvalidEnumArgumentException) { return "host_not_applicable"; }
        if (!string.Equals(layout.Build, Phase3HomeReleaseContract.Home231AsBuild,
                StringComparison.Ordinal))
            return "different_local_baseline_no_mutation";
        if (layout.ManagedFileCount != Phase3HomeReleaseContract.Home231AsManagedFiles ||
            !layout.WebIsReparsePoint || layout.ActiveSlotTarget is null)
            throw new InvalidDataException("STABLE_RECOVERY_EXACT_231AS_R8_REQUIRED");

        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
            layout.ActiveSlotTarget, layout.PackageRoot);
        LocalManagedTreeAttestation.RequireExact(
            layout.WebRoot,
            Phase3HomeReleaseContract.Home231AsManagedFiles,
            Phase3HomeReleaseContract.Home231AsManagedTreeSha256);

        var persistent = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        if (!Directory.Exists(persistent) || IsReparse(persistent))
            throw new InvalidDataException("STABLE_RECOVERY_PERSISTENT_ROOT_INVALID");
        RoutineLocalBinding.RequireCurrent(layout.WebRoot, layout.PackageRoot, layout.Build);
        RoutineLocalBinding.RequireTarget(
            persistent, layout.ActiveSlotTarget, layout.PackageRoot, layout.Build);

        using var asset = typeof(StableLocalRecoveryOverlayRepair).Assembly
            .GetManifestResourceStream(Resource)
            ?? throw new InvalidDataException("STABLE_RECOVERY_TRUSTED_EMBEDDED_SOURCE_MISSING");
        using var bytes = new MemoryStream();
        asset.CopyTo(bytes);
        if (bytes.Length is < 100 or > 65536)
            throw new InvalidDataException("STABLE_RECOVERY_EMBEDDED_SOURCE_SIZE_INVALID");
        var payload = bytes.ToArray();
        var targetHash = SHA256.HashData(payload);
        var activeFile = Path.Combine(layout.WebRoot, Relative);
        var persistentFile = Path.Combine(persistent, Relative);

        // Neither the machine-bound overlay nor active file may be a link or
        // contain an unrecognized version that a new installer silently replaces.
        CheckAllowed(activeFile, targetHash);
        CheckAllowed(persistentFile, targetHash);
        var created = new List<string>();
        try
        {
            foreach (var file in new[] { persistentFile, activeFile })
            {
                if (File.Exists(file)) continue;
                var directory = Path.GetDirectoryName(file)!;
                if (!Directory.Exists(directory) || IsReparse(directory))
                    throw new InvalidDataException("STABLE_RECOVERY_OVERLAY_DIRECTORY_INVALID");
                var temporary = file + ".incoming." + Guid.NewGuid().ToString("N");
                try
                {
                    File.WriteAllBytes(temporary, payload);
                    if (!Equal(SHA256.HashData(File.ReadAllBytes(temporary)), targetHash))
                        throw new InvalidDataException("STABLE_RECOVERY_STAGING_HASH_MISMATCH");
                    File.Move(temporary, file, overwrite: false);
                    created.Add(file);
                }
                finally
                {
                    if (File.Exists(temporary)) File.Delete(temporary);
                }
            }
            CheckAllowed(activeFile, targetHash);
            CheckAllowed(persistentFile, targetHash);
            LocalManagedTreeAttestation.RequireExact(
                layout.WebRoot,
                Phase3HomeReleaseContract.Home231AsManagedFiles,
                Phase3HomeReleaseContract.Home231AsManagedTreeSha256);
            return created.Count == 0 ? "verified_unchanged" : "repaired_and_verified";
        }
        catch
        {
            // A failed partially-applied repair must not leave an inconsistent
            // overlay pair. Only remove bytes this transaction created.
            foreach (var file in created)
                if (File.Exists(file) && Equal(SHA256.HashData(File.ReadAllBytes(file)), targetHash))
                    File.Delete(file);
            throw;
        }
    }

    private static void CheckAllowed(string file, byte[] expected)
    {
        if (!File.Exists(file)) return;
        if (IsReparse(file) || !Equal(SHA256.HashData(File.ReadAllBytes(file)), expected))
            throw new InvalidDataException("STABLE_RECOVERY_EXISTING_OVERLAY_NOT_QUALIFIED");
    }

    private static bool IsReparse(string path) =>
        (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;

    private static bool Equal(byte[] a, byte[] b) =>
        CryptographicOperations.FixedTimeEquals(a, b);
}
