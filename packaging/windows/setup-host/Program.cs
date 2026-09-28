using System.Diagnostics;
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

internal static class Program
{
    private const int UsageError = 64;
    private static readonly JsonSerializerOptions StrictJson = new() { PropertyNameCaseInsensitive = false };

    public static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) return Fail("این برنامه فقط روی ویندوز قابل اجرا است.", 2);
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
            "setup-windows-services.ps1", "remove-windows-services.ps1",
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
        foreach (var a in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script,
            "-Mode", CanonicalMode(plan.Mode), "-ShellRoot", Path.GetFullPath(plan.ShellRoot), "-InstallRoot", Path.GetFullPath(plan.InstallRoot),
            "-DataRoot", Path.GetFullPath(plan.DataRoot), "-PairingFile", string.IsNullOrWhiteSpace(plan.PairingFile) ? "" : Path.GetFullPath(plan.PairingFile),
            "-StartWhenPaired", plan.StartWhenPaired ? "1" : "0" }) psi.ArgumentList.Add(a);
        using var process = Process.Start(psi) ?? throw new PlanException("Windows PowerShell برای lifecycle سرویس‌ها اجرا نشد.");
        var stdoutTask = process.StandardOutput.ReadToEndAsync();
        var stderrTask = process.StandardError.ReadToEndAsync();
        process.WaitForExit();
        Task.WaitAll(stdoutTask, stderrTask);
        if (!string.IsNullOrWhiteSpace(stdoutTask.Result)) Console.Out.Write(stdoutTask.Result);
        if (!string.IsNullOrWhiteSpace(stderrTask.Result)) Console.Error.Write(stderrTask.Result);
        if (process.ExitCode != 0) return process.ExitCode;
        Console.Error.WriteLine($"SOKNA Windows Services lifecycle complete ({CanonicalMode(plan.Mode)}). Plan: {Path.GetFileName(planPath)}");
        return 0;
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
