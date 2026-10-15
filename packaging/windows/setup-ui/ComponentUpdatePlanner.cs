using Microsoft.Win32;
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;

namespace Sokna.SetupUi;

internal enum ComponentAction { Keep, Install, Update, Repair, Blocked, PendingRestart, NoActionOptional }
internal enum ComponentHealth { Healthy, Starting, Degraded, Failed, Stopped, Unknown }

internal sealed record ComponentPlanRow(
    string ComponentId,
    string DisplayName,
    string Kind,
    bool Primary,
    bool Required,
    string? ParentComponent,
    string CurrentVersion,
    string TargetVersion,
    ComponentHealth Health,
    string HealthText,
    string UpdateState,
    ComponentAction Action,
    string ActionText,
    string Reason,
    string InstalledPrimary,
    string TargetPrimary,
    string InstalledSha256,
    string TargetSha256,
    string ServiceName,
    string ServiceState,
    bool RegistrationHealthy,
    string TransactionGroup);

internal sealed record ComponentPlanSnapshot(
    string PackageVersion,
    DateTimeOffset VerifiedAtUtc,
    IReadOnlyList<ComponentPlanRow> Rows)
{
    internal bool IsBlocked => Rows.Any(x => x.Action == ComponentAction.Blocked);
    internal bool HasMutation => Rows.Any(x => x.Action is ComponentAction.Install or ComponentAction.Update or ComponentAction.Repair);
    internal IReadOnlyList<ComponentPlanRow> PrimaryRows => Rows.Where(x => x.Primary).ToArray();
}

internal static class ComponentUpdatePlanner
{
    internal const string ManifestFileName = "windows-services-component-manifest-v2.json";

    internal static ComponentPlanSnapshot Build(string shellRoot, string installRoot, string dataRoot, bool forceRepair = false)
    {
        shellRoot = Path.GetFullPath(shellRoot);
        installRoot = Path.GetFullPath(installRoot);
        dataRoot = Path.GetFullPath(dataRoot);
        var manifestPath = Path.Combine(shellRoot, ManifestFileName);
        if (!File.Exists(manifestPath)) throw new InvalidOperationException("مانیفست اجزای Windows Services پیدا نشد.");
        using var doc = JsonDocument.Parse(File.ReadAllText(manifestPath));
        var root = doc.RootElement;
        if (root.GetProperty("format").GetString() != "sokna-windows-component-manifest-v2" || root.GetProperty("schema_version").GetInt32() != 2)
            throw new InvalidOperationException("نسخه مانیفست اجزا پشتیبانی نمی‌شود.");
        var packageVersion = root.GetProperty("package_version").GetString() ?? "";
        var paired = ReadPaired(dataRoot);
        var rows = new List<ComponentPlanRow>();
        foreach (var c in root.GetProperty("components").EnumerateArray())
            rows.Add(BuildRow(c, shellRoot, installRoot, dataRoot, paired, forceRepair));

        foreach (var row in rows.Where(r => r.Required && !r.Primary && r.ParentComponent is not null))
        {
            var parentIndex = rows.FindIndex(r => r.ComponentId == row.ParentComponent);
            if (parentIndex < 0) continue;
            var parent = rows[parentIndex];
            if (row.Action == ComponentAction.Blocked && parent.Action != ComponentAction.Blocked)
                rows[parentIndex] = parent with { Action = ComponentAction.Blocked, ActionText = "مسدود", UpdateState = "blocked_incompatible", Reason = $"وابستگی {row.DisplayName} قابل تأیید نیست." };
            else if (row.Action is ComponentAction.Install or ComponentAction.Update or ComponentAction.Repair && parent.Action == ComponentAction.Keep)
                rows[parentIndex] = parent with { Action = ComponentAction.Repair, ActionText = "تعمیر", UpdateState = "repair_required", Reason = $"یکی از اجزای داخلی Maintenance ({row.DisplayName}) نیاز به همگام‌سازی دارد." };
        }
        return new ComponentPlanSnapshot(packageVersion, DateTimeOffset.UtcNow, rows);
    }

