using Microsoft.Win32;
using System.ComponentModel;
using System.Diagnostics;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.SetupUi;

internal sealed class PrerequisitePolicy
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("ownership")] public string Ownership { get; init; } = "";
    [JsonPropertyName("automatic_download_allowed")] public bool AutomaticDownloadAllowed { get; init; }
    [JsonPropertyName("automatic_install_allowed")] public bool AutomaticInstallAllowed { get; init; }
    [JsonPropertyName("items")] public List<PrerequisiteItem> Items { get; init; } = [];
}

internal sealed class PrerequisiteItem
{
    [JsonPropertyName("id")] public string Id { get; init; } = "";
    [JsonPropertyName("display_name")] public string DisplayName { get; init; } = "";
    [JsonPropertyName("required_for")] public string RequiredFor { get; init; } = "";
    [JsonPropertyName("blocks_windows_services")] public bool BlocksWindowsServices { get; init; }
    [JsonPropertyName("detection")] public DetectionRule Detection { get; init; } = new();
    [JsonPropertyName("release_lock_dependency")] public string ReleaseLockDependency { get; init; } = "";
    [JsonPropertyName("manual_guidance_fa")] public string ManualGuidanceFa { get; init; } = "";
}

internal sealed class DetectionRule
{
    [JsonPropertyName("type")] public string Type { get; init; } = "";
    [JsonPropertyName("commands")] public List<string> Commands { get; init; } = [];
    [JsonPropertyName("version_argument")] public string VersionArgument { get; init; } = "";
    [JsonPropertyName("minimum_version")] public string MinimumVersion { get; init; } = "";
    [JsonPropertyName("required_extensions")] public List<string> RequiredExtensions { get; init; } = [];
    [JsonPropertyName("registry_path")] public string RegistryPath { get; init; } = "";
    [JsonPropertyName("value_name")] public string ValueName { get; init; } = "";
}

internal sealed class ReleaseLock
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("release_frozen")] public bool ReleaseFrozen { get; init; }
    [JsonPropertyName("artifacts")] public List<LockedArtifact> Artifacts { get; init; } = [];
}

internal sealed class LockedArtifact
{
    [JsonPropertyName("id")] public string Id { get; init; } = "";
    [JsonPropertyName("dependency")] public string Dependency { get; init; } = "";
    [JsonPropertyName("version")] public string Version { get; init; } = "";
    [JsonPropertyName("filename")] public string Filename { get; init; } = "";
    [JsonPropertyName("source_url")] public string SourceUrl { get; init; } = "";
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
    [JsonPropertyName("installation")] public string Installation { get; init; } = "";
    [JsonPropertyName("authenticode")] public AuthenticodePolicy Authenticode { get; init; } = new();
    [JsonPropertyName("instructions_fa")] public string InstructionsFa { get; init; } = "";
}

internal sealed class AuthenticodePolicy
{
    [JsonPropertyName("required")] public bool Required { get; init; }
    [JsonPropertyName("publisher_contains")] public string PublisherContains { get; init; } = "";
}

internal sealed record DetectionResult(bool Satisfied, string Status, string FoundPath, string FoundVersion);

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();
        Application.Run(new SetupForm());
    }
}

