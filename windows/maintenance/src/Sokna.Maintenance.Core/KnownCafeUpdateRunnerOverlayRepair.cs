using System.Security.Cryptography;
using System.Runtime.CompilerServices;

[assembly: InternalsVisibleTo("Sokna.Maintenance.CafeRunnerHarness")]

namespace Sokna.Maintenance.Core;

/// <summary>
/// Scoped Cafe 231bz host repair. The active update runner is missing while
/// a known, machine-local persistent runner is present. Restore ONLY the
/// exact pinned source bytes into the missing active sibling. No user-provided
/// path, arbitrary PHP source, replacement of existing code or DB mutation.
/// Must run under the machine lease, verified installed identity, physical
/// managed-tree attestation, matching machine binding and idle lifecycle.
/// </summary>
public static class KnownCafeUpdateRunnerOverlayRepair
{
    internal const string CafeVersion = KnownCafeRecoveryOverlayMigration.Cafe231BzVersion;
    internal const string CafeTree = KnownCafeRecoveryOverlayMigration.Cafe231BzTree;
    // Field-observed persistent source, not a general trusted PHP fingerprint.
    // Its use is restricted to this one exact Cafe physical baseline, and
    // only to restore the absent partner from the same machine installation.
    internal const string CafePersistentRunnerSha =
        "21c1fea741bc343c7027471b8ea118a3f61f1faae57129c2873338a5423d42bd";
    private const string Relative = "local-update-runner.php";
    private const int MaxBytes = 65536;

    public static string RepairCurrentInstallation(MaintenancePaths paths)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("Cafe runner repair requires Windows.");
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(paths,
                "known-cafe-missing-active-runner");
            LocalUpdateOperationGate.RequireNoPendingMutation(
                new MaintenanceOperationStore(paths));
            if (!File.Exists(paths.InstalledReleaseIdentityPath))
                return "not_adopted_no_mutation";

