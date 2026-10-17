using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.SetupHostV3;

internal sealed class InstallPlanV3
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("transaction_id")] public string TransactionId { get; init; } = "";
    [JsonPropertyName("correlation_id")] public string CorrelationId { get; init; } = "";
    [JsonPropertyName("setup_session_id")] public string SetupSessionId { get; init; } = "";
    [JsonPropertyName("mode")] public string Mode { get; init; } = "";
    [JsonPropertyName("shell_root")] public string ShellRoot { get; init; } = "";
    [JsonPropertyName("install_root")] public string InstallRoot { get; init; } = "";
    [JsonPropertyName("data_root")] public string DataRoot { get; init; } = "";
    [JsonPropertyName("package_version")] public string PackageVersion { get; init; } = "";
    [JsonPropertyName("components")] public List<JsonElement> Components { get; init; } = [];
}

internal sealed class ShellManifestV3
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("package_version")] public string PackageVersion { get; init; } = "";
    [JsonPropertyName("files")] public List<ShellFile> Files { get; init; } = [];
}

internal sealed class ShellFile
{
    [JsonPropertyName("path")] public string Path { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
}

internal static class Program
{
    private const int UsageError = 64;
    private const long MaxPlanBytes = 512 * 1024;
    private const long MaxManifestBytes = 4 * 1024 * 1024;
    private const string LifecycleScriptName = "setup-windows-components-v4.ps1";
    private static readonly JsonSerializerOptions StrictJson = new() { PropertyNameCaseInsensitive = false };

