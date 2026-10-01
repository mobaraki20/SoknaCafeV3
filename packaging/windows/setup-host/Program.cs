using System.Diagnostics;
using System.Net;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.SetupHost;

internal sealed class SetupPlan
{
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("mode")] public string Mode { get; init; } = "";
    [JsonPropertyName("shell_root")] public string ShellRoot { get; init; } = "";
    [JsonPropertyName("install_root")] public string InstallRoot { get; init; } = "";
    [JsonPropertyName("data_root")] public string DataRoot { get; init; } = "";
    [JsonPropertyName("pairing_file")] public string PairingFile { get; init; } = "";
    [JsonPropertyName("pairing_code")] public string PairingCode { get; init; } = "";
    [JsonPropertyName("pairing_base_url")] public string PairingBaseUrl { get; init; } = "";
    [JsonPropertyName("start_when_paired")] public bool StartWhenPaired { get; init; } = true;
}

internal sealed class PayloadManifest
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("package_version")] public string PackageVersion { get; init; } = "";
    [JsonPropertyName("source_git_sha")] public string SourceGitSha { get; init; } = "";
    [JsonPropertyName("ownership")] public string Ownership { get; init; } = "";
    [JsonPropertyName("components")] public Dictionary<string, PayloadComponent> Components { get; init; } = [];
    [JsonPropertyName("files")] public List<PayloadFile> Files { get; init; } = [];
}

internal sealed class PayloadComponent
{
    [JsonPropertyName("version")] public string Version { get; init; } = "";
    [JsonPropertyName("contracts")] public Dictionary<string, JsonElement> Contracts { get; init; } = [];
}

internal sealed class PayloadFile
{
    [JsonPropertyName("path")] public string Path { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
}

internal sealed class CompatibilityManifest
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("components")] public Dictionary<string, CompatibilityComponent> Components { get; init; } = [];
}

internal sealed class CompatibilityComponent
{
    [JsonPropertyName("version")] public string Version { get; init; } = "";
}


internal sealed class WindowsServicesPairingBundle
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("local_base_url")] public string LocalBaseUrl { get; init; } = "";
    [JsonPropertyName("local_bridge_allowed_origin")] public string LocalBridgeAllowedOrigin { get; init; } = "";
    [JsonPropertyName("runtime_token")] public string RuntimeToken { get; init; } = "";
    [JsonPropertyName("local_token")] public string LocalToken { get; init; } = "";
    [JsonPropertyName("print_agent_token")] public string PrintAgentToken { get; init; } = "";
    [JsonPropertyName("runtime_triggers")] public List<PairingRuntimeTrigger> RuntimeTriggers { get; init; } = [];
}

internal sealed class PairingRuntimeTrigger
{
    [JsonPropertyName("key")] public string Key { get; init; } = "";
    [JsonPropertyName("intervalSeconds")] public int IntervalSeconds { get; init; }
}

internal sealed class PairingClient : IDisposable
{
    private readonly HttpClient http;
    private readonly Uri endpoint;
    private readonly string code;
    private static readonly JsonSerializerOptions Json = new() { PropertyNameCaseInsensitive = false };

    public PairingClient(string baseUrl,string pairingCode,HttpMessageHandler? handler=null)
    {
        endpoint = new Uri(ValidateLoopbackOrigin(baseUrl), "internal/windows-services/v1/pairing.php");
        code=(pairingCode??"").Trim();
        if(!System.Text.RegularExpressions.Regex.IsMatch(code,"^ws1_[a-f0-9]{24}_[a-f0-9]{48}$"))
            throw new PlanException("کد اتصال Windows Services معتبر نیست.");
        http=handler is null?new HttpClient():new HttpClient(handler,false);
        http.Timeout=TimeSpan.FromSeconds(10);
    }

    public WindowsServicesPairingBundle Exchange()
    {
        var root=Post("exchange");
        if(!root.TryGetProperty("bundle",out var bundleElement))throw new PlanException("Local Web بسته اتصال Windows Services را برنگرداند.");
        var bundle=bundleElement.Deserialize<WindowsServicesPairingBundle>(Json)??throw new PlanException("بسته اتصال Windows Services قابل خواندن نیست.");
        ValidateBundle(bundle);
        return bundle;
    }

    public void Confirm()
    {
        _=PostWithRetry("confirm",3);
    }

