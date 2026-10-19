using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalCodeOnlyActivationRequest(
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId);
public sealed record LocalCodeOnlyRecoveryRequest(
    [property: JsonPropertyName("activation_operation_id")] string ActivationOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId);
public sealed record LocalCodeOnlyExecutionOutcome(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

/// <summary>
/// Existing Maintenance Supervisor owns execution of a signed code-only Local
/// release. It NEVER receives a physical slot or file path from HTTP clients:
/// the signed Stage/physical sibling inspector resolves both. Windows Services
/// self-updates and Public updates are separate owners.
/// </summary>
public sealed class SupervisorLocalCodeOnlyExecutionCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly SupervisorLocalCodeOnlyPreExecution _proof;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;
    private readonly string _engineExecutable;
    private readonly SupervisorInactiveLocalCandidateCoordinator _legacyCandidate;
    private readonly SupervisorLocalStageRecoveryCoordinator _signedStage;

    public SupervisorLocalCodeOnlyExecutionCoordinator(
        MaintenancePaths paths, MaintenanceOperationStore operations,
        SupervisorLocalCodeOnlyPreExecution proof, DurableJournal journal,
        SupervisorState state, string engineExecutable,
        SupervisorInactiveLocalCandidateCoordinator legacyCandidate,
        SupervisorLocalStageRecoveryCoordinator signedStage)
    {
        _paths = paths; _operations = operations; _proof = proof;
        _journal = journal; _state = state;
        _engineExecutable = Path.GetFullPath(engineExecutable);
        _legacyCandidate=legacyCandidate; _signedStage=signedStage;
    }

    public LocalCodeOnlyExecutionOutcome Activate(
        string stageId, string operatorId, string idempotencyKey)
    {
        RequireRequest(stageId, operatorId, idempotencyKey);
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, EngineDelegationPermit.Owner);
            const string operationType = "update.local-code-only-activate";
            var requestHash = MaintenanceOperationStore.CanonicalRequestHash(
                operationType, stageId, operatorId);
            var prior = _operations.GetByIdempotencyKey(idempotencyKey);
            if (prior is not null)
            {
                RequireSameRequest(prior, operationType, stageId, operatorId, requestHash);
                // An interrupted activation is NEVER retried by repeating POST.
                return new(prior, false, true);
            }
            if (_operations.ListBlocking().Count != 0)
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            var stage = _operations.Get(stageId)
                ?? throw new InvalidDataException("CODE_ONLY_SIGNED_STAGE_NOT_FOUND");
            if (stage.OperationType != "update.local-stage" ||
                stage.State != "staged" || stage.OperatorId != operatorId)
                throw new InvalidDataException("CODE_ONLY_STAGE_OPERATOR_MISMATCH");

            // A legacy physical Web is handled by the FIRST-SLOT branch of the
            // SAME signed Supervisor activation route, never by a Go installer
            // or a PHP active-Web overwrite.
            try
            {
                var physical = new LocalSlotManager(_paths).InspectLegacyPhysicalCurrent();
                return ActivateVerifiedLegacyPhysical(stageId, operatorId, idempotencyKey,
                    operationType, requestHash, physical, lease);
            }
            catch (InvalidDataException ex) when (
                ex.Message is "LEGACY_WEB_ALREADY_JUNCTION" or
                "LEGACY_PHYSICAL_WEB_REQUIRED")
            {
                // Existing R8 installations use the previous production path.
            }

            // Repeat full signature, manifest target and physical slot proof
            // UNDER the live machine mutation lease immediately before Engine.
            var verified = _proof.Inspect(stageId);
            if (!verified.SignedStageVerified || !verified.PhysicalSiblingVerified ||
                !verified.UnchangedMigrationsVerified || verified.ExecutionAuthorized ||
                verified.PanelUpdateReady)
                throw new InvalidDataException("CODE_ONLY_PREEXECUTION_PROOF_INVALID");
            var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                _paths.InstalledReleaseIdentityPath);
            if (identity.Local is null ||
                identity.Local.Version == verified.TargetVersion)
                throw new InvalidDataException("CODE_ONLY_INSTALLED_BASELINE_INVALID");
            var currentRoot = new LocalSlotManager(_paths).InspectHealthyCurrent();
            if (identity.Local.Version != currentRoot.Build ||
                !string.Equals(currentRoot.ActiveSlotTarget, verified.PreviousSlot,
                    StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("CODE_ONLY_ACTIVE_SLOT_DRIFT");
            var inventoryPath = Path.Combine(currentRoot.WebRoot,
                "resources", "local-managed-files-v1.json");
            using var inventory = JsonDocument.Parse(File.ReadAllBytes(inventoryPath));
            var currentCount = inventory.RootElement.GetProperty("files").GetArrayLength();
            LocalManagedTreeAttestation.RequireExact(currentRoot.WebRoot,
                currentCount, identity.Local.ManagedTreeSha256);
            if (!File.Exists(_engineExecutable))
                throw new InvalidDataException("CODE_ONLY_UPDATE_ENGINE_UNAVAILABLE");

            var (operation, created) = _operations.GetOrCreate(idempotencyKey,
                requestHash, operationType, stageId, operatorId);
            operation = _operations.Update(operation, "activating",
                "CODE_ONLY_ENGINE_START_INTENT",
                "Signed Local code-only cutover intent persisted before Engine execution.",
                incrementAttempt: true, activationPerformed: false,
                databaseMutationPerformed: false);
            Journal("update.local_code_only.activate_intent", operation);

            var args = new[] {
                "local-slot-activate-qualified",
                "--slot", verified.TargetSlot,
                "--current-build", identity.Local.Version,
                "--current-managed-files", currentCount.ToString(CultureInfo.InvariantCulture),
                "--current-managed-tree-sha256", identity.Local.ManagedTreeSha256,
                "--target-build", verified.TargetVersion,
                "--target-managed-files", verified.TargetManagedFiles.ToString(CultureInfo.InvariantCulture),
                "--target-managed-tree-sha256", verified.TargetTreeSha256,
                "--release-set-id", verified.ReleaseSetId,
                "--data-root", _paths.DataRoot
            };
            try
            {
                var permit = EngineDelegationPermit.Issue(_paths, lease,
                    operation.OperationId, args);
                using var child = WindowsJobChildProcess.Start(_engineExecutable,
                    args.Concat(new[] {"--delegation-id", permit}).ToArray(),
                    AppContext.BaseDirectory);
                var result = child.WaitAndCapture(240_000);
                if (!result.Finished || result.ExitCode != 0)
                    throw new InvalidOperationException(
                        result.Finished ? "CODE_ONLY_ENGINE_FAILED" :
                        "CODE_ONLY_ENGINE_TIMEOUT_RECOVERY_REQUIRED");

                using var envelope = JsonDocument.Parse(result.Stdout);
                var root = envelope.RootElement;
                if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() ||
                    !root.TryGetProperty("phase", out var phase) ||
                    phase.GetString() != "COMMITTED" ||
                    !root.TryGetProperty("database_mutation_performed", out var db) ||
                    db.GetBoolean() ||
                    !root.TryGetProperty("public_mutation_performed", out var pub) ||
                    pub.GetBoolean())
                    throw new InvalidDataException("CODE_ONLY_ENGINE_SUCCESS_ENVELOPE_INVALID");

                // Engine must atomically update Local lifecycle AND Maintenance's
                // installed-release identity. The caller re-reads the REAL host;
                // an Engine stdout success flag alone cannot commit the operation.
                var observed = new LocalSlotManager(_paths).InspectHealthyCurrent();
                var installed = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                    _paths.InstalledReleaseIdentityPath);
                if (!string.Equals(observed.ActiveSlotTarget, verified.TargetSlot,
                        StringComparison.OrdinalIgnoreCase) ||
                    observed.Build != verified.TargetVersion ||
                    installed.Local is null ||
                    installed.Local.Version != verified.TargetVersion ||
                    installed.Local.ReleaseId != verified.ReleaseSetId ||
                    !string.Equals(installed.Local.ManagedTreeSha256, verified.TargetTreeSha256,
                        StringComparison.OrdinalIgnoreCase))
                    throw new InvalidDataException("CODE_ONLY_INSTALLED_READBACK_UNPROVEN");
                LocalManagedTreeAttestation.RequireExact(observed.WebRoot,
                    verified.TargetManagedFiles, verified.TargetTreeSha256);

                operation = _operations.Update(operation, "committed",
                    "CODE_ONLY_INSTALLED_READBACK_VERIFIED",
                    "Local version and Maintenance installed identity agree; HTTP and managed tree verified.",
                    activationPerformed: true, databaseMutationPerformed: false);
                Journal("update.local_code_only.committed", operation);
                return new(operation, created, false);
            }
            catch (Exception ex) when (ex is IOException or InvalidDataException or
                InvalidOperationException or JsonException or
                System.ComponentModel.Win32Exception or UnauthorizedAccessException)
            {
                // A routine may have ALREADY performed a controlled rollback
                // after an injected health failure. Read the terminal durable
                // state AND the actual old Apache/Local/Maintenance identity;
                // do not strand a cleanly recovered cafe in recovery_required.
                try
                {
                    var routinePath = Path.Combine(_paths.LocalSlotStateRoot,
                        "routine-state.json");
                    if (File.Exists(routinePath))
                    {
                        var routine = AtomicFile.ReadJson<RoutineLocalSlotState>(routinePath);
                        if (routine.Phase == "ROLLED_BACK" &&
                            !routine.RecoveryRequired &&
                            routine.MaintenanceIdentityCommitExpected &&
                            routine.CurrentBuild == identity.Local.Version &&
                            string.Equals(routine.PreviousSlot, verified.PreviousSlot,
                                StringComparison.OrdinalIgnoreCase))
                        {
                            var liveOld = new LocalSlotManager(_paths).InspectHealthyCurrent();
                            var restoredIdentity = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                                _paths.InstalledReleaseIdentityPath);
                            if (string.Equals(liveOld.ActiveSlotTarget, verified.PreviousSlot,
                                    StringComparison.OrdinalIgnoreCase) &&
                                liveOld.Build == identity.Local.Version &&
                                restoredIdentity.Local is not null &&
                                restoredIdentity.Local.Version == identity.Local.Version &&
                                restoredIdentity.Local.ReleaseId == identity.Local.ReleaseId &&
                                restoredIdentity.Local.ManagedTreeSha256 == identity.Local.ManagedTreeSha256)
                            {
                                LocalManagedTreeAttestation.RequireExact(liveOld.WebRoot,
                                    currentCount, identity.Local.ManagedTreeSha256);
                                operation = _operations.Update(operation, "failed",
                                    "CODE_ONLY_ENGINE_ROLLED_BACK_VERIFIED",
                                    "Engine failed but previous Local release was safely restored.",
                                    routinePath, activationPerformed: true,
                                    databaseMutationPerformed: false);
                                Journal("update.local_code_only.safe_rollback_verified", operation);
                                return new(operation, created, false);
                            }
                        }
                    }
                }
                catch (Exception checkError) when (checkError is IOException or
                    InvalidDataException or InvalidOperationException or JsonException or
                    UnauthorizedAccessException)
                {
                    // No proof means the ambiguous installation stays blocked.
                }
                // Never claim failure was pre-mutation after Engine dispatch:
                // a timeout or crash might have already switched Web.
                operation = _operations.Update(operation, "recovery_required",
                    "CODE_ONLY_EXECUTION_OUTCOME_UNPROVEN", ex.Message,
                    Path.Combine(_paths.LocalSlotStateRoot, "routine-state.json"),
                    activationPerformed: true, databaseMutationPerformed: false);
                Journal("update.local_code_only.recovery_required", operation);
                return new(operation, created, false);
            }
        }
    }

    private LocalCodeOnlyExecutionOutcome ActivateVerifiedLegacyPhysical(
        string stageId, string operatorId, string idempotencyKey,
        string operationType, string requestHash, LocalInstallLayout physical,
        MachineMutationLease lease)
    {
        // This inspection cryptographically re-verifies the original signed
        // Local-only release, the durable Stage and installed baseline.
        var qualified = _signedStage.InspectActivationReadiness(stageId);
        if (!qualified.StageEvidenceVerified || qualified.ReleaseType != "local_only" ||
            qualified.ActivationAllowed || qualified.PanelUpdateReady)
            throw new InvalidDataException("LEGACY_SIGNED_STAGE_NOT_QUALIFIED");
        var before = AtomicFile.ReadJson<InstalledReleaseIdentity>(
            _paths.InstalledReleaseIdentityPath);
        if (before.Local is null || before.Local.Version != physical.Build)
            throw new InvalidDataException("LEGACY_INSTALLED_IDENTITY_MISMATCH");

        // A previous signed prepare-inactive POST must have already prepared
        // this exact candidate. Here we independently read and rehash it;
        // acquiring another machine lease inside Activate would deadlock.
        var payload = _legacyCandidate.InspectExisting(stageId, operatorId);
        if (payload.TargetVersion == physical.Build ||
            !payload.ActiveSlotUnchanged || payload.PromotionQualified ||
            payload.ActivationPerformed || payload.DatabaseMutationPerformed ||
            payload.PublicMutationPerformed ||
            !string.Equals(payload.ReleaseSetId, qualified.ReleaseSetId,
                StringComparison.Ordinal) ||
            !string.Equals(payload.InstalledIdentitySha256,
                Sha256File(_paths.InstalledReleaseIdentityPath),
                StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("LEGACY_CANDIDATE_NOT_SIGNED");

        LegacyLocalBaselineAttestation.RequireExactPhysicalWeb(physical.WebRoot);
        RoutineLocalBinding.RequireCurrent(physical.WebRoot, physical.PackageRoot,
            physical.Build);
        RoutineLocalLifecycle.RequireUnchangedMigrationCatalog(
            physical.WebRoot, payload.CandidateRoot);
        if (!File.Exists(_engineExecutable))
            throw new InvalidDataException("LEGACY_UPDATE_ENGINE_UNAVAILABLE");

        var (operation, created) = _operations.GetOrCreate(
            idempotencyKey, requestHash, operationType, stageId, operatorId);
        operation = _operations.Update(operation, "activating",
            "LEGACY_FIRST_SLOT_INTENT",
            "Signed Legacy->R8 cutover durable intent, before Apache mutation.",
            incrementAttempt: true, activationPerformed: false,
            databaseMutationPerformed: false);
        Journal("update.legacy_first_slot.activate_intent", operation);

        var args = new[] {
            "local-slot-activate-qualified-legacy",
            "--source", payload.CandidateRoot,
            "--current-build", physical.Build,
            "--target-build", payload.TargetVersion,
            "--target-managed-files",
                payload.TargetManagedFiles.ToString(CultureInfo.InvariantCulture),
            "--target-managed-tree-sha256", payload.TargetManagedTreeSha256,
            "--release-set-id", payload.ReleaseSetId,
            "--data-root", _paths.DataRoot
        };
        try
        {
            var permit = EngineDelegationPermit.Issue(_paths, lease,
                operation.OperationId, args);
            using var child = WindowsJobChildProcess.Start(_engineExecutable,
                args.Concat(new[] { "--delegation-id", permit }).ToArray(),
                AppContext.BaseDirectory);
            var result = child.WaitAndCapture(240_000);
            if (!result.Finished || result.ExitCode != 0)
                throw new InvalidOperationException(
                    result.Finished ? "LEGACY_ENGINE_FAILED" :
                    "LEGACY_ENGINE_TIMEOUT_RECOVERY_REQUIRED");

            using var envelope = JsonDocument.Parse(result.Stdout);
            var output = envelope.RootElement;
            if (!output.TryGetProperty("ok", out var success) ||
                !success.GetBoolean() ||
                !output.TryGetProperty("phase", out var phase) ||
                phase.GetString() != "COMMITTED" ||
                !output.TryGetProperty("database_mutation_performed", out var db) ||
                db.GetBoolean() ||
                !output.TryGetProperty("public_mutation_performed", out var pub) ||
                pub.GetBoolean())
                throw new InvalidDataException("LEGACY_ENGINE_ENVELOPE_UNPROVEN");

            // Never trust Engine stdout alone. Re-read physical OS layout,
            // current HTTP build, managed tree and installed signed identity.
            var active = new LocalSlotManager(_paths).InspectHealthyCurrent();
            var final = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                _paths.InstalledReleaseIdentityPath);
            if (!active.WebIsReparsePoint ||
                active.Build != payload.TargetVersion ||
                final.Local is null ||
                final.Local.Version != payload.TargetVersion ||
                final.Local.ReleaseId != payload.ReleaseSetId ||
                !string.Equals(final.Local.ManagedTreeSha256,
                    payload.TargetManagedTreeSha256,
                    StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("LEGACY_FINAL_INSTALLED_IDENTITY_UNPROVEN");
            LocalManagedTreeAttestation.RequireExact(active.WebRoot,
                payload.TargetManagedFiles, payload.TargetManagedTreeSha256);

            operation = _operations.Update(operation, "committed",
                "LEGACY_FIRST_SLOT_VERIFIED",
                "Signed Local target, HTTP, physical junction and installed identity verified.",
                activationPerformed: true, databaseMutationPerformed: false);
            Journal("update.legacy_first_slot.committed", operation);
            return new(operation, created, false);
        }
        catch (Exception ex) when (ex is IOException or InvalidDataException or
            InvalidOperationException or JsonException or
            System.ComponentModel.Win32Exception or UnauthorizedAccessException or
            TimeoutException)
        {
            try
            {
                var statePath = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");
                if (File.Exists(statePath))
                {
                    var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(statePath);
                    if (!state.RecoveryRequired && state.Phase is
                        ("ROLLED_BACK" or "STOP_FAILED_BASELINE_HEALTHY"))
                    {
                        var old = new LocalSlotManager(_paths).InspectLegacyPhysicalCurrent();
                        var now = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                            _paths.InstalledReleaseIdentityPath);
                        var attested = LegacyLocalBaselineAttestation
                            .RequireExactPhysicalWeb(old.WebRoot);
                        if (old.Build == physical.Build &&
                            attested.ExactBaselineVerified &&
                            now.Local is not null &&
                            now.Local.Version == before.Local.Version &&
                            now.Local.ReleaseId == before.Local.ReleaseId &&
                            now.Local.ManagedTreeSha256 == before.Local.ManagedTreeSha256)
                        {
                            operation = _operations.Update(operation, "failed",
                                "LEGACY_OLD_PHYSICAL_WEB_RESTORED",
                                "Original Legacy Web, HTTP and Local identity restored.",
                                statePath, activationPerformed: state.ActivationPerformed,
                                databaseMutationPerformed: false);
                            Journal("update.legacy_first_slot.rollback_verified", operation);
                            return new(operation, created, false);
                        }
                    }
                }
            }
            catch (Exception) { /* Unproven recovery remains blocked. */ }

            operation = _operations.Update(operation, "recovery_required",
                "LEGACY_ACTIVATION_OUTCOME_UNPROVEN", ex.Message,
                Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json"),
                activationPerformed: true, databaseMutationPerformed: false);
            Journal("update.legacy_first_slot.recovery_required", operation);
            return new(operation, created, false);
        }
    }

    private static string Sha256File(string path)
        => Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(
            File.ReadAllBytes(path))).ToLowerInvariant();

    public LocalCodeOnlyExecutionOutcome Recover(
        string activationOperationId, string operatorId, string idempotencyKey)
    {
        RequireRequest(activationOperationId, operatorId, idempotencyKey);
        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, EngineDelegationPermit.Owner);
            const string operationType = "update.local-code-only-recover";
            var requestHash = MaintenanceOperationStore.CanonicalRequestHash(
                operationType, activationOperationId, operatorId);
            var prior = _operations.GetByIdempotencyKey(idempotencyKey);
            if (prior is not null)
            {
                RequireSameRequest(prior, operationType, activationOperationId,
                    operatorId, requestHash);
                return new(prior, false, true);
            }
            var activation = _operations.Get(activationOperationId)
                ?? throw new InvalidDataException("CODE_ONLY_ACTIVATION_NOT_FOUND");
            if (activation.OperationType != "update.local-code-only-activate" ||
                activation.State is not ("recovery_required" or "activating") ||
                activation.OperatorId != operatorId)
                throw new InvalidDataException("CODE_ONLY_RECOVERY_NOT_AUTHORIZED");
            // After a hard Supervisor/Engine process kill the persistent
            // operation remains 'activating', not 'recovery_required'. The
            // exclusive machine lease plus a verified interrupted routine
            // snapshot permit RECOVERY, never another Activate attempt.
            // No simultaneous foreign updater operation, and no blind retry.
            if (_operations.ListBlocking().Any(o =>
                o.OperationId != activationOperationId))
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            if (activation.ResultCode?.StartsWith("LEGACY_", StringComparison.Ordinal) == true)
                return RecoverVerifiedLegacy(activation, activationOperationId,
                    operatorId, idempotencyKey, operationType, requestHash, lease);
            var routineStatePath = Path.Combine(_paths.LocalSlotStateRoot,
                "routine-state.json");
            if (!File.Exists(routineStatePath))
                throw new InvalidDataException("CODE_ONLY_RECOVERY_EVIDENCE_MISSING");
            var state = AtomicFile.ReadJson<RoutineLocalSlotState>(routineStatePath);
            ProductionLocalSlotMigrationBridge.RoutineLocalSlotSafety
                .RequireInterruptedForRecovery(state);
            if (!state.MaintenanceIdentityCommitExpected)
                throw new InvalidDataException("CODE_ONLY_QUALIFIED_RECOVERY_REQUIRED");
            RoutineLocalLifecycle.RequireCrashRollbackEvidence(state.PackageRoot,
                _paths.LocalSlotStateRoot, state.CurrentBuild,
                _paths.InstalledReleaseIdentityPath);
            if (!File.Exists(_engineExecutable))
                throw new InvalidDataException("CODE_ONLY_UPDATE_ENGINE_UNAVAILABLE");
            var (operation, created) = _operations.GetOrCreate(idempotencyKey,
                requestHash, operationType, activationOperationId, operatorId);
            operation = _operations.Update(operation, "recovering",
                "CODE_ONLY_RECOVERY_ENGINE_START_INTENT",
                "Operator-authorized recovery-console routine rollback with byte-verified snapshot.",
                incrementAttempt: true, databaseMutationPerformed: false);
            Journal("update.local_code_only.recover_intent", operation);
            try
            {
                var args = new[] { "local-slot-recover-routine",
                    "--data-root", _paths.DataRoot };
                var permit = EngineDelegationPermit.Issue(_paths, lease,
                    operation.OperationId, args);
                using var child = WindowsJobChildProcess.Start(_engineExecutable,
                    args.Concat(new[] {"--delegation-id", permit}).ToArray(),
                    AppContext.BaseDirectory);
                var result = child.WaitAndCapture(240_000);
                if (!result.Finished || result.ExitCode != 0)
                    throw new InvalidOperationException("CODE_ONLY_RECOVERY_EXECUTION_UNPROVEN");
                using var response = JsonDocument.Parse(result.Stdout);
                var root = response.RootElement;
                if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() ||
                    !root.TryGetProperty("phase", out var phase) ||
                    phase.GetString() != "RECOVERED_TO_PREVIOUS_SLOT")
                    throw new InvalidDataException("CODE_ONLY_RECOVERY_RESULT_UNPROVEN");
                var live = new LocalSlotManager(_paths).InspectHealthyCurrent();
                var installed = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                    _paths.InstalledReleaseIdentityPath);
                if (live.Build != state.CurrentBuild ||
                    !string.Equals(live.ActiveSlotTarget, state.PreviousSlot,
                        StringComparison.OrdinalIgnoreCase) ||
                    installed.Local is null ||
                    installed.Local.Version != state.CurrentBuild)
                    throw new InvalidDataException("CODE_ONLY_RECOVERY_READBACK_UNPROVEN");
                LocalManagedTreeAttestation.RequireExact(live.WebRoot,
                    state.CurrentManagedFiles, state.CurrentManagedTreeSha256);
                activation = _operations.Update(activation, "failed",
                    "CODE_ONLY_PREVIOUS_RELEASE_RECOVERED",
                    "Original Local release restored with verified lifecycle and installed identity.",
                    activationPerformed: true, databaseMutationPerformed: false);
                operation = _operations.Update(operation, "committed",
                    "CODE_ONLY_RECOVERY_VERIFIED",
                    "Previous Apache slot and installed Local identity verified.",
                    activationPerformed: true, databaseMutationPerformed: false);
                Journal("update.local_code_only.recovery_committed", operation);
                return new(operation, created, false);
            }
            catch (Exception ex) when (ex is IOException or InvalidDataException or
                InvalidOperationException or JsonException or
                System.ComponentModel.Win32Exception or UnauthorizedAccessException)
            {
                operation = _operations.Update(operation, "recovery_required",
                    "CODE_ONLY_RECOVERY_UNPROVEN", ex.Message,
                    routineStatePath, activationPerformed: true,
                    databaseMutationPerformed: false);
                Journal("update.local_code_only.recovery_required", operation);
                return new(operation, created, false);
            }
        }
    }

    private LocalCodeOnlyExecutionOutcome RecoverVerifiedLegacy(
        MaintenanceOperation activation, string activationId, string actor,
        string idem, string operationType, string requestHash, MachineMutationLease lease)
    {
        var path=Path.Combine(_paths.LocalSlotStateRoot,"migration-state.json");
        if(!File.Exists(path))
            throw new InvalidDataException("LEGACY_RECOVERY_STATE_MISSING");
        var state=AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(path);
        if(state.Format!="sokna-local-slot-migration-state-v1" ||
           state.DatabaseMutationPerformed || state.PublicMutationPerformed ||
           state.Phase is "COMMITTED" or "ROLLED_BACK" or "RECOVERED_TO_PHYSICAL_WEB")
            throw new InvalidDataException("LEGACY_RECOVERY_NOT_INTERRUPTED");
        RoutineLocalLifecycle.RequireCrashRollbackEvidence(state.PackageRoot,
            _paths.LocalSlotStateRoot,state.ExpectedBuild,
            _paths.InstalledReleaseIdentityPath);
        var (op,created)=_operations.GetOrCreate(idem,requestHash,
            operationType,activationId,actor);
        op=_operations.Update(op,"recovering","LEGACY_RECOVERY_INTENT",
            "Signed Recovery Console physical-Web restoration.",
            incrementAttempt:true,databaseMutationPerformed:false);
        Journal("update.legacy.recovery_intent",op);
        try
        {
            var args=new[]{"local-slot-recover-qualified-legacy","--data-root",_paths.DataRoot};
            var token=EngineDelegationPermit.Issue(_paths,lease,op.OperationId,args);
            using var child=WindowsJobChildProcess.Start(_engineExecutable,
                args.Concat(new[]{"--delegation-id",token}).ToArray(),
                AppContext.BaseDirectory);
            var execution=child.WaitAndCapture(240_000);
            if(!execution.Finished || execution.ExitCode!=0)
                throw new InvalidOperationException("LEGACY_RECOVERY_ENGINE_FAILED");
            using var json=JsonDocument.Parse(execution.Stdout);
            if(!json.RootElement.TryGetProperty("phase",out var phase) ||
               phase.GetString()!="RECOVERED_TO_PHYSICAL_WEB")
                throw new InvalidDataException("LEGACY_RECOVERY_ENVELOPE_MISMATCH");
            var live=new LocalSlotManager(_paths).InspectLegacyPhysicalCurrent();
            var proof=LegacyLocalBaselineAttestation.RequireExactPhysicalWeb(live.WebRoot);
            var identity=AtomicFile.ReadJson<InstalledReleaseIdentity>(
                _paths.InstalledReleaseIdentityPath);
            if(live.WebIsReparsePoint || proof.Build!=state.ExpectedBuild ||
               identity.Local?.Version!=state.ExpectedBuild)
                throw new InvalidDataException("LEGACY_RECOVERY_HOST_UNPROVEN");
            activation=_operations.Update(activation,"failed",
                "LEGACY_PHYSICAL_WEB_RESTORED","Verified original host restored.",
                activationPerformed:true,databaseMutationPerformed:false);
            op=_operations.Update(op,"committed","LEGACY_RECOVERY_VERIFIED",
                "Original physical Web and Local identity verified.",
                activationPerformed:true,databaseMutationPerformed:false);
            Journal("update.legacy.recovery_verified",op);
            return new(op,created,false);
        }
        catch(Exception ex) when(ex is IOException or InvalidDataException or
            InvalidOperationException or UnauthorizedAccessException or JsonException)
        {
            op=_operations.Update(op,"recovery_required",
                "LEGACY_RECOVERY_OUTCOME_UNPROVEN",ex.Message,path,
                activationPerformed:true,databaseMutationPerformed:false);
            Journal("update.legacy.recovery_unproven",op);
            return new(op,created,false);
        }
    }

    private static void RequireRequest(string id, string actor, string key)
    {
        if (id is not {Length: 32} || !id.All(char.IsAsciiHexDigit) ||
            !MaintenanceOperationStore.IsValidIdempotencyKey(key) ||
            string.IsNullOrWhiteSpace(actor) || actor.Length > 128 ||
            actor.Any(c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("CODE_ONLY_REQUEST_INVALID");
    }

    private static void RequireSameRequest(MaintenanceOperation previous,
        string type, string reference, string actor, string hash)
    {
        if (previous.OperationType != type || previous.PackageRef != reference ||
            previous.OperatorId != actor ||
            previous.CanonicalRequestSha256 != hash)
            throw new InvalidDataException("CODE_ONLY_IDEMPOTENCY_CONFLICT");
    }

    private void Journal(string type, MaintenanceOperation operation)
        => _journal.Append(type, _state.InstanceId, _state.InstallGeneration,
            new Dictionary<string, object?> {
                ["operation_id"] = operation.OperationId,
                ["state"] = operation.State,
                ["activation_performed"] = operation.ActivationPerformed,
                ["database_mutation_performed"] = operation.DatabaseMutationPerformed
            });
}
