using Microsoft.Win32;
using System.ComponentModel;
using System.Diagnostics;
using System.Net.Sockets;
using System.Runtime.CompilerServices;
using System.Text;
using System.Text.Json;

namespace Sokna.SetupUi;

internal enum SelfServiceSeverity
{
    Ok,
    Info,
    Warning,
    Error
}

internal enum SelfServiceRemediation
{
    None,
    StartRuntime,
    StartPrint,
    Repair,
    Pairing,
    Prerequisites,
    StartLocalWeb
}

internal sealed record SelfServiceFinding(
    string Code,
    SelfServiceSeverity Severity,
    string Title,
    string Guidance,
    SelfServiceRemediation Remediation = SelfServiceRemediation.None,
    string Technical = "");

internal sealed record ServiceProbe(string Name, bool Installed, bool Running, string ImagePath, string ExecutablePath, bool ExecutableExists);

internal sealed record SelfServiceSnapshot(
    ServiceProbe Runtime,
    ServiceProbe Print,
    bool PrerequisiteReady,
    string PrerequisiteVersion,
    bool Paired,
    string LocalBaseUrl,
    string InstalledVersion,
    string DataRoot,
    bool StateReadable,
    bool DataRootExists,
    bool DataRootWritable,
    bool LocalWebReachable);

internal static class SelfServiceBootstrap
{
    private static readonly ConditionalWeakTable<Form, object> Attached = new();

    [ModuleInitializer]
    internal static void Initialize()
    {
        Application.Idle += (_, _) => AttachOpenForms();
    }

    private static void AttachOpenForms()
    {
        foreach (Form form in Application.OpenForms)
        {
            if (!string.Equals(form.GetType().Name, "PersianDashboardFormV2", StringComparison.Ordinal)) continue;
            if (Attached.TryGetValue(form, out _)) continue;
            Attached.Add(form, new object());
            SelfServiceUx.Attach(form);
        }
    }
}

internal static class SelfServiceUx
{
    private const char Lri = '\u2066';
    private const char Pdi = '\u2069';
    private static readonly string[] TechnicalTokens =
    {
        "Local Web",
        "Windows Services",
        "Runtime",
        "Print Agent",
        "Visual C++",
        "Pairing",
        "Downgrade"
    };

    internal static void Attach(Form owner)
    {
        ReplaceSupportButton(owner);
        NormalizeBidi(owner);

        var timer = new System.Windows.Forms.Timer { Interval = 900 };
        timer.Tick += (_, _) =>
        {
            if (owner.IsDisposed)
            {
                timer.Stop();
                timer.Dispose();
                return;
            }
            NormalizeBidi(owner);
            ImproveFailureHint(owner);
        };
        owner.FormClosed += (_, _) =>
        {
            timer.Stop();
            timer.Dispose();
        };
        timer.Start();
    }

    private static void ReplaceSupportButton(Form owner)
    {
        var oldButton = Descendants<Button>(owner)
            .FirstOrDefault(button => string.Equals(button.Text, "بسته عیب‌یابی", StringComparison.Ordinal));
        if (oldButton?.Parent is not FlowLayoutPanel flow) return;

        var index = flow.Controls.GetChildIndex(oldButton);
        oldButton.Visible = false;

        var diagnosticButton = new Button
        {
            Text = "عیب‌یابی",
            AutoSize = false,
            Size = new Size(92, 36),
            MinimumSize = new Size(92, 36),
            MaximumSize = new Size(92, 36),
            Font = oldButton.Font,
            Cursor = Cursors.Hand,
            FlatStyle = oldButton.FlatStyle,
            BackColor = oldButton.BackColor,
            ForeColor = oldButton.ForeColor,
            Padding = oldButton.Padding,
            Margin = oldButton.Margin,
            UseVisualStyleBackColor = oldButton.UseVisualStyleBackColor
        };
        diagnosticButton.FlatAppearance.BorderColor = oldButton.FlatAppearance.BorderColor;
        diagnosticButton.FlatAppearance.BorderSize = oldButton.FlatAppearance.BorderSize;
        diagnosticButton.Click += async (_, _) => await SelfServiceDiagnostics.ShowAsync(owner);
        flow.Controls.Add(diagnosticButton);
        flow.Controls.SetChildIndex(diagnosticButton, index);
    }

