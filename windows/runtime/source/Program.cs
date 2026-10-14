using System.Diagnostics;
using System.Net;
using System.Net.Http.Headers;
using System.Reflection;
using System.ServiceProcess;
using System.Text;
using System.Text.Json;

namespace Sokna.Runtime;

internal sealed record ScheduledTrigger(string Key,int IntervalSeconds);
internal sealed class RuntimeOptions
{
    public int ContractVersion { get; init; } = 1;
    public string InstanceId { get; init; } = "";
    public string DataRoot { get; init; } = "";
    public int HealthPort { get; init; } = 17621;
    public string RuntimeTokenFile { get; init; } = "";
    public string LocalTokenFile { get; init; } = "";
    public string LocalBaseUrl { get; init; } = "";
    public string PrintAgentServiceName { get; init; } = "SoknaPrintAgent";
    public bool SupervisePrintAgent { get; init; } = true;
    public List<ScheduledTrigger> Triggers { get; init; } = [];
}

internal static class SecretFile
{
    public static string Read(string path)
    {
        if(string.IsNullOrWhiteSpace(path)||!Path.IsPathFullyQualified(path)||!File.Exists(path))throw new InvalidOperationException("runtime_secret_file_missing");
        var value=File.ReadAllText(path,Encoding.UTF8).Trim();if(value.Length<32||value.Length>512)throw new InvalidOperationException("runtime_secret_invalid");return value;
    }
}

internal sealed record RuntimeSchedulerHealth(bool healthy,DateTimeOffset? last_cycle_at,string? last_error_code,int tick_milliseconds);
internal sealed record RuntimeTriggerHealth(int configured_interval_seconds,int effective_interval_seconds,DateTimeOffset? last_started_at,DateTimeOffset? last_completed_at,DateTimeOffset? next_run_at,bool in_flight,int consecutive_failures,string? last_error_code);
internal sealed record RuntimeComponentHealth(string status,DateTimeOffset? last_seen_at,string? version,string? error_code);
internal sealed class RuntimeTriggerHttpException(int statusCode,string failureClass,int retryAfterSeconds)
    : Exception("local_trigger_failed_"+statusCode)
{
    public int StatusCode { get; }=statusCode;
    public string FailureClass { get; }=failureClass;
    public int RetryAfterSeconds { get; }=retryAfterSeconds;
}
internal sealed record RuntimeHealthSnapshot(bool success,int contract_version,string runtime_version,string instance_id,string status,DateTimeOffset started_at,DateTimeOffset last_seen_at,string[] capabilities,RuntimeSchedulerHealth scheduler,Dictionary<string,RuntimeTriggerHealth> triggers,Dictionary<string,RuntimeComponentHealth> supervised_components);

internal static class RuntimeVersionInfo
{
    public static string Current()
    {
        var info=typeof(Program).Assembly.GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion?.Trim()??"";
        var value=info.Split('+',2)[0];
        if(string.IsNullOrWhiteSpace(value))value=typeof(Program).Assembly.GetName().Version?.ToString(3)??"unknown";
        return value.Length<=64?value:value[..64];
    }
}

