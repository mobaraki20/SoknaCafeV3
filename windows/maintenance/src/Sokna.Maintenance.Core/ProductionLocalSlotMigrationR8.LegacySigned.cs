using System.Security.Cryptography;

namespace Sokna.Maintenance.Core;

public sealed partial class ProductionLocalSlotMigrationBridge
{
    /// <summary>
    /// FIRST physical-Web -> sibling-release transition for an exact qualified
    /// historical Local installation. This method is INTERNAL: the Supervisor
    /// must first prove the signed release-set Stage, the installed identity,
    /// private candidate provenance, operator, idempotency and mutation lease.
    /// It is not exposed as an unrestricted CLI, PHP fallback, or panel action.
    /// </summary>
    public ProductionLocalSlotMigrationResult MigrateSignedLegacy(
        string currentBuild, string targetBuild, string verifiedCandidateRoot,
        int targetManagedFiles, string targetTreeSha256, string signedReleaseSetId)
    {
        if (string.IsNullOrWhiteSpace(signedReleaseSetId) ||
            signedReleaseSetId.Length > 180 ||
            signedReleaseSetId.Any(char.IsControl))
            throw new InvalidDataException("LEGACY_SIGNED_RELEASE_ID_INVALID");
        var layout = _discovery.InspectLegacyPhysicalCurrent();
        var prior = LegacyLocalBaselineAttestation.RequireExactPhysicalWeb(layout.WebRoot);
        if (!string.Equals(prior.Build, currentBuild, StringComparison.Ordinal))
            throw new InvalidDataException("LEGACY_SIGNED_BASELINE_MISMATCH");
        if (string.IsNullOrWhiteSpace(targetBuild) || targetBuild.Length > 180 ||
            targetBuild.Any(char.IsControl) ||
            string.Equals(currentBuild, targetBuild, StringComparison.Ordinal))
            throw new InvalidDataException("LEGACY_CROSS_VERSION_TARGET_INVALID");
        ValidateManagedCount(targetManagedFiles);
        targetTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(targetTreeSha256);

        // The signed caller may only pass Maintenance's independently
        // hash-checked PRIVATE candidate. Reject caller-owned arbitrary paths.
        var candidateRoot = Path.GetFullPath(verifiedCandidateRoot);
        var ownedRoot = Path.GetFullPath(_paths.InactiveLocalCandidatesRoot)
            .TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        if (!candidateRoot.StartsWith(ownedRoot, StringComparison.OrdinalIgnoreCase) ||
            !Directory.Exists(candidateRoot) ||
            (File.GetAttributes(candidateRoot) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("LEGACY_TARGET_NOT_MAINTENANCE_OWNED");
        var versionPath = Path.Combine(candidateRoot, "VERSION.txt");
        if (!File.Exists(versionPath) || File.ReadAllText(versionPath).Trim() != targetBuild)
            throw new InvalidDataException("LEGACY_SIGNED_TARGET_BUILD_MISMATCH");
        LocalManagedTreeAttestation.RequireExact(candidateRoot,
            targetManagedFiles, targetTreeSha256);

        if (File.Exists(StatePath))
            throw new InvalidOperationException("LEGACY_MIGRATION_STATE_RECONCILIATION_REQUIRED");
        RequireHttpHealth(currentBuild, layout.HealthUrl);
        RoutineLocalBinding.RequireCurrent(layout.WebRoot, layout.PackageRoot, currentBuild);
        RoutineLocalLifecycle.RequireUnchangedMigrationCatalog(layout.WebRoot, candidateRoot);

        var persistentRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        var preSlotRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "pre-slot");
        var targetSlot = BuildProductionSlotPath(layout.PackageRoot, targetBuild, targetTreeSha256);
        if (Directory.Exists(targetSlot))
            throw new InvalidOperationException("LEGACY_TARGET_SLOT_ALREADY_EXISTS");
        Directory.CreateDirectory(persistentRoot);
        Directory.CreateDirectory(preSlotRoot);
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);

        // Fail closed if Local lifecycle is pending, if an installed-release
        // identity has not been bound, or if previous rollback is unresolved.
        var lifecycle = RoutineLocalLifecycle.Open(layout.PackageRoot,
            _paths.LocalSlotStateRoot, currentBuild, _paths,
            signedReleaseSetId, targetTreeSha256);
        var inventory = ReadManagedFiles(candidateRoot, targetManagedFiles);
        CaptureMachineLocalFiles(layout.WebRoot, persistentRoot);
        StageProductionSlot(candidateRoot, targetSlot, inventory, persistentRoot, preSlotRoot);
        RoutineLocalBinding.PrepareTarget(
            persistentRoot, targetSlot, layout.PackageRoot, currentBuild, targetBuild);
        VerifyStagedSlot(targetSlot, persistentRoot, targetBuild,
            targetManagedFiles, targetTreeSha256);
        RoutineLocalLifecycle.RequireTargetSetupBoot(layout.PackageRoot, targetSlot);
        RoutineLocalLifecycle.RequireUnchangedMigrationCatalog(layout.WebRoot, targetSlot);
        RequireHttpHealth(currentBuild, layout.HealthUrl);

        var history = new List<ProductionLocalSlotMigrationEvent>();
        var state = NewState(layout, currentBuild, prior.ManagedFiles,
            prior.ManagedTreeSha256, layout.PackageRoot, targetSlot,
            null, null, history);
        state = Event(state, history, "SIGNED_TARGET_STAGED_VERIFIED", targetTreeSha256);
        var backup = Path.Combine(preSlotRoot, "physical-web-" +
            DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssfffZ"));
        state = state with { PhysicalWebBackup = backup };
        state = Event(state, history, "READY_TO_ACTIVATE", backup);

        // The crash-recovery proof must exist on durable storage BEFORE
        // issuing any command that might stop/switch Apache.
        lifecycle.SaveRollbackEvidence();
        state = Event(state, history, "APACHE_STOP_INTENT", layout.ApacheExecutable);
        try
        {
            StopApacheAndProveExit(layout.ApacheExecutable);
        }
        catch
        {
            try
            {
                StartApache();
                RequireHttpHealth(currentBuild, layout.HealthUrl);
                lifecycle.Complete();
                state = Event(state, history, "STOP_FAILED_BASELINE_HEALTHY", layout.WebRoot);
            }
            catch
            {
                state = state with { RecoveryRequired = true };
                state = Event(state, history, "RECOVERY_REQUIRED", "APACHE_STOP_OUTCOME_UNPROVEN");
            }
            throw;
        }
        state = Event(state, history, "APACHE_STOPPED");
        try
        {
            Directory.Move(layout.WebRoot, backup);
            state = Event(state, history, "PHYSICAL_WEB_BACKED_UP", backup);
            CreateJunction(layout.WebRoot, targetSlot);
            state = state with { ActivationPerformed = true, ActiveSlot = targetSlot, PreviousSlot = backup };
            state = Event(state, history, "POINTER_SWITCHED", targetSlot);
            StartApache();
            state = Event(state, history, "APACHE_STARTED");
            RequireHttpHealth(targetBuild, layout.HealthUrl);
            var healthy = _discovery.DiscoverAndVerify(targetBuild, targetManagedFiles);
            RequireProductionSlotPath(healthy.ActiveSlotTarget, layout.PackageRoot);
            if (!PathsEqual(healthy.ActiveSlotTarget!, targetSlot))
                throw new InvalidDataException("LEGACY_ACTIVATED_SLOT_NOT_SIGNED_TARGET");
            var tree = LocalManagedTreeAttestation.RequireExact(
                healthy.WebRoot, targetManagedFiles, targetTreeSha256);
            VerifyMachineLocalOverlay(healthy.WebRoot, persistentRoot);
            state = Event(state, history, "LOCAL_IDENTITY_COMMIT_INTENT", targetBuild);
            lifecycle.Commit(targetBuild, targetSlot, targetManagedFiles);
            state = Event(state, history, "LOCAL_IDENTITY_COMMITTED", targetBuild);
            state = state with { RecoveryRequired = false };
            state = Event(state, history, "COMMITTED", tree);
            lifecycle.Complete();
            return Result(state, tree);
        }
        catch (Exception error)
        {
            state = Event(state, history, "PRE_DB_ROLLBACK_INTENT", error.GetType().Name);
            try
            {
                // Restore the old PHYSICAL Web and the exact old Local +
                // Maintenance identity. Keep target and physical backup
                // evidence until the old HTTP health is independently proven.
                lifecycle.Restore();
                RestorePhysicalWebAndRequireHealth(layout, backup, currentBuild,
                    prior.ManagedFiles, prior.ManagedTreeSha256);
                state = state with { RecoveryRequired = false, ActivationPerformed = true,
                    ActiveSlot = null, PreviousSlot = targetSlot };
                state = Event(state, history, "ROLLED_BACK", error.Message);
                lifecycle.Complete();
            }
            catch (Exception rollbackError)
            {
                state = state with { RecoveryRequired = true, ActivationPerformed = true };
                state = Event(state, history, "RECOVERY_REQUIRED", rollbackError.Message);
                throw new AggregateException(
                    "LEGACY_CUTOVER_AND_PHYSICAL_ROLLBACK_NOT_PROVEN",
                    error, rollbackError);
            }
            throw;
        }
    }
    /// <summary>
    /// Recovery Console only, after exclusive supervisor lease and one-use
    /// Engine delegation. Never called from normal local-web HMAC credentials.
    /// Restores ONLY the old physical Web and byte-exact lifecycle identity.
    /// </summary>
    public ProductionLocalSlotMigrationResult RecoverSignedLegacy()
    {
        if (!File.Exists(StatePath))
            throw new InvalidDataException("LEGACY_RECOVERY_STATE_MISSING");
        var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(StatePath);
        if (state.Format != "sokna-local-slot-migration-state-v1" ||
            state.SchemaVersion != 1 || state.DatabaseMutationPerformed ||
            state.PublicMutationPerformed || state.WindowsRebootRequired ||
            state.Phase is "COMMITTED" or "ROLLED_BACK" or "RECOVERED_TO_PHYSICAL_WEB")
            throw new InvalidDataException("LEGACY_RECOVERY_NOT_INTERRUPTED");
        if (string.IsNullOrWhiteSpace(state.PhysicalWebBackup))
            throw new InvalidDataException("LEGACY_PHYSICAL_BACKUP_UNAVAILABLE");
        var package = Path.GetFullPath(state.PackageRoot);
        var stable = Path.GetFullPath(state.StableWebRoot);
        var backup = Path.GetFullPath(state.PhysicalWebBackup);
        var expectedStable = Path.Combine(package, "Web");
        var expectedPrefix = Path.Combine(package, "LocalWeb", "pre-slot")
            + Path.DirectorySeparatorChar;
        if (!PathsEqual(stable, expectedStable) ||
            !backup.StartsWith(expectedPrefix, StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(backup).StartsWith("physical-web-", StringComparison.Ordinal))
            throw new InvalidDataException("LEGACY_RECOVERY_PATH_UNQUALIFIED");

        var pinnedBuild = state.ExpectedBuild;
        // Validate COMPLETE crash proof before touching any service/pointer.
        RoutineLocalLifecycle.RequireCrashRollbackEvidence(package,
            _paths.LocalSlotStateRoot, pinnedBuild, _paths.InstalledReleaseIdentityPath);
        var history = state.Events.ToList();
        state = Event(state, history, "RECOVERY_INTENT", "restore physical Legacy Web");
        try
        {
            StopApacheAndProveExit(state.ApacheExecutable);
            if (Directory.Exists(stable))
            {
                if ((File.GetAttributes(stable) & FileAttributes.ReparsePoint) != 0)
                    RemoveJunction(stable);
                else if (Directory.Exists(backup))
                    throw new InvalidDataException(
                        "LEGACY_RECOVERY_PHYSICAL_WEB_AND_BACKUP_BOTH_PRESENT");
            }
            if (!Directory.Exists(stable))
            {
                if (!Directory.Exists(backup))
                    throw new DirectoryNotFoundException("LEGACY_RECOVERY_BACKUP_MISSING");
                Directory.Move(backup, stable);
            }
            RoutineLocalLifecycle.RestoreCrashBackup(package,
                _paths.LocalSlotStateRoot, pinnedBuild, _paths.InstalledReleaseIdentityPath);
            StartApache();
            RequireHttpHealth(pinnedBuild, state.HealthUrl);
            var old = _discovery.InspectLegacyPhysicalCurrent();
            var tree = LegacyLocalBaselineAttestation.RequireExactPhysicalWeb(old.WebRoot);
            if (old.Build != pinnedBuild ||
                tree.ManagedFiles != state.ExpectedManagedFiles ||
                tree.ManagedTreeSha256 != state.ExpectedManagedTreeSha256)
                throw new InvalidDataException("LEGACY_RECOVERY_BASELINE_DRIFT");
            state = state with { ActiveSlot = null, PreviousSlot = state.TargetSlot,
                RecoveryRequired = false };
            state = Event(state, history, "RECOVERED_TO_PHYSICAL_WEB", tree.ManagedTreeSha256);
            RoutineLocalLifecycle.CompleteCrashBackup(_paths.LocalSlotStateRoot);
            return Result(state, tree.ManagedTreeSha256);
        }
        catch (Exception ex)
        {
            state = state with { RecoveryRequired = true };
            Event(state, history, "RECOVERY_REQUIRED", ex.GetType().Name);
            throw;
        }
    }

}