    public void CancelBestEffort()
    {
        try{_=PostWithRetry("cancel",2);}catch{}
    }

    private JsonElement PostWithRetry(string action,int attempts)
    {
        Exception? last=null;
        for(var i=0;i<attempts;i++)
        {
            try{return Post(action);}
            catch(Exception e){last=e;if(i+1<attempts)Thread.Sleep(250*(i+1));}
        }
        throw last??new PlanException("پاسخ Local Web برای Pairing دریافت نشد.");
    }

    private JsonElement Post(string action)
    {
        using var req=new HttpRequestMessage(HttpMethod.Post,endpoint);
        req.Headers.Add("X-Sokna-Windows-Services-Pairing","1");
        req.Content=new StringContent(JsonSerializer.Serialize(new{action,pairing_code=code}),System.Text.Encoding.UTF8,"application/json");
        using var response=http.Send(req);
        var bytes=response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult();
        if(bytes.Length>65536)throw new PlanException("پاسخ Pairing بیش از حد بزرگ است.");
        JsonDocument doc;
        try{doc=JsonDocument.Parse(bytes);}catch{throw new PlanException("پاسخ Pairing از Local Web معتبر نیست.");}
        using(doc)
        {
            var root=doc.RootElement.Clone();
            if(!response.IsSuccessStatusCode)
            {
                var errorCode=root.TryGetProperty("code",out var ce)?SafeCode(ce.GetString()):"pairing_http_"+(int)response.StatusCode;
                throw new PlanException("Local Web اتصال Windows Services را نپذیرفت ("+errorCode+").");
            }
            if(!root.TryGetProperty("success",out var success)||success.ValueKind!=JsonValueKind.True)
                throw new PlanException("Local Web پاسخ موفق Pairing را تأیید نکرد.");
            return root;
        }
    }

    private void ValidateBundle(WindowsServicesPairingBundle p)
    {
        if(p.Format!="sokna-windows-services-pairing-v1"||p.SchemaVersion!=1)throw new PlanException("نسخه بسته Pairing پشتیبانی نمی‌شود.");
        var local=ValidateLoopbackOrigin(p.LocalBaseUrl);
        var bridge=ValidateLoopbackOrigin(p.LocalBridgeAllowedOrigin);
        if(!string.Equals(local.GetLeftPart(UriPartial.Authority),bridge.GetLeftPart(UriPartial.Authority),StringComparison.OrdinalIgnoreCase)||
           !string.Equals(local.GetLeftPart(UriPartial.Authority),endpoint.GetLeftPart(UriPartial.Authority),StringComparison.OrdinalIgnoreCase))
            throw new PlanException("Origin بسته Pairing با Local Web یکسان نیست.");
        foreach(var value in new[]{p.RuntimeToken,p.LocalToken,p.PrintAgentToken})
            if(string.IsNullOrWhiteSpace(value)||value.Length<32||value.Length>512||value.StartsWith("REPLACE_",StringComparison.OrdinalIgnoreCase))
                throw new PlanException("یکی از secretهای Pairing معتبر نیست.");
        if(p.RuntimeTriggers.Count is <1 or >64)throw new PlanException("فهرست triggerهای Runtime معتبر نیست.");
        foreach(var trigger in p.RuntimeTriggers)
            if(!System.Text.RegularExpressions.Regex.IsMatch(trigger.Key??"","^[a-z][a-z0-9_.-]{1,63}$")||trigger.IntervalSeconds is <5 or >86400)
                throw new PlanException("یکی از triggerهای Runtime معتبر نیست.");
    }

    internal static Uri ValidateLoopbackOrigin(string value)
    {
        if(!Uri.TryCreate((value??"").Trim(),UriKind.Absolute,out var uri)||!uri.IsLoopback||
           (uri.Scheme!="http"&&uri.Scheme!="https")||!string.IsNullOrEmpty(uri.UserInfo)||!string.IsNullOrEmpty(uri.Query)||!string.IsNullOrEmpty(uri.Fragment)||
           uri.AbsolutePath!="/"||uri.Port is <1024 or >65535)
            throw new PlanException("آدرس Local Web برای Pairing باید یک origin محلی معتبر باشد.");
        return uri;
    }