    public static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) return Fail("این برنامه فقط روی ویندوز قابل اجرا است.", 2, null);
            var planPath = ReadPlanArgument(args);
            var plan = LoadPlan(planPath);
            ValidatePlan(plan);
            var shellRoot = RequireExistingDirectory(plan.ShellRoot, "shell_root");
            var lifecycle = Path.Combine(shellRoot, LifecycleScriptName);
            VerifyLifecycle(shellRoot, lifecycle, plan.PackageVersion);
            return RunElevatedLifecycle(planPath, lifecycle);
        }
        catch (HostPlanException ex)
        {
            return Fail(ex.Message, UsageError, TryPlanPath(args));
        }
        catch (Exception ex)
        {
            return Fail("Setup Host v3 کامل نشد: " + Safe(ex.Message), 2, TryPlanPath(args));
        }
    }

    private static string ReadPlanArgument(string[] args)
    {
        if (args.Length != 2 || !string.Equals(args[0], "--plan-file", StringComparison.OrdinalIgnoreCase))
            throw new HostPlanException("فایل plan-v3 مشخص نشده است.");
        var value = args[1].Trim();
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathRooted(value)) throw new HostPlanException("مسیر plan-v3 باید absolute باشد.");
        var full = Path.GetFullPath(value);
        if (!File.Exists(full)) throw new HostPlanException("فایل plan-v3 پیدا نشد.");
        return full;
    }

    private static InstallPlanV3 LoadPlan(string path)
    {
        var info = new FileInfo(path);
        if (info.Length is < 2 or > MaxPlanBytes) throw new HostPlanException("اندازه plan-v3 معتبر نیست.");
        try
        {
            return JsonSerializer.Deserialize<InstallPlanV3>(File.ReadAllBytes(path), StrictJson)
                   ?? throw new HostPlanException("plan-v3 خالی است.");
        }
        catch (JsonException ex)
        {
            throw new HostPlanException("JSON plan-v3 معتبر نیست.", ex);
        }
    }

    private static void ValidatePlan(InstallPlanV3 plan)
    {
        if (plan.Format != "sokna-windows-install-plan-v3" || plan.SchemaVersion != 3)
            throw new HostPlanException("نسخه plan-v3 پشتیبانی نمی‌شود.");
        if (plan.Mode is not ("install" or "repair" or "uninstall")) throw new HostPlanException("حالت plan-v3 معتبر نیست.");
        _ = RequireAbsolute(plan.ShellRoot, "shell_root");
        _ = RequireAbsolute(plan.InstallRoot, "install_root");
        _ = RequireAbsolute(plan.DataRoot, "data_root");
        if (!SafeToken(plan.TransactionId, 160) || !SafeToken(plan.CorrelationId, 160) || !SafeToken(plan.SetupSessionId, 160))
            throw new HostPlanException("شناسه‌های transaction plan-v3 معتبر نیستند.");
        if (string.IsNullOrWhiteSpace(plan.PackageVersion) || plan.PackageVersion.Length > 64 || plan.PackageVersion.Any(char.IsControl))
            throw new HostPlanException("package_version plan-v3 معتبر نیست.");
        if (plan.Mode != "uninstall" && plan.Components.Count == 0) throw new HostPlanException("plan-v3 هیچ component ندارد.");
        if (plan.Components.Count > 64) throw new HostPlanException("تعداد componentهای plan-v3 بیش از حد مجاز است.");
    }

    private static void VerifyLifecycle(string shellRoot, string lifecyclePath, string packageVersion)
    {
        if (!File.Exists(lifecyclePath)) throw new HostPlanException("Lifecycle script در shell پیدا نشد.");
        var manifestPath = Path.Combine(shellRoot, "payload-manifest.json");
        if (!File.Exists(manifestPath)) throw new HostPlanException("payload-manifest.json پیدا نشد.");
        var manifestInfo = new FileInfo(manifestPath);
        if (manifestInfo.Length is < 2 or > MaxManifestBytes) throw new HostPlanException("اندازه payload manifest معتبر نیست.");
        ShellManifestV3 manifest;
        try
        {
            manifest = JsonSerializer.Deserialize<ShellManifestV3>(File.ReadAllBytes(manifestPath), StrictJson)
                       ?? throw new HostPlanException("payload manifest خالی است.");
        }
        catch (JsonException ex)
        {
            throw new HostPlanException("payload manifest JSON معتبر نیست.", ex);
        }
        if (manifest.Format != "sokna-windows-services-shell-v3" || manifest.SchemaVersion != 3)
            throw new HostPlanException("فرمت payload manifest پشتیبانی نمی‌شود.");
        if (!string.Equals(manifest.PackageVersion, packageVersion, StringComparison.Ordinal))
            throw new HostPlanException("package_version بین plan و shell یکسان نیست.");
        var row = manifest.Files.SingleOrDefault(f => string.Equals(NormalizeManifestPath(f.Path), LifecycleScriptName, StringComparison.OrdinalIgnoreCase));
        if (row is null) throw new HostPlanException("Lifecycle script در payload manifest ثبت نشده است.");
        var file = new FileInfo(lifecyclePath);
        if (row.Size != file.Length) throw new HostPlanException("اندازه Lifecycle script با payload manifest یکسان نیست.");
        var expected = NormalizeSha256(row.Sha256);
        var actual = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(lifecyclePath))).ToLowerInvariant();
        if (!FixedHexEquals(expected, actual)) throw new HostPlanException("SHA-256 Lifecycle script با payload manifest یکسان نیست.");
    }

    private static int RunElevatedLifecycle(string planPath, string lifecyclePath)
    {
        var powershell = Path.Combine(Environment.SystemDirectory, "WindowsPowerShell", "v1.0", "powershell.exe");
        if (!File.Exists(powershell)) throw new HostPlanException("Windows PowerShell 5.1 پیدا نشد.");
        var psi = new ProcessStartInfo
        {
            FileName = powershell,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = Path.GetDirectoryName(planPath) ?? Environment.CurrentDirectory
        };
        psi.ArgumentList.Add("-NoLogo");
        psi.ArgumentList.Add("-NoProfile");
        psi.ArgumentList.Add("-NonInteractive");
        psi.ArgumentList.Add("-ExecutionPolicy");
        psi.ArgumentList.Add("Bypass");
        psi.ArgumentList.Add("-File");
        psi.ArgumentList.Add(lifecyclePath);
        psi.ArgumentList.Add("-PlanFile");
        psi.ArgumentList.Add(planPath);
        using var process = Process.Start(psi) ?? throw new HostPlanException("Lifecycle process شروع نشد.");
        var stdoutTask = process.StandardOutput.ReadToEndAsync();
        var stderrTask = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(15 * 60 * 1000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            throw new HostPlanException("Lifecycle process بیش از زمان مجاز طول کشید.");
        }
        var stdout = stdoutTask.GetAwaiter().GetResult();
        var stderr = stderrTask.GetAwaiter().GetResult();
        if (process.ExitCode != 0)
        {
            var detail = !string.IsNullOrWhiteSpace(stderr) ? stderr : stdout;
            _ = Fail($"Lifecycle process با کد {process.ExitCode} متوقف شد. {Safe(detail)}", process.ExitCode, planPath);
        }
        return process.ExitCode;
    }

    private static string RequireExistingDirectory(string value, string label)
    {
        var full = RequireAbsolute(value, label);
        if (!Directory.Exists(full)) throw new HostPlanException($"{label} پیدا نشد.");
        return full;
    }

    private static string RequireAbsolute(string value, string label)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathRooted(value)) throw new HostPlanException($"{label} باید absolute باشد.");
        try { return Path.GetFullPath(value); }
        catch (Exception ex) when (ex is ArgumentException or NotSupportedException or PathTooLongException)
        {
            throw new HostPlanException($"{label} معتبر نیست.", ex);
        }
    }

    private static bool SafeToken(string value, int max)
        => !string.IsNullOrWhiteSpace(value) && value.Length <= max && value.All(c => char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':');

    private static string NormalizeManifestPath(string value) => (value ?? string.Empty).Replace('\\', '/').TrimStart('/').Replace('/', Path.DirectorySeparatorChar);

    private static string NormalizeSha256(string value)
    {
        var normalized = (value ?? string.Empty).Trim().ToLowerInvariant();
        if (normalized.Length != 64 || normalized.Any(c => !char.IsAsciiHexDigit(c))) throw new HostPlanException("SHA-256 ثبت‌شده در manifest معتبر نیست.");
        return normalized;
    }

    private static bool FixedHexEquals(string left, string right)
    {
        try
        {
            var a = Convert.FromHexString(left);
            var b = Convert.FromHexString(right);
            return a.Length == b.Length && CryptographicOperations.FixedTimeEquals(a, b);
        }
        catch (FormatException) { return false; }
    }

    private static int Fail(string message, int code, string? planPath)
    {
        try
        {
            var safe = Safe(message);
            var directory = !string.IsNullOrWhiteSpace(planPath) ? Path.GetDirectoryName(planPath) : null;
            if (!string.IsNullOrWhiteSpace(directory) && Directory.Exists(directory))
                File.WriteAllText(Path.Combine(directory, "setup-host-v3-error.txt"), safe + Environment.NewLine, new UTF8Encoding(false));
        }
        catch { }
        return code;
    }

    private static string Safe(string value)
    {
        var text = (value ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ').Replace('\0', ' ').Trim();
        if (text.Length > 1200) text = text[..1200];
        return text;
    }

    private static string? TryPlanPath(string[] args)
    {
        try { return args.Length == 2 && string.Equals(args[0], "--plan-file", StringComparison.OrdinalIgnoreCase) && Path.IsPathRooted(args[1]) ? Path.GetFullPath(args[1]) : null; }
        catch { return null; }
    }
}

internal sealed class HostPlanException : Exception
{
    public HostPlanException(string message) : base(message) { }
    public HostPlanException(string message, Exception inner) : base(message, inner) { }
}