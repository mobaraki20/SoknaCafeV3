using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Security.Cryptography;

namespace Sokna.SetupHost;

internal sealed class SetupPlan
{
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("mode")] public string Mode { get; init; } = "";
    [JsonPropertyName("shell_root")] public string ShellRoot { get; init; } = "";
    [JsonPropertyName("app_root")] public string AppRoot { get; init; } = "";
    [JsonPropertyName("data_root")] public string DataRoot { get; init; } = "";
    [JsonPropertyName("php_exe")] public string PhpExe { get; init; } = "";
    [JsonPropertyName("openssl_exe")] public string OpenSslExe { get; init; } = "";
    [JsonPropertyName("web_server_exe")] public string WebServerExe { get; init; } = "";
    [JsonPropertyName("setup_config_file")] public string SetupConfigFile { get; init; } = "";
    [JsonPropertyName("recovery_file")] public string RecoveryFile { get; init; } = "";
    [JsonPropertyName("recovery_passphrase_file")] public string RecoveryPassphraseFile { get; init; } = "";
    [JsonPropertyName("hostname")] public string Hostname { get; init; } = "sokna.local";
    [JsonPropertyName("require_web_server_preflight")] public bool RequireWebServerPreflight { get; init; } = true;
    [JsonPropertyName("skip_https")] public bool SkipHttps { get; init; }
    [JsonPropertyName("skip_service")] public bool SkipService { get; init; }
}


internal sealed class PayloadManifest
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("app_version")] public string AppVersion { get; init; } = "";
    [JsonPropertyName("source_git_sha")] public string SourceGitSha { get; init; } = "";
    [JsonPropertyName("ownership")] public string Ownership { get; init; } = "";
    [JsonPropertyName("live_app_owner")] public string LiveAppOwner { get; init; } = "";
    [JsonPropertyName("print_worker_owner")] public string PrintWorkerOwner { get; init; } = "";
    [JsonPropertyName("files")] public List<PayloadFile> Files { get; init; } = [];
}

internal sealed class PayloadFile
{
    [JsonPropertyName("path")] public string Path { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
}

internal static class Program
{
    private const int UsageError = 64;