    private static void ImproveFailureHint(Form owner)
    {
        foreach (var label in Descendants<Label>(owner))
        {
            if (string.Equals(label.Text, "عملیات کامل نشد.", StringComparison.Ordinal))
                label.Text = "عملیات کامل نشد؛ برای علت و راه‌حل، «عیب‌یابی» را اجرا کنید.";
            else if (string.Equals(label.Text, "بررسی وضعیت کامل نشد.", StringComparison.Ordinal))
                label.Text = "بررسی وضعیت کامل نشد؛ «عیب‌یابی» را اجرا کنید.";
        }
    }

    private static void NormalizeBidi(Control owner)
    {
        foreach (Control control in Descendants<Control>(owner))
        {
            if (control is TextBoxBase) continue;
            if (string.IsNullOrWhiteSpace(control.Text)) continue;
            control.Text = IsolateTechnicalTokens(control.Text);
        }
    }

    private static string IsolateTechnicalTokens(string text)
    {
        var result = text;
        foreach (var token in TechnicalTokens)
        {
            var isolated = $"{Lri}{token}{Pdi}";
            if (result.Contains(isolated, StringComparison.Ordinal)) continue;
            result = result.Replace(token, isolated, StringComparison.Ordinal);
        }
        return result;
    }

    internal static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}

internal static class SelfServiceDiagnostics
{
    private const string RuntimeService = "SoknaRuntime";
    private const string PrintService = "SoknaPrintWorker";