    internal static string SafeCode(string? value)
    {
        var v=(value??"").Trim();
        return v.Length is >=1 and <=96 && v.All(c=>char.IsLetterOrDigit(c)||c is '_' or '-')?v:"pairing_error";
    }

    public void Dispose()=>http.Dispose();

    internal static void SelfTest()
    {
        const string pairingCode="ws1_0123456789abcdef01234567_0123456789abcdef0123456789abcdef0123456789abcdef";
        var handler=new PairingSelfTestHandler(pairingCode);
        using var client=new PairingClient("http://127.0.0.1:18080/",pairingCode,handler);
        var bundle=client.Exchange();
        if(bundle.RuntimeToken.Length<32||bundle.RuntimeTriggers.Count!=1)throw new InvalidOperationException("pairing_selftest_bundle_invalid");
        client.Confirm();
        if(handler.Actions.Count!=2||handler.Actions[0]!="exchange"||handler.Actions[1]!="confirm")throw new InvalidOperationException("pairing_selftest_actions_invalid");
        if(handler.CodeAppearedInUri)throw new InvalidOperationException("pairing_selftest_code_leaked_to_uri");
    }
}

internal sealed class PairingSelfTestHandler(string expectedCode) : HttpMessageHandler
{
    public List<string> Actions { get; } = [];
    public bool CodeAppearedInUri { get; private set; }
    protected override HttpResponseMessage Send(HttpRequestMessage request,CancellationToken cancellationToken)=>Handle(request,cancellationToken);
    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken cancellationToken)=>Task.FromResult(Handle(request,cancellationToken));

    private HttpResponseMessage Handle(HttpRequestMessage request,CancellationToken cancellationToken)
    {
        if(request.RequestUri is null||request.RequestUri.AbsolutePath!="/internal/windows-services/v1/pairing.php")throw new InvalidOperationException("pairing_selftest_path");
        if(request.RequestUri.ToString().Contains(expectedCode,StringComparison.Ordinal))CodeAppearedInUri=true;
        if(!request.Headers.TryGetValues("X-Sokna-Windows-Services-Pairing",out var values)||values.Single()!="1")throw new InvalidOperationException("pairing_selftest_header");
        var raw=request.Content!.ReadAsStringAsync(cancellationToken).GetAwaiter().GetResult();
        using var doc=JsonDocument.Parse(raw);
        var action=doc.RootElement.GetProperty("action").GetString()??"";
        if(doc.RootElement.GetProperty("pairing_code").GetString()!=expectedCode)throw new InvalidOperationException("pairing_selftest_code");
        Actions.Add(action);
        var json=action=="exchange"
            ?JsonSerializer.Serialize(new{success=true,pairing_id="selftest",bundle=new{format="sokna-windows-services-pairing-v1",schema_version=1,local_base_url="http://127.0.0.1:18080/",local_bridge_allowed_origin="http://127.0.0.1:18080",runtime_token=new string('a',64),local_token=new string('b',64),print_agent_token=new string('c',64),runtime_triggers=new[]{new{key="maintenance.health",intervalSeconds=60}}}})
            :JsonSerializer.Serialize(new{success=true,pairing_id="selftest",confirmed=true});
        return new HttpResponseMessage(HttpStatusCode.OK){Content=new StringContent(json,System.Text.Encoding.UTF8,"application/json")};
    }
}

internal static class Program
{
    private const int UsageError = 64;
    private static readonly JsonSerializerOptions StrictJson = new() { PropertyNameCaseInsensitive = false };