internal sealed class SetupForm : Form
{
    private static readonly JsonSerializerOptions StrictJson = new() { PropertyNameCaseInsensitive = false };
    private readonly TextBox _installRoot = new() { Dock = DockStyle.Fill };
    private readonly TextBox _dataRoot = new() { Dock = DockStyle.Fill };
    private readonly TextBox _pairing = new() { Dock = DockStyle.Fill };
    private readonly CheckBox _startPaired = new() { Text = "پس از Pairing سرویس Runtime نیز شروع شود", AutoSize = true, Checked = true };
    private readonly ListView _prereqs = new() { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true, GridLines = true, MultiSelect = false };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Minimum = 0, Maximum = 100 };
    private readonly Label _status = new() { Dock = DockStyle.Fill, AutoSize = true, Text = "آماده" };
    private readonly Button _install = new() { Text = "نصب / به‌روزرسانی سرویس‌ها", AutoSize = true };
    private readonly Button _repair = new() { Text = "تعمیر سرویس‌ها", AutoSize = true };
    private readonly Button _uninstall = new() { Text = "حذف سرویس‌ها", AutoSize = true };
    private readonly Button _refresh = new() { Text = "بررسی دوباره پیش‌نیازها", AutoSize = true };
    private readonly Button _download = new() { Text = "دریافت فایل تأییدشده", AutoSize = true };
    private readonly Button _guidance = new() { Text = "راهنمای نصب دستی", AutoSize = true };
    private readonly Button _browsePairing = new() { Text = "انتخاب…", AutoSize = true };
    private readonly PrerequisitePolicy _policy;
    private readonly ReleaseLock _lock;
    private readonly Dictionary<string, DetectionResult> _results = new(StringComparer.OrdinalIgnoreCase);

    public SetupForm()
    {
        Text = "SOKNA Windows Services";
        Width = 980; Height = 700; MinimumSize = new Size(820, 600); StartPosition = FormStartPosition.CenterScreen;
        RightToLeft = RightToLeft.Yes; RightToLeftLayout = true; Font = new Font("Segoe UI", 10F);
        _installRoot.Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "SOKNA Windows Services");
        _dataRoot.Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
        _policy = LoadJson<PrerequisitePolicy>(Path.Combine(AppContext.BaseDirectory, "prerequisites.json"), "فایل سیاست پیش‌نیازها");
        _lock = LoadJson<ReleaseLock>(Path.Combine(AppContext.BaseDirectory, "release-lock.json"), "release lock پیش‌نیازها");
        ValidateContracts();
        BuildUi();
        Shown += async (_, _) => await RefreshPrerequisitesAsync();
    }

    private void BuildUi()
    {
        var root = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(16), ColumnCount = 1, RowCount = 8 };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize)); root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        var intro = new Label { Dock = DockStyle.Fill, AutoSize = true, Text = "این بسته فقط Runtime و Print Agent سکنا را مدیریت می‌کند. PHP، Apache و MariaDB خارج از مالکیت نصب‌کننده هستند؛ این صفحه فقط سازگاری آن‌ها را بررسی می‌کند یا فایل تأییدشده را برای نصب دستی دریافت می‌کند." };
        root.Controls.Add(intro);
        root.Controls.Add(LabeledRow("پوشه سرویس‌ها", _installRoot));
        root.Controls.Add(LabeledRow("پوشه داده مشترک", _dataRoot));
        _prereqs.Columns.Add("پیش‌نیاز", 260); _prereqs.Columns.Add("وضعیت", 240); _prereqs.Columns.Add("نسخه/مسیر", 360);
        root.Controls.Add(_prereqs);
        var prereqButtons = Flow(_refresh, _download, _guidance); root.Controls.Add(prereqButtons);
        var pairRow = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 3 };
        pairRow.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize)); pairRow.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100)); pairRow.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        pairRow.Controls.Add(new Label { Text = "فایل Pairing (اختیاری)", AutoSize = true, Anchor = AnchorStyles.Right }, 0, 0); pairRow.Controls.Add(_pairing, 1, 0); pairRow.Controls.Add(_browsePairing, 2, 0);
        root.Controls.Add(pairRow);
        var actionRow = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2 };
        actionRow.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100)); actionRow.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        actionRow.Controls.Add(_startPaired, 0, 0); actionRow.Controls.Add(Flow(_install, _repair, _uninstall), 1, 0); root.Controls.Add(actionRow);
        var bottom = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2 };
        bottom.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100)); bottom.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 260));
        bottom.Controls.Add(_status, 0, 0); bottom.Controls.Add(_progress, 1, 0); root.Controls.Add(bottom);
        Controls.Add(root);
        _refresh.Click += async (_, _) => await RefreshPrerequisitesAsync();
        _download.Click += async (_, _) => await DownloadSelectedAsync();
        _guidance.Click += (_, _) => ShowGuidance();
        _browsePairing.Click += (_, _) => BrowsePairing();
        _install.Click += async (_, _) => await RunLifecycleAsync("install");
        _repair.Click += async (_, _) => await RunLifecycleAsync("repair");
        _uninstall.Click += async (_, _) => await RunLifecycleAsync("uninstall");
    }

    private static Control LabeledRow(string label, Control control)
    {
        var row = new TableLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, ColumnCount = 2 };
        row.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize)); row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        row.Controls.Add(new Label { Text = label, AutoSize = true, Anchor = AnchorStyles.Right }, 0, 0); row.Controls.Add(control, 1, 0); return row;
    }
    private static FlowLayoutPanel Flow(params Control[] controls) { var p = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft, WrapContents = false }; p.Controls.AddRange(controls); return p; }

    private void ValidateContracts()
    {
        if (_policy.Format != "sokna-windows-prerequisites-v2" || _policy.SchemaVersion != 2 || _policy.Ownership != "external" || _policy.AutomaticInstallAllowed)
            throw new InvalidOperationException("قرارداد پیش‌نیازهای Windows Services معتبر نیست.");
        if (_lock.Format != "sokna-windows-prerequisite-lock-v1" || _lock.SchemaVersion != 1 || !_lock.ReleaseFrozen)
            throw new InvalidOperationException("release lock پیش‌نیازها frozen و معتبر نیست.");
        foreach (var a in _lock.Artifacts)
        {
            if (!Uri.TryCreate(a.SourceUrl, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps || !IsSha(a.Sha256) || a.Size <= 0 || Path.GetFileName(a.Filename) != a.Filename || a.Installation != "manual-external")
                throw new InvalidOperationException("یکی از artifactهای release lock معتبر نیست.");
            if (a.Authenticode.Required && string.IsNullOrWhiteSpace(a.Authenticode.PublisherContains)) throw new InvalidOperationException("سیاست امضای artifact کامل نیست.");
        }
    }

    private async Task RefreshPrerequisitesAsync()
    {
        SetBusy(true, "در حال بررسی پیش‌نیازها…");
        try
        {
            _results.Clear(); _prereqs.Items.Clear();
            foreach (var item in _policy.Items)
            {
                var result = await Task.Run(() => Detect(item)); _results[item.Id] = result;
                var row = new ListViewItem(item.DisplayName) { Tag = item.Id };
                row.SubItems.Add(result.Status); row.SubItems.Add(string.Join(" · ", new[] { result.FoundVersion, result.FoundPath }.Where(x => !string.IsNullOrWhiteSpace(x)))); _prereqs.Items.Add(row);
            }
            var blockers = _policy.Items.Where(x => x.BlocksWindowsServices && (!_results.TryGetValue(x.Id, out var r) || !r.Satisfied)).ToList();
            _status.Text = blockers.Count == 0 ? "پیش‌نیازهای blocking سرویس‌ها آماده‌اند. موارد Local Web صرفاً اطلاع‌رسانی هستند." : "برخی پیش‌نیازهای لازم برای سرویس‌ها آماده نیستند: " + string.Join("، ", blockers.Select(x => x.DisplayName));
        }
        catch (Exception e) { ShowError(e.Message); }
        finally { SetBusy(false); }
    }

    private DetectionResult Detect(PrerequisiteItem item)
    {
        try
        {
            if (item.Detection.Type == "registry") return DetectRegistry(item.Detection);
            var exe = FindCommand(item.Detection.Commands);
            if (string.IsNullOrWhiteSpace(exe)) return new(false, "پیدا نشد", "", "");
            var versionOutput = RunCapture(exe, item.Detection.VersionArgument);
            var version = ExtractVersion(versionOutput);
            if (!VersionAtLeast(version, item.Detection.MinimumVersion)) return new(false, "نسخه قدیمی", exe, version);
            if (item.Detection.Type == "php" && item.Detection.RequiredExtensions.Count > 0)
            {
                var modules = RunCapture(exe, "-m").Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries).Select(x => x.Trim()).ToHashSet(StringComparer.OrdinalIgnoreCase);
                var missing = item.Detection.RequiredExtensions.Where(x => !modules.Contains(x)).ToList();
                if (missing.Count > 0) return new(false, "extension ناقص: " + string.Join(", ", missing), exe, version);
            }
            return new(true, "سازگار", exe, version);
        }
        catch (Exception e) { return new(false, "خطای بررسی: " + Safe(e.Message), "", ""); }
    }

    private static DetectionResult DetectRegistry(DetectionRule rule)
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            using var baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
            using var key = baseKey.OpenSubKey(rule.RegistryPath, false); if (key is null) continue;
            var value = Convert.ToString(key.GetValue(rule.ValueName)) ?? ""; var version = ExtractVersion(value);
            if (VersionAtLeast(version, rule.MinimumVersion)) return new(true, "سازگار", "HKLM\\" + rule.RegistryPath, version);
            if (!string.IsNullOrWhiteSpace(version)) return new(false, "نسخه قدیمی", "HKLM\\" + rule.RegistryPath, version);
        }
        return new(false, "پیدا نشد", "", "");
    }

    private async Task DownloadSelectedAsync()
    {
        if (!_policy.AutomaticDownloadAllowed) { ShowError("دریافت آنلاین طبق policy غیرفعال است."); return; }
        var item = SelectedItem(); if (item is null) { ShowError("ابتدا یک پیش‌نیاز را انتخاب کنید."); return; }
        var artifact = _lock.Artifacts.SingleOrDefault(x => x.Dependency.Equals(item.ReleaseLockDependency, StringComparison.OrdinalIgnoreCase));
        if (artifact is null) { ShowError("برای این پیش‌نیاز artifact قفل‌شده‌ای وجود ندارد."); return; }
        SetBusy(true, "در حال دریافت فایل تأییدشده…");
        try
        {
            var cache = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "PrerequisiteCache"); Directory.CreateDirectory(cache);
            var final = Path.Combine(cache, artifact.Filename); var partial = final + ".partial";
            if (File.Exists(final) && await VerifyArtifactAsync(final, artifact)) { _status.Text = "فایل از قبل تأیید شده است: " + final; OpenExplorer(final); return; }
            if (File.Exists(final)) File.Delete(final);
            await DownloadResumableAsync(artifact, partial);
            if (new FileInfo(partial).Length != artifact.Size) throw new InvalidOperationException("اندازه فایل دریافت‌شده با release lock یکسان نیست.");
            if (!await VerifyArtifactAsync(partial, artifact)) throw new InvalidOperationException("هش یا امضای فایل دریافت‌شده معتبر نیست.");
            File.Move(partial, final, true); _progress.Value = 100; _status.Text = "فایل تأیید شد. نصب خودکار انجام نمی‌شود: " + final; OpenExplorer(final);
        }
        catch (Exception e) { ShowError(e.Message); }
        finally { SetBusy(false); }
    }

    private async Task DownloadResumableAsync(LockedArtifact artifact, string partial)
    {
        var existing = File.Exists(partial) ? new FileInfo(partial).Length : 0L; if (existing < 0 || existing > artifact.Size) { File.Delete(partial); existing = 0; }
        using var handler = new HttpClientHandler { AllowAutoRedirect = true, AutomaticDecompression = DecompressionMethods.None };
        using var client = new HttpClient(handler) { Timeout = TimeSpan.FromMinutes(20) };
        using var request = new HttpRequestMessage(HttpMethod.Get, artifact.SourceUrl);
        if (existing > 0) request.Headers.Range = new RangeHeaderValue(existing, null);
        using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead);
        if (response.RequestMessage?.RequestUri?.Scheme != Uri.UriSchemeHttps) throw new InvalidOperationException("redirect دانلود از HTTPS خارج شد.");
        if (existing > 0 && response.StatusCode != HttpStatusCode.PartialContent) { existing = 0; if (File.Exists(partial)) File.Delete(partial); }
        response.EnsureSuccessStatusCode();
        var mode = existing > 0 ? FileMode.Append : FileMode.Create;
        await using var input = await response.Content.ReadAsStreamAsync(); await using var output = new FileStream(partial, mode, FileAccess.Write, FileShare.None, 128 * 1024, true);
        var buffer = new byte[128 * 1024]; long done = existing; int read;
        while ((read = await input.ReadAsync(buffer)) > 0)
        {
            await output.WriteAsync(buffer.AsMemory(0, read)); done += read;
            var pct = artifact.Size <= 0 ? 0 : (int)Math.Clamp(done * 100L / artifact.Size, 0, 100); _progress.Value = pct; _status.Text = $"دریافت {artifact.Filename}: {pct}%"; Application.DoEvents();
            if (done > artifact.Size) throw new InvalidOperationException("حجم دانلود از اندازه frozen عبور کرد.");
        }
    }

    private static async Task<bool> VerifyArtifactAsync(string path, LockedArtifact artifact)
    {
        await using var stream = File.OpenRead(path); var hash = Convert.ToHexString(await SHA256.HashDataAsync(stream)).ToLowerInvariant();
        if (!hash.Equals(artifact.Sha256, StringComparison.OrdinalIgnoreCase)) return false;
        if (!artifact.Authenticode.Required) return true;
        var ps = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        var escaped = path.Replace("'", "''"); var publisher = artifact.Authenticode.PublisherContains.Replace("'", "''");
        var script = $"$s=Get-AuthenticodeSignature -LiteralPath '{escaped}'; if($s.Status -ne 'Valid' -or -not $s.SignerCertificate -or $s.SignerCertificate.Subject.IndexOf('{publisher}',[StringComparison]::OrdinalIgnoreCase) -lt 0){{exit 7}}";
        var psi = new ProcessStartInfo(ps) { UseShellExecute = false, CreateNoWindow = true }; psi.ArgumentList.Add("-NoProfile"); psi.ArgumentList.Add("-NonInteractive"); psi.ArgumentList.Add("-Command"); psi.ArgumentList.Add(script);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("بررسی Authenticode اجرا نشد."); await p.WaitForExitAsync(); return p.ExitCode == 0;
    }

    private async Task RunLifecycleAsync(string mode)
    {
        if (mode != "uninstall")
        {
            var blockers = _policy.Items.Where(x => x.BlocksWindowsServices && (!_results.TryGetValue(x.Id, out var r) || !r.Satisfied)).ToList();
            if (blockers.Count > 0) { ShowError("پیش از نصب سرویس‌ها این موارد باید آماده شوند: " + string.Join("، ", blockers.Select(x => x.DisplayName))); return; }
        }
        ValidatePath(_installRoot.Text, "پوشه سرویس‌ها"); ValidatePath(_dataRoot.Text, "پوشه داده");
        if (!string.IsNullOrWhiteSpace(_pairing.Text) && (!Path.IsPathFullyQualified(_pairing.Text.Trim()) || !File.Exists(_pairing.Text.Trim()))) { ShowError("فایل Pairing معتبر نیست."); return; }
        SetBusy(true, "در حال اجرای lifecycle سرویس‌ها…"); string? temp = null;
        try
        {
            temp = Path.Combine(Path.GetTempPath(), "sokna-services-plan-" + Guid.NewGuid().ToString("N") + ".json");
            var plan = new Dictionary<string, object?> { ["schema_version"] = 2, ["mode"] = mode, ["shell_root"] = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar), ["install_root"] = Path.GetFullPath(_installRoot.Text.Trim()), ["data_root"] = Path.GetFullPath(_dataRoot.Text.Trim()), ["pairing_file"] = string.IsNullOrWhiteSpace(_pairing.Text) ? "" : Path.GetFullPath(_pairing.Text.Trim()), ["start_when_paired"] = _startPaired.Checked };
            await File.WriteAllTextAsync(temp, JsonSerializer.Serialize(plan, new JsonSerializerOptions { WriteIndented = true }));
            var host = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe"); if (!File.Exists(host)) throw new InvalidOperationException("SoknaSetupHost.exe در بسته وجود ندارد.");
            var psi = new ProcessStartInfo(host) { UseShellExecute = true, Verb = "runas", WorkingDirectory = AppContext.BaseDirectory }; psi.ArgumentList.Add("--plan-file"); psi.ArgumentList.Add(temp);
            using var p = Process.Start(psi) ?? throw new InvalidOperationException("Setup Host اجرا نشد."); await p.WaitForExitAsync();
            if (p.ExitCode != 0) throw new InvalidOperationException("عملیات با کد " + p.ExitCode + " متوقف شد. گزارش نصب را بررسی کنید.");
            _status.Text = mode switch { "uninstall" => "سرویس‌های سکنا حذف شدند؛ داده‌ها و زیرساخت خارجی دست‌نخورده باقی ماندند.", "repair" => "سرویس‌های سکنا تعمیر شدند.", _ => "Runtime و Print Agent نصب/به‌روزرسانی شدند." };
            MessageBox.Show(this, _status.Text, "SOKNA Windows Services", MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223) { _status.Text = "درخواست دسترسی مدیر لغو شد؛ تغییری اعمال نشد."; }
        catch (Exception e) { ShowError(e.Message); }
        finally { if (temp is not null) try { File.Delete(temp); } catch { } SetBusy(false); }
    }

    private void BrowsePairing() { using var d = new OpenFileDialog { Filter = "JSON files (*.json)|*.json|All files (*.*)|*.*", CheckFileExists = true }; if (d.ShowDialog(this) == DialogResult.OK) _pairing.Text = d.FileName; }
    private void ShowGuidance() { var item = SelectedItem(); if (item is null) { ShowError("ابتدا یک پیش‌نیاز را انتخاب کنید."); return; } var art = _lock.Artifacts.FirstOrDefault(x => x.Dependency.Equals(item.ReleaseLockDependency, StringComparison.OrdinalIgnoreCase)); MessageBox.Show(this, (art?.InstructionsFa ?? item.ManualGuidanceFa) + "\n\nSOKNA فایل را اجرا یا نصب نمی‌کند؛ پس از نصب دستی، «بررسی دوباره» را بزنید.", item.DisplayName, MessageBoxButtons.OK, MessageBoxIcon.Information); }
    private PrerequisiteItem? SelectedItem() { if (_prereqs.SelectedItems.Count != 1) return null; var id = Convert.ToString(_prereqs.SelectedItems[0].Tag) ?? ""; return _policy.Items.SingleOrDefault(x => x.Id == id); }
    private void SetBusy(bool busy, string? text = null) { foreach (var b in new[] { _install, _repair, _uninstall, _refresh, _download, _guidance, _browsePairing }) b.Enabled = !busy; if (text is not null) _status.Text = text; if (!busy && _progress.Value == 100) _progress.Value = 0; }
    private void ShowError(string message) { _status.Text = Safe(message); MessageBox.Show(this, _status.Text, "SOKNA Windows Services", MessageBoxButtons.OK, MessageBoxIcon.Warning); }
    private static void ValidatePath(string value, string label) { if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value.Trim())) throw new InvalidOperationException(label + " باید مسیر کامل ویندوز باشد."); _ = Path.GetFullPath(value.Trim()); }
    private static T LoadJson<T>(string path, string label) { if (!File.Exists(path)) throw new InvalidOperationException(label + " در بسته وجود ندارد."); return JsonSerializer.Deserialize<T>(File.ReadAllText(path), StrictJson) ?? throw new InvalidOperationException(label + " معتبر نیست."); }
    private static string FindCommand(IEnumerable<string> names) { var path = Environment.GetEnvironmentVariable("PATH") ?? ""; foreach (var d in path.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries)) foreach (var n in names) try { var p = Path.Combine(d.Trim().Trim('"'), n); if (File.Exists(p)) return Path.GetFullPath(p); } catch { } return ""; }
    private static string RunCapture(string exe, string args) { var psi = new ProcessStartInfo(exe) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true }; foreach (var a in SplitArgs(args)) psi.ArgumentList.Add(a); using var p = Process.Start(psi) ?? throw new InvalidOperationException("فرآیند بررسی اجرا نشد."); var o = p.StandardOutput.ReadToEndAsync(); var e = p.StandardError.ReadToEndAsync(); if (!p.WaitForExit(15000)) { try { p.Kill(true); } catch { } throw new InvalidOperationException("زمان بررسی پیش‌نیاز تمام شد."); } Task.WaitAll(o, e); return o.Result + "\n" + e.Result; }
    private static IEnumerable<string> SplitArgs(string value) => string.IsNullOrWhiteSpace(value) ? [] : value.Split(' ', StringSplitOptions.RemoveEmptyEntries);
    private static string ExtractVersion(string text) { var m = System.Text.RegularExpressions.Regex.Match(text ?? "", @"(?<!\d)(\d+\.\d+(?:\.\d+){0,2})"); return m.Success ? m.Groups[1].Value : ""; }
    private static bool VersionAtLeast(string found, string minimum) { if (!Version.TryParse(NormalizeVersion(found), out var f) || !Version.TryParse(NormalizeVersion(minimum), out var m)) return false; return f >= m; }
    private static string NormalizeVersion(string v) { var p = (v ?? "").Split('.', StringSplitOptions.RemoveEmptyEntries).Take(4).ToList(); while (p.Count < 2) p.Add("0"); return string.Join('.', p); }
    private static bool IsSha(string value) => (value ?? "").Length == 64 && value.All(Uri.IsHexDigit);
    private static string Safe(string message) { var v = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim(); return v.Length > 700 ? v[..700] : v; }
    private static void OpenExplorer(string path) { try { Process.Start(new ProcessStartInfo("explorer.exe", $"/select,\"{path}\"") { UseShellExecute = true }); } catch { } }
}