    internal static async Task ShowAsync(Form owner)
    {
        using var dialog = CreateDialog(owner);
        var grid = (DataGridView)dialog.Controls.Find("diagnosticGrid", true).Single();
        var summary = (Label)dialog.Controls.Find("diagnosticSummary", true).Single();
        var autoFix = (Button)dialog.Controls.Find("autoFixButton", true).Single();
        var repair = (Button)dialog.Controls.Find("repairButton", true).Single();
        var support = (Button)dialog.Controls.Find("supportButton", true).Single();
        var refresh = (Button)dialog.Controls.Find("refreshButton", true).Single();
        var copy = (Button)dialog.Controls.Find("copyButton", true).Single();
        var logs = (Button)dialog.Controls.Find("logsButton", true).Single();

        List<SelfServiceFinding> findings = new();
        SelfServiceSnapshot? snapshot = null;

        async Task RefreshAsync()
        {
            SetButtonsEnabled(dialog, false);
            summary.Text = "در حال بررسی سرویس‌ها، فایل‌ها، اتصال و پیش‌نیازها…";
            grid.Rows.Clear();
            try
            {
                snapshot = await ProbeAsync();
                findings = BuildFindings(snapshot);
                RenderFindings(grid, findings);
                var blocking = findings.Count(f => f.Severity == SelfServiceSeverity.Error);
                var warnings = findings.Count(f => f.Severity == SelfServiceSeverity.Warning);
                summary.Text = blocking == 0 && warnings == 0
                    ? "مشکل مهمی پیدا نشد. سیستم از نظر سرویس‌ها و اتصال قابل استفاده است."
                    : $"نتیجه: {blocking} خطای نیازمند اقدام و {warnings} هشدار پیدا شد.";
                autoFix.Enabled = findings.Any(f => f.Remediation is SelfServiceRemediation.StartRuntime or SelfServiceRemediation.StartPrint);
                repair.Enabled = findings.Any(f => f.Remediation == SelfServiceRemediation.Repair);
                support.Enabled = true;
                refresh.Enabled = true;
                copy.Enabled = true;
                logs.Enabled = true;
            }
            catch (Exception exception)
            {
                var fallback = new SelfServiceFinding(
                    "WS-DIAG-001",
                    SelfServiceSeverity.Error,
                    "عیب‌یابی کامل نشد",
                    "بسته پشتیبانی را بسازید و در صورت امکان برنامه را یک‌بار با دسترسی Administrator اجرا کنید.",
                    SelfServiceRemediation.None,
                    Safe(exception.Message));
                findings = new List<SelfServiceFinding> { fallback };
                RenderFindings(grid, findings);
                summary.Text = "خود عیب‌یابی با خطا روبه‌رو شد؛ کد WS-DIAG-001 را یادداشت کنید.";
                support.Enabled = true;
                refresh.Enabled = true;
                copy.Enabled = true;
                logs.Enabled = true;
            }
        }

        autoFix.Click += async (_, _) =>
        {
            if (snapshot is null) return;
            SetButtonsEnabled(dialog, false);
            try
            {
                foreach (var finding in findings)
                {
                    if (finding.Remediation == SelfServiceRemediation.StartRuntime)
                        await StartServiceElevatedAsync(RuntimeService);
                    else if (finding.Remediation == SelfServiceRemediation.StartPrint)
                        await StartServiceElevatedAsync(PrintService);
                }
                await Task.Delay(800);
                await RefreshAsync();
            }
            catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
            {
                MessageBox.Show(dialog, "درخواست Administrator لغو شد.", "WS-UAC-001", MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
                SetButtonsEnabled(dialog, true);
            }
            catch (Exception exception)
            {
                MessageBox.Show(dialog, "اصلاح خودکار کامل نشد. کد WS-AUTOFIX-001\r\n\r\n" + Safe(exception.Message), "اصلاح خودکار", MessageBoxButtons.OK, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
                SetButtonsEnabled(dialog, true);
            }
        };

        repair.Click += (_, _) =>
        {
            dialog.Close();
            var repairButton = SelfServiceUx.Descendants<Button>(owner)
                .FirstOrDefault(button => button.Visible && button.Text.Replace(Lri.ToString(), "").Replace(Pdi.ToString(), "") == "تعمیر نصب");
            repairButton?.PerformClick();
        };

        support.Click += async (_, _) =>
        {
            try
            {
                SetButtonsEnabled(dialog, false);
                var output = await BuildSupportBundleAsync();
                MessageBox.Show(dialog, $"بسته پشتیبانی ساخته شد:\r\n{output}", "بسته پشتیبانی", MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            }
            catch (Win32Exception exception) when (exception.NativeErrorCode == 1223)
            {
                MessageBox.Show(dialog, "درخواست Administrator لغو شد. کد WS-UAC-001", "بسته پشتیبانی", MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            }
            catch (Exception exception)
            {
                MessageBox.Show(dialog, "ساخت بسته پشتیبانی کامل نشد. کد WS-SUPPORT-001\r\n\r\n" + Safe(exception.Message), "بسته پشتیبانی", MessageBoxButtons.OK, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            }
            finally
            {
                SetButtonsEnabled(dialog, true);
            }
        };

        refresh.Click += async (_, _) => await RefreshAsync();
        copy.Click += (_, _) =>
        {
            try
            {
                Clipboard.SetText(BuildTextReport(findings));
                summary.Text = "گزارش عیب‌یابی در Clipboard کپی شد.";
            }
            catch
            {
                summary.Text = "کپی گزارش ممکن نشد.";
            }
        };
        logs.Click += (_, _) =>
        {
            var path = Path.Combine(snapshot?.DataRoot ?? ResolveDataRoot(), "Logs");
            Directory.CreateDirectory(path);
            Process.Start(new ProcessStartInfo("explorer.exe", path) { UseShellExecute = true });
        };

        dialog.Shown += async (_, _) => await RefreshAsync();
        dialog.ShowDialog(owner);
    }

    private static Form CreateDialog(Form owner)
    {
        var dialog = new Form
        {
            Text = "عیب‌یابی و راه‌حل‌های Windows Services سکنا",
            StartPosition = FormStartPosition.CenterParent,
            ClientSize = new Size(920, 560),
            MinimumSize = new Size(780, 500),
            ShowInTaskbar = false,
            MinimizeBox = false,
            RightToLeft = RightToLeft.Yes,
            RightToLeftLayout = true,
            BackColor = Color.FromArgb(246, 244, 240),
            Font = owner.Font
        };

        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(16),
            ColumnCount = 1,
            RowCount = 4,
            BackColor = dialog.BackColor
        };
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        var title = new Label
        {
            AutoSize = true,
            Text = "عیب‌یابی خودکار و راه‌حل‌ها",
            Font = new Font(owner.Font.FontFamily, 14f, FontStyle.Bold),
            ForeColor = Color.FromArgb(11, 63, 59),
            Margin = new Padding(0, 0, 0, 5)
        };
        var summary = new Label
        {
            Name = "diagnosticSummary",
            AutoSize = true,
            Text = "در حال آماده‌سازی…",
            ForeColor = Color.FromArgb(82, 92, 90),
            Margin = new Padding(0, 0, 0, 10)
        };

        var grid = new DataGridView
        {
            Name = "diagnosticGrid",
            Dock = DockStyle.Fill,
            ReadOnly = true,
            AllowUserToAddRows = false,
            AllowUserToDeleteRows = false,
            AllowUserToResizeRows = true,
            RowHeadersVisible = false,
            AutoGenerateColumns = false,
            AutoSizeRowsMode = DataGridViewAutoSizeRowsMode.AllCells,
            BackgroundColor = Color.White,
            BorderStyle = BorderStyle.FixedSingle,
            SelectionMode = DataGridViewSelectionMode.FullRowSelect,
            MultiSelect = false,
            RightToLeft = RightToLeft.Yes
        };
        grid.DefaultCellStyle.WrapMode = DataGridViewTriState.True;
        grid.DefaultCellStyle.Padding = new Padding(5);
        grid.ColumnHeadersDefaultCellStyle.Alignment = DataGridViewContentAlignment.MiddleRight;
        grid.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "وضعیت", Width = 90 });
        grid.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "کد", Width = 145 });
        grid.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "مشکل", Width = 240 });
        grid.Columns.Add(new DataGridViewTextBoxColumn { HeaderText = "راه‌حل", AutoSizeMode = DataGridViewAutoSizeColumnMode.Fill, MinimumWidth = 250 });

        var actions = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = true,
            Padding = new Padding(0, 10, 0, 0)
        };

        Button MakeButton(string name, string text)
        {
            var button = new Button
            {
                Name = name,
                Text = text,
                AutoSize = true,
                MinimumSize = new Size(100, 36),
                Padding = new Padding(9, 3, 9, 3),
                Margin = new Padding(6, 0, 0, 0),
                FlatStyle = FlatStyle.Flat,
                BackColor = Color.White,
                ForeColor = Color.FromArgb(11, 63, 59),
                Cursor = Cursors.Hand
            };
            button.FlatAppearance.BorderColor = Color.FromArgb(215, 223, 221);
            return button;
        }

        var autoFix = MakeButton("autoFixButton", "اصلاح خودکار");
        var repair = MakeButton("repairButton", "تعمیر نصب");
        var support = MakeButton("supportButton", "ساخت بسته پشتیبانی");
        var logs = MakeButton("logsButton", "باز کردن لاگ‌ها");
        var copy = MakeButton("copyButton", "کپی گزارش");
        var refresh = MakeButton("refreshButton", "بررسی دوباره");
        var close = MakeButton("closeButton", "بستن");
        close.Click += (_, _) => dialog.Close();

        actions.Controls.Add(autoFix);
        actions.Controls.Add(repair);
        actions.Controls.Add(support);
        actions.Controls.Add(logs);
        actions.Controls.Add(copy);
        actions.Controls.Add(refresh);
        actions.Controls.Add(close);

        root.Controls.Add(title, 0, 0);
        root.Controls.Add(summary, 0, 1);
        root.Controls.Add(grid, 0, 2);
        root.Controls.Add(actions, 0, 3);
        dialog.Controls.Add(root);
        return dialog;
    }

    private static void SetButtonsEnabled(Form dialog, bool enabled)
    {
        foreach (var button in SelfServiceUx.Descendants<Button>(dialog)) button.Enabled = enabled;
    }

    private static async Task<SelfServiceSnapshot> ProbeAsync()
    {
        var runtime = ProbeService(RuntimeService);
        var print = ProbeService(PrintService);
        var vc = ProbeVcRuntime();
        var dataRoot = ResolveDataRoot();
        var state = ReadState(dataRoot);
        var installedVersion = state.InstalledVersion;
        if (string.IsNullOrWhiteSpace(installedVersion))
        {
            var versionFile = Path.Combine(AppContext.BaseDirectory, "WINDOWS_SERVICES_VERSION.txt");
            if (File.Exists(versionFile)) installedVersion = File.ReadAllText(versionFile).Trim();
        }

        var dataRootExists = Directory.Exists(dataRoot);
        var dataRootWritable = false;
        if (dataRootExists)
        {
            try
            {
                var probeDirectory = Path.Combine(dataRoot, "setup");
                Directory.CreateDirectory(probeDirectory);
                var probeFile = Path.Combine(probeDirectory, ".write-probe-" + Guid.NewGuid().ToString("N") + ".tmp");
                await File.WriteAllTextAsync(probeFile, "sokna");
                File.Delete(probeFile);
                dataRootWritable = true;
            }
            catch { }
        }

        var reachable = false;
        if (state.Paired && Uri.TryCreate(state.LocalBaseUrl, UriKind.Absolute, out var uri) && uri.IsLoopback)
            reachable = await ProbeTcpAsync(uri.Host, uri.Port, 1200);

        return new SelfServiceSnapshot(
            runtime,
            print,
            vc.Ready,
            vc.Version,
            state.Paired,
            state.LocalBaseUrl,
            installedVersion,
            dataRoot,
            state.Readable,
            dataRootExists,
            dataRootWritable,
            reachable);
    }

    private static List<SelfServiceFinding> BuildFindings(SelfServiceSnapshot snapshot)
    {
        var findings = new List<SelfServiceFinding>();

        if (!snapshot.PrerequisiteReady)
            findings.Add(new SelfServiceFinding(
                "WS-PREREQ-VC-001",
                SelfServiceSeverity.Error,
                "پیش‌نیاز Visual C++ آماده نیست",
                "برنامه «SOKNA Prerequisites Setup» را اجرا کنید و Visual C++ x64 Runtime را نصب/تعمیر کنید؛ سپس «بررسی دوباره» را بزنید.",
                SelfServiceRemediation.Prerequisites));

        if (!snapshot.Runtime.Installed && !snapshot.Print.Installed)
        {
            findings.Add(new SelfServiceFinding(
                "WS-INSTALL-001",
                SelfServiceSeverity.Info,
                "Windows Services هنوز نصب نشده است",
                "این پنجره را ببندید و از صفحه اصلی «نصب سرویس‌ها» را اجرا کنید."));
            return findings;
        }

        if (!snapshot.Runtime.Installed || !snapshot.Print.Installed)
            findings.Add(new SelfServiceFinding(
                "WS-SVC-MISSING-001",
                SelfServiceSeverity.Error,
                "یکی از سرویس‌های سکنا نصب نیست",
                "«تعمیر نصب» را اجرا کنید تا Runtime و Print Agent دوباره ثبت و بررسی شوند.",
                SelfServiceRemediation.Repair));

        foreach (var service in new[] { snapshot.Runtime, snapshot.Print })
        {
            if (!service.Installed) continue;
            if (string.IsNullOrWhiteSpace(service.ExecutablePath) || !service.ExecutableExists)
            {
                findings.Add(new SelfServiceFinding(
                    service.Name == RuntimeService ? "WS-FILE-RUNTIME-001" : "WS-FILE-PRINT-001",
                    SelfServiceSeverity.Error,
                    $"فایل اجرایی {FriendlyServiceName(service.Name)} پیدا نشد",
                    "«تعمیر نصب» را اجرا کنید. اگر دوباره خطا شد، «ساخت بسته پشتیبانی» را بزنید.",
                    SelfServiceRemediation.Repair,
                    service.ImagePath));
            }
        }

        if (snapshot.Print.Installed && !snapshot.Print.Running)
            findings.Add(new SelfServiceFinding(
                "WS-SVC-PRINT-STOPPED-001",
                SelfServiceSeverity.Error,
                "Print Agent متوقف است",
                "«اصلاح خودکار» را بزنید. اگر سرویس دوباره متوقف شد، بسته پشتیبانی بسازید.",
                SelfServiceRemediation.StartPrint));

        if (snapshot.Runtime.Installed && snapshot.Paired && !snapshot.Runtime.Running)
            findings.Add(new SelfServiceFinding(
                "WS-SVC-RUNTIME-STOPPED-001",
                SelfServiceSeverity.Error,
                "Runtime متوقف است",
                "«اصلاح خودکار» را بزنید. اگر Runtime دوباره متوقف شد، بسته پشتیبانی بسازید.",
                SelfServiceRemediation.StartRuntime));

        if (!snapshot.StateReadable)
            findings.Add(new SelfServiceFinding(
                "WS-STATE-001",
                SelfServiceSeverity.Warning,
                "اطلاعات وضعیت نصب خوانده نشد",
                "«تعمیر نصب» را اجرا کنید تا state معتبر بازسازی شود.",
                SelfServiceRemediation.Repair));

        if (string.IsNullOrWhiteSpace(snapshot.InstalledVersion))
            findings.Add(new SelfServiceFinding(
                "WS-VERSION-001",
                SelfServiceSeverity.Warning,
                "نسخه نصب‌شده قابل تشخیص نیست",
                "«تعمیر نصب» را اجرا کنید. این کار داده‌های کسب‌وکار را حذف نمی‌کند.",
                SelfServiceRemediation.Repair));

        if (!snapshot.DataRootExists)
            findings.Add(new SelfServiceFinding(
                "WS-DATA-ROOT-001",
                SelfServiceSeverity.Error,
                "پوشه داده پیدا نشد",
                "مسیر داده را در «جزئیات» بررسی کنید؛ اگر مسیر درست است، «تعمیر نصب» را اجرا کنید.",
                SelfServiceRemediation.Repair,
                snapshot.DataRoot));
        else if (!snapshot.DataRootWritable)
            findings.Add(new SelfServiceFinding(
                "WS-DATA-WRITE-001",
                SelfServiceSeverity.Warning,
                "پوشه داده برای کاربر فعلی قابل نوشتن نیست",
                "برنامه را با دسترسی Administrator اجرا کنید. اگر مشکل ادامه داشت، دسترسی پوشه DataRoot را بررسی کنید.",
                SelfServiceRemediation.None,
                snapshot.DataRoot));

        if (snapshot.Runtime.Installed && snapshot.Print.Installed && !snapshot.Paired)
            findings.Add(new SelfServiceFinding(
                "WS-PAIR-001",
                SelfServiceSeverity.Info,
                "Local Web هنوز Pair نشده است",
                "از Local Web یک کد اتصال جدید بسازید و در صفحه اصلی وارد کنید. Pairing سرویس‌ها را دوباره نصب نمی‌کند.",
                SelfServiceRemediation.Pairing));

        if (snapshot.Paired)
        {
            if (!Uri.TryCreate(snapshot.LocalBaseUrl, UriKind.Absolute, out var localUri) || !localUri.IsLoopback)
                findings.Add(new SelfServiceFinding(
                    "WS-PAIR-URL-001",
                    SelfServiceSeverity.Error,
                    "آدرس Local Web معتبر نیست",
                    "یک کد اتصال جدید از Local Web بسازید و اتصال را نوسازی کنید.",
                    SelfServiceRemediation.Pairing,
                    snapshot.LocalBaseUrl));
            else if (!snapshot.LocalWebReachable)
                findings.Add(new SelfServiceFinding(
                    "WS-LOCAL-OFFLINE-001",
                    SelfServiceSeverity.Warning,
                    "Local Web روی آدرس ثبت‌شده پاسخ نمی‌دهد",
                    "Local Web را اجرا کنید و مطمئن شوید پورت محلی در دسترس است؛ سپس «بررسی دوباره» را بزنید.",
                    SelfServiceRemediation.StartLocalWeb,
                    snapshot.LocalBaseUrl));
        }

        if (findings.Count == 0)
            findings.Add(new SelfServiceFinding(
                "WS-HEALTH-OK",
                SelfServiceSeverity.Ok,
                "مشکل مهمی پیدا نشد",
                "Runtime، Print Agent، پیش‌نیاز، DataRoot و اتصال محلی در وضعیت قابل استفاده هستند."));

        return findings;
    }

    private static void RenderFindings(DataGridView grid, IEnumerable<SelfServiceFinding> findings)
    {
        grid.Rows.Clear();
        foreach (var finding in findings)
        {
            var status = finding.Severity switch
            {
                SelfServiceSeverity.Ok => "سالم",
                SelfServiceSeverity.Info => "اطلاع",
                SelfServiceSeverity.Warning => "هشدار",
                _ => "خطا"
            };
            var row = grid.Rows.Add(status, finding.Code, finding.Title, finding.Guidance);
            grid.Rows[row].Cells[1].Style.Alignment = DataGridViewContentAlignment.MiddleLeft;
            grid.Rows[row].Cells[1].Style.WrapMode = DataGridViewTriState.False;
        }
    }

    private static string BuildTextReport(IEnumerable<SelfServiceFinding> findings)
    {
        var builder = new StringBuilder();
        builder.AppendLine("SOKNA Windows Services - Self Service Diagnostic Report");
        builder.AppendLine(DateTimeOffset.Now.ToString("O"));
        builder.AppendLine();
        foreach (var finding in findings)
        {
            builder.AppendLine($"[{finding.Code}] {finding.Severity} - {finding.Title}");
            builder.AppendLine("راه‌حل: " + finding.Guidance);
            if (!string.IsNullOrWhiteSpace(finding.Technical)) builder.AppendLine("جزئیات: " + finding.Technical);
            builder.AppendLine();
        }
        return builder.ToString();
    }

    private static ServiceProbe ProbeService(string serviceName)
    {
        var imagePath = ReadServiceImage(serviceName);
        var installed = !string.IsNullOrWhiteSpace(imagePath);
        var executable = ExtractExecutablePath(imagePath);
        var exists = !string.IsNullOrWhiteSpace(executable) && File.Exists(executable);
        var running = false;
        if (installed)
        {
            try
            {
                var sc = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe");
                var info = new ProcessStartInfo(sc) { UseShellExecute = false, RedirectStandardOutput = true, CreateNoWindow = true };
                info.ArgumentList.Add("query");
                info.ArgumentList.Add(serviceName);
                using var process = Process.Start(info);
                if (process is not null)
                {
                    var output = process.StandardOutput.ReadToEnd();
                    process.WaitForExit(3000);
                    running = process.ExitCode == 0 && output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase);
                }
            }
            catch { }
        }
        return new ServiceProbe(serviceName, installed, running, imagePath, executable, exists);
    }

    private static string ReadServiceImage(string serviceName)
    {
        try
        {
            using var registry = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + serviceName, false);
            return Convert.ToString(registry?.GetValue("ImagePath")) ?? "";
        }
        catch { return ""; }
    }

    private static string ExtractExecutablePath(string command)
    {
        var value = (command ?? "").Trim();
        if (value.StartsWith('"'))
        {
            var end = value.IndexOf('"', 1);
            return end > 1 ? value[1..end] : "";
        }
        var exeIndex = value.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
        return exeIndex >= 0 ? value[..(exeIndex + 4)] : "";
    }

    private static (bool Ready, string Version) ProbeVcRuntime()
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            try
            {
                using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
                using var key = root.OpenSubKey(@"SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64", false);
                var installed = Convert.ToInt32(key?.GetValue("Installed") ?? 0) == 1;
                var version = Convert.ToString(key?.GetValue("Version")) ?? "";
                if (installed || !string.IsNullOrWhiteSpace(version)) return (true, version);
            }
            catch { }
        }
        return (false, "");
    }

    private static (bool Readable, bool Paired, string LocalBaseUrl, string InstalledVersion) ReadState(string dataRoot)
    {
        var path = Path.Combine(dataRoot, "setup", "windows-services-state.json");
        if (!File.Exists(path)) return (false, false, "", "");
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            var root = document.RootElement;
            var paired = root.TryGetProperty("paired", out var pairedNode) && pairedNode.ValueKind == JsonValueKind.True;
            var url = root.TryGetProperty("local_base_url", out var urlNode) && urlNode.ValueKind == JsonValueKind.String ? urlNode.GetString() ?? "" : "";
            var version = root.TryGetProperty("package_version", out var versionNode) && versionNode.ValueKind == JsonValueKind.String ? versionNode.GetString() ?? "" : "";
            if (paired && string.IsNullOrWhiteSpace(url))
            {
                var runtimeConfig = Path.Combine(dataRoot, "runtime", "runtime-config.json");
                if (File.Exists(runtimeConfig))
                {
                    using var configDocument = JsonDocument.Parse(File.ReadAllText(runtimeConfig));
                    if (configDocument.RootElement.TryGetProperty("localBaseUrl", out var configUrl) && configUrl.ValueKind == JsonValueKind.String)
                        url = configUrl.GetString() ?? "";
                }
            }
            return (true, paired, url, version);
        }
        catch
        {
            return (false, false, "", "");
        }
    }

    private static string ResolveDataRoot()
    {
        foreach (var view in new[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            try
            {
                using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
                using var key = root.OpenSubKey(@"SOFTWARE\Sokna\Local\PrintWorker", false);
                var printDataRoot = Convert.ToString(key?.GetValue("DataRoot"));
                if (!string.IsNullOrWhiteSpace(printDataRoot))
                {
                    var parent = Directory.GetParent(Path.GetFullPath(printDataRoot))?.FullName;
                    if (!string.IsNullOrWhiteSpace(parent)) return parent;
                }
            }
            catch { }
        }
        return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA");
    }

    private static async Task<bool> ProbeTcpAsync(string host, int port, int timeoutMs)
    {
        try
        {
            using var client = new TcpClient();
            using var cancellation = new CancellationTokenSource(timeoutMs);
            await client.ConnectAsync(host, port, cancellation.Token);
            return client.Connected;
        }
        catch { return false; }
    }

    private static async Task StartServiceElevatedAsync(string serviceName)
    {
        var sc = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe");
        var info = new ProcessStartInfo(sc)
        {
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = AppContext.BaseDirectory
        };
        info.ArgumentList.Add("start");
        info.ArgumentList.Add(serviceName);
        using var process = Process.Start(info) ?? throw new InvalidOperationException("شروع سرویس آغاز نشد.");
        await process.WaitForExitAsync();
        if (process.ExitCode != 0 && process.ExitCode != 1056)
            throw new InvalidOperationException($"sc.exe با کد {process.ExitCode} متوقف شد.");
    }

    private static async Task<string> BuildSupportBundleAsync()
    {
        var outputRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "SOKNA-Support");
        Directory.CreateDirectory(outputRoot);
        var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        var script = Path.Combine(AppContext.BaseDirectory, "collect-support.ps1");
        if (!File.Exists(script)) throw new FileNotFoundException("collect-support.ps1 پیدا نشد.", script);
        var dataRoot = ResolveDataRoot();
        var info = new ProcessStartInfo(powershell)
        {
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = AppContext.BaseDirectory
        };
        foreach (var argument in new[]
        {
            "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
            "-File", script, "-DataRoot", dataRoot, "-OutputRoot", outputRoot
        }) info.ArgumentList.Add(argument);
        using var process = Process.Start(info) ?? throw new InvalidOperationException("ساخت بسته پشتیبانی شروع نشد.");
        await process.WaitForExitAsync();
        if (process.ExitCode != 0) throw new InvalidOperationException($"collect-support.ps1 با کد {process.ExitCode} متوقف شد.");
        Process.Start(new ProcessStartInfo("explorer.exe", outputRoot) { UseShellExecute = true });
        return outputRoot;
    }

    private static string FriendlyServiceName(string serviceName) => serviceName == RuntimeService ? "Runtime" : "Print Agent";

    private static string Safe(string? message)
    {
        var value = (message ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return value.Length > 700 ? value[..700] : value;
    }
}