internal static class RuntimeSchedulerPolicy
{
    public const int TickMilliseconds=100;
    public const int RetryBudgetMaxAttempts=6;
    public const int RetryBudgetWindowSeconds=60;
    private const int MaxBackoffSeconds=120;
    public static bool AllowsFastCadence(string key)=>string.Equals(key,"public.relay_sync",StringComparison.Ordinal);
    public static int EffectiveIntervalSeconds(ScheduledTrigger trigger)=>AllowsFastCadence(trigger.Key)?(trigger.IntervalSeconds==5?1:Math.Max(1,trigger.IntervalSeconds)):Math.Max(5,trigger.IntervalSeconds);
    public static int TriggerBudgetMilliseconds(string key)=>key switch
    {
        "public.relay_sync"=>4500,
        "public.projection_sync"=>15000,
        "inventory.order_events"=>8000,
        "notifications.outbox"=>8000,
        "maintenance.health"=>10000,
        _=>10000,
    };
    public static TimeSpan FailureBackoff(int effectiveIntervalSeconds,int consecutiveFailures,string jitterKey="",int retryAfterSeconds=0)
    {
        var exponent=Math.Min(5,Math.Max(0,consecutiveFailures-1));
        var exponential=1<<exponent;
        var seconds=Math.Min(MaxBackoffSeconds,Math.Max(Math.Max(effectiveIntervalSeconds,exponential),Math.Max(0,retryAfterSeconds)));
        var jitterWindowMs=Math.Min(1000,Math.Max(100,seconds*250));
        var seed=System.Security.Cryptography.SHA256.HashData(Encoding.UTF8.GetBytes(jitterKey+"|"+consecutiveFailures));
        var jitterMs=(int)(BitConverter.ToUInt32(seed,0)%(uint)(jitterWindowMs+1));
        return TimeSpan.FromMilliseconds(Math.Min(MaxBackoffSeconds*1000,seconds*1000+jitterMs));
    }
    public static void SelfTest()
    {
        var relay=new RuntimeTriggerState(new ScheduledTrigger("public.relay_sync",1));
        var legacyRelay=new RuntimeTriggerState(new ScheduledTrigger("public.relay_sync",5));
        var t0=new DateTimeOffset(2026,10,12,0,0,0,TimeSpan.Zero);
        if(relay.EffectiveIntervalSeconds!=1||legacyRelay.EffectiveIntervalSeconds!=1)throw new InvalidOperationException("runtime_scheduler_fast_cadence_invalid");
        if(!relay.TryStart(t0,out var first)||first!=t0)throw new InvalidOperationException("runtime_scheduler_first_due_invalid");
        if(relay.TryStart(t0.AddMilliseconds(10),out _))throw new InvalidOperationException("runtime_scheduler_overlap_allowed");
        relay.CompleteSuccess(t0.AddMilliseconds(50),first);
        if(relay.TryStart(t0.AddMilliseconds(999),out _))throw new InvalidOperationException("runtime_scheduler_early_run_invalid");
        if(!relay.TryStart(t0.AddMilliseconds(1000),out var second)||second!=t0.AddMilliseconds(1000))throw new InvalidOperationException("runtime_scheduler_one_second_cadence_invalid");
        relay.CompleteAmbiguousFailure(t0.AddMilliseconds(1100),"httprequestexception");
        if(relay.TryStart(t0.AddMilliseconds(2000),out _))throw new InvalidOperationException("runtime_scheduler_backoff_invalid");
        if(!relay.TryStart(t0.AddMilliseconds(2100),out var retry)||retry!=second)throw new InvalidOperationException("runtime_scheduler_retry_identity_invalid");
        relay.CompleteDefinitiveFailure(t0.AddMilliseconds(2150),"local_trigger_failed_503");
        if(relay.NextRunAt!=DateTimeOffset.MinValue)throw new InvalidOperationException("runtime_scheduler_definitive_failure_identity_not_rotated");
        if(relay.TryStart(t0.AddMilliseconds(4149),out _))throw new InvalidOperationException("runtime_scheduler_definitive_backoff_invalid");
        if(relay.NextRunAt!=DateTimeOffset.MinValue)throw new InvalidOperationException("runtime_scheduler_definitive_identity_assigned_before_backoff");
        if(!relay.TryStart(t0.AddMilliseconds(4150),out var rotated)||rotated!=t0.AddMilliseconds(4150)||rotated.ToUnixTimeSeconds()==second.ToUnixTimeSeconds())throw new InvalidOperationException("runtime_scheduler_definitive_identity_rotation_invalid");
        foreach(var seconds in new[]{15,30,60})if(RuntimeSchedulerPolicy.EffectiveIntervalSeconds(new ScheduledTrigger("maintenance.test",seconds))!=seconds)throw new InvalidOperationException("runtime_scheduler_slow_cadence_changed");
        if(RuntimeSchedulerPolicy.EffectiveIntervalSeconds(new ScheduledTrigger("custom.fast",1))!=5)throw new InvalidOperationException("runtime_scheduler_fast_allowlist_bypass");
        if(TriggerBudgetMilliseconds("public.relay_sync")!=4500||TriggerBudgetMilliseconds("public.projection_sync")!=15000)throw new InvalidOperationException("runtime_scheduler_budget_invalid");
        var retryAfter=FailureBackoff(1,1,"runtime-selftest",17);if(retryAfter<TimeSpan.FromSeconds(17)||retryAfter>TimeSpan.FromSeconds(18.001))throw new InvalidOperationException("runtime_scheduler_retry_after_invalid");
        var budget=new RuntimeTriggerState(new ScheduledTrigger("public.relay_sync",1));
        for(var i=0;i<RetryBudgetMaxAttempts;i++)budget.CompleteAmbiguousFailure(t0.AddSeconds(i),"httprequestexception","budget-selftest");
        if(!budget.RetryNotBefore.HasValue||budget.RetryNotBefore.Value<t0.AddSeconds(RetryBudgetWindowSeconds))throw new InvalidOperationException("runtime_scheduler_retry_budget_invalid");
        if(TickMilliseconds>100)throw new InvalidOperationException("runtime_scheduler_tick_too_slow");
    }
}

