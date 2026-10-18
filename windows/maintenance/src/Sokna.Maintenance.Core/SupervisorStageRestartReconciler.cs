namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S3 safe startup barrier: a previous process could have died after
/// writing stage bytes / anti-rollback state but before its operation commit.
/// Do NOT infer "staged" from a receipt or replay a write on Supervisor startup.
/// Keep the original evidence and require independent recovery qualification.
/// </summary>
public sealed class SupervisorStageRestartReconciler
{
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;

    public SupervisorStageRestartReconciler(
        MaintenanceOperationStore operations, DurableJournal journal, SupervisorState state)
    {
        _operations = operations;
        _journal = journal;
        _state = state;
    }

    public IReadOnlyList<MaintenanceOperation> ReconcileStoppedProcess()
    {
        lock (LocalUpdateOperationGate.Sync)
        {
            var interrupted = _operations.ListActive()
                .Where(static o => (o.OperationType is "update.local-stage" or "update.stage" or "update.local-package-preflight")
                    && (o.State is "accepted" or "running"))
                .ToArray();

            var changed = new List<MaintenanceOperation>(interrupted.Length);
            foreach (var operation in interrupted)
            {
                var isStage = operation.OperationType is "update.local-stage" or "update.stage";
                var next = _operations.Update(operation,
                    isStage ? "recovery_required" : "rejected_pre_mutation",
                    isStage ? "STAGE_INTERRUPTED_OUTCOME_UNPROVEN" : "PREFLIGHT_INTERRUPTED_RECHECK_REQUIRED",
                    isStage
                        ? "Supervisor restarted with unfinished Stage. Disk/sequence evidence may be partially written; activation forbidden pending explicit recovery."
                        : "Supervisor restarted with unfinished read-only Preflight. Re-submit with a new idempotency key.");
                _journal.Append(isStage ? "update.local_stage.startup_quarantined" : "update.local_preflight.startup_invalidated",
                    _state.InstanceId, _state.InstallGeneration,
                    new Dictionary<string, object?>
                    {
                        ["operation_id"] = next.OperationId,
                        ["state"] = next.State,
                        ["result_code"] = next.ResultCode,
                        ["activation_performed"] = false,
                        ["database_mutation_performed"] = false
                    });
                changed.Add(next);
            }
            return changed;
        }
    }

    public StageRestartStatus Inspect()
    {
        lock (LocalUpdateOperationGate.Sync)
        {
            var blocking = _operations.ListBlocking();
            var stages = blocking.Where(static o =>
                o.OperationType is "update.local-stage" or "update.stage").ToArray();
            return new StageRestartStatus(
                Format: "sokna-local-stage-recovery-status-v1",
                Blocked: blocking.Count != 0,
                BlockingOperationIds: blocking.Select(static o => o.OperationId).ToArray(),
                StageRecoveryRequiredIds: stages.Where(static o => o.State == "recovery_required")
                    .Select(static o => o.OperationId).ToArray(),
                StageStateEvidence: stages.Select(static o =>
                    new StageRecoveryStatusItem(o.OperationId, o.State, o.ResultCode, o.StageReceiptPath is not null))
                    .ToArray(),
                PanelUpdateReady: false,
                ActivationEnabled: false,
                AutomaticRestageEnabled: false);
        }
    }
}

public sealed record StageRecoveryStatusItem(
    string OperationId, string State, string? ResultCode, bool StageReceiptReferencePresent);

public sealed record StageRestartStatus(
    string Format, bool Blocked, string[] BlockingOperationIds,
    string[] StageRecoveryRequiredIds, StageRecoveryStatusItem[] StageStateEvidence,
    bool PanelUpdateReady, bool ActivationEnabled, bool AutomaticRestageEnabled);