    private static ComponentPlanRow BuildRow(JsonElement c, string shellRoot, string installRoot, string dataRoot, bool paired, bool forceRepair)
    {
        var id = S(c, "component_id");
        var display = S(c, "display_name_fa");
        var kind = S(c, "kind");
        var primary = B(c, "primary");
        var required = B(c, "required");
        var parent = c.TryGetProperty("parent_component", out var p) ? p.GetString() : null;
        var targetVersion = S(c, "version");
        var serviceName = c.TryGetProperty("service_name", out var sn) ? sn.GetString() ?? "" : "";
        var payloadRel = S(c, "payload_primary").Replace('/', Path.DirectorySeparatorChar);
        var installedRel = S(c, "installed_primary").Replace('/', Path.DirectorySeparatorChar);
        var group = c.TryGetProperty("transaction_group", out var tg) ? tg.GetString() ?? id : id;
        var targetPath = Path.GetFullPath(Path.Combine(shellRoot, payloadRel));
        var installedPath = Path.GetFullPath(Path.Combine(installRoot, installedRel));
        if (!IsUnder(targetPath, shellRoot) || !IsUnder(installedPath, installRoot)) throw new InvalidOperationException("مسیر جزء خارج از مرز بسته است.");
        var targetHash = HashIfExists(targetPath);
        var installedHash = HashIfExists(installedPath);
        var currentVersion = LifecycleFileVersion(installedPath);
        var service = string.IsNullOrWhiteSpace(serviceName) ? new ServiceProbe(false, "n/a", "") : ProbeService(serviceName);
        var installed = File.Exists(installedPath) && (string.IsNullOrWhiteSpace(serviceName) || service.Installed);
        var unsafeServiceOwnership = !string.IsNullOrWhiteSpace(serviceName) && service.Installed && !ServiceImageOwnedByInstallRoot(service.ImagePath, installRoot);
        var registrationHealthy = string.IsNullOrWhiteSpace(serviceName) || (!unsafeServiceOwnership && RegistrationMatches(id, service.ImagePath, installedPath, dataRoot));
        var health = HealthFor(id, installed, service.State, paired, kind);
        var healthText = HealthText(id, health, paired, kind);

        ComponentAction action;
        string state;
        string reason;
        if (!File.Exists(targetPath) || string.IsNullOrWhiteSpace(targetHash))
        {
            action = ComponentAction.Blocked; state = "blocked_incompatible"; reason = "فایل هدف جزء داخل بسته قابل تأیید نیست.";
        }
        else if (unsafeServiceOwnership)
        {
            action = ComponentAction.Blocked; state = "blocked_incompatible"; reason = "ImagePath سرویس خارج از محدوده مالکیت Windows Services سکنا است؛ تغییر خودکار برای جلوگیری از دست‌زدن به سرویس نامطمئن مسدود شد.";
        }
        else if (!installed)
        {
            action = required ? ComponentAction.Install : ComponentAction.NoActionOptional;
            state = required ? "install_required" : "not_installed_optional";
            reason = required ? "این جزء روی سیستم نصب نیست." : "جزء اختیاری نصب نشده است.";
        }
        else
        {
            var cmp = CompareVersions(currentVersion, targetVersion);
            if (cmp > 0)
            {
                action = ComponentAction.Blocked; state = "blocked_incompatible"; reason = $"نسخه نصب‌شده {currentVersion} از نسخه هدف {targetVersion} جدیدتر است؛ downgrade خودکار مجاز نیست.";
            }
            else if (cmp < 0)
            {
                action = ComponentAction.Update; state = "required_update"; reason = $"نسخه {currentVersion} باید به {targetVersion} ارتقا یابد.";
            }
            else if (!string.Equals(installedHash, targetHash, StringComparison.OrdinalIgnoreCase))
            {
                action = ComponentAction.Repair; state = "repair_required"; reason = "نسخه یکسان است اما fingerprint فایل اجرایی با release هدف یکسان نیست.";
            }
            else if (!registrationHealthy)
            {
                action = ComponentAction.Repair; state = "repair_required"; reason = "ثبت Windows Service با قرارداد release یکسان نیست.";
            }
            else if (forceRepair)
            {
                action = ComponentAction.Repair; state = "repair_required"; reason = "تعمیر صریح توسط کاربر درخواست شده است.";
            }
            else
            {
                action = ComponentAction.Keep; state = "up_to_date"; reason = "نسخه، fingerprint و ثبت service با release هدف یکسان است.";
            }
        }
        return new ComponentPlanRow(id, display, kind, primary, required, parent, currentVersion, targetVersion, health, healthText, state, action, ActionText(action), reason,
            installedPath, targetPath, installedHash, targetHash, serviceName, service.State, registrationHealthy, group);
    }

