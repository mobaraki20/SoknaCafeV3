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
        // A label from a new installer cannot authorize an old payload.
        // Fail closed if the actual unified shell manifest, component plan,
        // and compiled dashboard disagree (RC1 legacy-shell regression).
        var shellProofPath = Path.Combine(shellRoot, "payload-manifest.json");
        if (!File.Exists(shellProofPath))
            throw new InvalidOperationException("بستهٔ نصب کامل نیست؛ Manifest یکپارچه وجود ندارد.");
        using (var proofDoc = JsonDocument.Parse(File.ReadAllText(shellProofPath)))
        {
            var proof = proofDoc.RootElement;
            if (proof.GetProperty("format").GetString() != "sokna-windows-services-shell-v3" ||
                proof.GetProperty("schema_version").GetInt32() != 3 ||
                proof.GetProperty("package_version").GetString() != packageVersion ||
                root.GetProperty("components").GetArrayLength() != 5 ||
                proof.GetProperty("components").GetArrayLength() != 5)
                throw new InvalidOperationException("بستهٔ نصب با نسخهٔ اجزا یکسان نیست؛ عملیات متوقف شد.");
        }
        var shellUiPath = Path.Combine(shellRoot, "SoknaSetupUi.exe");
        if (!File.Exists(shellUiPath) ||
            !string.Equals(NormalizeVersion(FileVersionInfo.GetVersionInfo(shellUiPath).ProductVersion ?? ""),
                packageVersion, StringComparison.Ordinal))
            throw new InvalidOperationException("نسخهٔ رابط نصب با نسخهٔ Manifest هم‌خوانی ندارد.");
        var paired = ReadPaired(dataRoot);
        var rows = new List<ComponentPlanRow>();
        foreach (var c in root.GetProperty("components").EnumerateArray())
            rows.Add(BuildRow(c, shellRoot, installRoot, dataRoot, paired, forceRepair));

        PromoteDependentActions(rows);
        return new ComponentPlanSnapshot(packageVersion, DateTimeOffset.UtcNow, rows);
    }

    // Snapshot child rows first: assigning a new parent record invalidates List<T>'s
    // enumerator, including the LINQ Where enumerator used by the old dashboard.
    internal static void PromoteDependentActions(List<ComponentPlanRow> rows)
    {
        foreach (var row in rows.Where(r => r.Required && !r.Primary && r.ParentComponent is not null).ToArray())
        {
            var parentIndex = rows.FindIndex(r => r.ComponentId == row.ParentComponent);
            if (parentIndex < 0) continue;
            var parent = rows[parentIndex];
            if (row.Action == ComponentAction.Blocked && parent.Action != ComponentAction.Blocked)
                rows[parentIndex] = parent with { Action = ComponentAction.Blocked, ActionText = "مسدود", UpdateState = "blocked_incompatible", Reason = $"وابستگی {row.DisplayName} قابل تأیید نیست." };
            else if (row.Action is ComponentAction.Install or ComponentAction.Update or ComponentAction.Repair && parent.Action == ComponentAction.Keep)
                rows[parentIndex] = parent with { Action = ComponentAction.Repair, ActionText = "تعمیر", UpdateState = "repair_required", Reason = $"یکی از اجزای داخلی Maintenance ({row.DisplayName}) نیاز به همگام‌سازی دارد." };
        }
    }

    // Recovery is frozen in the independent lifecycle: only an exact, released
    // 0.1.1 binary allowlisted by SHA256 can remain installed under a 0.1.2
    // package. Do not claim its installed version changed or copy a new binary.
    internal static bool CanKeepFrozenRecovery(string id, string currentVersion, string targetVersion,
        bool acceptedFingerprint, bool registrationHealthy) =>
        id == "maintenance-recovery-console" &&
        string.Equals(currentVersion, "0.1.1", StringComparison.Ordinal) &&
        CompareVersions(currentVersion, targetVersion) < 0 &&
        acceptedFingerprint && registrationHealthy;

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
        var compatibleInstalledHash = IsAcceptedInstalledFingerprint(c, installedHash);
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
            // Windows Services 1.1.19 may coexist with an independently
            // installed Maintenance 0.3.10. Keep the newer pair ONLY after
            // verifying the exact scoped-install receipt, both executables,
            // and the running owned Supervisor service. All other downgrades
            // remain blocked.
            var keepIndependentMaintenance = cmp >= 0 && IsVerifiedIndependentMaintenancePair(
                id, currentVersion, targetVersion, installedHash, registrationHealthy, installRoot, dataRoot);
            // The standalone receipt is guarded by Windows ACLs. The ordinary
            // asInvoker UI is not an authority to read it or to decide whether
            // a newer Maintenance can be overwritten. If the physical pair
            // matches, display a read-only KEEP candidate; the elevated
            // executor must REATTEST the exact scoped receipt and hashes or
            // reject the entire plan before touching any service.
            var pendingPrivilegedProof = !keepIndependentMaintenance && cmp > 0 &&
                IsReadOnlyIndependentMaintenanceCandidate(
                    id, currentVersion, targetVersion, installedHash,
                    registrationHealthy, installRoot);
            if (cmp > 0 && !keepIndependentMaintenance && !pendingPrivilegedProof)
            {
                action = ComponentAction.Blocked; state = "blocked_incompatible"; reason = $"نسخه نصب‌شده {currentVersion} از نسخه هدف {targetVersion} جدیدتر است؛ downgrade خودکار مجاز نیست.";
            }
            else if (keepIndependentMaintenance)
            {
                action = ComponentAction.Keep; state = "independent_compatible_keep";
                reason = $"Maintenance مستقل {currentVersion} با رسید نصب و سلامت هر دو جزء تأیید شد؛ با نسخهٔ Windows Services سازگار و بدون تغییر باقی می‌ماند.";
            }
            else if (pendingPrivilegedProof)
            {
                action = ComponentAction.Keep; state = "independent_privileged_verification_required";
                reason = $"Maintenance مستقل {currentVersion} جدیدتر است و جایگزین نمی‌شود؛ تأیید نهایی رسید و هش‌ها فقط در مرحلهٔ بررسی مدیریتی انجام می‌شود.";
            }
            else if (CanKeepFrozenRecovery(id, currentVersion, targetVersion, compatibleInstalledHash, registrationHealthy))
            {
                action = ComponentAction.Keep; state = "compatible_frozen_recovery";
                reason = $"Recovery Console نصب‌شده {currentVersion} با اثرانگشت رسمی تأیید شد؛ بدون تغییر باقی می‌ماند (نسخه بسته {targetVersion}).";
            }
            else if (cmp < 0)
            {
                action = ComponentAction.Update; state = "required_update"; reason = $"نسخه {currentVersion} باید به {targetVersion} ارتقا یابد.";
            }
            else if (!string.Equals(installedHash, targetHash, StringComparison.OrdinalIgnoreCase) && compatibleInstalledHash && registrationHealthy)
            {
                action = ComponentAction.Keep; state = "up_to_date_compatible_fingerprint"; reason = "نسخه و fingerprint نصب‌شده در فهرست سازگاری رسمی همین release قرار دارد؛ این جزء بدون تغییر نگه داشته می‌شود.";
            }
            else if (!string.Equals(installedHash, targetHash, StringComparison.OrdinalIgnoreCase))
            {
                action = ComponentAction.Repair; state = "repair_required"; reason = "نسخه یکسان است اما fingerprint فایل اجرایی با release هدف یکسان نیست.";
            }
            else if (!registrationHealthy)
            {
                action = ComponentAction.Repair; state = "repair_required"; reason = "ثبت Windows Service با قرارداد release یکسان نیست.";
            }
            else if (forceRepair && id != "maintenance-recovery-console")
            {
                action = ComponentAction.Repair; state = "repair_required"; reason = "تعمیر صریح توسط کاربر درخواست شده است.";
            }
            else
            {
                action = ComponentAction.Keep; state = "up_to_date"; reason = "نسخه، fingerprint و ثبت service با release هدف یکسان است.";
            }
        }

        // Recovery Console is deliberately outside the mutable Maintenance App lifecycle.
        // Known released 0.1.1 fingerprints can be declared compatible by the signed package
        // manifest and remain KEEP. Any unknown drift is blocked before privileged mutation.
        if (id == "maintenance-recovery-console" && action is ComponentAction.Install or ComponentAction.Update or ComponentAction.Repair)
        {
            action = ComponentAction.Blocked;
            state = "blocked_recovery_lifecycle";
            reason = "Recovery Console نیازمند چرخه مستقل Recovery است؛ این بسته قبل از هرگونه تغییر متوقف می‌شود.";
        }

        return new ComponentPlanRow(id, display, kind, primary, required, parent, currentVersion, targetVersion, health, healthText, state, action, ActionText(action), reason,
            installedPath, targetPath, installedHash, targetHash, serviceName, service.State, registrationHealthy, group);
    }

    internal static bool IsAcceptedInstalledFingerprint(JsonElement component, string installedHash)
    {
        if (string.IsNullOrWhiteSpace(installedHash)) return false;
        if (!component.TryGetProperty("accepted_installed_sha256", out var accepted) || accepted.ValueKind != JsonValueKind.Array) return false;
        foreach (var item in accepted.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String) continue;
            if (string.Equals(item.GetString(), installedHash, StringComparison.OrdinalIgnoreCase)) return true;
        }
        return false;
    }

    // Read-only classification only. It can NEVER authorize a mutation;
    // the elevated installer recomputes the full scoped receipt attestation.
    private static bool IsReadOnlyIndependentMaintenanceCandidate(
        string componentId, string observedVersion, string targetVersion,
        string observedSha256, bool registrationHealthy, string installRoot)
    {
        if (componentId is not ("maintenance" or "maintenance-update-engine") ||
            CompareVersions(observedVersion, targetVersion) <= 0 ||
            observedSha256.Length != 64 || !registrationHealthy) return false;
        try
        {
            var app = Path.Combine(installRoot, "Maintenance", "App");
            var supervisor = Path.Combine(app, "SoknaMaintenanceSupervisor.exe");
            var engine = Path.Combine(app, "SoknaMaintenanceUpdateEngine.exe");
            if (!File.Exists(supervisor) || !File.Exists(engine) ||
                CompareVersions(LifecycleFileVersion(supervisor), observedVersion) != 0 ||
                CompareVersions(LifecycleFileVersion(engine), observedVersion) != 0)
                return false;
            var hash = HashIfExists(componentId == "maintenance" ? supervisor : engine);
            if (!string.Equals(hash, observedSha256, StringComparison.OrdinalIgnoreCase))
                return false;
            var service = ProbeService("SoknaMaintenanceSupervisor");
            // The non-elevated UI may lack SCM status-query permission.
            // This is a no-mutation *pending* state only; the privileged core
            // independently demands a running service and scoped receipt.
            return service.Installed &&
                ServiceImageOwnedByInstallRoot(service.ImagePath, installRoot) &&
                RegistrationMatches("maintenance", service.ImagePath, supervisor, "");
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
                                   ArgumentException or InvalidOperationException)
        {
            return false;
        }
    }

    // Maintenance-only has an independent lifecycle. Preserve any newer
    // independently released pair ONLY when a scoped receipt proves matching
    // physical SHA256s, file versions and owned running service. Never downgrade.
    private static bool IsVerifiedIndependentMaintenancePair(
        string componentId, string observedVersion, string targetVersion, string observedSha256,
        bool registrationHealthy, string installRoot, string dataRoot)
    {
        if (componentId is not ("maintenance" or "maintenance-update-engine") ||
            CompareVersions(observedVersion, targetVersion) < 0 ||
            !registrationHealthy || observedSha256.Length != 64)
            return false;

        try
        {
            var app = Path.Combine(installRoot, "Maintenance", "App");
            var supervisor = Path.Combine(app, "SoknaMaintenanceSupervisor.exe");
            var engine = Path.Combine(app, "SoknaMaintenanceUpdateEngine.exe");
            var receiptPath = Path.Combine(dataRoot, "setup", "maintenance-only-component-receipt-v1.json");
            if (!File.Exists(receiptPath) || !File.Exists(supervisor) || !File.Exists(engine))
                return false;

            using var doc = JsonDocument.Parse(File.ReadAllBytes(receiptPath));
            var receipt = doc.RootElement;
            if (receipt.GetProperty("format").GetString() != "sokna-maintenance-only-component-receipt-v1" ||
                receipt.GetProperty("schema_version").GetInt32() != 1 ||
                receipt.GetProperty("release_scope").GetString() != "maintenance_only" ||
                receipt.GetProperty("version").GetString() != observedVersion ||
                !Guid.TryParseExact(receipt.GetProperty("transaction_id").GetString(), "N", out _) ||
                !receipt.GetProperty("windows_shell_state_unchanged").GetBoolean() ||
                !receipt.GetProperty("runtime_unchanged").GetBoolean() ||
                !receipt.GetProperty("print_agent_unchanged").GetBoolean() ||
                !receipt.GetProperty("recovery_console_unchanged").GetBoolean())
                return false;

            var supervisorHash = HashIfExists(supervisor);
            var engineHash = HashIfExists(engine);
            if (supervisorHash.Length != 64 || engineHash.Length != 64 ||
                !string.Equals(LifecycleFileVersion(supervisor), observedVersion, StringComparison.Ordinal) ||
                !string.Equals(LifecycleFileVersion(engine), observedVersion, StringComparison.Ordinal) ||
                !string.Equals(supervisorHash, receipt.GetProperty("supervisor_sha256").GetString(), StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(engineHash, receipt.GetProperty("engine_sha256").GetString(), StringComparison.OrdinalIgnoreCase))
                return false;

            var service = ProbeService("SoknaMaintenanceSupervisor");
            return service.Installed &&
                string.Equals(service.State, "RUNNING", StringComparison.OrdinalIgnoreCase) &&
                ServiceImageOwnedByInstallRoot(service.ImagePath, installRoot) &&
                RegistrationMatches("maintenance", service.ImagePath, supervisor, dataRoot) &&
                string.Equals(observedSha256,
                    componentId == "maintenance" ? supervisorHash : engineHash,
                    StringComparison.OrdinalIgnoreCase);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
                                   JsonException or InvalidOperationException or
                                   KeyNotFoundException or ArgumentException or
                                   FormatException or NotSupportedException)
        {
            return false;
        }
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

        // The compatible-fingerprint allowlist must be exact. A byte-different
        // 1.0.5/6.2.9 binary is NOT silently accepted as KEEP.
        const string accepted = "26deed62524278fa93a9a576d958f9177fe97c878b75829a8dc93a1440955afd";
        using var knownDocument = JsonDocument.Parse("{\"accepted_installed_sha256\":[\"" + accepted + "\"]}");
        if (!IsAcceptedInstalledFingerprint(knownDocument.RootElement, accepted) ||
            IsAcceptedInstalledFingerprint(knownDocument.RootElement, new string('0', 64)) ||
            IsAcceptedInstalledFingerprint(knownDocument.RootElement, ""))
            throw new InvalidOperationException("component_fingerprint_allowlist_contract");
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
            // FileVersion 1.0.5.0 is the same release as manifest 1.0.5.
            // Show the three-part product version, without changing comparison.
            if (normalized.Split('.').Length == 4 && normalized.EndsWith(".0", StringComparison.Ordinal))
                normalized = normalized[..^2];
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