            var layout = new LocalSlotManager(paths).InspectHealthyCurrent();
            if (layout.Build != CafeVersion || !layout.WebIsReparsePoint ||
                layout.ActiveSlotTarget is null)
                return "different_installation_no_mutation";
            var identity=AtomicFile.ReadJson<InstalledReleaseIdentity>(
                paths.InstalledReleaseIdentityPath);
            if (identity.Local is null || identity.Local.Version != CafeVersion ||
                !string.Equals(identity.Local.ManagedTreeSha256, CafeTree,
                    StringComparison.OrdinalIgnoreCase))
                return "different_identity_no_mutation";
            LocalManagedTreeAttestation.RequireExact(layout.WebRoot,
                layout.ManagedFileCount, CafeTree);
            RoutineLocalLifecycle.Open(layout.PackageRoot,
                paths.LocalSlotStateRoot, layout.Build).RequireIdle();
            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
                layout.ActiveSlotTarget, layout.PackageRoot);
            var web=Path.GetFullPath(layout.WebRoot);
            var slot=Path.GetFullPath(layout.ActiveSlotTarget);
            if (!string.Equals(web,Path.Combine(layout.PackageRoot,"Web"),
                    StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(new DirectoryInfo(web).ResolveLinkTarget(true)?.FullName,
                    slot,StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("CAFE_RUNNER_ACTIVE_JUNCTION_MISMATCH");
            var persistent=Path.Combine(layout.PackageRoot,"LocalWeb","persistent");
            RoutineLocalBinding.RequireCurrent(slot,layout.PackageRoot,CafeVersion);
            RoutineLocalBinding.RequireTarget(persistent,slot,layout.PackageRoot,CafeVersion);

            var source=Path.Combine(persistent,"public",Relative);
            var target=Path.Combine(slot,"public",Relative);
            var result=RestoreExactMissingPair(source,target,CafePersistentRunnerSha);
            // Read back via the actual Web junction, not only the slot path.
            var linked=Path.Combine(web,"public",Relative);
            if (!File.Exists(linked) ||
                !EqualHash(linked,CafePersistentRunnerSha))
                throw new InvalidDataException("CAFE_RUNNER_ACTIVE_JUNCTION_READBACK_FAILED");
            LocalManagedTreeAttestation.RequireExact(layout.WebRoot,
                layout.ManagedFileCount,CafeTree);
            RoutineLocalBinding.RequireCurrent(slot,layout.PackageRoot,CafeVersion);
            RoutineLocalBinding.RequireTarget(persistent,slot,layout.PackageRoot,CafeVersion);
            return result;
        }
    }

    // Dedicated fixture harness passes a synthetic digest. Production NEVER
    // gets this parameter from the browser, operator, or a release manifest.
    internal static string RestoreExactMissingPair(string source,string active,
        string expectedSha256)
    {
        if (expectedSha256.Length != 64 ||
            !expectedSha256.All(static c => Uri.IsHexDigit(c)))
            throw new InvalidDataException("CAFE_RUNNER_EXPECTED_HASH_INVALID");
        var sourceDir=Path.GetDirectoryName(source)!;
        var activeDir=Path.GetDirectoryName(active)!;
        foreach (var dir in new[]{sourceDir,activeDir})
            if (!Directory.Exists(dir) ||
                (File.GetAttributes(dir)&FileAttributes.ReparsePoint)!=0)
                throw new InvalidDataException("CAFE_RUNNER_DIRECTORY_UNQUALIFIED");
        if (Directory.Exists(source) || !File.Exists(source) ||
            (File.GetAttributes(source)&FileAttributes.ReparsePoint)!=0)
            throw new InvalidDataException("CAFE_RUNNER_PERSISTENT_SOURCE_UNQUALIFIED");
        var length=new FileInfo(source).Length;
        if (length is < 32 or > MaxBytes || !EqualHash(source,expectedSha256))
            throw new InvalidDataException("CAFE_RUNNER_PERSISTENT_HASH_UNQUALIFIED");
        if (Directory.Exists(active))
            throw new InvalidDataException("CAFE_RUNNER_ACTIVE_PATH_UNQUALIFIED");
        if (File.Exists(active))
        {
            if ((File.GetAttributes(active)&FileAttributes.ReparsePoint)!=0 ||
                !EqualHash(active,expectedSha256))
                throw new InvalidDataException("CAFE_RUNNER_ACTIVE_FOREIGN_CONTENT");
            return "already_verified_no_mutation";
        }

        // The only permitted mutation is a verified new non-managed file. The
        // stable source itself is NOT changed. The WriteThrough temp is checked
        // and then renamed without overwrite; any interrupted attempt can
        // safely resume and use the idempotent readback branch above.
        var bytes=File.ReadAllBytes(source);
        if (!string.Equals(Hash(bytes),expectedSha256,
                StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("CAFE_RUNNER_SOURCE_CHANGED");
        var incoming=active+".incoming."+Guid.NewGuid().ToString("N");
        try
        {
            using(var writer=new FileStream(incoming,FileMode.CreateNew,
                FileAccess.Write,FileShare.None,4096,FileOptions.WriteThrough))
            {
                writer.Write(bytes);writer.Flush(true);
            }
            if (!EqualHash(incoming,expectedSha256) ||
                !EqualHash(source,expectedSha256))
                throw new InvalidDataException("CAFE_RUNNER_INCOMING_READBACK_FAILED");
            File.Move(incoming,active,overwrite:false);
            if (!EqualHash(active,expectedSha256))
                throw new InvalidDataException("CAFE_RUNNER_ACTIVE_READBACK_FAILED");
            return "restored_known_cafe_missing_active_runner";
        }
        finally
        {
            if (File.Exists(incoming)) File.Delete(incoming);
        }
    }

    private static bool EqualHash(string path,string expected)
    {
        using var f=File.OpenRead(path);
        var actual=Convert.ToHexString(SHA256.HashData(f)).ToLowerInvariant();
        return string.Equals(actual,expected,StringComparison.OrdinalIgnoreCase);
    }
    private static string Hash(byte[] bytes)=>
        Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
}