internal sealed class RuntimeTriggerState
{
    public ScheduledTrigger Trigger { get; }
    public int EffectiveIntervalSeconds { get; }
    public DateTimeOffset NextRunAt { get; private set; }=DateTimeOffset.MinValue;
    public DateTimeOffset? RetryNotBefore { get; private set; }
    public bool InFlight { get; private set; }
    public Task? InFlightTask { get; set; }
    public DateTimeOffset? LastStartedAt { get; private set; }
    public DateTimeOffset? LastCompletedAt { get; private set; }
    public int ConsecutiveFailures { get; private set; }
    public string? LastErrorCode { get; private set; }
    private DateTimeOffset RetryWindowStartedAt { get; set; }=DateTimeOffset.MinValue;
    private int RetryAttemptsInWindow { get; set; }
    public RuntimeTriggerState(ScheduledTrigger trigger){Trigger=trigger;EffectiveIntervalSeconds=RuntimeSchedulerPolicy.EffectiveIntervalSeconds(trigger);}
    public bool TryStart(DateTimeOffset now,out DateTimeOffset scheduledAt)
    {
        scheduledAt=DateTimeOffset.MinValue;
        if(InFlight)return false;
        if(RetryNotBefore.HasValue&&now<RetryNotBefore.Value)return false;
        if(NextRunAt==DateTimeOffset.MinValue)NextRunAt=now;
        if(now<NextRunAt)return false;
        InFlight=true;LastStartedAt=now;scheduledAt=NextRunAt;return true;
    }
    public void CompleteSuccess(DateTimeOffset completedAt,DateTimeOffset scheduledAt)
    {
        LastCompletedAt=completedAt;LastErrorCode=null;ConsecutiveFailures=0;RetryNotBefore=null;RetryWindowStartedAt=DateTimeOffset.MinValue;RetryAttemptsInWindow=0;
        var cadenceNext=scheduledAt.AddSeconds(EffectiveIntervalSeconds);
        NextRunAt=cadenceNext>completedAt?cadenceNext:completedAt;
        InFlight=false;InFlightTask=null;
    }
    public void CompleteAmbiguousFailure(DateTimeOffset failedAt,string code,string jitterKey="",int retryAfterSeconds=0)
    {
        LastCompletedAt=failedAt;LastErrorCode=code;ConsecutiveFailures++;
        if(RetryWindowStartedAt==DateTimeOffset.MinValue||failedAt-RetryWindowStartedAt>=TimeSpan.FromSeconds(RuntimeSchedulerPolicy.RetryBudgetWindowSeconds)){RetryWindowStartedAt=failedAt;RetryAttemptsInWindow=0;}
        RetryAttemptsInWindow++;
        var backoff=RuntimeSchedulerPolicy.FailureBackoff(EffectiveIntervalSeconds,ConsecutiveFailures,jitterKey,retryAfterSeconds);
        if(RetryAttemptsInWindow>=RuntimeSchedulerPolicy.RetryBudgetMaxAttempts){var windowEnd=RetryWindowStartedAt.AddSeconds(RuntimeSchedulerPolicy.RetryBudgetWindowSeconds);if(windowEnd>failedAt&&windowEnd-failedAt>backoff)backoff=windowEnd-failedAt;}
        RetryNotBefore=failedAt.Add(backoff);InFlight=false;InFlightTask=null;
    }
    public void CompletePolicyBlockedFailure(DateTimeOffset failedAt,string code,int minimumSeconds,string jitterKey="",int retryAfterSeconds=0)
    {
        LastCompletedAt=failedAt;LastErrorCode=code;ConsecutiveFailures++;
        var delay=RuntimeSchedulerPolicy.FailureBackoff(Math.Max(1,minimumSeconds),ConsecutiveFailures,jitterKey,Math.Max(minimumSeconds,retryAfterSeconds));
        RetryNotBefore=failedAt.Add(delay);InFlight=false;InFlightTask=null;
    }
    public void CompleteDefinitiveFailure(DateTimeOffset failedAt,string code,string jitterKey="")
    {
        LastCompletedAt=failedAt;LastErrorCode=code;ConsecutiveFailures++;NextRunAt=DateTimeOffset.MinValue;RetryNotBefore=failedAt.Add(RuntimeSchedulerPolicy.FailureBackoff(Math.Max(5,EffectiveIntervalSeconds),ConsecutiveFailures,jitterKey));InFlight=false;InFlightTask=null;
    }
    public void CompleteCancellation(){InFlight=false;InFlightTask=null;}
    public RuntimeTriggerHealth Snapshot(){DateTimeOffset? next=NextRunAt==DateTimeOffset.MinValue?null:NextRunAt;if(RetryNotBefore.HasValue&&(!next.HasValue||RetryNotBefore.Value>next.Value))next=RetryNotBefore;return new(Trigger.IntervalSeconds,EffectiveIntervalSeconds,LastStartedAt,LastCompletedAt,next,InFlight,ConsecutiveFailures,LastErrorCode);}
}

