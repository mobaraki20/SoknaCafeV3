namespace Sokna.Maintenance.Core;

/// <summary>
/// One Supervisor-process gate for the canonical Preflight/Stage lifecycle
/// and the older Windows slot mutation owners. The durable operation ledger
/// is checked while the gate is held: a recovered/crashed Stage is never idle.
/// This does not imply panel update readiness or permit Activate.
/// </summary>
public static class LocalUpdateOperationGate
{
    public static readonly object Sync = new();

    public static void RequireNoPendingMutation(MaintenanceOperationStore operations)
    {
        ArgumentNullException.ThrowIfNull(operations);
        if (operations.ListBlocking().Count != 0)
            throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
    }

    /// <summary>
    /// The signed Stage inspector is normally read-only and requires an idle
    /// operation ledger. During Activate, however, the Supervisor has already
    /// reserved exactly one durable intent for the SAME Stage/operator.
    /// Allow that one accepted, not-yet-executed intent to inspect its own
    /// signed evidence. This is not an Engine permit, and never permits
    /// a foreign, interrupted or recovery-required operation.
    /// </summary>
    public static void RequireStageInspection(
        MaintenanceOperationStore operations, MaintenanceOperation stage,
        string? ownActivationOperationId = null)
    {
        ArgumentNullException.ThrowIfNull(operations);
        ArgumentNullException.ThrowIfNull(stage);
        if (ownActivationOperationId is null)
        {
            RequireNoPendingMutation(operations);
            return;
        }

        var storedStage = operations.Get(stage.OperationId);
        var own = operations.Get(ownActivationOperationId);
        if (storedStage is null ||
            storedStage.OperationType != "update.local-stage" ||
            storedStage.State != "staged" ||
            storedStage.OperatorId != stage.OperatorId ||
            storedStage.ActivationPerformed || storedStage.DatabaseMutationPerformed ||
            own is null ||
            own.OperationType != "update.local-code-only-activate" ||
            own.PackageRef != stage.OperationId ||
            own.OperatorId != stage.OperatorId ||
            own.State != "accepted" || own.AttemptCount != 0 ||
            own.ActivationPerformed || own.DatabaseMutationPerformed ||
            own.CanonicalRequestSha256 != MaintenanceOperationStore.CanonicalRequestHash(
                "update.local-code-only-activate", stage.OperationId, stage.OperatorId))
            throw new InvalidOperationException("UPDATER_OPERATION_BUSY");

        var blockers = operations.ListBlocking();
        if (blockers.Count != 1 || blockers[0].OperationId != own.OperationId)
            throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
    }

    /// <summary>
    /// Old slot recovery may clear its own previously quarantined migration,
    /// but must never run across ANY Stage/Preflight or other owner evidence.
    /// Holding Sync rules out a concurrent in-process slot migration.
    /// </summary>
    public static void RequireLegacySlotRecoveryOnly(MaintenanceOperationStore operations)
    {
        ArgumentNullException.ThrowIfNull(operations);
        var blockers = operations.ListBlocking();
        if (blockers.Any(static op => op.OperationType is not ("update.local-slot-migrate" or "update.local-slot-recover") ||
            op.State != "recovery_required"))
            throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
    }
}
