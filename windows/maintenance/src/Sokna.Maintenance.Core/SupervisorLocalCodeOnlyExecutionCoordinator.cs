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

    public SupervisorLocalCodeOnlyExecutionCoordinator(
        MaintenancePaths paths, MaintenanceOperationStore operations,
        SupervisorLocalCodeOnlyPreExecution proof, DurableJournal journal,
        SupervisorState state, string engineExecutable)
    {
        _paths = paths; _operations = operations; _proof = proof;
        _journal = journal; _state = state;
        _engineExecutable = Path.GetFullPath(engineExecutable);
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
                activation.State != "recovery_required" ||
                activation.OperatorId != operatorId)
                throw new InvalidDataException("CODE_ONLY_RECOVERY_NOT_AUTHORIZED");
            // No simultaneous foreign updater operation, and no blind retry.
            if (_operations.ListBlocking().Any(o =>
                o.OperationId != activationOperationId))
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
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