internal static class RuntimeHealthModel
{
    public static RuntimeHealthSnapshot Build(string instanceId,string runtimeVersion,DateTimeOffset startedAt,DateTimeOffset? lastCycleAt,string schedulerError,Dictionary<string,RuntimeTriggerHealth> triggers,string printStatus,DateTimeOffset? printSeen,string? printError,DateTimeOffset now)
    {
        var schedulerObserved=lastCycleAt.HasValue;
        var schedulerHealthy=schedulerObserved&&string.IsNullOrEmpty(schedulerError);
        var triggerDegraded=triggers.Values.Any(t=>!string.IsNullOrEmpty(t.last_error_code));
        var printDegraded=printStatus is "failed" or "degraded" or "stopped";
        var status=!schedulerObserved?"starting":(!schedulerHealthy||triggerDegraded||printDegraded?"degraded":"running");
        return new RuntimeHealthSnapshot(true,1,runtimeVersion,instanceId,status,startedAt,now,["scheduler","local_trigger","trigger_cadence_health","print_agent_supervision"],new RuntimeSchedulerHealth(schedulerHealthy,lastCycleAt,string.IsNullOrEmpty(schedulerError)?null:schedulerError,RuntimeSchedulerPolicy.TickMilliseconds),triggers,new Dictionary<string,RuntimeComponentHealth>{{"print_agent",new RuntimeComponentHealth(printStatus,printSeen,null,printError)}});
    }

    public static string MapServiceStatus(ServiceControllerStatus status)=>status switch
    {
        ServiceControllerStatus.Running=>"running",
        ServiceControllerStatus.Stopped=>"stopped",
        ServiceControllerStatus.StartPending or ServiceControllerStatus.ContinuePending=>"starting",
        ServiceControllerStatus.StopPending or ServiceControllerStatus.PausePending or ServiceControllerStatus.Paused=>"degraded",
        _=>"unknown"
    };

    public static void SelfTest()
    {
        var t0=new DateTimeOffset(2026,10,1,12,0,0,TimeSpan.Zero);
        var empty=new Dictionary<string,RuntimeTriggerHealth>(StringComparer.Ordinal);
        var starting=Build("runtime-selftest","1.0.3",t0,null,"",empty,"unknown",null,null,t0.AddSeconds(1));
        if(starting.status!="starting"||starting.scheduler.healthy||starting.scheduler.last_cycle_at is not null||starting.scheduler.tick_milliseconds!=RuntimeSchedulerPolicy.TickMilliseconds)throw new InvalidOperationException("runtime_health_starting_invalid");
        var cycle=t0.AddSeconds(5);
        var running=Build("runtime-selftest","1.0.3",t0,cycle,"",empty,"running",cycle,null,cycle.AddMinutes(5));
        if(running.status!="running"||!running.scheduler.healthy||running.scheduler.last_cycle_at!=cycle)throw new InvalidOperationException("runtime_health_cycle_evidence_invalid");
        var schedulerFailed=Build("runtime-selftest","1.0.3",t0,cycle,"scheduler_failed",empty,"running",cycle,null,cycle.AddSeconds(1));
        if(schedulerFailed.status!="degraded"||schedulerFailed.scheduler.last_error_code!="scheduler_failed")throw new InvalidOperationException("runtime_health_scheduler_failure_invalid");
        var failedTriggers=new Dictionary<string,RuntimeTriggerHealth>(StringComparer.Ordinal){{"public.relay_sync",new RuntimeTriggerHealth(1,1,cycle,cycle,cycle.AddSeconds(1),false,1,"httprequestexception")}};
        var triggerFailed=Build("runtime-selftest","1.0.3",t0,cycle,"",failedTriggers,"running",cycle,null,cycle.AddSeconds(1));
        if(triggerFailed.status!="degraded"||triggerFailed.triggers["public.relay_sync"].effective_interval_seconds!=1)throw new InvalidOperationException("runtime_health_trigger_failure_invalid");
        var printFailed=Build("runtime-selftest","1.0.3",t0,cycle,"",empty,"failed",cycle,"print_agent_supervision_failed",cycle.AddSeconds(1));
        if(printFailed.status!="degraded"||printFailed.supervised_components["print_agent"].error_code!="print_agent_supervision_failed")throw new InvalidOperationException("runtime_health_print_failure_invalid");
        var allowed=new HashSet<string>(["unknown","starting","running","degraded","stopped","failed","disabled"],StringComparer.Ordinal);
        foreach(ServiceControllerStatus value in Enum.GetValues<ServiceControllerStatus>())if(!allowed.Contains(MapServiceStatus(value)))throw new InvalidOperationException("runtime_health_service_mapping_invalid");
        var version=RuntimeVersionInfo.Current();if(string.IsNullOrWhiteSpace(version)||version.Length>64)throw new InvalidOperationException("runtime_health_version_invalid");
    }
}

