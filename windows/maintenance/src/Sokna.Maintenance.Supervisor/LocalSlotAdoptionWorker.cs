using Microsoft.Extensions.Hosting;
using Sokna.Maintenance.Core;

namespace Sokna.Maintenance.Supervisor;

internal sealed class LocalSlotAdoptionWorker : BackgroundService
{
    private const int MaxAutomaticAttempts = 8;
    private static readonly TimeSpan IdleDelay = TimeSpan.FromSeconds(3);
    private static readonly TimeSpan[] RetryDelays =
    [
        TimeSpan.FromSeconds(2),
        TimeSpan.FromSeconds(5),
        TimeSpan.FromSeconds(10),
        TimeSpan.FromSeconds(20),
        TimeSpan.FromSeconds(30),
        TimeSpan.FromMinutes(1),
        TimeSpan.FromMinutes(2),
        TimeSpan.FromMinutes(5)
    ];

    private readonly LocalSlotAdoptionQueue _queue;
    private readonly SupervisorProductionMigrationCoordinator _coordinator;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisorState;

    public LocalSlotAdoptionWorker(
        LocalSlotAdoptionQueue queue,
        SupervisorProductionMigrationCoordinator coordinator,
        DurableJournal journal,
        SupervisorState supervisorState)
    {
        _queue = queue;
        _coordinator = coordinator;
        _journal = journal;
        _supervisorState = supervisorState;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        // The worker is deliberately independent of the loopback HTTP listener. A persisted bootstrap
        // request is owned by Supervisor itself and must survive installer exit, browser closure and
        // Supervisor restarts. The first short delay avoids contending with service-start housekeeping.
        await Delay(TimeSpan.FromSeconds(1), stoppingToken);

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

            await Delay(IdleDelay, stoppingToken);
        }
    }

    internal async Task ProcessOnce(CancellationToken stoppingToken)
    {
        stoppingToken.ThrowIfCancellationRequested();
        var request = _queue.LoadRequest();
        if (request is null) return;

        var status = _queue.EnsurePending(request);
        if (LocalSlotAdoptionQueue.IsTerminal(status.State)) return;
        if (status.State == "retry_wait" && status.NextAttemptAtUtc is { } next && next > DateTimeOffset.UtcNow) return;

        var attempt = checked(status.AttemptCount + 1);
        if (attempt > MaxAutomaticAttempts)
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
        if (activationPerformed || attempt >= MaxAutomaticAttempts)
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

        var delay = RetryDelays[Math.Min(attempt - 1, RetryDelays.Length - 1)];
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
        await Delay(TimeSpan.FromMilliseconds(50), stoppingToken);
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