    private static bool ReadPaired(string dataRoot)
    {
        try
        {
            var path = Path.Combine(dataRoot, "setup", "windows-services-state.json");
            if (!File.Exists(path)) return false;
            using var d = JsonDocument.Parse(File.ReadAllText(path));
            return d.RootElement.TryGetProperty("paired", out var p) && p.ValueKind == JsonValueKind.True;
        }
        catch { return false; }
    }

    private static ComponentHealth HealthFor(string id, bool installed, string state, bool paired, string kind)
    {
        if (!installed) return ComponentHealth.Unknown;
        if (kind != "service") return ComponentHealth.Healthy;
        if (state.Equals("RUNNING", StringComparison.OrdinalIgnoreCase)) return ComponentHealth.Healthy;
        if (state.Equals("START_PENDING", StringComparison.OrdinalIgnoreCase)) return ComponentHealth.Starting;
        if (state.Equals("STOPPED", StringComparison.OrdinalIgnoreCase)) return ComponentHealth.Stopped;
        return string.IsNullOrWhiteSpace(state) ? ComponentHealth.Unknown : ComponentHealth.Degraded;
    }

    private static string HealthText(string id, ComponentHealth h, bool paired, string kind)
    {
        if (kind != "service") return "آماده";
        return h switch
        {
            ComponentHealth.Healthy => "فعال",
            ComponentHealth.Starting => "در حال شروع",
            ComponentHealth.Stopped when id == "runtime" && !paired => "آماده اتصال",
            ComponentHealth.Stopped => "متوقف",
            ComponentHealth.Degraded => "نیاز به بررسی",
            ComponentHealth.Failed => "خطا",
            _ => "نامشخص"
        };
    }

    private static string ActionText(ComponentAction a) => a switch
    {
        ComponentAction.Keep => "بدون تغییر",
        ComponentAction.Install => "نصب",
        ComponentAction.Update => "به‌روزرسانی",
        ComponentAction.Repair => "تعمیر",
        ComponentAction.Blocked => "مسدود",
        ComponentAction.PendingRestart => "نیازمند راه‌اندازی مجدد",
        _ => "بدون اقدام"
    };

    private static int CompareVersions(string current, string target)
    {
        if (!TryParseComparableVersion(current, out var a)) return -1;
        if (!TryParseComparableVersion(target, out var b)) return -1;
        for (var i = 0; i < 4; i++)
        {
            var cmp = a[i].CompareTo(b[i]);
            if (cmp != 0) return cmp;
        }
        return 0;
    }

    private static bool TryParseComparableVersion(string value, out int[] parts)
    {
        parts = new int[4];
        var normalized = NormalizeVersion(value);
        if (string.IsNullOrWhiteSpace(normalized)) return false;
        var tokens = normalized.Split('.', StringSplitOptions.RemoveEmptyEntries);
        if (tokens.Length is < 1 or > 4) return false;
        for (var i = 0; i < tokens.Length; i++)
        {
            if (!int.TryParse(tokens[i], out var parsed) || parsed < 0) return false;
            parts[i] = parsed;
        }
        return true;
    }

    private static string NormalizeVersion(string value)
    {
        value = (value ?? "").Trim();
        var cut = value.IndexOfAny(['-', '+']);
        return cut >= 0 ? value[..cut] : value;
    }

