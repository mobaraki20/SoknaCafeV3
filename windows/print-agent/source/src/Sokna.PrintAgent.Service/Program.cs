using System.Text.Json;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Sokna.PrintAgent.Core;
using Sokna.PrintAgent.Service;

var provisionIndex=Array.FindIndex(args,a=>string.Equals(a,"--provision-file",StringComparison.OrdinalIgnoreCase));
if(provisionIndex>=0)
{
    if(!OperatingSystem.IsWindows())throw new PlatformNotSupportedException("Print Worker provisioning requires Windows DPAPI.");
    if(provisionIndex+1>=args.Length||string.IsNullOrWhiteSpace(args[provisionIndex+1]))throw new ArgumentException("Provision file path is required.");
    var provisionFile=Path.GetFullPath(args[provisionIndex+1]);
    using var doc=JsonDocument.Parse(File.ReadAllText(provisionFile));
    var root=doc.RootElement;
    var serverBaseUrl=root.GetProperty("server_base_url").GetString()?.Trim()??"";
    var token=root.GetProperty("token").GetString()?.Trim()??"";
    var agentName=root.TryGetProperty("agent_name",out var nameElement)?nameElement.GetString()?.Trim():null;
    var allowedOrigin=root.TryGetProperty("local_bridge_allowed_origin",out var originElement)?originElement.GetString()?.Trim():null;
    var provisionPaths=AgentPaths.Default();
    provisionPaths.EnsureDirectories();
    var options=File.Exists(provisionPaths.ConfigPath)?AgentOptions.Load(provisionPaths.ConfigPath):new AgentOptions();
    options=options with
    {
        ServerBaseUrl=serverBaseUrl,
        AgentName=string.IsNullOrWhiteSpace(agentName)?Environment.MachineName:agentName,
        LocalBridgeAllowedOrigin=allowedOrigin??serverBaseUrl
    };
    options.Validate();
    options.Save(provisionPaths.ConfigPath);
    SecretStore.Save(provisionPaths.SecretPath,token);
    Console.WriteLine(JsonSerializer.Serialize(new {ok=true,component="sokna-print-worker",configured=true}));
    return;
}

AgentPaths? paths=null;
try
{
    var builder=Host.CreateApplicationBuilder(args);
    builder.Services.AddWindowsService(options=>options.ServiceName="SoknaPrintWorker");
    paths=AgentPaths.Default();
    paths.EnsureDirectories();

    var preparation=await QueueDatabaseBootstrap.PrepareAsync(paths.DatabasePath);
    if(preparation.ReinitializedLegacyEmptyDatabase)
    {
        new AgentLog(paths.LogsPath).Warn("queue_db_legacy_empty_reinitialized",$"Legacy empty queue database was preserved as backup: {preparation.BackupPath}");
    }

    builder.Services.AddSingleton(paths);
    builder.Services.AddSingleton(sp=>new LocalQueueStore(paths.DatabasePath));
    builder.Services.AddSingleton<IPrinterHealthProvider,WindowsPrinterHealthProvider>();
    builder.Services.AddSingleton<IAgentTimeSource,SystemAgentTimeSource>();
    builder.Services.AddSingleton<PrinterHealthState>();
    builder.Services.AddSingleton<IPrinterHealthReader>(sp=>sp.GetRequiredService<PrinterHealthState>());
    builder.Services.AddSingleton(sp=>new AgentLog(paths.LogsPath));
    builder.Services.AddSingleton<PrintWakeSignal>();
    builder.Services.AddSingleton<BridgeRuntimeState>();
    builder.Services.AddSingleton<ReportDeliveryPolicy>();
    builder.Services.AddSingleton<ReportDispatcher>();
    builder.Services.AddSingleton<DurableMutationRequestStore>();
    builder.Services.AddSingleton<IWorkerProcessFactory,SystemWorkerProcessFactory>();
    builder.Services.AddSingleton<WorkerSupervisor>();
    builder.Services.AddSingleton<IPreviewExecutor,SystemPreviewExecutor>();
    builder.Services.AddSingleton(sp=>new PreviewScheduler(sp.GetRequiredService<IPreviewExecutor>()));
    builder.Services.AddHostedService<PrinterDiscoveryService>();
    builder.Services.AddHostedService<PrintAgentService>();
    builder.Services.AddHostedService<LocalBridgeService>();
    builder.Services.AddHostedService<WorkerEvidenceJanitor>();
    await builder.Build().RunAsync();
}
catch(Exception e)
{
    try
    {
        paths??=AgentPaths.Default();
        paths.EnsureDirectories();
        var safe=SafeLogText.Sanitize($"{e.GetType().Name}: {e.Message}",900);
        var diagnostic=new
        {
            timestamp_utc=DateTimeOffset.UtcNow.ToString("O"),
            stage="service_startup",
            exception_type=e.GetType().FullName,
            message=safe,
            database_path=paths.DatabasePath
        };
        var json=JsonSerializer.Serialize(diagnostic,new JsonSerializerOptions{WriteIndented=true});
        File.WriteAllText(Path.Combine(paths.LogsPath,"startup-fatal.json"),json);
        File.AppendAllText(Path.Combine(paths.LogsPath,$"agent-{DateTime.UtcNow:yyyyMMdd}.log"),$"{DateTimeOffset.UtcNow:O}\tERROR\tservice_startup\t{safe}{Environment.NewLine}");
    }
    catch
    {
    }
    throw;
}
