using Microsoft.Extensions.Hosting;
using Sokna.Maintenance.Core;

namespace Sokna.Maintenance.Supervisor;

public sealed record LocalSlotAdoptionWorkerOptions(
    int MaxAutomaticAttempts,
    TimeSpan StartupDelay,
    TimeSpan IdleDelay,
    IReadOnlyList<TimeSpan> RetryDelays)
{
    public static LocalSlotAdoptionWorkerOptions Default { get; } = new(
        MaxAutomaticAttempts: 8,
        StartupDelay: TimeSpan.FromSeconds(1),
        IdleDelay: TimeSpan.FromSeconds(3),
        RetryDelays:
        [
            TimeSpan.FromSeconds(2),
            TimeSpan.FromSeconds(5),
            TimeSpan.FromSeconds(10),
            TimeSpan.FromSeconds(20),
            TimeSpan.FromSeconds(30),
            TimeSpan.FromMinutes(1),
            TimeSpan.FromMinutes(2),
            TimeSpan.FromMinutes(5)
        ]);

    public void Validate()
    {
        if (MaxAutomaticAttempts is < 1 or > 100) throw new InvalidDataException("Local adoption max automatic attempts is invalid.");
        if (StartupDelay < TimeSpan.Zero || StartupDelay > TimeSpan.FromMinutes(5)) throw new InvalidDataException("Local adoption startup delay is invalid.");
        if (IdleDelay < TimeSpan.FromMilliseconds(10) || IdleDelay > TimeSpan.FromMinutes(5)) throw new InvalidDataException("Local adoption idle delay is invalid.");
        if (RetryDelays.Count == 0 || RetryDelays.Any(static d => d < TimeSpan.Zero || d > TimeSpan.FromHours(1)))
            throw new InvalidDataException("Local adoption retry delay policy is invalid.");
    }
}

public sealed class LocalSlotAdoptionWorker : BackgroundService
{
    private readonly LocalSlotAdoptionQueue _queue;
    private readonly IProductionLocalSlotMigrationCoordinator _coordinator;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisorState;
    private readonly LocalSlotAdoptionWorkerOptions _options;

