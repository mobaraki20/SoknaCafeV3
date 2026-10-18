namespace Sokna.Maintenance.Core;

/// <summary>
/// Shared Supervisor-process serialization point for the experimental Local
/// preflight/stage contract. Other maintenance operation families must be
/// reconciled into one owner before panel_update_ready can ever become true.
/// </summary>
public static class LocalUpdateOperationGate
{
    public static readonly object Sync = new();
}
