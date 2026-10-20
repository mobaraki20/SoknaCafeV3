using Sokna.Maintenance.Core;

var directory = Path.Combine(Path.GetTempPath(), "SoknaV4DurableActivate-" + Guid.NewGuid().ToString("N"));
try
{
    var paths = new MaintenancePaths(directory);
    Directory.CreateDirectory(paths.OperationsRoot);
    Directory.CreateDirectory(paths.LocalSlotStateRoot);
    Directory.CreateDirectory(paths.InboxRoot);
    var state = new SupervisorStateStore(paths).LoadOrCreate();
    var operations = new MaintenanceOperationStore(paths);
    var journal = new DurableJournal(paths.JournalPath);
    var coordinator = new SupervisorLocalCodeOnlyExecutionCoordinator(
        paths, operations, null!, journal, state,
        Path.Combine(directory, "nonexistent-engine.exe"), null!, null!);

    var stageHash = MaintenanceOperationStore.CanonicalRequestHash(
        "update.local-stage", "source-1", "operator-test");
    var (stage, _) = operations.GetOrCreate(
        "stage-reservation-fixture-1", stageHash, "update.local-stage",
        "source-1", "operator-test");
    stage = operations.Update(stage, "staged");

    var accepted = coordinator.ReserveActivation(
        stage.OperationId, "operator-test", "activation-key-one");
    Require(accepted.Created && accepted.Operation.State == "accepted",
        "durable accepted intent missing");
    Require(operations.ListBlocking().Count == 1,
        "accepted intent must block all other mutations");

    var replay = coordinator.ReserveActivation(
        stage.OperationId, "operator-test", "activation-key-one");
    Require(!replay.Created && replay.Reconciled &&
        replay.Operation.OperationId == accepted.Operation.OperationId,
        "duplicate request must return exact existing operation");
    Require(operations.ListBlocking().Count == 1,
        "duplicate request spawned an extra operation");

    bool mismatchedKeyRejected = false;
    try
    {
        coordinator.ReserveActivation("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
            "operator-test", "activation-key-one");
    }
    catch (InvalidDataException) { mismatchedKeyRejected = true; }
    Require(mismatchedKeyRejected, "idempotency key accepted a different Stage");

    bool concurrentMutationRejected = false;
    try
    {
        coordinator.ReserveActivation(stage.OperationId, "operator-test", "activation-key-two");
    }
    catch (InvalidOperationException e) when (e.Message == "UPDATER_OPERATION_BUSY")
    { concurrentMutationRejected = true; }
    Require(concurrentMutationRejected, "two simultaneous accepted activations permitted");

    // Crash before Engine dispatch: no HTTP success and no mutation.
    var interrupted = coordinator.QuarantineInterruptedActivations();
    Require(interrupted == 1, "startup failed to identify unstarted intent");
    var afterRestart = operations.Get(accepted.Operation.OperationId)!;
    Require(afterRestart.State == "failed" && !afterRestart.ActivationPerformed,
        "pre-execution interrupted operation must fail without false commit");
    Require(operations.ListBlocking().Count == 0,
        "safe pre-execution failure still blocks the machine");
    Require(coordinator.QuarantineInterruptedActivations() == 0,
        "already quarantined operation retriggered on restart");

    var accepted2 = coordinator.ReserveActivation(
        stage.OperationId, "operator-test", "activation-key-three");
    // After Engine-start intent, loss of Supervisor must ALWAYS require recovery.
    operations.Update(accepted2.Operation, "activating",
        "CODE_ONLY_ENGINE_START_INTENT", incrementAttempt: true);
    Require(coordinator.QuarantineInterruptedActivations() == 1,
        "startup failed to identify interrupted engine intent");
    var crashed = operations.Get(accepted2.Operation.OperationId)!;
    Require(crashed.State == "recovery_required",
        "interrupted Engine intent was not fail-closed");
    Require(operations.ListBlocking().Count == 1,
        "unproven Engine outcome must block another update");
    Require(coordinator.QuarantineInterruptedActivations() == 0,
        "recovery-required event must not be dispatched repeatedly");

    bool postEngineMutationBlocked = false;
    try
    {
        coordinator.ReserveActivation(stage.OperationId, "operator-test", "activation-key-four");
    }
    catch (InvalidOperationException e) when (e.Message == "UPDATER_OPERATION_BUSY")
    { postEngineMutationBlocked = true; }
    Require(postEngineMutationBlocked,
        "unresolved Engine mutation incorrectly allowed a fresh install");

    Console.WriteLine("V4_DURABLE_LOCAL_ACTIVATION_CONTRACT_PASS: reservation, exact replay, conflict, preengine crash, postengine recovery, no false commit");
}
finally
{
    try { Directory.Delete(directory, true); }
    catch { /* temporary fixture may remain locked only if test already failed */ }
}

static void Require(bool condition, string error)
{
    if (!condition) throw new InvalidOperationException(error);
}