    internal static void AssertVersionContract()
    {
        static void Expect(string current, string target, int expected)
        {
            var actual = Math.Sign(CompareVersions(current, target));
            if (actual != Math.Sign(expected))
                throw new InvalidOperationException($"component_version_contract:{current}:{target}:expected={expected}:actual={actual}");
        }

        Expect("1.0.5.0", "1.0.5", 0);
        Expect("6.2.9.0", "6.2.9", 0);
        Expect("0.2.1.0", "0.2.1", 0);
        Expect("1.0.5.1", "1.0.5", 1);
        Expect("1.0.4.9", "1.0.5", -1);
        Expect("1.0.5+build.42", "1.0.5", 0);
        Expect("1.0.5-beta", "1.0.5", 0);
    }

    // Lifecycle comparison must use the executable FileVersion. ProductVersion can carry
    // informational/provenance text in older builds and must never turn engineering lineage
    // into a fake semantic upgrade/downgrade decision.
    private static string LifecycleFileVersion(string path)
    {
        try
        {
            if (!File.Exists(path)) return "—";
            var info = FileVersionInfo.GetVersionInfo(path);
            var raw = info.FileVersion ?? "";
            if (string.IsNullOrWhiteSpace(raw)) raw = info.ProductVersion ?? "";
            var normalized = NormalizeVersion(raw);
            return string.IsNullOrWhiteSpace(normalized) ? "نامشخص" : normalized;
        }
        catch { return "نامشخص"; }
    }

    private static string HashIfExists(string path)
    {
        try
        {
            if (!File.Exists(path)) return "";
            using var s = File.OpenRead(path);
            return Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant();
        }
        catch { return ""; }
    }

    private static bool ServiceImageOwnedByInstallRoot(string imagePath, string installRoot)
    {
        if (string.IsNullOrWhiteSpace(imagePath)) return false;
        var root = Path.GetFullPath(installRoot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar) + Path.DirectorySeparatorChar;
        return imagePath.IndexOf(root, StringComparison.OrdinalIgnoreCase) >= 0;
    }

    private static bool RegistrationMatches(string id, string imagePath, string installedExe, string dataRoot)
    {
        if (string.IsNullOrWhiteSpace(imagePath)) return false;
        var normalized = imagePath.Trim();
        if (!normalized.Contains(installedExe, StringComparison.OrdinalIgnoreCase)) return false;
        if (id == "runtime")
        {
            var cfg = Path.Combine(dataRoot, "runtime", "runtime-config.json");
            return normalized.Contains("--config", StringComparison.OrdinalIgnoreCase) && normalized.Contains(cfg, StringComparison.OrdinalIgnoreCase);
        }
        return true;
    }

    private sealed record ServiceProbe(bool Installed, string State, string ImagePath);
    private static ServiceProbe ProbeService(string name)
    {
        var image = "";
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{name}");
            if (key is null) return new(false, "", "");
            image = Convert.ToString(key.GetValue("ImagePath")) ?? "";
        }
        catch { return new(false, "", ""); }
        var state = "UNKNOWN";
        try
        {
            var psi = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe"), $"query \"{name}\"")
            { UseShellExecute = false, RedirectStandardOutput = true, RedirectStandardError = true, CreateNoWindow = true };
            using var p = Process.Start(psi);
            if (p is not null)
            {
                var text = p.StandardOutput.ReadToEnd(); p.WaitForExit(2500);
                if (text.Contains("RUNNING", StringComparison.OrdinalIgnoreCase)) state = "RUNNING";
                else if (text.Contains("START_PENDING", StringComparison.OrdinalIgnoreCase)) state = "START_PENDING";
                else if (text.Contains("STOPPED", StringComparison.OrdinalIgnoreCase)) state = "STOPPED";
            }
        }
        catch { }
        return new(true, state, image);
    }

    private static bool IsUnder(string path, string root)
    {
        root = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        return Path.GetFullPath(path).StartsWith(root, StringComparison.OrdinalIgnoreCase);
    }
    private static string S(JsonElement e, string name) => e.GetProperty(name).GetString() ?? "";
    private static bool B(JsonElement e, string name) => e.GetProperty(name).GetBoolean();
}
