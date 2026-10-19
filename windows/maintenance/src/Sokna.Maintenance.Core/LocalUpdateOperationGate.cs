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
