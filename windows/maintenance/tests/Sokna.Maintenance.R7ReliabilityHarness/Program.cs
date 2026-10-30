using Sokna.Maintenance.Core;
using Sokna.Maintenance.Supervisor;

var tests = new List<(string Name, Func<Task> Run)>
{
    ("no_request_is_idle", NoRequestIsIdle),
    ("pre_mutation_failure_schedules_retry", PreMutationFailureSchedulesRetry),
    ("restart_resumes_due_retry_with_new_idempotency", RestartResumesDueRetry),
    ("commit_is_terminal_and_idempotent", CommitIsTerminal),
    ("activation_failure_never_auto_retries", ActivationFailureNeverRetries),
    ("recovery_required_never_auto_retries", RecoveryRequiredNeverRetries),
    ("coordinator_exception_is_retryable_pre_mutation", CoordinatorExceptionIsRetryable),
    ("retry_budget_is_bounded", RetryBudgetIsBounded),
    ("durable_status_survives_queue_reconstruction", DurableStatusSurvivesReconstruction),
    ("request_identity_conflict_fails_closed", RequestIdentityConflictFailsClosed)
};

var passed = 0;
foreach (var test in tests)
{
    await test.Run();
    Console.WriteLine($"PASS {test.Name}");
    passed++;
}
Console.WriteLine($"R7_RELIABILITY_HARNESS_PASS={passed}/{tests.Count}");

static async Task NoRequestIsIdle()
{
    using var env = TestEnv.Create();
    var fake = new FakeCoordinator();
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    Assert(fake.Calls.Count == 0, "No request must not invoke migration.");
    Assert(env.Queue.LoadStatus() is null, "No request must not create status.");
}