    public LocalSlotAdoptionWorker(
        LocalSlotAdoptionQueue queue,
        IProductionLocalSlotMigrationCoordinator coordinator,
        DurableJournal journal,
        SupervisorState supervisorState,
        LocalSlotAdoptionWorkerOptions options)
    {
        _queue = queue;
        _coordinator = coordinator;
        _journal = journal;
        _supervisorState = supervisorState;
        _options = options;
        _options.Validate();
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // The worker is deliberately independent of the loopback HTTP listener. A persisted bootstrap
        // request is owned by Supervisor itself and must survive installer exit, browser closure and
        // Supervisor restarts. The first short delay avoids contending with service-start housekeeping.
        await Delay(_options.StartupDelay, stoppingToken);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                await ProcessOnce(stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                return;
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidDataException or InvalidOperationException)
            {
                Journal("update.local_adoption.worker_error", new Dictionary<string, object?>
                {
                    ["error"] = Safe(ex.Message)
                });
            }

            await Delay(_options.IdleDelay, stoppingToken);
        }
    }

    public async Task ProcessOnce(CancellationToken stoppingToken)
    {
        stoppingToken.ThrowIfCancellationRequested();
        var request = _queue.LoadRequest();
        if (request is null) return;

        var status = _queue.EnsurePending(request);
        if (LocalSlotAdoptionQueue.IsTerminal(status.State))
        {
            if (status.State is "recovery_required" or "failed_terminal")
            {
                var reconciled = _queue.TryReconcileVerifiedRecovery();
                if (reconciled is { State: "committed" })
                {
                    Journal("update.local_adoption.recovery_reconciled", new Dictionary<string, object?>
                    {
                        ["request_id"] = request.RequestId,
                        ["attempt_count"] = reconciled.AttemptCount,
                        ["result_code"] = reconciled.LastCode,
                        ["activation_performed"] = true
                    });
                }
            }
            return;
        }
        if (status.State == "retry_wait" && status.NextAttemptAtUtc is { } next && next > DateTimeOffset.UtcNow) return;

        var attempt = checked(status.AttemptCount + 1);
        if (attempt > _options.MaxAutomaticAttempts)
        {
            _queue.MarkFailedTerminal(
                request,
                status.AttemptCount,
                status.LastOperationId,
                "LOCAL_ADOPTION_RETRY_LIMIT",
                "Automatic Local layout adoption exhausted its bounded pre-mutation retry budget.",
                status.ActivationPerformed);
            Journal("update.local_adoption.failed_terminal", new Dictionary<string, object?>
            {
                ["request_id"] = request.RequestId,
                ["attempt_count"] = status.AttemptCount,
                ["result_code"] = "LOCAL_ADOPTION_RETRY_LIMIT"
            });
            return;
        }

        _queue.MarkRunning(request, attempt);
        Journal("update.local_adoption.attempt_started", new Dictionary<string, object?>
        {
            ["request_id"] = request.RequestId,
            ["attempt_count"] = attempt,
            ["expected_build"] = request.ExpectedBuild
        });

        LocalSlotOperationResult result;
        try
        {
            var idempotencyKey = BuildAttemptIdempotencyKey(request.RequestId, attempt);
            result = _coordinator.Migrate(
                request.ExpectedBuild,
                request.ExpectedManagedFiles,
                request.ExpectedManagedTreeSha256,
                "supervisor-bootstrap",
                idempotencyKey);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidDataException or InvalidOperationException)
        {
            await ScheduleRetryOrFail(request, attempt, null, "LOCAL_ADOPTION_SUPERVISOR_ERROR", ex.Message, activationPerformed: false, stoppingToken);
            return;
        }

        var operation = result.Operation;
        if (operation.State == "committed")
        {
            _queue.MarkCommitted(request, attempt, operation.OperationId, operation.ResultCode, operation.ResultMessage, operation.ActivationPerformed);
            Journal("update.local_adoption.committed", new Dictionary<string, object?>
            {
                ["request_id"] = request.RequestId,
                ["operation_id"] = operation.OperationId,
                ["attempt_count"] = attempt,
                ["activation_performed"] = operation.ActivationPerformed
            });
            return;
        }

        if (operation.State == "recovery_required")
        {
            _queue.MarkRecoveryRequired(request, attempt, operation.OperationId, operation.ResultCode, operation.ResultMessage, operation.ActivationPerformed);
            Journal("update.local_adoption.recovery_required", new Dictionary<string, object?>
            {
                ["request_id"] = request.RequestId,
                ["operation_id"] = operation.OperationId,
                ["attempt_count"] = attempt,
                ["result_code"] = operation.ResultCode,
                ["activation_performed"] = operation.ActivationPerformed
            });
            return;
        }

        // Automatic retry is intentionally limited to failures that provably occurred before pointer
        // activation. Once activation happened, even if rollback appears to have succeeded, stop and
        // preserve evidence rather than creating an unattended activation loop.
        if (operation.ActivationPerformed)
        {
            _queue.MarkFailedTerminal(request, attempt, operation.OperationId, operation.ResultCode, operation.ResultMessage, true);
            Journal("update.local_adoption.failed_after_activation", new Dictionary<string, object?>
            {
                ["request_id"] = request.RequestId,
                ["operation_id"] = operation.OperationId,
                ["attempt_count"] = attempt,
                ["result_code"] = operation.ResultCode
            });
            return;
        }

        await ScheduleRetryOrFail(
            request,
            attempt,
            operation.OperationId,
            operation.ResultCode ?? "LOCAL_ADOPTION_PRE_MUTATION_FAILED",
            operation.ResultMessage ?? "Local layout adoption failed before activation.",
            activationPerformed: false,
            stoppingToken);
    }

    private async Task ScheduleRetryOrFail(
        LocalSlotAdoptionRequest request,
        int attempt,
        string? operationId,
        string? code,
        string? message,
        bool activationPerformed,
        CancellationToken stoppingToken)
    {
        if (activationPerformed || attempt >= _options.MaxAutomaticAttempts)
        {
            _queue.MarkFailedTerminal(request, attempt, operationId, code, message, activationPerformed);
            Journal("update.local_adoption.failed_terminal", new Dictionary<string, object?>
            {
                ["request_id"] = request.RequestId,
                ["operation_id"] = operationId,
                ["attempt_count"] = attempt,
                ["result_code"] = code,
                ["activation_performed"] = activationPerformed
            });
            return;
        }

        var delay = _options.RetryDelays[Math.Min(attempt - 1, _options.RetryDelays.Count - 1)];
        var next = DateTimeOffset.UtcNow.Add(delay);
        _queue.MarkRetry(request, attempt, operationId, code, message, next);
        Journal("update.local_adoption.retry_scheduled", new Dictionary<string, object?>
        {
            ["request_id"] = request.RequestId,
            ["operation_id"] = operationId,
            ["attempt_count"] = attempt,
            ["result_code"] = code,
            ["next_attempt_at_utc"] = next
        });
        await Delay(TimeSpan.FromMilliseconds(10), stoppingToken);
    }

    private void Journal(string eventType, IReadOnlyDictionary<string, object?> data)
        => _journal.Append(eventType, _supervisorState.InstanceId, _supervisorState.InstallGeneration, data);

    private static string BuildAttemptIdempotencyKey(string requestId, int attempt)
    {
        var candidate = $"adopt-{requestId}-a{attempt}";
        if (candidate.Length > 128) candidate = candidate[..128];
        return candidate;
    }

    private static string Safe(string value)
    {
        var text = (value ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ').Replace('\0', ' ').Trim();
        return text.Length <= 1200 ? text : text[..1200];
    }

    private static async Task Delay(TimeSpan delay, CancellationToken token)
    {
        if (delay <= TimeSpan.Zero) return;
        await Task.Delay(delay, token);
    }
}