internal sealed class RuntimeEngine : IDisposable
{
    private readonly RuntimeOptions options; private readonly string runtimeToken; private readonly string localToken;
    private readonly HttpClient http=new(new HttpClientHandler{ServerCertificateCustomValidationCallback=(m,c,ch,e)=>e==System.Net.Security.SslPolicyErrors.None}){Timeout=Timeout.InfiniteTimeSpan};
    private readonly CancellationTokenSource stop=new(); private readonly Dictionary<string,RuntimeTriggerState> triggerStates=new(StringComparer.Ordinal);
    private HttpListener? listener; private Task? healthTask; private Task? schedulerTask; private Task? supervisionTask; private DateTimeOffset startedAt=DateTimeOffset.UtcNow; private string schedulerError="";
    private readonly object stateGate=new(); private DateTimeOffset? schedulerLastCycle; private string printStatus="unknown"; private DateTimeOffset? printSeen; private string? printError; private readonly string runtimeVersion=RuntimeVersionInfo.Current(); private DateTimeOffset nextStateWriteAt=DateTimeOffset.MinValue;
    public RuntimeEngine(RuntimeOptions options){this.options=options;runtimeToken=SecretFile.Read(options.RuntimeTokenFile);localToken=SecretFile.Read(options.LocalTokenFile);Directory.CreateDirectory(options.DataRoot);Directory.CreateDirectory(Path.Combine(options.DataRoot,"logs"));foreach(var t in options.Triggers)triggerStates[t.Key]=new RuntimeTriggerState(t);}
    public void Start(){listener=new HttpListener();listener.Prefixes.Add($"http://127.0.0.1:{options.HealthPort}/");listener.Start();WriteState("starting");healthTask=Task.Run(HealthLoop);schedulerTask=Task.Run(SchedulerLoop);supervisionTask=Task.Run(PrintSupervisionLoop);SafeLog("runtime_started");}
    public async Task StopAsync(){stop.Cancel();try{listener?.Stop();}catch{}if(healthTask is not null)await Ignore(healthTask);if(schedulerTask is not null)await Ignore(schedulerTask);if(supervisionTask is not null)await Ignore(supervisionTask);Task[] active;lock(stateGate)active=triggerStates.Values.Select(s=>s.InFlightTask).Where(t=>t is not null).Cast<Task>().ToArray();if(active.Length>0)await Ignore(Task.WhenAll(active));WriteState("stopped");SafeLog("runtime_stopped");}
    private async Task HealthLoop(){while(!stop.IsCancellationRequested){HttpListenerContext? ctx=null;try{ctx=await listener!.GetContextAsync();_ = Task.Run(()=>HandleHealth(ctx));}catch when(stop.IsCancellationRequested){break;}catch{await Task.Delay(250,stop.Token).ContinueWith(_=>{});}}}
    private async Task HandleHealth(HttpListenerContext ctx){try{if(ctx.Request.Url?.AbsolutePath!="/v1/health"){ctx.Response.StatusCode=404;return;}var auth=ctx.Request.Headers["Authorization"]??"";var contract=ctx.Request.Headers["X-Sokna-Runtime-Contract"]??"";if(contract!="1"){ctx.Response.StatusCode=426;await WriteJson(ctx,new{success=false,code="runtime_contract_upgrade_required"});return;}if(!FixedEquals(auth,"Bearer "+runtimeToken)){ctx.Response.StatusCode=401;await WriteJson(ctx,new{success=false,code="unauthorized"});return;}ctx.Response.StatusCode=200;await WriteJson(ctx,HealthPayload());}catch{try{ctx.Response.StatusCode=500;}catch{}}finally{try{ctx.Response.Close();}catch{}}}
    private Dictionary<string,RuntimeTriggerHealth> TriggerHealthUnsafe()=>triggerStates.ToDictionary(k=>k.Key,v=>v.Value.Snapshot(),StringComparer.Ordinal);
    private RuntimeHealthSnapshot HealthPayload(){lock(stateGate)return RuntimeHealthModel.Build(options.InstanceId,runtimeVersion,startedAt,schedulerLastCycle,schedulerError,TriggerHealthUnsafe(),printStatus,printSeen,printError,DateTimeOffset.UtcNow);}
    private async Task SchedulerLoop()
    {
        while(!stop.IsCancellationRequested)
        {
            try
            {
                var now=DateTimeOffset.UtcNow;
                foreach(var state in triggerStates.Values)
                {
                    DateTimeOffset scheduledAt;
                    lock(stateGate){if(!state.TryStart(now,out scheduledAt))continue;}
                    var task=Task.Run(()=>ExecuteTrigger(state,scheduledAt),CancellationToken.None);
                    lock(stateGate)state.InFlightTask=task;
                }
                lock(stateGate){schedulerError="";schedulerLastCycle=now;}
                if(now>=nextStateWriteAt){WriteState();nextStateWriteAt=now.AddSeconds(1);}
            }
            catch(Exception ex)
            {
                var code=SafeCode(ex);lock(stateGate){schedulerError=code;schedulerLastCycle=DateTimeOffset.UtcNow;}SafeLog("scheduler_error "+code);WriteState();
            }
            await Task.Delay(RuntimeSchedulerPolicy.TickMilliseconds,stop.Token).ContinueWith(_=>{});
        }
    }
    private async Task ExecuteTrigger(RuntimeTriggerState state,DateTimeOffset scheduledAt)
    {
        var budgetMs=RuntimeSchedulerPolicy.TriggerBudgetMilliseconds(state.Trigger.Key);
        var retryKey=options.InstanceId+"|"+state.Trigger.Key+"|"+scheduledAt.ToUnixTimeMilliseconds();
        using var deadlineCts=CancellationTokenSource.CreateLinkedTokenSource(stop.Token);
        deadlineCts.CancelAfter(budgetMs);
        try
        {
            await SendTrigger(state.Trigger.Key,scheduledAt,budgetMs,deadlineCts.Token);
            lock(stateGate)state.CompleteSuccess(DateTimeOffset.UtcNow,scheduledAt);
        }
        catch(RuntimeTriggerHttpException ex)
        {
            var code=ex.Message;
            lock(stateGate)
            {
                if(ex.FailureClass is "overload" or "transport")state.CompleteAmbiguousFailure(DateTimeOffset.UtcNow,code,retryKey,ex.RetryAfterSeconds);
                else if(ex.FailureClass=="authentication")state.CompletePolicyBlockedFailure(DateTimeOffset.UtcNow,code,120,retryKey,ex.RetryAfterSeconds);
                else if(ex.FailureClass=="compatibility")state.CompletePolicyBlockedFailure(DateTimeOffset.UtcNow,code,60,retryKey,ex.RetryAfterSeconds);
                else state.CompleteDefinitiveFailure(DateTimeOffset.UtcNow,code,retryKey);
            }
            SafeLog("trigger_error "+state.Trigger.Key+" "+code+" class="+ex.FailureClass+" retry_after="+ex.RetryAfterSeconds);
        }
        catch(HttpRequestException ex)
        {
            var code=SafeCode(ex);lock(stateGate)state.CompleteAmbiguousFailure(DateTimeOffset.UtcNow,code,retryKey);SafeLog("trigger_error "+state.Trigger.Key+" "+code+" class=transport");
        }
        catch(TaskCanceledException) when(!stop.IsCancellationRequested&&deadlineCts.IsCancellationRequested)
        {
            const string code="trigger_deadline_exceeded";lock(stateGate)state.CompleteAmbiguousFailure(DateTimeOffset.UtcNow,code,retryKey);SafeLog("trigger_error "+state.Trigger.Key+" "+code+" class=transport");
        }
        catch(OperationCanceledException) when(stop.IsCancellationRequested)
        {
            lock(stateGate)state.CompleteCancellation();
        }
        catch(Exception ex)
        {
            var code=SafeCode(ex);lock(stateGate)state.CompleteDefinitiveFailure(DateTimeOffset.UtcNow,code,retryKey);SafeLog("trigger_error "+state.Trigger.Key+" "+code);
        }
    }
    private async Task SendTrigger(string key,DateTimeOffset scheduledAt,int budgetMs,CancellationToken cancellationToken){var identity=$"{options.InstanceId}|{key}|{scheduledAt.ToUnixTimeSeconds()}";var digest=Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(Encoding.UTF8.GetBytes(identity))).ToLowerInvariant();var requestId="rt-"+digest[..48];var correlationId="corr-"+digest[48..];var deadlineAt=DateTimeOffset.UtcNow.AddMilliseconds(budgetMs);var body=JsonSerializer.Serialize(new{request_id=requestId,runtime_instance_id=options.InstanceId,trigger_key=key,requested_at=scheduledAt,correlation_id=correlationId,budget_ms=budgetMs,deadline_at=deadlineAt});using var req=new HttpRequestMessage(HttpMethod.Post,new Uri(new Uri(options.LocalBaseUrl),"/internal/runtime/v1/trigger"));req.Headers.Authorization=new AuthenticationHeaderValue("Bearer",localToken);req.Headers.Add("X-Sokna-Runtime-Contract","1");req.Content=new StringContent(body,Encoding.UTF8,"application/json");using var response=await http.SendAsync(req,cancellationToken);if(!response.IsSuccessStatusCode){var status=(int)response.StatusCode;throw new RuntimeTriggerHttpException(status,LocalFailureClass(status),RetryAfterSeconds(response));}}
    private static string LocalFailureClass(int status)=>status switch{429 or 502 or 503 or 504 or 408=>"overload",401 or 403=>"authentication",426=>"compatibility",_ when status>=500=>"overload",_=>"domain"};
    private static int RetryAfterSeconds(HttpResponseMessage response){var retry=response.Headers.RetryAfter;if(retry?.Delta is TimeSpan delta)return Math.Clamp((int)Math.Ceiling(delta.TotalSeconds),0,300);if(retry?.Date is DateTimeOffset date)return Math.Clamp((int)Math.Ceiling((date-DateTimeOffset.UtcNow).TotalSeconds),0,300);return 0;}
    private async Task PrintSupervisionLoop(){while(!stop.IsCancellationRequested){SupervisePrintAgent();await Task.Delay(TimeSpan.FromSeconds(2),stop.Token).ContinueWith(_=>{});}}
    private void SupervisePrintAgent(){if(!options.SupervisePrintAgent){lock(stateGate){printStatus="disabled";printSeen=DateTimeOffset.UtcNow;printError=null;}return;}try{using var service=new ServiceController(options.PrintAgentServiceName);var status=service.Status;if(status==ServiceControllerStatus.Stopped){service.Start();service.WaitForStatus(ServiceControllerStatus.Running,TimeSpan.FromSeconds(15));status=service.Status;}var mapped=RuntimeHealthModel.MapServiceStatus(status);var error=mapped=="degraded"?"print_agent_service_degraded":mapped=="stopped"?"print_agent_stopped":null;lock(stateGate){printStatus=mapped;printSeen=DateTimeOffset.UtcNow;printError=error;}}catch(Exception ex){var code=SafeCode(ex);lock(stateGate){printStatus="failed";printSeen=DateTimeOffset.UtcNow;printError="print_agent_supervision_failed";}SafeLog("print_agent_supervision "+code);}}
    private void WriteState(string? statusOverride=null){try{RuntimeHealthSnapshot health;lock(stateGate)health=RuntimeHealthModel.Build(options.InstanceId,runtimeVersion,startedAt,schedulerLastCycle,schedulerError,TriggerHealthUnsafe(),printStatus,printSeen,printError,DateTimeOffset.UtcNow);var tmp=Path.Combine(options.DataRoot,"runtime-state.json.tmp");var final=Path.Combine(options.DataRoot,"runtime-state.json");File.WriteAllText(tmp,JsonSerializer.Serialize(new{format="sokna-runtime-v1",status=statusOverride??health.status,instance_id=options.InstanceId,runtime_version=runtimeVersion,updated_at=DateTimeOffset.UtcNow,scheduler_last_cycle_at=health.scheduler.last_cycle_at,scheduler_error=health.scheduler.last_error_code,scheduler_tick_milliseconds=health.scheduler.tick_milliseconds,triggers=health.triggers,print_agent_status=health.supervised_components["print_agent"].status,print_agent_last_seen_at=health.supervised_components["print_agent"].last_seen_at,print_agent_error_code=health.supervised_components["print_agent"].error_code}));File.Move(tmp,final,true);}catch{}}
    private void SafeLog(string message){try{File.AppendAllText(Path.Combine(options.DataRoot,"logs","runtime.log"),DateTimeOffset.UtcNow.ToString("O")+" "+message.Replace('\r',' ').Replace('\n',' ')+Environment.NewLine);}catch{}}
    private static string SafeCode(Exception ex)=>ex is InvalidOperationException&&ex.Message.All(c=>char.IsLetterOrDigit(c)||c is '_' or '-')?ex.Message:ex.GetType().Name.ToLowerInvariant();
    private static bool FixedEquals(string a,string b){var aa=Encoding.UTF8.GetBytes(a);var bb=Encoding.UTF8.GetBytes(b);return System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(aa,bb);}
    private static async Task WriteJson(HttpListenerContext ctx,object value){var bytes=Encoding.UTF8.GetBytes(JsonSerializer.Serialize(value));ctx.Response.ContentType="application/json; charset=utf-8";ctx.Response.ContentLength64=bytes.Length;await ctx.Response.OutputStream.WriteAsync(bytes);}
    private static async Task Ignore(Task t){try{await t;}catch{}}
    public void Dispose(){stop.Dispose();http.Dispose();listener?.Close();}
}

