using Sokna.PrintAgent.Service;

static void Require(bool ok,string code)
{
    if(!ok)throw new InvalidOperationException("FAIL_R198_"+code);
}
static HeartbeatMetricsSnapshot Sample(int open=4,int unknown=2,int pending=3)
    =>new(open,unknown,pending,1,2,"ok");

var fast=new HeartbeatMetricsSampler(TimeSpan.FromMilliseconds(150));
var good=await fast.CaptureAsync(_=>Task.FromResult(Sample()),CancellationToken.None);
Require(good.HealthStatus=="ok"&&good.OpenCount==4&&good.PendingReportCount==3,"METRICS_FRESH");
Console.WriteLine("PASS_R198_FRESH_METRICS");
var timed=await fast.CaptureAsync(async ct=>{await Task.Delay(Timeout.InfiniteTimeSpan,ct);return Sample(9);},CancellationToken.None);
Require(timed.HealthStatus=="metrics_timeout"&&timed.OpenCount==4&&timed.UnknownCount==2,"TIMEOUT_WITH_CACHED_COUNTERS");
Console.WriteLine("PASS_R198_SQLITE_TIMEOUT_DOES_NOT_BLOCK_HEARTBEAT");
var failed=await fast.CaptureAsync(_=>Task.FromException<HeartbeatMetricsSnapshot>(new InvalidDataException("private db path")),CancellationToken.None);
Require(failed.HealthStatus=="metrics_error"&&failed.OpenCount==4&&failed.PendingReportCount==3,"ERROR_CACHED_NO_LEAK");
Console.WriteLine("PASS_R198_SQLITE_ERROR_FALLBACK");
var reopened=await fast.CaptureAsync(_=>Task.FromResult(Sample(7,0,8)),CancellationToken.None);
Require(reopened.HealthStatus=="ok"&&reopened.OpenCount==7&&reopened.UnknownCount==0,"RECOVERY_UPDATES_METRICS");
Console.WriteLine("PASS_R198_SQLITE_RECOVERY");
var negative=await fast.CaptureAsync(_=>Task.FromResult(Sample(-3)),CancellationToken.None);
Require(negative.HealthStatus=="metrics_error"&&negative.OpenCount==7,"INVALID_METRICS_DO_NOT_CORRUPT_CACHE");
Console.WriteLine("PASS_R198_BAD_METRICS_REJECTED");
var fresh=new HeartbeatMetricsSampler(TimeSpan.FromMilliseconds(100));
var noHistory=await fresh.CaptureAsync(async ct=>{await Task.Delay(Timeout.InfiniteTimeSpan,ct);return Sample();},CancellationToken.None);
Require(noHistory.HealthStatus=="metrics_timeout"&&noHistory.OpenCount==0,"NO_CACHE_EXPLICIT_UNAVAILABLE");
Console.WriteLine("PASS_R198_NO_HISTORY_EXPLICIT_UNAVAILABLE");
using var source=new CancellationTokenSource();
source.Cancel();
try
{
    await fast.CaptureAsync(_=>Task.FromResult(Sample()),source.Token);
    throw new InvalidOperationException("FAIL_R198_SERVICE_CANCELLATION_SWALLOWED");
}
catch(OperationCanceledException){}
Console.WriteLine("PASS_R198_SERVICE_CANCELLATION_PROPAGATES");
var slow=new HeartbeatMetricsSampler(TimeSpan.FromMilliseconds(70));
var started=System.Diagnostics.Stopwatch.StartNew();
var uncooperative=await slow.CaptureAsync(async _=>{await Task.Delay(2000);return Sample();},CancellationToken.None);
started.Stop();
Require(uncooperative.HealthStatus=="metrics_timeout"&&started.Elapsed<TimeSpan.FromMilliseconds(800),"NONCOOPERATING_PROVIDER_BOUND");
Console.WriteLine("PASS_R198_PROVIDER_NONCOOPERATION_BOUNDED");
Console.WriteLine("PASS_R198_HEARTBEAT_METRICS_8_SCENARIOS");
