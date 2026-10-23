using Microsoft.Extensions.Hosting;
using Sokna.Maintenance.Core;

namespace Sokna.Maintenance.Supervisor;

/// <summary>
/// Managed dispatch only. All real mutations stay in the existing
/// SupervisorLocalCodeOnlyExecutionCoordinator + qualified Update Engine.
/// A durable accepted intent is created by the authenticated HTTP route
/// BEFORE this worker is signalled. There is never an automatic replay after
/// a process restart: Program quarantines abandoned intents before listening.
/// </summary>
public sealed class LocalCodeOnlyActivationWorker : BackgroundService
{
    private readonly SupervisorLocalCodeOnlyExecutionCoordinator _coordinator;
    private readonly MaintenanceOperationStore _operations;
    private readonly SupervisorInactiveLocalSlotCoordinator _slots;
    private readonly SemaphoreSlim _wake = new(0, 1);
    // Persisting a reservation and signalling the hosted worker are two
    // separate operations. If the signal is lost but Supervisor stays alive,
    // bounded ledger inspection must discover the durable accepted intent.
    // A process restart is different: startup quarantine runs before hosting.
    private static readonly TimeSpan LostSignalRecoveryInterval = TimeSpan.FromSeconds(5);

    public LocalCodeOnlyActivationWorker(
        SupervisorLocalCodeOnlyExecutionCoordinator coordinator,
        MaintenanceOperationStore operations, SupervisorInactiveLocalSlotCoordinator slots)
    {
        _coordinator = coordinator;
        _operations = operations;
        _slots = slots;
    }

    public void NotifyAccepted()
    {
        try { _wake.Release(); }
        catch (SemaphoreFullException) { /* One signal is sufficient: ledger is authoritative. */ }
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        try
        {
            while (!stoppingToken.IsCancellationRequested)
            {
                // Fast path: a signed HTTP POST signals immediately.
                // Safety path: a lost notification cannot strand a durable
                // reservation forever in the SAME running Supervisor.
                // Timed out waits check only persisted operations; no package
                // path, unsigned request, or retry permission is inferred.
                await _wake.WaitAsync(LostSignalRecoveryInterval, stoppingToken);
                // Any active operation except the current accepted reservation
                // already blocks another mutation in the core coordinator.
                // Never infer queued work from a stale HTTP response.
                while (!stoppingToken.IsCancellationRequested)
                {
                    var accepted = _operations.ListActive()
                        .FirstOrDefault(static o =>
                            o.OperationType == "update.local-code-only-activate"
                            && o.State == "accepted");
                    if (accepted is null) break;

                    try
                    {
                        // Running the existing synchronous, qualified Engine
                        // coordinator on a managed hosted worker prevents the
                        // 240s Windows child budget from blocking Local's
                        // 10s signed HTTP transport.
                        await Task.Run(() => _coordinator.ExecutePreparedReservedActivation(
                            accepted.PackageRef, accepted.OperatorId, accepted.IdempotencyKey, _slots),
                            stoppingToken);
                    }
                    catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
                    {
                        // Service shutdown / crash reconciliation quarantines
                        // the durable operation before next listener startup.
                        return;
                    }
                    catch (Exception error)
                    {
                        Console.Error.WriteLine(
                            "Local code-only activation worker failed: " + error.GetType().Name);
                        try { _coordinator.QuarantineActivationFailure(accepted.OperationId, error); }
                        catch (Exception evidenceError)
                        {
                            // Never pretend the updater is idle when durable
                            // evidence cannot be reconciled.
                            Console.Error.WriteLine(
                                "Durable Local activation quarantine failed: " +
                                evidenceError.GetType().Name);
                            return;
                        }
                    }
                }
            }
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested) { }
    }
}