    public static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) return Fail("این برنامه فقط روی ویندوز قابل اجرا است.", 2);
            var planPath = ReadPlanArgument(args);
            var plan = LoadPlan(planPath);
            ValidatePlan(plan);
            VerifyShellManifest(Path.GetFullPath(plan.ShellRoot));
            var sessionId = Guid.NewGuid().ToString("N");
            Console.Error.WriteLine($"SOKNA setup session: {sessionId}");
            return RunPlan(plan, sessionId);
        }
        catch (PlanException e)
        {
            return Fail(e.Message, UsageError);
        }
        catch (Exception e)
        {
            return Fail("راه‌اندازی سکنا کامل نشد: " + SafeMessage(e.Message), 2);
        }
    }

    private static string ReadPlanArgument(string[] args)
    {
        if (args.Length != 2 || !args[0].Equals("--plan-file", StringComparison.OrdinalIgnoreCase))
            throw new PlanException("فایل برنامه راه‌اندازی مشخص نشده است.");
        return RequireAbsoluteFile(args[1], "فایل برنامه راه‌اندازی");
    }

    private static SetupPlan LoadPlan(string path)
    {
        var info = new FileInfo(path);
        if (info.Length is < 2 or > 1024 * 1024) throw new PlanException("اندازه فایل برنامه راه‌اندازی معتبر نیست.");
        var plan = JsonSerializer.Deserialize<SetupPlan>(File.ReadAllText(path), new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = false,
            UnmappedMemberHandling = JsonUnmappedMemberHandling.Disallow
        });
        return plan ?? throw new PlanException("فایل برنامه راه‌اندازی معتبر نیست.");
    }

    private static void ValidatePlan(SetupPlan p)
    {
        if (p.SchemaVersion != 1) throw new PlanException("نسخه فایل برنامه راه‌اندازی پشتیبانی نمی‌شود.");
        var mode = p.Mode.Trim().ToLowerInvariant();
        if (mode is not ("new" or "recover" or "repair")) throw new PlanException("حالت راه‌اندازی معتبر نیست.");

        RequireAbsoluteDirectoryOrFuture(p.ShellRoot, "پوشه نصب‌کننده");
        RequireAbsoluteDirectoryOrFuture(p.AppRoot, "پوشه برنامه");
        RequireAbsoluteDirectoryOrFuture(p.DataRoot, "پوشه داده");
        RequireAbsoluteFile(p.PhpExe, "PHP");
        if (!p.SkipHttps) RequireAbsoluteFile(p.OpenSslExe, "OpenSSL");
        if (!string.IsNullOrWhiteSpace(p.WebServerExe)) RequireAbsoluteFile(p.WebServerExe, "وب‌سرور");
        if (!IsSafeHostname(p.Hostname)) throw new PlanException("نام محلی سامانه معتبر نیست.");

        if (mode is "new" or "recover")
        {
            RequireAbsoluteFile(p.SetupConfigFile, "فایل تنظیمات امن Setup");
            if (mode == "recover")
            {
                RequireAbsoluteFile(p.RecoveryFile, "فایل بازیابی");
                if (!string.IsNullOrWhiteSpace(p.RecoveryPassphraseFile)) RequireAbsoluteFile(p.RecoveryPassphraseFile, "فایل رمز بازیابی");
            }
        }
    }

    private static void VerifyShellManifest(string shellRoot)
    {
        var manifestPath = Path.Combine(shellRoot, "payload-manifest.json");
        RequireAbsoluteFile(manifestPath, "مانیفست بسته نصب");
        var manifest = JsonSerializer.Deserialize<PayloadManifest>(File.ReadAllText(manifestPath), new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = false,
            UnmappedMemberHandling = JsonUnmappedMemberHandling.Disallow
        }) ?? throw new PlanException("مانیفست بسته نصب معتبر نیست.");
        if (manifest.Format != "sokna-windows-shell-payload-v1" || manifest.Ownership != "msi-shell-cache-only" || manifest.LiveAppOwner != "sokna-updater")
            throw new PlanException("قرارداد مالکیت بسته نصب معتبر نیست.");
        if (manifest.Files.Count == 0) throw new PlanException("مانیفست بسته نصب خالی است.");

        var root = Path.GetFullPath(shellRoot).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var expected = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in manifest.Files)
        {
            var rel = (entry.Path ?? "").Replace('/', Path.DirectorySeparatorChar).Replace('\\', Path.DirectorySeparatorChar);
            if (string.IsNullOrWhiteSpace(rel) || Path.IsPathFullyQualified(rel)) throw new PlanException("مسیر فایل در مانیفست معتبر نیست.");
            var full = Path.GetFullPath(Path.Combine(shellRoot, rel));
            if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase)) throw new PlanException("مسیر فایل از محدوده بسته نصب خارج شده است.");
            if (!File.Exists(full)) throw new PlanException("یکی از فایل‌های بسته نصب موجود نیست.");
            var info = new FileInfo(full);
            if (info.Length != entry.Size) throw new PlanException("اندازه یکی از فایل‌های بسته نصب با مانیفست سازگار نیست.");
            var expectedHash = (entry.Sha256 ?? "").Trim().ToLowerInvariant();
            if (expectedHash.Length != 64 || expectedHash.Any(c => !Uri.IsHexDigit(c))) throw new PlanException("هش یکی از فایل‌های بسته نصب معتبر نیست.");
            using var stream = File.OpenRead(full);
            var actualHash = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
            if (!CryptographicOperations.FixedTimeEquals(Convert.FromHexString(actualHash), Convert.FromHexString(expectedHash)))
                throw new PlanException("هش یکی از فایل‌های بسته نصب با مانیفست سازگار نیست.");
            var normalized = Path.GetRelativePath(shellRoot, full).Replace('\\', '/');
            if (!expected.Add(normalized)) throw new PlanException("مسیر تکراری در مانیفست بسته نصب وجود دارد.");
        }

        var actual = Directory.EnumerateFiles(shellRoot, "*", SearchOption.AllDirectories)
            .Where(f => !string.Equals(Path.GetFullPath(f), Path.GetFullPath(manifestPath), StringComparison.OrdinalIgnoreCase))
            .Select(f => Path.GetRelativePath(shellRoot, f).Replace('\\', '/'))
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
        if (!actual.SetEquals(expected)) throw new PlanException("مجموعه فایل‌های shell با مانیفست بسته نصب یکسان نیست.");

        foreach (var required in new[]
        {
            "deploy-seed.ps1", "collect-support.ps1", "verify-prerequisite-bundle.ps1", "setup-sokna.ps1", "setup-support.psm1", "provision-local-https.ps1", "configure-apache.ps1", "sokna-local-https.conf.template", "prerequisites.json",
            "SoknaSetupHost.exe", "SoknaSetupUi.exe", "SoknaRuntimeService.exe", "SoknaAppPayload.zip", "print-worker/component-manifest.json"
        })
            if (!expected.Contains(required)) throw new PlanException("بسته نصب یکی از ownerهای اجباری را ندارد.");
    }

    private static int RunPlan(SetupPlan p, string sessionId)
    {
        var mode = p.Mode.Trim().ToLowerInvariant();
        var shell = Path.GetFullPath(p.ShellRoot);
        var script = mode == "repair"
            ? Path.Combine(Path.GetFullPath(p.AppRoot), "runtime", "windows", "setup-sokna.ps1")
            : Path.Combine(shell, "deploy-seed.ps1");
        RequireAbsoluteFile(script, "موتور راه‌اندازی سکنا");

        var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        RequireAbsoluteFile(ps, "Windows PowerShell");

        var childArgs = new List<string> { "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", script };
        if (mode == "repair")
        {
            Add(childArgs, "-Mode", "Repair");
            AddCommon(childArgs, p, includeSetupConfig: false);
            Add(childArgs, "-ServiceHostExe", Path.Combine(shell, "SoknaRuntimeService.exe"));
            Add(childArgs, "-PrintWorkerBundle", Path.Combine(shell, "print-worker"));
        }
        else
        {
            Add(childArgs, "-Mode", mode == "new" ? "New" : "Recover");
            AddCommon(childArgs, p, includeSetupConfig: true);
            if (mode == "recover")
            {
                Add(childArgs, "-RecoveryFile", p.RecoveryFile);
                if (!string.IsNullOrWhiteSpace(p.RecoveryPassphraseFile)) Add(childArgs, "-RecoveryPassphraseFile", p.RecoveryPassphraseFile);
            }
        }

        using var process = new Process();
        process.StartInfo = new ProcessStartInfo(ps)
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true
        };
        process.StartInfo.Environment["SOKNA_SETUP_SESSION_ID"] = sessionId;
        foreach (var arg in childArgs) process.StartInfo.ArgumentList.Add(arg);
        if (!process.Start()) return Fail("موتور راه‌اندازی اجرا نشد.", 2);
        var stdout = process.StandardOutput.ReadToEndAsync();
        var stderr = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(35 * 60 * 1000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            return Fail("زمان راه‌اندازی از حد مجاز گذشت. گزارش Setup را بررسی کنید.", 2);
        }
        Task.WaitAll(stdout, stderr);
        if (!string.IsNullOrWhiteSpace(stdout.Result)) Console.Out.Write(stdout.Result);
        if (!string.IsNullOrWhiteSpace(stderr.Result)) Console.Error.Write(stderr.Result);
        return process.ExitCode;
    }

    private static void AddCommon(List<string> args, SetupPlan p, bool includeSetupConfig)
    {
        if (includeSetupConfig) Add(args, "-ShellRoot", p.ShellRoot);
        Add(args, "-AppRoot", p.AppRoot);
        Add(args, "-DataRoot", p.DataRoot);
        Add(args, "-PhpExe", p.PhpExe);
        if (includeSetupConfig) Add(args, "-SetupConfigFile", p.SetupConfigFile);
        if (!string.IsNullOrWhiteSpace(p.OpenSslExe)) Add(args, "-OpenSslExe", p.OpenSslExe);
        if (!string.IsNullOrWhiteSpace(p.WebServerExe)) Add(args, "-WebServerExe", p.WebServerExe);
        Add(args, "-Hostname", p.Hostname);
        if (p.RequireWebServerPreflight) args.Add("-RequireWebServerPreflight");
        if (p.SkipHttps) args.Add("-SkipHttps");
        if (p.SkipService) args.Add("-SkipService");
    }

    private static void Add(List<string> args, string name, string value)
    {
        args.Add(name);
        args.Add(value);
    }

    private static bool IsSafeHostname(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 253) return false;
        foreach (var c in value)
            if (!(char.IsAsciiLetterOrDigit(c) || c is '.' or '-')) return false;
        return !value.StartsWith('.') && !value.EndsWith('.') && !value.Contains("..", StringComparison.Ordinal);
    }

    private static string RequireAbsoluteFile(string value, string label)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value) || !File.Exists(value))
            throw new PlanException($"{label} پیدا نشد یا مسیر آن معتبر نیست.");
        return Path.GetFullPath(value);
    }

    private static string RequireAbsoluteDirectoryOrFuture(string value, string label)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value)) throw new PlanException($"{label} معتبر نیست.");
        return Path.GetFullPath(value);
    }

    private static int Fail(string message, int code)
    {
        Console.Error.WriteLine(SafeMessage(message));
        return code;
    }

    private static string SafeMessage(string message)
    {
        var text = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return text.Length > 600 ? text[..600] : text;
    }

    private sealed class PlanException(string message) : Exception(message);
}
