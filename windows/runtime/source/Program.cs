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

internal sealed record RuntimeSchedulerHealth(bool healthy,DateTimeOffset? last_cycle_at,string? last_error_code);
internal sealed record RuntimeComponentHealth(string status,DateTimeOffset? last_seen_at,string? version,string? error_code);
internal sealed record RuntimeHealthSnapshot(bool success,int contract_version,string runtime_version,string instance_id,string status,DateTimeOffset started_at,DateTimeOffset last_seen_at,string[] capabilities,RuntimeSchedulerHealth scheduler,Dictionary<string,RuntimeComponentHealth> supervised_components);

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

internal static class RuntimeHealthModel
{
    public static RuntimeHealthSnapshot Build(string instanceId,string runtimeVersion,DateTimeOffset startedAt,DateTimeOffset? lastCycleAt,string schedulerError,string printStatus,DateTimeOffset? printSeen,string? printError,DateTimeOffset now)
    {
        var schedulerObserved=lastCycleAt.HasValue;
        var schedulerHealthy=schedulerObserved&&string.IsNullOrEmpty(schedulerError);
        var printDegraded=printStatus is "failed" or "degraded" or "stopped";
        var status=!schedulerObserved?"starting":(!schedulerHealthy||printDegraded?"degraded":"running");
        return new RuntimeHealthSnapshot(true,1,runtimeVersion,instanceId,status,startedAt,now,["scheduler","local_trigger","print_agent_supervision"],new RuntimeSchedulerHealth(schedulerHealthy,lastCycleAt,string.IsNullOrEmpty(schedulerError)?null:schedulerError),new Dictionary<string,RuntimeComponentHealth>{{"print_agent",new RuntimeComponentHealth(printStatus,printSeen,null,printError)}});
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
        var starting=Build("runtime-selftest","1.0.1",t0,null,"","unknown",null,null,t0.AddSeconds(1));
        if(starting.status!="starting"||starting.scheduler.healthy||starting.scheduler.last_cycle_at is not null)throw new InvalidOperationException("runtime_health_starting_invalid");
        var cycle=t0.AddSeconds(5);
        var running=Build("runtime-selftest","1.0.1",t0,cycle,"","running",cycle,null,cycle.AddMinutes(5));
        if(running.status!="running"||!running.scheduler.healthy||running.scheduler.last_cycle_at!=cycle)throw new InvalidOperationException("runtime_health_cycle_evidence_invalid");
        var schedulerFailed=Build("runtime-selftest","1.0.1",t0,cycle,"local_trigger_failed_503","running",cycle,null,cycle.AddSeconds(1));
        if(schedulerFailed.status!="degraded"||schedulerFailed.scheduler.last_error_code!="local_trigger_failed_503")throw new InvalidOperationException("runtime_health_scheduler_failure_invalid");
        var printFailed=Build("runtime-selftest","1.0.1",t0,cycle,"","failed",cycle,"print_agent_supervision_failed",cycle.AddSeconds(1));
        if(printFailed.status!="degraded"||printFailed.supervised_components["print_agent"].error_code!="print_agent_supervision_failed")throw new InvalidOperationException("runtime_health_print_failure_invalid");
        var allowed=new HashSet<string>(["unknown","starting","running","degraded","stopped","failed","disabled"],StringComparer.Ordinal);
        foreach(ServiceControllerStatus value in Enum.GetValues<ServiceControllerStatus>())if(!allowed.Contains(MapServiceStatus(value)))throw new InvalidOperationException("runtime_health_service_mapping_invalid");
        var version=RuntimeVersionInfo.Current();if(string.IsNullOrWhiteSpace(version)||version.Length>64)throw new InvalidOperationException("runtime_health_version_invalid");
    }
}

