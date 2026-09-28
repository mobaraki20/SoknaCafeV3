using System.Diagnostics;
using System.Net;
using System.Net.Http.Headers;
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

internal sealed class RuntimeEngine : IDisposable
{
    private readonly RuntimeOptions options; private readonly string runtimeToken; private readonly string localToken;
    private readonly HttpClient http=new(new HttpClientHandler{ServerCertificateCustomValidationCallback=(m,c,ch,e)=>e==System.Net.Security.SslPolicyErrors.None});
    private readonly CancellationTokenSource stop=new(); private readonly Dictionary<string,DateTimeOffset> nextRun=new(StringComparer.Ordinal);
    private HttpListener? listener; private Task? healthTask; private Task? schedulerTask; private DateTimeOffset startedAt=DateTimeOffset.UtcNow; private string schedulerError="";
    private readonly object stateGate=new(); private string printStatus="unknown"; private DateTimeOffset? printSeen;
    public RuntimeEngine(RuntimeOptions options){this.options=options;runtimeToken=SecretFile.Read(options.RuntimeTokenFile);localToken=SecretFile.Read(options.LocalTokenFile);Directory.CreateDirectory(options.DataRoot);Directory.CreateDirectory(Path.Combine(options.DataRoot,"logs"));foreach(var t in options.Triggers)nextRun[t.Key]=DateTimeOffset.MinValue;}
    public void Start(){listener=new HttpListener();listener.Prefixes.Add($"http://127.0.0.1:{options.HealthPort}/");listener.Start();healthTask=Task.Run(HealthLoop);schedulerTask=Task.Run(SchedulerLoop);SafeLog("runtime_started");}
    public async Task StopAsync(){stop.Cancel();try{listener?.Stop();}catch{}if(healthTask is not null)await Ignore(healthTask);if(schedulerTask is not null)await Ignore(schedulerTask);WriteState("stopped");SafeLog("runtime_stopped");}
    private async Task HealthLoop(){while(!stop.IsCancellationRequested){HttpListenerContext? ctx=null;try{ctx=await listener!.GetContextAsync();_ = Task.Run(()=>HandleHealth(ctx));}catch when(stop.IsCancellationRequested){break;}catch{await Task.Delay(250,stop.Token).ContinueWith(_=>{});}}}
    private async Task HandleHealth(HttpListenerContext ctx){try{if(ctx.Request.Url?.AbsolutePath!="/v1/health"){ctx.Response.StatusCode=404;return;}var auth=ctx.Request.Headers["Authorization"]??"";var contract=ctx.Request.Headers["X-Sokna-Runtime-Contract"]??"";if(contract!="1"){ctx.Response.StatusCode=426;await WriteJson(ctx,new{success=false,code="runtime_contract_upgrade_required"});return;}if(!FixedEquals(auth,"Bearer "+runtimeToken)){ctx.Response.StatusCode=401;await WriteJson(ctx,new{success=false,code="unauthorized"});return;}ctx.Response.StatusCode=200;await WriteJson(ctx,HealthPayload());}catch{try{ctx.Response.StatusCode=500;}catch{}}finally{try{ctx.Response.Close();}catch{}}}
    private object HealthPayload(){lock(stateGate)return new{success=true,contract_version=1,runtime_version="1.0.0",instance_id=options.InstanceId,status=string.IsNullOrEmpty(schedulerError)?"running":"degraded",started_at=startedAt,last_seen_at=DateTimeOffset.UtcNow,capabilities=new[]{"scheduler","local_trigger","print_agent_supervision"},scheduler=new{healthy=string.IsNullOrEmpty(schedulerError),last_cycle_at=DateTimeOffset.UtcNow,last_error_code=string.IsNullOrEmpty(schedulerError)?null:schedulerError},supervised_components=new Dictionary<string,object>{{"print_agent",new{status=printStatus,last_seen_at=printSeen,version=(string?)null,error_code=(string?)null}}}};}
    private async Task SchedulerLoop(){while(!stop.IsCancellationRequested){try{var now=DateTimeOffset.UtcNow;foreach(var trigger in options.Triggers){if(now<(nextRun.TryGetValue(trigger.Key,out var due)?due:DateTimeOffset.MinValue))continue;await SendTrigger(trigger.Key,now);nextRun[trigger.Key]=now.AddSeconds(Math.Max(5,trigger.IntervalSeconds));}SupervisePrintAgent();lock(stateGate)schedulerError="";WriteState("running");}catch(Exception ex){lock(stateGate)schedulerError=SafeCode(ex);SafeLog("scheduler_error "+SafeCode(ex));}await Task.Delay(TimeSpan.FromSeconds(2),stop.Token).ContinueWith(_=>{});}}
    private async Task SendTrigger(string key,DateTimeOffset now){var requestId=$"rt-{options.InstanceId}-{key}-{now.ToUnixTimeSeconds()}";if(requestId.Length>96)requestId=requestId[..96];var body=JsonSerializer.Serialize(new{request_id=requestId,runtime_instance_id=options.InstanceId,trigger_key=key,requested_at=now,correlation_id=Guid.NewGuid().ToString("N")});using var req=new HttpRequestMessage(HttpMethod.Post,new Uri(new Uri(options.LocalBaseUrl),"/internal/runtime/v1/trigger"));req.Headers.Authorization=new AuthenticationHeaderValue("Bearer",localToken);req.Headers.Add("X-Sokna-Runtime-Contract","1");req.Content=new StringContent(body,Encoding.UTF8,"application/json");using var response=await http.SendAsync(req,stop.Token);if((int)response.StatusCode==426)throw new InvalidOperationException("local_contract_upgrade_required");if(!response.IsSuccessStatusCode)throw new InvalidOperationException("local_trigger_failed_"+(int)response.StatusCode);}
    private void SupervisePrintAgent(){if(!options.SupervisePrintAgent){lock(stateGate){printStatus="disabled";printSeen=DateTimeOffset.UtcNow;}return;}try{using var service=new ServiceController(options.PrintAgentServiceName);var status=service.Status;if(status==ServiceControllerStatus.Stopped){service.Start();service.WaitForStatus(ServiceControllerStatus.Running,TimeSpan.FromSeconds(15));status=service.Status;}lock(stateGate){printStatus=status==ServiceControllerStatus.Running?"running":status.ToString().ToLowerInvariant();printSeen=DateTimeOffset.UtcNow;}}catch(Exception ex){lock(stateGate){printStatus="failed";printSeen=DateTimeOffset.UtcNow;}SafeLog("print_agent_supervision "+SafeCode(ex));}}
    private void WriteState(string status){try{var tmp=Path.Combine(options.DataRoot,"runtime-state.json.tmp");var final=Path.Combine(options.DataRoot,"runtime-state.json");File.WriteAllText(tmp,JsonSerializer.Serialize(new{format="sokna-runtime-v1",status,instance_id=options.InstanceId,updated_at=DateTimeOffset.UtcNow,scheduler_error=string.IsNullOrEmpty(schedulerError)?null:schedulerError,print_agent_status=printStatus}));File.Move(tmp,final,true);}catch{}}
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
    public static int Main(string[] args){try{var configPath=Arg(args,"--config")??throw new InvalidOperationException("runtime_config_required");var options=JsonSerializer.Deserialize<RuntimeOptions>(File.ReadAllText(configPath),new JsonSerializerOptions{PropertyNameCaseInsensitive=true})??throw new InvalidOperationException("runtime_config_invalid");Validate(options);using var engine=new RuntimeEngine(options);if(args.Contains("--self-test")){Console.WriteLine("Sokna Runtime self-test PASS");return 0;}if(args.Contains("--console")){engine.Start();Console.CancelKeyPress+=(s,e)=>{e.Cancel=true;};Thread.Sleep(Timeout.Infinite);return 0;}ServiceBase.Run(new RuntimeWindowsService(engine));return 0;}catch(Exception ex){Console.Error.WriteLine(ex.Message);return 2;}}
    private static void Validate(RuntimeOptions o){if(o.ContractVersion!=1)throw new InvalidOperationException("runtime_contract_version_invalid");if(o.InstanceId.Length<8||o.InstanceId.Length>128)throw new InvalidOperationException("runtime_instance_invalid");if(!Path.IsPathFullyQualified(o.DataRoot))throw new InvalidOperationException("runtime_data_root_invalid");if(o.HealthPort is <1024 or >65535)throw new InvalidOperationException("runtime_health_port_invalid");if(!Uri.TryCreate(o.LocalBaseUrl,UriKind.Absolute,out var uri)||!uri.IsLoopback)throw new InvalidOperationException("runtime_local_endpoint_must_be_loopback");if(uri.Scheme is not ("http" or "https")||!string.IsNullOrEmpty(uri.UserInfo)||!string.IsNullOrEmpty(uri.Query)||!string.IsNullOrEmpty(uri.Fragment)||uri.AbsolutePath!="/")throw new InvalidOperationException("runtime_local_endpoint_must_be_origin");if(o.Triggers.Any(t=>!System.Text.RegularExpressions.Regex.IsMatch(t.Key,"^[a-z][a-z0-9_.-]{1,63}$")))throw new InvalidOperationException("runtime_trigger_invalid");}
    private static string? Arg(string[] args,string key){for(var i=0;i<args.Length-1;i++)if(string.Equals(args[i],key,StringComparison.OrdinalIgnoreCase))return args[i+1];return null;}
}
