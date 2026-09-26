using System.ComponentModel;
using System.Diagnostics;
using System.Security.Principal;
using System.Text.Json;
using System.Windows.Forms;

namespace Sokna.SetupUi;

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
    private readonly ComboBox _mode = new() { DropDownStyle = ComboBoxStyle.DropDownList };
    private readonly TextBox _appRoot = new() { Text = @"C:\SOKNA\Cafe" };
    private readonly TextBox _dataRoot = new() { Text = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA") };
    private readonly TextBox _hostname = new() { Text = "sokna.local" };
    private readonly TextBox _php = new();
    private readonly TextBox _openssl = new();
    private readonly TextBox _webServer = new();
    private readonly TextBox _dbHost = new() { Text = "127.0.0.1" };
    private readonly TextBox _dbPort = new() { Text = "3306" };
    private readonly TextBox _dbName = new() { Text = "sokna_cafe" };
    private readonly TextBox _dbUser = new() { Text = "sokna" };
    private readonly TextBox _dbPass = new() { UseSystemPasswordChar = true };
    private readonly TextBox _adminUser = new() { Text = "admin" };
    private readonly TextBox _adminPass = new() { UseSystemPasswordChar = true };
    private readonly TextBox _cafeName = new() { Text = "کافه سکنا" };
    private readonly NumericUpDown _tableCount = new() { Minimum = 1, Maximum = 200, Value = 30 };
    private readonly TextBox _recoveryFile = new();
    private readonly TextBox _recoveryPass = new() { UseSystemPasswordChar = true };
    private readonly Button _run = new() { Text = "بررسی و اجرا", AutoSize = true, MinimumSize = new Size(120, 44) };
    private readonly Label _status = new() { AutoSize = true, MaximumSize = new Size(720, 0) };
    private readonly Panel _businessPanel = new() { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Dock = DockStyle.Top };
    private readonly Panel _adminPanel = new() { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Dock = DockStyle.Top };
    private readonly Panel _recoveryPanel = new() { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Dock = DockStyle.Top };

    internal SetupForm()
    {
        Text = "راه‌اندازی سکنا";
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = true;
        StartPosition = FormStartPosition.CenterScreen;
        MinimumSize = new Size(720, 560);
        Size = new Size(860, 680);
        AutoScaleMode = AutoScaleMode.Dpi;
        Font = new Font("Segoe UI", 10F);

        _mode.Items.AddRange(new object[] { "نصب جدید", "بازیابی روی رایانه جدید", "تعمیر نصب موجود" });
        _mode.SelectedIndex = 0;
        _php.Text = FindExecutable("php.exe");
        _openssl.Text = FindExecutable("openssl.exe");
        _webServer.Text = FindExecutable("httpd.exe", "apache.exe");

        var outer = new TableLayoutPanel { Dock = DockStyle.Fill, AutoScroll = true, Padding = new Padding(24), ColumnCount = 1, RowCount = 1 };
        outer.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        var content = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Dock = DockStyle.Top, Width = 740 };
        content.Controls.Add(Title("راه‌اندازی SOKNA Cafe Local"));
        content.Controls.Add(Help("این صفحه اطلاعات لازم برای نصب، بازیابی یا تعمیر سکنا را جمع‌آوری می‌کند. عملیات اصلی فقط پس از بررسی کامل و با اجازه مدیر ویندوز انجام می‌شود. اتصال امن محلی و Apache برای استفاده عملیاتی سکنا الزامی‌اند."));
        content.Controls.Add(Field("حالت", _mode));
        content.Controls.Add(PathField("پوشه برنامه (قابل تغییر)", _appRoot, true));
        content.Controls.Add(PathField("پوشه داده (جدا از برنامه)", _dataRoot, true));
        content.Controls.Add(Field("نام محلی", _hostname));
        content.Controls.Add(PathField("PHP 8.2+ (x64)", _php, false, "php.exe"));
        content.Controls.Add(PathField("OpenSSL", _openssl, false, "openssl.exe"));
        content.Controls.Add(PathField("Apache", _webServer, false, "httpd.exe;apache.exe"));
        var offlinePrerequisites = BuildOfflinePrerequisiteAction();
        if (offlinePrerequisites is not null) content.Controls.Add(offlinePrerequisites);
        content.Controls.Add(Help("پوشه داده باید خارج از پوشه برنامه باشد. سکنا Apache را پیکربندی می‌کند، اما شروع/توقف/راه‌اندازی مجدد وب‌سرور همچنان در اختیار مالک خارجی آن است. اگر پیش‌نیازی نصب نیست، از بسته آفلاین تأییدشده استفاده کنید و سپس همین بررسی را دوباره اجرا کنید."));

        _businessPanel.Controls.Add(BuildBusinessPanel());
        _adminPanel.Controls.Add(BuildAdminPanel());
        _recoveryPanel.Controls.Add(BuildRecoveryPanel());
        content.Controls.Add(_businessPanel);
        content.Controls.Add(_adminPanel);
        content.Controls.Add(_recoveryPanel);

        var actions = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.LeftToRight, Padding = new Padding(0, 16, 0, 0) };
        var cancel = new Button { Text = "بستن", AutoSize = true, MinimumSize = new Size(96, 44) };
        cancel.Click += (_, _) => Close();
        _run.MinimumSize = new Size(128, 44);
        _run.Click += async (_, _) => await ExecuteAsync();
        actions.Controls.Add(cancel);
        actions.Controls.Add(_run);
        content.Controls.Add(actions);
        content.Controls.Add(_status);
        outer.Controls.Add(content, 0, 0);
        Controls.Add(outer);

        _mode.SelectedIndexChanged += (_, _) => RefreshMode();
        RefreshMode();
    }

    private Control BuildBusinessPanel()
    {
        var box = Group("پایگاه داده");
        box.Controls.Add(Field("میزبان", _dbHost));
        box.Controls.Add(Field("پورت", _dbPort));
        box.Controls.Add(Field("نام پایگاه داده خالی", _dbName));
        box.Controls.Add(Field("کاربر پایگاه داده", _dbUser));
        box.Controls.Add(Field("رمز پایگاه داده", _dbPass));
        return box;
    }

    private Control BuildAdminPanel()
    {
        var box = Group("اطلاعات اولیه نصب جدید");
        box.Controls.Add(Field("نام کافه", _cafeName));
        box.Controls.Add(Field("نام کاربری مدیر", _adminUser));
        box.Controls.Add(Field("رمز مدیر", _adminPass));
        box.Controls.Add(Field("تعداد میز", _tableCount));
        return box;
    }

    private Control BuildRecoveryPanel()
    {
        var box = Group("بازیابی");
        box.Controls.Add(PathField("فایل بازیابی / پشتیبان", _recoveryFile, false, "*.skb;*.zip;*.json;*.*"));
        box.Controls.Add(Field("رمز فایل بازیابی (در صورت نیاز)", _recoveryPass));
        return box;
    }

    private static Label Title(string text) => new() { Text = text, AutoSize = true, Font = new Font("Segoe UI", 15F, FontStyle.Bold), Margin = new Padding(0, 0, 0, 10) };
    private static Label Help(string text) => new() { Text = text, AutoSize = true, MaximumSize = new Size(740, 0), Margin = new Padding(0, 0, 0, 16) };

    private static FlowLayoutPanel Group(string title)
    {
        var panel = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, Padding = new Padding(0, 12, 0, 8), Margin = new Padding(0) };
        panel.Controls.Add(new Label { Text = title, AutoSize = true, Font = new Font("Segoe UI", 11F, FontStyle.Bold) });
        return panel;
    }

    private static Control Field(string label, Control control)
    {
        var row = new TableLayoutPanel { AutoSize = true, ColumnCount = 2, Width = 700, Margin = new Padding(0, 4, 0, 4) };
        row.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 190));
        row.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        var l = new Label { Text = label, AutoSize = true, Anchor = AnchorStyles.Right, Padding = new Padding(0, 7, 0, 0) };
        control.Width = 480;
        control.Anchor = AnchorStyles.Left | AnchorStyles.Right;
        control.AccessibleName = label;
        row.Controls.Add(l, 0, 0);
        row.Controls.Add(control, 1, 0);
        return row;
    }

    private static Control PathField(string label, TextBox text, bool folder, string filter = "*.*")
    {
        var holder = new TableLayoutPanel { AutoSize = true, ColumnCount = 2, Width = 480 };
        holder.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        holder.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        text.Dock = DockStyle.Fill;
        var browse = new Button { Text = "انتخاب…", AutoSize = true, MinimumSize = new Size(88, 44) };
        browse.Click += (_, _) =>
        {
            if (folder)
            {
                using var d = new FolderBrowserDialog { SelectedPath = Directory.Exists(text.Text) ? text.Text : "" };
                if (d.ShowDialog() == DialogResult.OK) text.Text = d.SelectedPath;
            }
            else
            {
                using var d = new OpenFileDialog { Filter = BuildFilter(filter), CheckFileExists = true };
                if (File.Exists(text.Text)) d.FileName = text.Text;
                if (d.ShowDialog() == DialogResult.OK) text.Text = d.FileName;
            }
        };
        holder.Controls.Add(text, 0, 0);
        holder.Controls.Add(browse, 1, 0);
        return Field(label, holder);
    }

    private static string BuildFilter(string raw)
    {
        if (raw.Contains(';')) return "فایل‌های پشتیبانی|" + raw + "|همه فایل‌ها|*.*";
        if (raw == "php.exe") return "PHP|php.exe|همه فایل‌ها|*.*";
        if (raw == "openssl.exe") return "OpenSSL|openssl.exe|همه فایل‌ها|*.*";
        if (raw.Contains("httpd")) return "Apache|httpd.exe;apache.exe|همه فایل‌ها|*.*";
        return "همه فایل‌ها|*.*";
    }

    private Control? BuildOfflinePrerequisiteAction()
    {
        var root = Path.Combine(AppContext.BaseDirectory, "Prerequisites");
        var manifest = Path.Combine(root, "bundle-manifest.json");
        var verifier = Path.Combine(AppContext.BaseDirectory, "verify-prerequisite-bundle.ps1");
        if (!Directory.Exists(root) || !File.Exists(manifest) || !File.Exists(verifier)) return null;
        var panel = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.RightToLeft, WrapContents = false, Margin = new Padding(0, 2, 0, 12) };
        var open = new Button { Text = "باز کردن پیش‌نیازهای آفلاین", AutoSize = true, MinimumSize = new Size(180, 44) };
        open.Click += async (_, _) =>
        {
            try
            {
                open.Enabled = false;
                _status.Text = "در حال بررسی یکپارچگی بسته پیش‌نیازها…";
                var code = await VerifyPrerequisiteBundleAsync(root, verifier);
                if (code != 0) throw new InvalidOperationException("بسته پیش‌نیازهای آفلاین تأیید نشد؛ از فایل‌های آن استفاده نکنید.");
                Process.Start(new ProcessStartInfo("explorer.exe", root) { UseShellExecute = true });
                _status.Text = "بسته آفلاین تأیید شد. پیش‌نیاز لازم را نصب کنید و سپس «بررسی و اجرا» را دوباره بزنید.";
            }
            catch (Exception e) { _status.Text = Safe(e.Message); MessageBox.Show(this, _status.Text, "پیش‌نیازهای سکنا", MessageBoxButtons.OK, MessageBoxIcon.Error); }
            finally { open.Enabled = true; }
        };
        panel.Controls.Add(open);
        panel.Controls.Add(new Label { Text = "این بسته فقط cache تأییدشده است؛ نصب و lifecycle پیش‌نیازهای مشترک همچنان مستقل از سکناست.", AutoSize = true, MaximumSize = new Size(480, 0), Padding = new Padding(8, 9, 0, 0) });
        return panel;
    }

    private static async Task<int> VerifyPrerequisiteBundleAsync(string root, string verifier)
    {
        var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        RequireFile(powershell, "Windows PowerShell برای بررسی بسته پیش‌نیازها پیدا نشد.");
        var psi = new ProcessStartInfo(powershell) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardError = true, RedirectStandardOutput = true };
        foreach (var arg in new[] { "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", verifier, "-BundleRoot", root }) psi.ArgumentList.Add(arg);
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("بررسی بسته پیش‌نیازها اجرا نشد.");
        await process.WaitForExitAsync();
        return process.ExitCode;
    }

    private void RefreshMode()
    {
        var mode = SelectedMode();
        _businessPanel.Visible = mode is "new" or "recover";
        _adminPanel.Visible = mode == "new";
        _recoveryPanel.Visible = mode == "recover";
        _run.Text = mode switch { "repair" => "بررسی و تعمیر", "recover" => "بررسی و بازیابی", _ => "بررسی و نصب" };
    }

    private string SelectedMode() => _mode.SelectedIndex switch { 1 => "recover", 2 => "repair", _ => "new" };

    private async Task ExecuteAsync()
    {
        _status.Text = "";
        string? temp = null;
        try
        {
            ValidateInputs();
            _run.Enabled = false;
            _status.Text = "در حال آماده‌سازی ورودی امن و اجرای بررسی‌های نصب…";
            temp = CreatePrivateTempDirectory();
            var mode = SelectedMode();
            var setupConfig = "";
            var passphraseFile = "";
            if (mode is "new" or "recover")
            {
                setupConfig = Path.Combine(temp, "setup-config.json");
                await File.WriteAllTextAsync(setupConfig, JsonSerializer.Serialize(BuildSetupConfig(mode), JsonOptions()));
                if (mode == "recover" && !string.IsNullOrEmpty(_recoveryPass.Text))
                {
                    passphraseFile = Path.Combine(temp, "recovery-passphrase.txt");
                    await File.WriteAllTextAsync(passphraseFile, _recoveryPass.Text);
                }
            }
            var planPath = Path.Combine(temp, "setup-plan.json");
            var plan = new Dictionary<string, object?>
            {
                ["schema_version"] = 1,
                ["mode"] = mode,
                ["shell_root"] = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar),
                ["app_root"] = Path.GetFullPath(_appRoot.Text.Trim()),
                ["data_root"] = Path.GetFullPath(_dataRoot.Text.Trim()),
                ["php_exe"] = Path.GetFullPath(_php.Text.Trim()),
                ["openssl_exe"] = Path.GetFullPath(_openssl.Text.Trim()),
                ["web_server_exe"] = Path.GetFullPath(_webServer.Text.Trim()),
                ["setup_config_file"] = setupConfig,
                ["recovery_file"] = mode == "recover" ? Path.GetFullPath(_recoveryFile.Text.Trim()) : "",
                ["recovery_passphrase_file"] = passphraseFile,
                ["hostname"] = _hostname.Text.Trim().ToLowerInvariant(),
                ["require_web_server_preflight"] = true,
                ["skip_https"] = false,
                ["skip_service"] = false
            };
            await File.WriteAllTextAsync(planPath, JsonSerializer.Serialize(plan, JsonOptions()));
            var code = await RunSetupHostAsync(planPath);
            _status.Text = code switch
            {
                0 => "عملیات با موفقیت کامل شد. برای ورود، SOKNA Cafe را از منوی Start یا Desktop باز کنید.",
                20 => "پیکربندی سکنا آماده است، اما Apache باید توسط مالک وب‌سرور Reload یا Restart شود. سپس «تعمیر نصب موجود» را اجرا کنید تا سلامت HTTPS تأیید شود.",
                21 => "سلامت HTTPS محلی تأیید نشد. Apache و تنظیمات سکنا را بررسی کنید و سپس «تعمیر نصب موجود» را دوباره اجرا کنید.",
                3010 => "مرحله فعلی کامل شد و ویندوز نیاز به راه‌اندازی مجدد دارد. پس از راه‌اندازی مجدد ویندوز، همین عملیات را دوباره اجرا کنید.",
                _ => "عملیات کامل نشد. از «گزارش پشتیبانی سکنا» بسته تشخیص را بسازید و مرحله خطا را بررسی کنید."
            };
            if (code == 0) MessageBox.Show(this, _status.Text, "سکنا", MessageBoxButtons.OK, MessageBoxIcon.Information);
            else MessageBox.Show(this, _status.Text, "سکنا", MessageBoxButtons.OK, MessageBoxIcon.Warning);
        }
        catch (Win32Exception e) when (e.NativeErrorCode == 1223)
        {
            _status.Text = "درخواست دسترسی مدیر لغو شد؛ هیچ تغییری اعمال نشد.";
        }
        catch (Exception e)
        {
            _status.Text = Safe(e.Message);
            MessageBox.Show(this, _status.Text, "راه‌اندازی سکنا", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        finally
        {
            _run.Enabled = true;
            if (!string.IsNullOrWhiteSpace(temp)) { try { Directory.Delete(temp, true); } catch { } }
        }
    }

    private Dictionary<string, object?> BuildSetupConfig(string mode)
    {
        var config = new Dictionary<string, object?>
        {
            ["db"] = new Dictionary<string, object?>
            {
                ["host"] = _dbHost.Text.Trim(), ["port"] = _dbPort.Text.Trim(), ["name"] = _dbName.Text.Trim(),
                ["user"] = _dbUser.Text.Trim(), ["pass"] = _dbPass.Text
            },
            ["app_url"] = "https://" + _hostname.Text.Trim().ToLowerInvariant(),
            ["timezone"] = "Asia/Tehran",
            ["data_dir"] = Path.GetFullPath(_dataRoot.Text.Trim()),
            ["local_hostname"] = _hostname.Text.Trim().ToLowerInvariant(),
            ["relay"] = new Dictionary<string, object?> { ["enabled"] = false }
        };
        if (mode == "new")
        {
            config["admin_user"] = _adminUser.Text.Trim();
            config["admin_password"] = _adminPass.Text;
            config["cafe_name"] = _cafeName.Text.Trim();
            config["table_count"] = (int)_tableCount.Value;
        }
        return config;
    }

    private void ValidateInputs()
    {
        var mode = SelectedMode();
        if (string.IsNullOrWhiteSpace(_appRoot.Text) || !Path.IsPathFullyQualified(_appRoot.Text.Trim())) throw new InvalidOperationException("پوشه برنامه باید مسیر کامل ویندوز باشد.");
        if (string.IsNullOrWhiteSpace(_dataRoot.Text) || !Path.IsPathFullyQualified(_dataRoot.Text.Trim())) throw new InvalidOperationException("پوشه داده باید مسیر کامل ویندوز باشد.");
        var appRoot = Path.GetFullPath(_appRoot.Text.Trim());
        var dataRoot = Path.GetFullPath(_dataRoot.Text.Trim());
        if (RootsOverlap(appRoot, dataRoot)) throw new InvalidOperationException("پوشه داده باید کاملاً خارج از پوشه برنامه باشد.");
        RequireFile(_php.Text, "فایل PHP انتخاب نشده یا وجود ندارد. اگر بسته آفلاین پیش‌نیازها در دسترس است، PHP تأییدشده را نصب کنید و دوباره بررسی کنید.");
        RequireFile(_openssl.Text, "فایل OpenSSL انتخاب نشده یا وجود ندارد. اگر بسته آفلاین پیش‌نیازها در دسترس است، نسخه تأییدشده را نصب کنید و دوباره بررسی کنید.");
        RequireFile(_webServer.Text, "فایل Apache انتخاب نشده یا وجود ندارد. اگر بسته آفلاین پیش‌نیازها در دسترس است، توزیع تأییدشده را نصب کنید و دوباره بررسی کنید.");
        var host = _hostname.Text.Trim().ToLowerInvariant();
        if (host.Length is < 1 or > 253 || host.Any(c => !(char.IsAsciiLetterOrDigit(c) || c is '.' or '-')) || host.StartsWith('.') || host.EndsWith('.') || host.Contains("..")) throw new InvalidOperationException("نام محلی سامانه معتبر نیست.");
        if (mode is "new" or "recover")
        {
            if (string.IsNullOrWhiteSpace(_dbName.Text) || string.IsNullOrWhiteSpace(_dbUser.Text)) throw new InvalidOperationException("اطلاعات دیتابیس کامل نیست.");
            if (!int.TryParse(_dbPort.Text, out var port) || port is < 1 or > 65535) throw new InvalidOperationException("پورت دیتابیس معتبر نیست.");
        }
        if (mode == "new")
        {
            if (_adminUser.Text.Trim().Length < 3) throw new InvalidOperationException("نام کاربری مدیر معتبر نیست.");
            if (_adminPass.Text.Length < 8) throw new InvalidOperationException("رمز مدیر باید حداقل ۸ کاراکتر باشد.");
        }
        if (mode == "recover") RequireFile(_recoveryFile.Text, "فایل بازیابی انتخاب نشده یا وجود ندارد.");
        var hostExe = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe");
        RequireFile(hostExe, "جزء داخلی راه‌اندازی سکنا در بسته نصب وجود ندارد.");
    }

    private static bool RootsOverlap(string left, string right)
    {
        static string Normalize(string value) => Path.GetFullPath(value).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var a = Normalize(left);
        var b = Normalize(right);
        if (string.Equals(a, b, StringComparison.OrdinalIgnoreCase)) return true;
        var aPrefix = a + Path.DirectorySeparatorChar;
        var bPrefix = b + Path.DirectorySeparatorChar;
        return b.StartsWith(aPrefix, StringComparison.OrdinalIgnoreCase) || a.StartsWith(bPrefix, StringComparison.OrdinalIgnoreCase);
    }

    private static void RequireFile(string value, string message)
    {
        if (string.IsNullOrWhiteSpace(value) || !Path.IsPathFullyQualified(value.Trim()) || !File.Exists(value.Trim())) throw new InvalidOperationException(message);
    }

    private static async Task<int> RunSetupHostAsync(string planPath)
    {
        var host = Path.Combine(AppContext.BaseDirectory, "SoknaSetupHost.exe");
        var psi = new ProcessStartInfo(host)
        {
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = AppContext.BaseDirectory
        };
        psi.ArgumentList.Add("--plan-file");
        psi.ArgumentList.Add(planPath);
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("Setup Host اجرا نشد.");
        await process.WaitForExitAsync();
        return process.ExitCode;
    }

    private static string CreatePrivateTempDirectory()
    {
        var baseDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Setup");
        Directory.CreateDirectory(baseDir);
        var dir = Path.Combine(baseDir, Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        var sid = WindowsIdentity.GetCurrent().User?.Value ?? throw new InvalidOperationException("شناسه کاربر ویندوز قابل تشخیص نیست.");
        RunIcacls(dir, "/inheritance:r");
        RunIcacls(dir, "/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F", $"*{sid}:(OI)(CI)F");
        return dir;
    }

    private static void RunIcacls(params string[] args)
    {
        var exe = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "icacls.exe");
        var psi = new ProcessStartInfo(exe) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardError = true, RedirectStandardOutput = true };
        foreach (var arg in args) psi.ArgumentList.Add(arg);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("تنظیم دسترسی فایل‌های موقت اجرا نشد.");
        p.WaitForExit();
        if (p.ExitCode != 0) throw new InvalidOperationException("امکان محدودکردن دسترسی فایل‌های موقت وجود ندارد.");
    }

    private static JsonSerializerOptions JsonOptions() => new() { WriteIndented = true };

    private static string FindExecutable(params string[] names)
    {
        var path = Environment.GetEnvironmentVariable("PATH") ?? "";
        foreach (var dir in path.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            foreach (var name in names)
            {
                try { var candidate = Path.Combine(dir.Trim(), name); if (File.Exists(candidate)) return Path.GetFullPath(candidate); } catch { }
            }
        }
        foreach (var candidate in new[]
        {
            @"C:\xampp\php\php.exe", @"C:\xampp\apache\bin\openssl.exe", @"C:\xampp\apache\bin\httpd.exe"
        })
            if (names.Contains(Path.GetFileName(candidate), StringComparer.OrdinalIgnoreCase) && File.Exists(candidate)) return candidate;
        return "";
    }

    private static string Safe(string message)
    {
        var value = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return value.Length > 600 ? value[..600] : value;
    }
}
