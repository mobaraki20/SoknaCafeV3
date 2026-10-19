using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record RoutineLocalSlotEvent(
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("at_utc")] DateTimeOffset AtUtc,
    [property: JsonPropertyName("detail")] string? Detail = null);

public sealed record RoutineLocalSlotState(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("package_root")] string PackageRoot,
    [property: JsonPropertyName("stable_web_root")] string StableWebRoot,
    [property: JsonPropertyName("apache_executable")] string ApacheExecutable,
    [property: JsonPropertyName("health_url")] string HealthUrl,
    [property: JsonPropertyName("previous_slot")] string PreviousSlot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("current_build")] string CurrentBuild,
    [property: JsonPropertyName("current_managed_files")] int CurrentManagedFiles,
    [property: JsonPropertyName("current_managed_tree_sha256")] string CurrentManagedTreeSha256,
    [property: JsonPropertyName("target_build")] string TargetBuild,
    [property: JsonPropertyName("target_managed_files")] int TargetManagedFiles,
    [property: JsonPropertyName("target_managed_tree_sha256")] string TargetManagedTreeSha256,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc,
    [property: JsonPropertyName("events")] IReadOnlyList<RoutineLocalSlotEvent> Events,
    [property: JsonPropertyName("maintenance_identity_commit_expected")] bool MaintenanceIdentityCommitExpected = false);

public sealed record RoutineLocalSlotResult(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("active_slot")] string ActiveSlot,
    [property: JsonPropertyName("previous_slot")] string PreviousSlot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("active_build")] string ActiveBuild,
    [property: JsonPropertyName("managed_tree_sha256")] string ManagedTreeSha256,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("state_path")] string StatePath);

public sealed partial class ProductionLocalSlotMigrationBridge
{
    public string RoutineStatePath => Path.Combine(_paths.LocalSlotStateRoot, "routine-state.json");

    public string StageRoutineSiblingRelease(
        string sourceRoot,
        string currentBuild,
        int currentManagedFiles,
        string currentManagedTreeSha256,
        string targetBuild,
        int targetManagedFiles,
        string targetManagedTreeSha256,
        string? slotSuffix = null)
    {
        currentBuild = NormalizeBuild(currentBuild);
        targetBuild = NormalizeBuild(targetBuild);
        ValidateManagedCount(currentManagedFiles);
        ValidateManagedCount(targetManagedFiles);
        currentManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(currentManagedTreeSha256);
        targetManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(targetManagedTreeSha256);

        var layout = _discovery.DiscoverAndVerify(currentBuild, currentManagedFiles);
        if (!layout.WebIsReparsePoint) throw new InvalidDataException("Routine Local staging requires the R8 stable Web junction layout.");
        RequireProductionSlotPath(layout.ActiveSlotTarget, layout.PackageRoot);
        LocalManagedTreeAttestation.RequireExact(layout.WebRoot, currentManagedFiles, currentManagedTreeSha256);
        RequireHttpHealth(currentBuild, layout.HealthUrl);

        sourceRoot = Path.GetFullPath(sourceRoot);
        if (!Directory.Exists(sourceRoot)) throw new DirectoryNotFoundException("Routine Local source root does not exist.");
        var versionPath = Path.Combine(sourceRoot, "VERSION.txt");
        if (!File.Exists(versionPath) || !string.Equals(File.ReadAllText(versionPath).Trim(), targetBuild, StringComparison.Ordinal))
            throw new InvalidDataException("Routine Local source VERSION.txt does not match the target build.");
        LocalManagedTreeAttestation.RequireExact(sourceRoot, targetManagedFiles, targetManagedTreeSha256);
        var managed = ReadManagedFiles(sourceRoot, targetManagedFiles);

        var persistentRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        var archiveRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "pre-slot");
        Directory.CreateDirectory(persistentRoot);
        Directory.CreateDirectory(archiveRoot);
        RoutineLocalBinding.RequireCurrent(layout.WebRoot, layout.PackageRoot, currentBuild);
        CaptureMachineLocalFiles(layout.WebRoot, persistentRoot);