    public static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) return Fail("این برنامه فقط روی ویندوز قابل اجرا است.", 2);
            if(args.Length==1&&args[0].Equals("--pairing-self-test",StringComparison.OrdinalIgnoreCase)){PairingClient.SelfTest();Console.WriteLine("Sokna Setup Host pairing self-test PASS");return 0;}
            var planPath = ReadPlanArgument(args);
            var plan = LoadPlan(planPath);
            ValidatePlan(plan);
            VerifyShell(Path.GetFullPath(plan.ShellRoot));
            return RunLifecycle(plan, planPath);
        }
        catch (PlanException e) { return Fail(e.Message, UsageError); }
        catch (Exception e) { return Fail("عملیات سرویس‌های سکنا کامل نشد: " + SafeMessage(e.Message), 2); }
    }

    private static string ReadPlanArgument(string[] args)
    {
        if (args.Length != 2 || !args[0].Equals("--plan-file", StringComparison.OrdinalIgnoreCase))
            throw new PlanException("فایل برنامه سرویس‌ها مشخص نشده است.");
        return RequireAbsoluteFile(args[1], "فایل برنامه سرویس‌ها");
    }

    private static SetupPlan LoadPlan(string path)
    {
        var info = new FileInfo(path);
        if (info.Length is < 2 or > 256 * 1024) throw new PlanException("اندازه فایل برنامه سرویس‌ها معتبر نیست.");
        return JsonSerializer.Deserialize<SetupPlan>(File.ReadAllText(path), StrictJson)
            ?? throw new PlanException("فایل برنامه سرویس‌ها معتبر نیست.");
    }

    private static void ValidatePlan(SetupPlan p)
    {
        if (p.SchemaVersion != 2) throw new PlanException("نسخه فایل برنامه سرویس‌ها پشتیبانی نمی‌شود.");
        var mode = p.Mode.Trim().ToLowerInvariant();
        if (mode is not ("install" or "repair" or "uninstall")) throw new PlanException("حالت عملیات سرویس‌ها معتبر نیست.");
        RequireAbsoluteDirectoryOrFuture(p.ShellRoot, "پوشه بسته نصب");
        RequireAbsoluteDirectoryOrFuture(p.InstallRoot, "پوشه سرویس‌های سکنا");
        RequireAbsoluteDirectoryOrFuture(p.DataRoot, "پوشه داده‌های سکنا");
        if (!string.IsNullOrWhiteSpace(p.PairingFile)) RequireAbsoluteFile(p.PairingFile, "فایل Pairing");
        if(!string.IsNullOrWhiteSpace(p.PairingFile)&&!string.IsNullOrWhiteSpace(p.PairingCode))throw new PlanException("فایل Pairing و کد Pairing همزمان قابل استفاده نیستند.");
        if(!string.IsNullOrWhiteSpace(p.PairingCode))
        {
            _=PairingClient.ValidateLoopbackOrigin(p.PairingBaseUrl);
            if(!System.Text.RegularExpressions.Regex.IsMatch(p.PairingCode.Trim(),"^ws1_[a-f0-9]{24}_[a-f0-9]{48}$"))throw new PlanException("کد Pairing معتبر نیست.");
        }
    }

    private static void VerifyShell(string shellRoot)
    {
        var manifestPath = Path.Combine(shellRoot, "payload-manifest.json");
        RequireAbsoluteFile(manifestPath, "مانیفست بسته Windows Services");
        var manifest = JsonSerializer.Deserialize<PayloadManifest>(File.ReadAllText(manifestPath), StrictJson)
            ?? throw new PlanException("مانیفست بسته Windows Services معتبر نیست.");
        if (manifest.Format != "sokna-windows-services-shell-v2" || manifest.SchemaVersion != 2 || manifest.Ownership != "windows-services-packaging")
            throw new PlanException("قرارداد مالکیت بسته Windows Services معتبر نیست.");
        if (!IsVersion(manifest.PackageVersion)) throw new PlanException("نسخه بسته Windows Services معتبر نیست.");
        if (!manifest.Components.TryGetValue("runtime", out var runtime) || !manifest.Components.TryGetValue("print-agent", out var print))
            throw new PlanException("نسخه اجزای Runtime/Print Agent در مانیفست موجود نیست.");
        if (!IsVersion(runtime.Version) || !IsVersion(print.Version)) throw new PlanException("نسخه یکی از اجزای سرویس معتبر نیست.");
        if (manifest.Files.Count == 0) throw new PlanException("مانیفست بسته خالی است.");

        var compatPath = Path.Combine(shellRoot, "windows-services-compatibility-v1.json");
        RequireAbsoluteFile(compatPath, "مانیفست سازگاری Windows Services");
        var compat = JsonSerializer.Deserialize<CompatibilityManifest>(File.ReadAllText(compatPath), StrictJson)
            ?? throw new PlanException("مانیفست سازگاری Windows Services معتبر نیست.");
        if (compat.Format != "sokna-windows-services-compatibility-v1" || compat.SchemaVersion != 1)
            throw new PlanException("نسخه مانیفست سازگاری Windows Services پشتیبانی نمی‌شود.");
        if (!compat.Components.TryGetValue("runtime", out var runtimeCompat) || runtimeCompat.Version != runtime.Version ||
            !compat.Components.TryGetValue("print-agent", out var printCompat) || printCompat.Version != print.Version)
            throw new PlanException("نسخه payload با مانیفست سازگاری یکسان نیست.");

        var required = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "SoknaRuntimeService.exe", "SoknaSetupHost.exe", "SoknaSetupUi.exe",
            "setup-windows-services.ps1", "remove-windows-services.ps1", "collect-support.ps1",
            "prerequisites.json", "release-lock.json", "windows-services-compatibility-v1.json",
            "print-worker/component-manifest.json", "print-worker/Service/Sokna.PrintAgent.Service.exe",
            "print-worker/Worker/Sokna.PrintAgent.Worker.exe"
        };
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var root = Path.GetFullPath(shellRoot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar) + Path.DirectorySeparatorChar;
        foreach (var entry in manifest.Files)
        {
            var normalized = NormalizeRelative(entry.Path);
            if (!seen.Add(normalized)) throw new PlanException("مسیر تکراری در مانیفست بسته وجود دارد.");
            var full = Path.GetFullPath(Path.Combine(shellRoot, normalized.Replace('/', Path.DirectorySeparatorChar)));
            if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase)) throw new PlanException("مسیر فایل از محدوده بسته نصب خارج شده است.");
            if (!File.Exists(full)) throw new PlanException("یکی از فایل‌های بسته نصب موجود نیست: " + normalized);
            var info = new FileInfo(full);
            if (info.Length != entry.Size || entry.Size < 0) throw new PlanException("اندازه یکی از فایل‌های بسته با مانیفست سازگار نیست.");
            var expected = (entry.Sha256 ?? "").Trim().ToLowerInvariant();
            if (expected.Length != 64 || expected.Any(c => !Uri.IsHexDigit(c))) throw new PlanException("هش ثبت‌شده در مانیفست معتبر نیست.");
            using var stream = File.OpenRead(full);
            var actual = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
            if (!CryptographicOperations.FixedTimeEquals(Convert.FromHexString(actual), Convert.FromHexString(expected)))
                throw new PlanException("هش یکی از فایل‌های بسته نصب معتبر نیست: " + normalized);
        }
        foreach (var rel in required) if (!seen.Contains(rel.Replace('\\', '/'))) throw new PlanException("فایل ضروری در مانیفست بسته ثبت نشده است: " + rel);
        foreach (var forbidden in new[] { "SoknaAppPayload.zip", "php.exe", "httpd.exe", "apache.exe", "mysqld.exe", "mariadb.exe" })
            if (seen.Any(x => string.Equals(Path.GetFileName(x), forbidden, StringComparison.OrdinalIgnoreCase)))
                throw new PlanException("بسته Windows Services شامل payload خارج از مالکیت است: " + forbidden);
    }

    private static int RunLifecycle(SetupPlan plan, string planPath)
    {
        var logPath = Path.Combine(Path.GetFullPath(plan.DataRoot), "Logs", "windows-services-setup.log");
        var pairingByCode=!string.IsNullOrWhiteSpace(plan.PairingCode);
        AppendSetupLog(logPath, $"{DateTimeOffset.Now:O} START mode={CanonicalMode(plan.Mode)} install_root={Path.GetFullPath(plan.InstallRoot)} pairing_mode={(pairingByCode?"code":(!string.IsNullOrWhiteSpace(plan.PairingFile)?"legacy_file":"none"))}");
        PairingClient? pairingClient=null;WindowsServicesPairingBundle? pairingBundle=null;var pairingExchanged=false;
        try
        {
            if(pairingByCode)
            {
                pairingClient=new PairingClient(plan.PairingBaseUrl,plan.PairingCode.Trim());
                pairingBundle=pairingClient.Exchange();pairingExchanged=true;
            }
            var script = Path.Combine(Path.GetFullPath(plan.ShellRoot), "setup-windows-services.ps1");
            RequireAbsoluteFile(script, "اسکریپت lifecycle سرویس‌ها");
            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
            RequireAbsoluteFile(powershell, "Windows PowerShell");
            var psi = new ProcessStartInfo(powershell)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = Path.GetFullPath(plan.ShellRoot)
            };
            if(pairingBundle is not null)
            {
                var pairingJson=JsonSerializer.Serialize(pairingBundle,StrictJson);
                psi.Environment["SOKNA_WINDOWS_SERVICES_PAIRING_B64"]=Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(pairingJson));
            }
            foreach (var a in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script,
                "-Mode", CanonicalMode(plan.Mode), "-ShellRoot", Path.GetFullPath(plan.ShellRoot), "-InstallRoot", Path.GetFullPath(plan.InstallRoot),
                "-DataRoot", Path.GetFullPath(plan.DataRoot), "-PairingFile", string.IsNullOrWhiteSpace(plan.PairingFile) ? "" : Path.GetFullPath(plan.PairingFile),
                "-StartWhenPaired", plan.StartWhenPaired ? "1" : "0" }) psi.ArgumentList.Add(a);

            using var process = Process.Start(psi) ?? throw new PlanException("Windows PowerShell برای lifecycle سرویس‌ها اجرا نشد.");
            var stdoutTask = process.StandardOutput.ReadToEndAsync();
            var stderrTask = process.StandardError.ReadToEndAsync();
            process.WaitForExit();
            Task.WaitAll(stdoutTask, stderrTask);

            var stdout = stdoutTask.Result;
            var stderr = stderrTask.Result;
            if (!string.IsNullOrWhiteSpace(stdout)) Console.Out.Write(stdout);
            if (!string.IsNullOrWhiteSpace(stderr)) Console.Error.Write(stderr);

            AppendSetupLog(logPath,
                $"{DateTimeOffset.Now:O} END mode={CanonicalMode(plan.Mode)} exit_code={process.ExitCode}{Environment.NewLine}" +
                $"STDOUT: {SafeMessage(stdout)}{Environment.NewLine}STDERR: {SafeMessage(stderr)}");

            if (process.ExitCode != 0){if(pairingExchanged)pairingClient?.CancelBestEffort();return process.ExitCode;}
            if(pairingExchanged)pairingClient!.Confirm();
            Console.Error.WriteLine($"SOKNA Windows Services lifecycle complete ({CanonicalMode(plan.Mode)}). Plan: {Path.GetFileName(planPath)}");
            return 0;
        }
        catch (Exception e)
        {
            if(pairingExchanged)pairingClient?.CancelBestEffort();
            AppendSetupLog(logPath, $"{DateTimeOffset.Now:O} ERROR mode={CanonicalMode(plan.Mode)} {SafeMessage(e.Message)}");
            throw;
        }
        finally{pairingClient?.Dispose();}
    }

    private static void AppendSetupLog(string path, string message)
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);
            File.AppendAllText(path, message.Replace('\r', ' ').TrimEnd() + Environment.NewLine + "---" + Environment.NewLine);
        }
        catch { }
    }

    private static string CanonicalMode(string mode) => mode.Trim().ToLowerInvariant() switch
    {
        "install" => "Install", "repair" => "Repair", "uninstall" => "Uninstall", _ => throw new PlanException("حالت عملیات معتبر نیست.")
    };

    private static string NormalizeRelative(string value)
    {
        var rel = (value ?? "").Replace('\\', '/').Trim();
        if (string.IsNullOrWhiteSpace(rel) || rel.StartsWith('/') || rel.Contains("../", StringComparison.Ordinal) || rel.Contains("/..", StringComparison.Ordinal) || Path.IsPathFullyQualified(rel))
            throw new PlanException("مسیر فایل در مانیفست معتبر نیست.");
        return rel;
    }

    private static bool IsVersion(string value) => System.Text.RegularExpressions.Regex.IsMatch(value ?? "", @"^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$");

    private static string RequireAbsoluteFile(string value, string label)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value)) throw new PlanException(label + " باید مسیر کامل باشد.");
        var full = Path.GetFullPath(value);
        if (!File.Exists(full)) throw new PlanException(label + " وجود ندارد.");
        return full;
    }

    private static void RequireAbsoluteDirectoryOrFuture(string value, string label)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value)) throw new PlanException(label + " باید مسیر کامل باشد.");
        _ = Path.GetFullPath(value);
    }

    private static int Fail(string message, int code) { Console.Error.WriteLine(SafeMessage(message)); return code; }
    private static string SafeMessage(string message)
    {
        var value = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return value.Length > 700 ? value[..700] : value;
    }
}

internal sealed class PlanException(string message) : Exception(message);
