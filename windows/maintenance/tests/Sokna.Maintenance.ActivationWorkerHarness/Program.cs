using Sokna.Maintenance.Core;

// CI-only mutation of throwaway test data: never operate on a real SOKNA root.
static MaintenancePaths DisposableCiPaths(string dataRoot)
{
    var temp = Environment.GetEnvironmentVariable("RUNNER_TEMP");
    if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
        Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows" ||
        string.IsNullOrEmpty(temp))
        throw new InvalidOperationException("CI_DISPOSABLE_ROOT_REQUIRED");
    var path = Path.GetFullPath(dataRoot);
    if (!path.StartsWith(Path.GetFullPath(temp).TrimEnd(Path.DirectorySeparatorChar)
            + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
        throw new InvalidOperationException("CI_ROOT_MUST_BE_UNDER_RUNNER_TEMP");
    return new MaintenancePaths(path);
}

// This command writes ONLY synthetic staged-operation metadata to a disposable
// test directory. It is NOT a signed Release Set and cannot install Local.
if (args.Length == 2 && args[0] == "--seed-http-denial")
{
    var paths = DisposableCiPaths(args[1]);
    var ledger = new MaintenanceOperationStore(paths);
    var actor = "user:ci";
    var sourceRef = "synthetic-denial-stage-only";
    var kind = "update.local-stage";
    var hash = MaintenanceOperationStore.CanonicalRequestHash(kind, sourceRef, actor);
    var (seed, _) = ledger.GetOrCreate("p26-http-denial-stage", hash,
        kind, sourceRef, actor);
    var persisted = ledger.Update(seed, "staged");
    Console.WriteLine(persisted.OperationId);
    return;
}

// Inject only a PERSISTENT reservation (never Engine execution) while a
// disposable Supervisor is already listening. Do NOT send the in-memory worker
// notification. The hosted worker's ledger timeout must discover this intent.
if (args.Length == 5 && args[0] == "--reserve-http-denial")
{
    var paths = DisposableCiPaths(args[1]);
    var stageId = args[2];
    var actor = args[3];
    var idem = args[4];
    var ledger = new MaintenanceOperationStore(paths);
    var journal = new DurableJournal(paths.JournalPath);
    var state = new SupervisorStateStore(paths).LoadOrCreate();
    var coordinator = new SupervisorLocalCodeOnlyExecutionCoordinator(
        paths, ledger, null!, journal, state,
        Path.Combine(paths.DataRoot, "NO_ENGINE_IN_CI.exe"), null!, null!);
    var accepted = coordinator.ReserveActivation(stageId, actor, idem);
    if (!accepted.Created || accepted.Operation.State != "accepted")
        throw new InvalidOperationException("CI_LOST_SIGNAL_RESERVATION_NOT_ACCEPTED");
    Console.WriteLine(accepted.Operation.OperationId);
    return;
}

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

    // A durable reservation is a machine-wide blocker for everyone ELSE.
    // Its own signed Stage reinspection must be allowed before Engine intent.
    Require(IsBusy(() => LocalUpdateOperationGate.RequireStageInspection(
        operations, stage)), "read-only inspection bypassed active reservation");
    LocalUpdateOperationGate.RequireStageInspection(
        operations, stage, accepted.Operation.OperationId);
    Require(IsBusy(() => LocalUpdateOperationGate.RequireStageInspection(
        operations, stage with { OperatorId = "foreign-actor" },
        accepted.Operation.OperationId)), "foreign actor inherited activation allowance");
    Require(IsBusy(() => LocalUpdateOperationGate.RequireStageInspection(
        operations, stage with { OperationId = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" },
        accepted.Operation.OperationId)), "foreign Stage inherited activation allowance");

    var foreignHash = MaintenanceOperationStore.CanonicalRequestHash(
        "update.local-stage", "source-foreign", "operator-foreign");
    var (foreign, _) = operations.GetOrCreate("foreign-blocking-operation",
        foreignHash, "update.local-stage", "source-foreign", "operator-foreign");
    Require(IsBusy(() => LocalUpdateOperationGate.RequireStageInspection(
        operations, stage, accepted.Operation.OperationId)),
        "another active update was bypassed by owning reservation");
    operations.Update(foreign, "failed");
    LocalUpdateOperationGate.RequireStageInspection(
        operations, stage, accepted.Operation.OperationId);

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
    Require(IsBusy(() => LocalUpdateOperationGate.RequireStageInspection(
        operations, stage, accepted2.Operation.OperationId)),
        "already-dispatched Engine intent reused inspection exemption");
    Require(coordinator.QuarantineInterruptedActivations() == 1,
        "startup failed to identify interrupted engine intent");
    var crashed = operations.Get(accepted2.Operation.OperationId)!;
    Require(crashed.State == "recovery_required",
        "interrupted Engine intent was not fail-closed");
    Require(IsBusy(() => LocalUpdateOperationGate.RequireStageInspection(
        operations, stage, accepted2.Operation.OperationId)),
        "recovery-required operation reused inspection exemption");
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

static bool IsBusy(Action action)
{
    try { action(); return false; }
    catch (InvalidOperationException ex) when (ex.Message == "UPDATER_OPERATION_BUSY")
    {
        return true;
    }
}

static void Require(bool condition, string error)
{
    if (!condition) throw new InvalidOperationException(error);
}