        var targetSlot = BuildProductionSlotPath(layout.PackageRoot, targetBuild, targetManagedTreeSha256);
        if (!string.IsNullOrWhiteSpace(slotSuffix))
        {
            var safeSuffix = new string(slotSuffix.Trim().ToLowerInvariant().Where(static c => char.IsLetterOrDigit(c) || c is '-' or '_' or '.').ToArray());
            if (safeSuffix.Length is < 1 or > 40) throw new InvalidDataException("Routine Local slot suffix is invalid.");
            targetSlot += "." + safeSuffix;
        }
        if (PathsEqual(targetSlot, layout.ActiveSlotTarget!))
            throw new InvalidDataException("Routine Local staging target must be inactive.");

        StageProductionSlot(sourceRoot, targetSlot, managed, persistentRoot, archiveRoot);
        RoutineLocalBinding.PrepareTarget(persistentRoot, targetSlot, layout.PackageRoot, currentBuild, targetBuild);
        VerifyStagedSlot(targetSlot, persistentRoot, targetBuild, targetManagedFiles, targetManagedTreeSha256);
        RoutineLocalLifecycle.RequireTargetSetupBoot(layout.PackageRoot, targetSlot);
        RoutineLocalLifecycle.RequireUnchangedMigrationCatalog(layout.WebRoot, targetSlot);
        RequireProductionSlotPath(targetSlot, layout.PackageRoot);
        return targetSlot;
    }

    public RoutineLocalSlotResult ActivateRoutineSiblingRelease(
        string targetSlot,
        string currentBuild,
        int currentManagedFiles,
        string currentManagedTreeSha256,
        string targetBuild,
        int targetManagedFiles,
        string targetManagedTreeSha256,
        string? signedReleaseSetId = null)
    {
        currentBuild = NormalizeBuild(currentBuild);
        targetBuild = NormalizeBuild(targetBuild);
        ValidateManagedCount(currentManagedFiles);
        ValidateManagedCount(targetManagedFiles);
        currentManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(currentManagedTreeSha256);
        targetManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(targetManagedTreeSha256);
        targetSlot = Path.GetFullPath(targetSlot);

        var layout = _discovery.DiscoverAndVerify(currentBuild, currentManagedFiles);
        if (!layout.WebIsReparsePoint) throw new InvalidDataException("Routine Local activation requires the R8 stable Web junction layout.");
        RequireProductionSlotPath(layout.ActiveSlotTarget, layout.PackageRoot);
        var previousSlot = Path.GetFullPath(layout.ActiveSlotTarget!);
        LocalManagedTreeAttestation.RequireExact(layout.WebRoot, currentManagedFiles, currentManagedTreeSha256);
        RequireHttpHealth(currentBuild, layout.HealthUrl);
        RequireProductionSlotPath(targetSlot, layout.PackageRoot);
        // Never overwrite an interrupted or mismatched durable activation.
        // This is the actual routine Engine path, not a UI-only guard.
        RoutineLocalSlotSafety.RequirePreviousSettled(_paths, layout.PackageRoot,
            layout.WebRoot, previousSlot, currentBuild);

        var persistentRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        var lifecycle = RoutineLocalLifecycle.Open(layout.PackageRoot, _paths.LocalSlotStateRoot,
            currentBuild, signedReleaseSetId is null ? null : _paths,
            signedReleaseSetId, targetManagedTreeSha256);
        RoutineLocalBinding.RequireCurrent(layout.WebRoot, layout.PackageRoot, currentBuild);
        CaptureMachineLocalFiles(layout.WebRoot, persistentRoot);
        RefreshRoutineMachineLocalOverlay(targetSlot, persistentRoot);
        RoutineLocalBinding.PrepareTarget(persistentRoot, targetSlot, layout.PackageRoot, currentBuild, targetBuild);
        VerifyStagedSlot(targetSlot, persistentRoot, targetBuild, targetManagedFiles, targetManagedTreeSha256);
        RoutineLocalLifecycle.RequireTargetSetupBoot(layout.PackageRoot, targetSlot);
        RoutineLocalLifecycle.RequireUnchangedMigrationCatalog(layout.WebRoot, targetSlot);

        var events = new List<RoutineLocalSlotEvent>();
        var state = new RoutineLocalSlotState(
            "sokna-local-slot-routine-state-v1", 1, "READY_TO_ACTIVATE",
            layout.PackageRoot, layout.WebRoot, layout.ApacheExecutable, layout.HealthUrl,
            previousSlot, targetSlot,
            currentBuild, currentManagedFiles, currentManagedTreeSha256,
            targetBuild, targetManagedFiles, targetManagedTreeSha256,
            false, false, false, false, false, DateTimeOffset.UtcNow, events,
            MaintenanceIdentityCommitExpected: signedReleaseSetId is not null);
        state = RoutineEvent(state, events, "READY_TO_ACTIVATE", $"{previousSlot} -> {targetSlot}");

        if (PathsEqual(previousSlot, targetSlot))
        {
            RequireHttpHealth(targetBuild, layout.HealthUrl);
            state = RoutineEvent(state, events, "ACTIVATION_NOOP", targetSlot);
            state = RoutineEvent(state, events, "COMMITTED", targetManagedTreeSha256);
            return RoutineResult(state, targetSlot, targetBuild, targetManagedTreeSha256);
        }

        // Persist intent BEFORE touching Apache. Unknown stop outcome remains
        // recoverable and must not erase the lifecycle rollback proof.
        state = RoutineEvent(state, events, "APACHE_STOP_INTENT", layout.ApacheExecutable);
        lifecycle.SaveRollbackEvidence();
        try { StopApacheAndProveExit(layout.ApacheExecutable); }
        catch
        {
            state = state with { RecoveryRequired = true };
            state = RoutineEvent(state, events, "RECOVERY_REQUIRED", "APACHE_STOP_OUTCOME_UNPROVEN");
            throw;
        }
        state = RoutineEvent(state, events, "APACHE_STOPPED", targetSlot);
        try
        {
            state = RoutineEvent(state, events, "POINTER_SWITCH_INTENT", targetSlot);
            RemoveJunction(layout.WebRoot);
            CreateJunction(layout.WebRoot, targetSlot);
            state = state with { ActivationPerformed = true };
            state = RoutineEvent(state, events, "POINTER_SWITCHED", targetSlot);
            StartApache();
            state = RoutineEvent(state, events, "APACHE_STARTED", targetSlot);
            RequireHttpHealth(targetBuild, layout.HealthUrl);
            var finalLayout = _discovery.DiscoverAndVerify(targetBuild, targetManagedFiles);
            RequireProductionSlotPath(finalLayout.ActiveSlotTarget, layout.PackageRoot);
            if (!PathsEqual(finalLayout.ActiveSlotTarget!, targetSlot)) throw new InvalidDataException("Routine Local activation resolved to an unexpected sibling release.");
            var finalTree = LocalManagedTreeAttestation.RequireExact(finalLayout.WebRoot, targetManagedFiles, targetManagedTreeSha256);
            VerifyMachineLocalOverlay(finalLayout.WebRoot, persistentRoot);
            state = RoutineEvent(state, events, "LOCAL_LIFECYCLE_COMMIT_INTENT", targetBuild);
            lifecycle.Commit(targetBuild, targetSlot, targetManagedFiles);
            state = RoutineEvent(state, events, "LOCAL_LIFECYCLE_RECONCILED", targetBuild);
            state = state with { RecoveryRequired = false };
            state = RoutineEvent(state, events, "HTTP_HEALTHY", layout.HealthUrl);
            state = RoutineEvent(state, events, "COMMITTED", finalTree);
            lifecycle.Complete();
            return RoutineResult(state, targetSlot, targetBuild, finalTree);
        }
        catch (Exception activationError)
        {
            state = RoutineEvent(state, events, "PRE_DB_ROLLBACK_INTENT", activationError.GetType().Name);
            try
            {
                StopApacheAndProveExit(layout.ApacheExecutable);
                if (Directory.Exists(layout.WebRoot)) RemoveJunction(layout.WebRoot);
                RequireProductionSlotPath(previousSlot, layout.PackageRoot);
                CreateJunction(layout.WebRoot, previousSlot);
                lifecycle.Restore();
                StartApache();
                RequireHttpHealth(currentBuild, layout.HealthUrl);
                var restored = _discovery.DiscoverAndVerify(currentBuild, currentManagedFiles);
                if (!restored.WebIsReparsePoint || !PathsEqual(restored.ActiveSlotTarget!, previousSlot))
                    throw new InvalidDataException("Routine Local rollback did not restore the previous sibling release.");
                LocalManagedTreeAttestation.RequireExact(restored.WebRoot, currentManagedFiles, currentManagedTreeSha256);
                state = state with { RecoveryRequired = false, ActivationPerformed = true };
                state = RoutineEvent(state, events, "ROLLED_BACK", activationError.Message);
                lifecycle.Complete();
            }
            catch (Exception rollbackError)
            {
                state = state with { RecoveryRequired = true, ActivationPerformed = true };
                state = RoutineEvent(state, events, "RECOVERY_REQUIRED", rollbackError.Message);
                throw new AggregateException("Routine Local activation failed and previous sibling release could not be restored.", activationError, rollbackError);
            }
            throw;
        }
    }

    public RoutineLocalSlotResult RecoverRoutine()
    {
        if (!File.Exists(RoutineStatePath)) throw new FileNotFoundException("Routine Local activation state is unavailable.", RoutineStatePath);
        var state = AtomicFile.ReadJson<RoutineLocalSlotState>(RoutineStatePath);
        RoutineLocalSlotSafety.RequireInterruptedForRecovery(state);
        if (state.DatabaseMutationPerformed || state.PublicMutationPerformed)
            throw new InvalidDataException("Automatic routine code recovery is forbidden after database/Public mutation.");
        if (state.Phase == "COMMITTED" &&
            !string.Equals(state.CurrentBuild, state.TargetBuild, StringComparison.Ordinal))
            throw new InvalidDataException("A committed cross-version Local release requires explicit versioned rollback, not interrupted-transaction recovery.");
        if (!string.Equals(state.CurrentBuild, state.TargetBuild, StringComparison.Ordinal) &&
            state.Phase != "READY_TO_ACTIVATE" &&
            !File.Exists(Path.Combine(_paths.LocalSlotStateRoot, "routine-local-lifecycle-before.json")))
            throw new InvalidDataException("Interrupted cross-version recovery has no Local lifecycle rollback evidence.");
        RequireProductionSlotPath(state.PreviousSlot, state.PackageRoot);
        // Read-only validation BEFORE potentially stopping Apache or moving
        // the live Web junction. Missing/invalid rollback evidence is not a
        // reason to try an unproven recovery.
        var backupPath = Path.Combine(_paths.LocalSlotStateRoot,
            "routine-local-lifecycle-before.json");
        if (File.Exists(backupPath))
            RoutineLocalLifecycle.RequireCrashRollbackEvidence(state.PackageRoot,
                _paths.LocalSlotStateRoot, state.CurrentBuild,
                state.MaintenanceIdentityCommitExpected ? _paths.InstalledReleaseIdentityPath : null);
        else if (state.Phase != "READY_TO_ACTIVATE")
            throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_UNAVAILABLE");

        var events = state.Events.ToList();
        try
        {
            if (Directory.Exists(state.StableWebRoot) && (File.GetAttributes(state.StableWebRoot) & FileAttributes.ReparsePoint) != 0)
            {
                var resolved = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;
                if (!string.IsNullOrWhiteSpace(resolved) && PathsEqual(resolved, state.PreviousSlot))
                {
                    RoutineLocalLifecycle.RestoreCrashBackup(state.PackageRoot, _paths.LocalSlotStateRoot, state.CurrentBuild,
                        state.MaintenanceIdentityCommitExpected ? _paths.InstalledReleaseIdentityPath : null);
                    RequireHttpHealth(state.CurrentBuild, state.HealthUrl);
                    LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, state.CurrentManagedFiles, state.CurrentManagedTreeSha256);
                    RoutineLocalLifecycle.CompleteCrashBackup(_paths.LocalSlotStateRoot);
                    state = state with { RecoveryRequired = false };
                    state = RoutineEvent(state, events, "RECOVERED_TO_PREVIOUS_SLOT", state.PreviousSlot);
                    return RoutineResult(state, state.PreviousSlot, state.CurrentBuild, state.CurrentManagedTreeSha256);
                }
            }
        }
        catch { }

        StopApacheAndProveExit(state.ApacheExecutable);
        if (Directory.Exists(state.StableWebRoot))
        {
            if ((File.GetAttributes(state.StableWebRoot) & FileAttributes.ReparsePoint) == 0)
                throw new IOException("Routine recovery refuses to replace a physical stable Web directory.");
            RemoveJunction(state.StableWebRoot);
        }
        CreateJunction(state.StableWebRoot, state.PreviousSlot);
        RoutineLocalLifecycle.RestoreCrashBackup(state.PackageRoot, _paths.LocalSlotStateRoot, state.CurrentBuild);
        StartApache();
        RequireHttpHealth(state.CurrentBuild, state.HealthUrl);
        RoutineLocalLifecycle.CompleteCrashBackup(_paths.LocalSlotStateRoot);
        var restored = _discovery.DiscoverAndVerify(state.CurrentBuild, state.CurrentManagedFiles);
        if (!restored.WebIsReparsePoint || !PathsEqual(restored.ActiveSlotTarget!, state.PreviousSlot))
            throw new InvalidDataException("Routine recovery did not restore the previous sibling release.");
        var tree = LocalManagedTreeAttestation.RequireExact(restored.WebRoot, state.CurrentManagedFiles, state.CurrentManagedTreeSha256);
        state = state with { RecoveryRequired = false, ActivationPerformed = true };
        state = RoutineEvent(state, events, "RECOVERED_TO_PREVIOUS_SLOT", state.PreviousSlot);
        return RoutineResult(state, state.PreviousSlot, state.CurrentBuild, tree);
    }

    public RoutineLocalSlotResult CycleRoutineSameBuild(
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256)
    {
        expectedBuild = NormalizeBuild(expectedBuild);
        ValidateManagedCount(expectedManagedFiles);
        expectedManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(expectedManagedTreeSha256);
        var layout = _discovery.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
        if (!layout.WebIsReparsePoint) throw new InvalidDataException("Routine cycle requires the R8 stable Web junction layout.");
        RequireProductionSlotPath(layout.ActiveSlotTarget, layout.PackageRoot);
        var original = Path.GetFullPath(layout.ActiveSlotTarget!);
        var alternate = StageRoutineSiblingRelease(layout.WebRoot, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, "cycle-b");
        _ = ActivateRoutineSiblingRelease(alternate, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
        return ActivateRoutineSiblingRelease(original, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
    }

/// <summary>
/// The routine code-only Engine has a durable state file which belongs to the
/// installation, not an individual browser request. Never start a second
/// cutover on top of an unresolved or mismatched prior one.
/// </summary>
public static class RoutineLocalSlotSafety
{
    public const string Unresolved = "LOCAL_SLOT_ROUTINE_PRIOR_OPERATION_UNRESOLVED";

    public static void RequirePreviousSettled(MaintenancePaths paths, string packageRoot,
        string stableWebRoot, string observedActiveSlot, string observedBuild)
    {
        var path = Path.Combine(paths.LocalSlotStateRoot, "routine-state.json");
        if (!File.Exists(path)) return;
        if ((File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidOperationException(Unresolved);
        RoutineLocalSlotState state;
        try { state = AtomicFile.ReadJson<RoutineLocalSlotState>(path); }
        catch (Exception ex) when (ex is InvalidDataException or IOException or
            System.Text.Json.JsonException or UnauthorizedAccessException)
        { throw new InvalidOperationException(Unresolved, ex); }
        if (state is null || state.Format != "sokna-local-slot-routine-state-v1" ||
            state.SchemaVersion != 1 || state.RecoveryRequired ||
            state.DatabaseMutationPerformed || state.PublicMutationPerformed ||
            state.WindowsRebootRequired || state.Events is null ||
            state.Events.Count == 0 || state.Events[^1].State != state.Phase ||
            !Same(state.PackageRoot, packageRoot) || !Same(state.StableWebRoot, stableWebRoot))
            throw new InvalidOperationException(Unresolved);

        var expectedSlot = state.Phase switch
        {
            "COMMITTED" or "ACTIVATION_NOOP" => state.TargetSlot,
            "ROLLED_BACK" or "RECOVERED_TO_PREVIOUS_SLOT" => state.PreviousSlot,
            _ => throw new InvalidOperationException(Unresolved)
        };
        var expectedBuild = state.Phase is "COMMITTED" or "ACTIVATION_NOOP"
            ? state.TargetBuild : state.CurrentBuild;
        if (!Same(expectedSlot, observedActiveSlot) ||
            !string.Equals(expectedBuild, observedBuild, StringComparison.Ordinal) ||
            !Directory.Exists(expectedSlot))
            throw new InvalidOperationException(Unresolved);
        try { ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
            expectedSlot, packageRoot); }
        catch (Exception ex) when (ex is InvalidDataException or ArgumentException)
        { throw new InvalidOperationException(Unresolved, ex); }
    }

    public static void RequireInterruptedForRecovery(RoutineLocalSlotState state)
    {
        if (state is null || state.Format != "sokna-local-slot-routine-state-v1" ||
            state.SchemaVersion != 1 || state.DatabaseMutationPerformed ||
            state.PublicMutationPerformed || state.WindowsRebootRequired ||
            state.Phase is "COMMITTED" or "ACTIVATION_NOOP" or "ROLLED_BACK" or
                "RECOVERED_TO_PREVIOUS_SLOT" ||
            state.Events is null || state.Events.Count == 0 ||
            state.Events[^1].State != state.Phase)
            throw new InvalidOperationException("LOCAL_SLOT_ROUTINE_NOT_AN_INTERRUPTED_OPERATION");
    }

    private static bool Same(string a, string b) =>
        !string.IsNullOrWhiteSpace(a) && !string.IsNullOrWhiteSpace(b) &&
        string.Equals(Path.GetFullPath(a).TrimEnd(Path.DirectorySeparatorChar),
            Path.GetFullPath(b).TrimEnd(Path.DirectorySeparatorChar),
            StringComparison.OrdinalIgnoreCase);
}

    private static void RefreshRoutineMachineLocalOverlay(string targetSlot, string persistentRoot)
    {
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

    private RoutineLocalSlotState RoutineEvent(RoutineLocalSlotState state, List<RoutineLocalSlotEvent> events, string phase, string? detail = null)
    {
        events.Add(new RoutineLocalSlotEvent(phase, DateTimeOffset.UtcNow, detail));
        state = state with { Phase = phase, UpdatedAtUtc = DateTimeOffset.UtcNow, Events = events.ToArray() };
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);
        AtomicFile.WriteJson(RoutineStatePath, state);
        return state;
    }

    private RoutineLocalSlotResult RoutineResult(RoutineLocalSlotState state, string activeSlot, string activeBuild, string tree)
        => new(
            !state.RecoveryRequired && state.Phase is "COMMITTED" or "ACTIVATION_NOOP" or "RECOVERED_TO_PREVIOUS_SLOT",
            state.Phase,
            activeSlot,
            state.PreviousSlot,
            state.TargetSlot,
            activeBuild,
            tree,
            state.ActivationPerformed,
            false,
            false,
            false,
            state.RecoveryRequired,
            RoutineStatePath);
}