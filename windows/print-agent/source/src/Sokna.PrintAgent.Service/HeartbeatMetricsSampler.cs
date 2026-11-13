using System.Threading;

namespace Sokna.PrintAgent.Service;

// Immutable, last-known-good telemetry. These are diagnostics, never permission to print.
internal sealed record HeartbeatMetricsSnapshot(
    int OpenCount,
    int UnknownCount,
    int PendingReportCount,
    int AuthBlockedReportCount,
    int ReconciliationReportCount,
    string HealthStatus)
{
    internal static HeartbeatMetricsSnapshot Unknown => new(0,0,0,0,0,"metrics_unavailable");
}

/// <summary>
/// Best-effort telemetry only. A busy/broken local SQLite connection must not suppress
/// the independently authenticated heartbeat to Local. Last-known counts are explicitly
/// labelled stale; a stale metric is never used to claim a job or decide printing.
/// </summary>
internal sealed class HeartbeatMetricsSampler
{
    private readonly TimeSpan _budget;
    private HeartbeatMetricsSnapshot _lastGood=HeartbeatMetricsSnapshot.Unknown;

    internal HeartbeatMetricsSampler(TimeSpan budget)
    {
        if(budget<=TimeSpan.Zero)throw new ArgumentOutOfRangeException(nameof(budget));
        _budget=budget;
    }

    internal async Task<HeartbeatMetricsSnapshot> CaptureAsync(
        Func<CancellationToken,Task<HeartbeatMetricsSnapshot>> read,
        CancellationToken serviceToken)
    {
        ArgumentNullException.ThrowIfNull(read);
        serviceToken.ThrowIfCancellationRequested();
        using var limit=CancellationTokenSource.CreateLinkedTokenSource(serviceToken);
        limit.CancelAfter(_budget);
        try
        {
            // WaitAsync bounds even a provider that does not promptly honor cancellation.
            var sample=await read(limit.Token).WaitAsync(limit.Token);
            if(sample.OpenCount<0||sample.UnknownCount<0||sample.PendingReportCount<0||
               sample.AuthBlockedReportCount<0||sample.ReconciliationReportCount<0)
                throw new InvalidDataException("negative heartbeat metrics");
            var current=sample with { HealthStatus="ok" };
            Volatile.Write(ref _lastGood,current);
            return current;
        }
        catch(OperationCanceledException) when(!serviceToken.IsCancellationRequested)
        {
            return Volatile.Read(ref _lastGood) with { HealthStatus="metrics_timeout" };
        }
        catch(Exception) when(!serviceToken.IsCancellationRequested)
        {
            return Volatile.Read(ref _lastGood) with { HealthStatus="metrics_error" };
        }
    }
}