static async Task PreMutationFailureSchedulesRetry()
{
    using var env = TestEnv.Create();
    env.WriteRequest();
    var fake = new FakeCoordinator(Op("failed", false, "PRECHECK_TRANSIENT"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    var status = Require(env.Queue.LoadStatus());
    Assert(status.State == "retry_wait", "Pre-mutation failure should schedule retry.");
    Assert(status.AttemptCount == 1, "First failure should record attempt 1.");
    Assert(status.ActivationPerformed == false, "Retryable failure cannot claim activation.");
    Assert(status.NextAttemptAtUtc is not null, "Retry must be durable with next attempt timestamp.");
    Assert(fake.Calls.Count == 1, "Exactly one migration call expected.");
}

static async Task RestartResumesDueRetry()
{
    using var env = TestEnv.Create();
    var request = env.WriteRequest();
    var first = new FakeCoordinator(Op("failed", false, "TEMPORARY"));
    var worker1 = env.Worker(first);
    await worker1.ProcessOnce(CancellationToken.None);
    env.Queue.MarkRetry(request, 1, "op-first", "TEMPORARY", "due now", DateTimeOffset.UtcNow.AddSeconds(-1));

    var second = new FakeCoordinator(Op("committed", true, "LOCAL_SLOT_MIGRATION_COMMITTED"));
    var reconstructedQueue = new LocalSlotAdoptionQueue(env.Paths);
    var worker2 = env.Worker(second, reconstructedQueue);
    await worker2.ProcessOnce(CancellationToken.None);

    var status = Require(reconstructedQueue.LoadStatus());
    Assert(status.State == "committed", "Restarted worker should resume and commit due retry.");
    Assert(status.AttemptCount == 2, "Resumed attempt should be attempt 2.");
    Assert(second.Calls.Single().IdempotencyKey.EndsWith("-a2", StringComparison.Ordinal), "Retry must use a fresh durable idempotency key.");
}

static async Task CommitIsTerminal()
{
    using var env = TestEnv.Create();
    env.WriteRequest();
    var fake = new FakeCoordinator(Op("committed", true, "COMMITTED"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    await worker.ProcessOnce(CancellationToken.None);
    var status = Require(env.Queue.LoadStatus());
    Assert(status.State == "committed", "Commit must be terminal.");
    Assert(fake.Calls.Count == 1, "Committed request must never execute twice.");
}

static async Task ActivationFailureNeverRetries()
{
    using var env = TestEnv.Create();
    env.WriteRequest();
    var fake = new FakeCoordinator(Op("failed", true, "POST_SWITCH_ROLLBACK_OK"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    var status = Require(env.Queue.LoadStatus());
    Assert(status.State == "failed_terminal", "Any activation attempt must stop unattended retries.");
    Assert(status.ActivationPerformed, "Activation evidence must remain durable.");
    await worker.ProcessOnce(CancellationToken.None);
    Assert(fake.Calls.Count == 1, "Activated failure must not auto retry.");
}

static async Task RecoveryRequiredNeverRetries()
{
    using var env = TestEnv.Create();
    env.WriteRequest();
    var fake = new FakeCoordinator(Op("recovery_required", true, "LOCAL_SLOT_RECOVERY_REQUIRED"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    var status = Require(env.Queue.LoadStatus());
    Assert(status.State == "recovery_required", "Recovery-required state must be explicit and terminal for auto worker.");
    await worker.ProcessOnce(CancellationToken.None);
    Assert(fake.Calls.Count == 1, "Recovery-required request must not auto retry.");
}

static async Task CoordinatorExceptionIsRetryable()
{
    using var env = TestEnv.Create();
    env.WriteRequest();
    var fake = new FakeCoordinator(new IOException("injected pre-mutation coordinator outage"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    var status = Require(env.Queue.LoadStatus());
    Assert(status.State == "retry_wait", "Coordinator outage before activation should be retryable.");
    Assert(status.LastCode == "LOCAL_ADOPTION_SUPERVISOR_ERROR", "Exception should be normalized to stable code.");
}

static async Task RetryBudgetIsBounded()
{
    using var env = TestEnv.Create(maxAttempts: 2);
    var request = env.WriteRequest();
    var fake = new FakeCoordinator(Op("failed", false, "TRANSIENT"), Op("failed", false, "TRANSIENT"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    env.Queue.MarkRetry(request, 1, "op-1", "TRANSIENT", "due", DateTimeOffset.UtcNow.AddSeconds(-1));
    await worker.ProcessOnce(CancellationToken.None);
    var status = Require(env.Queue.LoadStatus());
    Assert(status.State == "failed_terminal", "Final allowed failed attempt should close bounded retry budget.");
    Assert(status.AttemptCount == 2, "Retry budget should stop at configured attempt count.");
    await worker.ProcessOnce(CancellationToken.None);
    Assert(fake.Calls.Count == 2, "Terminal retry budget must not execute again.");
}

static async Task DurableStatusSurvivesReconstruction()
{
    using var env = TestEnv.Create();
    env.WriteRequest();
    var fake = new FakeCoordinator(Op("failed", false, "TEMP"));
    var worker = env.Worker(fake);
    await worker.ProcessOnce(CancellationToken.None);
    var before = Require(env.Queue.LoadStatus());
    var after = Require(new LocalSlotAdoptionQueue(env.Paths).LoadStatus());
    Assert(after.RequestId == before.RequestId && after.State == before.State && after.AttemptCount == before.AttemptCount,
        "Status must survive process/queue reconstruction.");
}

static Task RequestIdentityConflictFailsClosed()
{
    using var env = TestEnv.Create();
    var request = env.WriteRequest();
    env.Queue.EnsurePending(request);
    var conflicting = request with { RequestId = "adopt-conflicting-request" };
    var threw = false;
    try { env.Queue.EnsurePending(conflicting); }
    catch (InvalidDataException) { threw = true; }
    Assert(threw, "Different request identity must not overwrite existing durable status.");
    return Task.CompletedTask;
}

static MaintenanceOperation Op(string state, bool activation, string code)
{
    var now = DateTimeOffset.UtcNow;
    return new MaintenanceOperation(
        "sokna-maintenance-operation-v1", 1, "op" + Guid.NewGuid().ToString("N"),
        "idem-test", new string('a', 64), "local_slot_migrate", "test", state, "local", now, now,
        1, code, code, null, activation, false);
}

static T Require<T>(T? value) where T : class => value ?? throw new InvalidOperationException("Expected non-null value.");
static void Assert(bool condition, string message)
{
    if (!condition) throw new InvalidOperationException(message);
}

sealed class FakeCoordinator : IProductionLocalSlotMigrationCoordinator
{
    private readonly Queue<object> _outcomes = new();
    public List<Call> Calls { get; } = [];

    public FakeCoordinator(params object[] outcomes)
    {
        foreach (var outcome in outcomes) _outcomes.Enqueue(outcome);
    }

    public LocalSlotOperationResult Migrate(string expectedBuild, int expectedManagedFiles, string expectedManagedTreeSha256, string operatorId, string idempotencyKey)
    {
        Calls.Add(new Call(expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, operatorId, idempotencyKey));
        if (_outcomes.Count == 0) throw new InvalidOperationException("Fake coordinator has no configured outcome.");
        var outcome = _outcomes.Dequeue();
        if (outcome is Exception ex) throw ex;
        return new LocalSlotOperationResult((MaintenanceOperation)outcome, true, false);
    }

    public LocalSlotOperationResult Recover(string operatorId, string idempotencyKey)
        => throw new NotSupportedException();

    public sealed record Call(string Build, int Files, string Sha256, string OperatorId, string IdempotencyKey);
}

sealed class TestEnv : IDisposable
{
    private readonly string _root;
    private readonly LocalSlotAdoptionWorkerOptions _options;
    public MaintenancePaths Paths { get; }
    public LocalSlotAdoptionQueue Queue { get; }
    public DurableJournal Journal { get; }
    public SupervisorState State { get; }

    private TestEnv(string root, int maxAttempts)
    {
        _root = root;
        Paths = new MaintenancePaths(Path.Combine(root, "maintenance"));
        Queue = new LocalSlotAdoptionQueue(Paths);
        Journal = new DurableJournal(Paths.JournalPath);
        State = new SupervisorState(
            "sokna-maintenance-supervisor-state-v1", 1, "1.0", "test-instance", 1,
            DateTimeOffset.UtcNow, DateTimeOffset.UtcNow, false, "unbound");
        _options = new LocalSlotAdoptionWorkerOptions(
            maxAttempts,
            TimeSpan.Zero,
            TimeSpan.FromMilliseconds(10),
            Enumerable.Repeat(TimeSpan.Zero, Math.Max(1, maxAttempts)).ToArray());
    }

    public static TestEnv Create(int maxAttempts = 8)
    {
        var root = Path.Combine(Path.GetTempPath(), "sokna-r7-harness-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        return new TestEnv(root, maxAttempts);
    }

    public LocalSlotAdoptionRequest WriteRequest()
    {
        var request = new LocalSlotAdoptionRequest(
            LocalSlotAdoptionQueue.RequestFormat,
            LocalSlotAdoptionQueue.SchemaVersion,
            "adopt-test-" + Guid.NewGuid().ToString("N"),
            "3.0.0-test",
            1,
            new string('b', 64),
            "test-package",
            DateTimeOffset.UtcNow);
        AtomicFile.WriteJson(Paths.LocalSlotAdoptionRequestPath, request);
        return request;
    }

    public LocalSlotAdoptionWorker Worker(IProductionLocalSlotMigrationCoordinator coordinator, LocalSlotAdoptionQueue? queue = null)
        => new(queue ?? Queue, coordinator, new MaintenanceOperationStore(Paths), Journal, State, _options);

    public void Dispose()
    {
        try { Directory.Delete(_root, recursive: true); } catch { }
    }
}
