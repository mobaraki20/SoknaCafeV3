using System.Runtime.CompilerServices;

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

internal sealed record ServiceProbe(
    string Name,
    bool Installed,
    bool Running,
    string ImagePath,
    string ExecutablePath,
    bool ExecutableExists);

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
            SelfServiceDashboardUx.Attach(form);
        }
    }
}

internal static class SelfServiceDashboardUx
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
            .FirstOrDefault(button => string.Equals(RemoveIsolation(button.Text), "بسته عیب‌یابی", StringComparison.Ordinal));
        if (oldButton?.Parent is not FlowLayoutPanel flow) return;

        var index = flow.Controls.GetChildIndex(oldButton);
        oldButton.Visible = false;

        var diagnosticButton = new Button
        {
            Name = "selfServiceDiagnosticButton",
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
            var plain = RemoveIsolation(label.Text);
            if (string.Equals(plain, "عملیات کامل نشد.", StringComparison.Ordinal))
                label.Text = "عملیات کامل نشد؛ برای علت و راه‌حل، «عیب‌یابی» را اجرا کنید.";
            else if (string.Equals(plain, "بررسی وضعیت کامل نشد.", StringComparison.Ordinal))
                label.Text = "بررسی وضعیت کامل نشد؛ «عیب‌یابی» را اجرا کنید.";
        }
    }

    private static void NormalizeBidi(Control owner)
    {
        foreach (Control control in Descendants<Control>(owner))
        {
            if (control is TextBoxBase) continue;
            if (string.IsNullOrWhiteSpace(control.Text)) continue;
            control.Text = IsolateTechnicalTokens(RemoveIsolation(control.Text));
        }
    }

    private static string IsolateTechnicalTokens(string text)
    {
        var result = text;
        foreach (var token in TechnicalTokens)
            result = result.Replace(token, $"{Lri}{token}{Pdi}", StringComparison.Ordinal);
        return result;
    }

    internal static string RemoveIsolation(string text) => text
        .Replace(Lri.ToString(), "", StringComparison.Ordinal)
        .Replace(Pdi.ToString(), "", StringComparison.Ordinal);

    internal static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}