internal sealed class RuntimeEngine : IDisposable
{
    private readonly RuntimeOptions options; private readonly string runtimeToken; private readonly string localToken;
    private readonly HttpClient http=new(new HttpClientHandler{ServerCertificateCustomValidationCallback=(m,c,ch,e)=>e==System.Net.Security.SslPolicyErrors.None});
    private readonly CancellationTokenSource stop=new(); private readonly Dictionary<string,DateTimeOffset> nextRun=new(StringComparer.Ordinal);
    private HttpListener? listener; private Task? healthTask; private Task? schedulerTask; private DateTimeOffset startedAt=DateTimeOffset.UtcNow; private string schedulerError="";
    private readonly object stateGate=new(); private DateTimeOffset? schedulerLastCycle; private string printStatus="unknown"; private DateTimeOffset? printSeen; private string? printError; private readonly string runtimeVersion=RuntimeVersionInfo.Current();
    public RuntimeEngine(RuntimeOptions options){this.options=options;runtimeToken=SecretFile.Read(options.RuntimeTokenFile);localToken=SecretFile.Read(options.LocalTokenFile);Directory.CreateDirectory(options.DataRoot);Directory.CreateDirectory(Path.Combine(options.DataRoot,"logs"));foreach(var t in options.Triggers)nextRun[t.Key]=DateTimeOffset.MinValue;}
    public void Start(){listener=new HttpListener();listener.Prefixes.Add($"http://127.0.0.1:{options.HealthPort}/");listener.Start();WriteState("starting");healthTask=Task.Run(HealthLoop);schedulerTask=Task.Run(SchedulerLoop);SafeLog("runtime_started");}
    public async Task StopAsync(){stop.Cancel();try{listener?.Stop();}catch{}if(healthTask is not null)await Ignore(healthTask);if(schedulerTask is not null)await Ignore(schedulerTask);WriteState("stopped");SafeLog("runtime_stopped");}
    private async Task HealthLoop(){while(!stop.IsCancellationRequested){HttpListenerContext? ctx=null;try{ctx=await listener!.GetContextAsync();_ = Task.Run(()=>HandleHealth(ctx));}catch when(stop.IsCancellationRequested){break;}catch{await Task.Delay(250,stop.Token).ContinueWith(_=>{});}}}
    private async Task HandleHealth(HttpListenerContext ctx){try{if(ctx.Request.Url?.AbsolutePath!="/v1/health"){ctx.Response.StatusCode=404;return;}var auth=ctx.Request.Headers["Authorization"]??"";var contract=ctx.Request.Headers["X-Sokna-Runtime-Contract"]??"";if(contract!="1"){ctx.Response.StatusCode=426;await WriteJson(ctx,new{success=false,code="runtime_contract_upgrade_required"});return;}if(!FixedEquals(auth,"Bearer "+runtimeToken)){ctx.Response.StatusCode=401;await WriteJson(ctx,new{success=false,code="unauthorized"});return;}ctx.Response.StatusCode=200;await WriteJson(ctx,HealthPayload());}catch{try{ctx.Response.StatusCode=500;}catch{}}finally{try{ctx.Response.Close();}catch{}}}
    private RuntimeHealthSnapshot HealthPayload(){lock(stateGate)return RuntimeHealthModel.Build(options.InstanceId,runtimeVersion,startedAt,schedulerLastCycle,schedulerError,printStatus,printSeen,printError,DateTimeOffset.UtcNow);}
    private async Task SchedulerLoop(){while(!stop.IsCancellationRequested){try{var now=DateTimeOffset.UtcNow;foreach(var trigger in options.Triggers){var due=nextRun.TryGetValue(trigger.Key,out var scheduled)?scheduled:DateTimeOffset.MinValue;if(due==DateTimeOffset.MinValue){due=now;nextRun[trigger.Key]=due;}if(now<due)continue;try{await SendTrigger(trigger.Key,due);}catch(HttpRequestException){throw;}catch(TaskCanceledException) when(!stop.IsCancellationRequested){throw;}catch{nextRun[trigger.Key]=DateTimeOffset.MinValue;throw;}nextRun[trigger.Key]=DateTimeOffset.UtcNow.AddSeconds(Math.Max(5,trigger.IntervalSeconds));}SupervisePrintAgent();lock(stateGate){schedulerError="";schedulerLastCycle=DateTimeOffset.UtcNow;}WriteState();}catch(Exception ex){var code=SafeCode(ex);lock(stateGate){schedulerError=code;schedulerLastCycle=DateTimeOffset.UtcNow;}SafeLog("scheduler_error "+code);WriteState();}await Task.Delay(TimeSpan.FromSeconds(2),stop.Token).ContinueWith(_=>{});}}
    private async Task SendTrigger(string key,DateTimeOffset scheduledAt){var identity=$"{options.InstanceId}|{key}|{scheduledAt.ToUnixTimeSeconds()}";var digest=Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(Encoding.UTF8.GetBytes(identity))).ToLowerInvariant();var requestId="rt-"+digest[..48];var correlationId="corr-"+digest[48..];var body=JsonSerializer.Serialize(new{request_id=requestId,runtime_instance_id=options.InstanceId,trigger_key=key,requested_at=scheduledAt,correlation_id=correlationId});using var req=new HttpRequestMessage(HttpMethod.Post,new Uri(new Uri(options.LocalBaseUrl),"/internal/runtime/v1/trigger"));req.Headers.Authorization=new AuthenticationHeaderValue("Bearer",localToken);req.Headers.Add("X-Sokna-Runtime-Contract","1");req.Content=new StringContent(body,Encoding.UTF8,"application/json");using var response=await http.SendAsync(req,stop.Token);if((int)response.StatusCode==426)throw new InvalidOperationException("local_contract_upgrade_required");if(!response.IsSuccessStatusCode)throw new InvalidOperationException("local_trigger_failed_"+(int)response.StatusCode);}
    private void SupervisePrintAgent(){if(!options.SupervisePrintAgent){lock(stateGate){printStatus="disabled";printSeen=DateTimeOffset.UtcNow;printError=null;}return;}try{using var service=new ServiceController(options.PrintAgentServiceName);var status=service.Status;if(status==ServiceControllerStatus.Stopped){service.Start();service.WaitForStatus(ServiceControllerStatus.Running,TimeSpan.FromSeconds(15));status=service.Status;}var mapped=RuntimeHealthModel.MapServiceStatus(status);var error=mapped=="degraded"?"print_agent_service_degraded":mapped=="stopped"?"print_agent_stopped":null;lock(stateGate){printStatus=mapped;printSeen=DateTimeOffset.UtcNow;printError=error;}}catch(Exception ex){var code=SafeCode(ex);lock(stateGate){printStatus="failed";printSeen=DateTimeOffset.UtcNow;printError="print_agent_supervision_failed";}SafeLog("print_agent_supervision "+code);}}
    private void WriteState(string? statusOverride=null){try{RuntimeHealthSnapshot health;lock(stateGate)health=RuntimeHealthModel.Build(options.InstanceId,runtimeVersion,startedAt,schedulerLastCycle,schedulerError,printStatus,printSeen,printError,DateTimeOffset.UtcNow);var tmp=Path.Combine(options.DataRoot,"runtime-state.json.tmp");var final=Path.Combine(options.DataRoot,"runtime-state.json");File.WriteAllText(tmp,JsonSerializer.Serialize(new{format="sokna-runtime-v1",status=statusOverride??health.status,instance_id=options.InstanceId,runtime_version=runtimeVersion,updated_at=DateTimeOffset.UtcNow,scheduler_last_cycle_at=health.scheduler.last_cycle_at,scheduler_error=health.scheduler.last_error_code,print_agent_status=health.supervised_components["print_agent"].status,print_agent_last_seen_at=health.supervised_components["print_agent"].last_seen_at,print_agent_error_code=health.supervised_components["print_agent"].error_code}));File.Move(tmp,final,true);}catch{}}
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
    public static int Main(string[] args){try{if(args.Contains("--health-self-test")){RuntimeHealthModel.SelfTest();Console.WriteLine("Sokna Runtime health self-test PASS");return 0;}var configPath=Arg(args,"--config")??throw new InvalidOperationException("runtime_config_required");var options=JsonSerializer.Deserialize<RuntimeOptions>(File.ReadAllText(configPath),new JsonSerializerOptions{PropertyNameCaseInsensitive=true})??throw new InvalidOperationException("runtime_config_invalid");Validate(options);using var engine=new RuntimeEngine(options);if(args.Contains("--self-test")){Console.WriteLine("Sokna Runtime self-test PASS");return 0;}if(args.Contains("--console")){engine.Start();Console.CancelKeyPress+=(s,e)=>{e.Cancel=true;};Thread.Sleep(Timeout.Infinite);return 0;}ServiceBase.Run(new RuntimeWindowsService(engine));return 0;}catch(Exception ex){Console.Error.WriteLine(ex.Message);return 2;}}
    private static void Validate(RuntimeOptions o){if(o.ContractVersion!=1)throw new InvalidOperationException("runtime_contract_version_invalid");if(o.InstanceId.Length<8||o.InstanceId.Length>128)throw new InvalidOperationException("runtime_instance_invalid");if(!Path.IsPathFullyQualified(o.DataRoot))throw new InvalidOperationException("runtime_data_root_invalid");if(o.HealthPort is <1024 or >65535)throw new InvalidOperationException("runtime_health_port_invalid");if(!Uri.TryCreate(o.LocalBaseUrl,UriKind.Absolute,out var uri)||!uri.IsLoopback)throw new InvalidOperationException("runtime_local_endpoint_must_be_loopback");if(uri.Scheme is not ("http" or "https")||!string.IsNullOrEmpty(uri.UserInfo)||!string.IsNullOrEmpty(uri.Query)||!string.IsNullOrEmpty(uri.Fragment)||uri.AbsolutePath!="/")throw new InvalidOperationException("runtime_local_endpoint_must_be_origin");if(uri.Port<1024||uri.Port>65535)throw new InvalidOperationException("runtime_local_endpoint_port_invalid");if(o.Triggers.Any(t=>!System.Text.RegularExpressions.Regex.IsMatch(t.Key,"^[a-z][a-z0-9_.-]{1,63}$")))throw new InvalidOperationException("runtime_trigger_invalid");}
    private static string? Arg(string[] args,string key){for(var i=0;i<args.Length-1;i++)if(string.Equals(args[i],key,StringComparison.OrdinalIgnoreCase))return args[i+1];return null;}
}