internal sealed class RuntimeWindowsService : ServiceBase
{
    private readonly RuntimeEngine engine; public RuntimeWindowsService(RuntimeEngine engine){this.engine=engine;ServiceName="SoknaRuntime";CanStop=true;CanShutdown=true;AutoLog=false;}
    protected override void OnStart(string[] args)=>engine.Start(); protected override void OnStop()=>engine.StopAsync().GetAwaiter().GetResult(); protected override void OnShutdown(){OnStop();base.OnShutdown();}
}

internal static class Program
{
    public static int Main(string[] args){try{if(args.Contains("--health-self-test")){RuntimeHealthModel.SelfTest();Console.WriteLine("Sokna Runtime health self-test PASS");return 0;}if(args.Contains("--scheduler-self-test")){RuntimeSchedulerPolicy.SelfTest();Console.WriteLine("Sokna Runtime scheduler self-test PASS");return 0;}var configPath=Arg(args,"--config")??throw new InvalidOperationException("runtime_config_required");var options=JsonSerializer.Deserialize<RuntimeOptions>(File.ReadAllText(configPath),new JsonSerializerOptions{PropertyNameCaseInsensitive=true})??throw new InvalidOperationException("runtime_config_invalid");Validate(options);using var engine=new RuntimeEngine(options);if(args.Contains("--self-test")){Console.WriteLine("Sokna Runtime self-test PASS");return 0;}if(args.Contains("--console")){engine.Start();Console.CancelKeyPress+=(s,e)=>{e.Cancel=true;};Thread.Sleep(Timeout.Infinite);return 0;}ServiceBase.Run(new RuntimeWindowsService(engine));return 0;}catch(Exception ex){Console.Error.WriteLine(ex.Message);return 2;}}
    private static void Validate(RuntimeOptions o){if(o.ContractVersion!=1)throw new InvalidOperationException("runtime_contract_version_invalid");if(o.InstanceId.Length<8||o.InstanceId.Length>128)throw new InvalidOperationException("runtime_instance_invalid");if(!Path.IsPathFullyQualified(o.DataRoot))throw new InvalidOperationException("runtime_data_root_invalid");if(o.HealthPort is <1024 or >65535)throw new InvalidOperationException("runtime_health_port_invalid");if(!Uri.TryCreate(o.LocalBaseUrl,UriKind.Absolute,out var uri)||!uri.IsLoopback)throw new InvalidOperationException("runtime_local_endpoint_must_be_loopback");if(uri.Scheme is not ("http" or "https")||!string.IsNullOrEmpty(uri.UserInfo)||!string.IsNullOrEmpty(uri.Query)||!string.IsNullOrEmpty(uri.Fragment)||uri.AbsolutePath!="/")throw new InvalidOperationException("runtime_local_endpoint_must_be_origin");if(uri.Port<1024||uri.Port>65535)throw new InvalidOperationException("runtime_local_endpoint_port_invalid");if(o.Triggers.Any(t=>!System.Text.RegularExpressions.Regex.IsMatch(t.Key,"^[a-z][a-z0-9_.-]{1,63}$")||t.IntervalSeconds is <1 or >86400))throw new InvalidOperationException("runtime_trigger_invalid");if(o.Triggers.Select(t=>t.Key).Distinct(StringComparer.Ordinal).Count()!=o.Triggers.Count)throw new InvalidOperationException("runtime_trigger_duplicate");}
    private static string? Arg(string[] args,string key){for(var i=0;i<args.Length-1;i++)if(string.Equals(args[i],key,StringComparison.OrdinalIgnoreCase))return args[i+1];return null;}
}
