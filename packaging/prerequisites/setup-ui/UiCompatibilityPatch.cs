using System.Reflection;
using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Presentation-only compatibility layer for the legacy Prerequisites MainForm.
/// The legacy three-column TableLayoutPanels collapse technical LTR inputs when the
/// top-level form is mirrored for Persian RTL. Re-parent the existing controls into
/// simple responsive rows so the exact same controls/events/business logic remain in use.
/// No infrastructure state, services, payloads or Data are mutated here.
/// </summary>
internal static class UiCompatibilityPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter MessageFilter = new ApplyWhenPumpingFilter();

    [ModuleInitializer]
    internal static void Initialize()
    {
        Application.Idle += (_, _) => ApplyOpenForms();
        Application.AddMessageFilter(MessageFilter);
    }

    private static void ApplyOpenForms()
    {
        foreach (Form form in Application.OpenForms)
        {
            if (form.GetType() != typeof(MainForm) || Applied.TryGetValue(form, out _)) continue;
            Apply(form);
            Applied.Add(form, new object());
        }
    }

    private sealed class ApplyWhenPumpingFilter : IMessageFilter
    {
        public bool PreFilterMessage(ref Message m)
        {
            ApplyOpenForms();
            return false;
        }
    }

    private static void Apply(Form form)
    {
        RebuildPathAndPort(form);
        RebuildMariaDbCredentials(form);
        NormalizeVersionText(form);
    }

    private static void RebuildPathAndPort(Form form)
    {
        var root = Field<TextBox>(form, "_root");
        var port = Field<NumericUpDown>(form, "_apachePort");
        var browse = Field<Button>(form, "_browse");
        var paths = Field<Label>(form, "_paths");
        if (root is null || port is null || browse is null || paths is null) return;

        var group = Ancestor<GroupBox>(root);
        if (group is null || group.Controls.Find("SoknaResponsivePathLayout", true).Length > 0) return;

        Detach(root);
        Detach(port);
        Detach(browse);
        Detach(paths);

        group.SuspendLayout();
        group.Controls.Clear();
        group.AutoSize = true;
        group.Padding = new Padding(16, 16, 16, 14);

        var layout = new TableLayoutPanel
        {
            Name = "SoknaResponsivePathLayout",
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 7,
            RightToLeft = RightToLeft.Yes,
            Margin = Padding.Empty,
            Padding = Padding.Empty
        };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        layout.Controls.Add(FieldLabel("مسیر ریشه زیرساخت"), 0, 0);

        var rootRow = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 2,
            RowCount = 1,
            RightToLeft = RightToLeft.No,
            Margin = new Padding(0, 4, 0, 10)
        };
        rootRow.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        rootRow.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        root.Dock = DockStyle.Fill;
        root.AutoSize = false;
        root.Height = 32;
        root.MinimumSize = new Size(360, 32);
        root.RightToLeft = RightToLeft.No;
        root.TextAlign = HorizontalAlignment.Left;
        root.Margin = new Padding(0, 0, 10, 0);
        browse.AutoSize = false;
        browse.MinimumSize = new Size(112, 32);
        browse.Height = 32;
        browse.Margin = Padding.Empty;
        rootRow.Controls.Add(root, 0, 0);
        rootRow.Controls.Add(browse, 1, 0);
        layout.Controls.Add(rootRow, 0, 1);

        layout.Controls.Add(FieldLabel("پورت Local Web"), 0, 2);

        var portRow = new FlowLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = false,
            RightToLeft = RightToLeft.No,
            Margin = new Padding(0, 4, 0, 4)
        };
        port.AutoSize = false;
        port.Width = 150;
        port.Height = 30;
        port.MinimumSize = new Size(130, 30);
        port.RightToLeft = RightToLeft.No;
        port.Margin = Padding.Empty;
        portRow.Controls.Add(port);
        layout.Controls.Add(portRow, 0, 3);

        var hint = new Label
        {
            Text = "پورت پیش‌فرض 18080 است. اگر اشغال باشد، Setup یک پورت آزاد loopback پیشنهاد می‌دهد.",
            AutoSize = true,
            Dock = DockStyle.Top,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.MiddleRight,
            Padding = new Padding(0, 2, 0, 8),
            ForeColor = SystemColors.GrayText
        };
        layout.Controls.Add(hint, 0, 4);

        paths.Dock = DockStyle.Top;
        paths.AutoSize = true;
        paths.RightToLeft = RightToLeft.Yes;
        paths.TextAlign = ContentAlignment.TopRight;
        paths.Padding = new Padding(0, 4, 0, 0);
        layout.Controls.Add(paths, 0, 5);

        // Keep a small bottom spacer so GroupBox captions do not crowd the next section.
        layout.Controls.Add(new Panel { Height = 2, Dock = DockStyle.Top }, 0, 6);

        group.Controls.Add(layout);
        group.ResumeLayout(true);
        group.PerformLayout();
    }

    private static void RebuildMariaDbCredentials(Form form)
    {
        var password = Field<TextBox>(form, "_password");
        var password2 = Field<TextBox>(form, "_password2");
        var showPassword = Field<CheckBox>(form, "_showPassword");
        if (password is null || password2 is null || showPassword is null) return;

        var group = Ancestor<GroupBox>(password);
        if (group is null || group.Controls.Find("SoknaResponsiveMariaLayout", true).Length > 0) return;

        var helpText = Descendants(group).OfType<Label>()
            .Select(x => x.Text)
            .FirstOrDefault(x => x.Contains("این رمز فقط", StringComparison.Ordinal))
            ?? "این رمز فقط هنگام راه‌اندازی اولیه MariaDB استفاده می‌شود و در Log یا State ذخیره نمی‌شود. اگر Data قبلی وجود داشته باشد، Setup اجازه راه‌اندازی مجدد دیتابیس را نمی‌دهد.";

        Detach(password);
        Detach(password2);
        Detach(showPassword);

        group.SuspendLayout();
        group.Controls.Clear();
        group.AutoSize = true;
        group.Padding = new Padding(16, 16, 16, 14);

        var layout = new TableLayoutPanel
        {
            Name = "SoknaResponsiveMariaLayout",
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 6,
            RightToLeft = RightToLeft.Yes,
            Margin = Padding.Empty,
            Padding = Padding.Empty
        };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        layout.Controls.Add(FieldLabel("رمز مدیر MariaDB"), 0, 0);
        ConfigurePassword(password);
        layout.Controls.Add(password, 0, 1);

        layout.Controls.Add(FieldLabel("تکرار رمز"), 0, 2);
        ConfigurePassword(password2);
        layout.Controls.Add(password2, 0, 3);

        showPassword.AutoSize = true;
        showPassword.RightToLeft = RightToLeft.Yes;
        showPassword.Anchor = AnchorStyles.Right;
        showPassword.Margin = new Padding(0, 6, 0, 6);
        layout.Controls.Add(showPassword, 0, 4);

        var help = new Label
        {
            Text = helpText,
            AutoSize = true,
            Dock = DockStyle.Top,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            ForeColor = SystemColors.GrayText,
            Padding = new Padding(0, 4, 0, 0)
        };
        layout.Controls.Add(help, 0, 5);

        group.Controls.Add(layout);
        group.ResumeLayout(true);
        group.PerformLayout();
    }

    private static void ConfigurePassword(TextBox box)
    {
        box.Dock = DockStyle.Top;
        box.AutoSize = false;
        box.Height = 32;
        box.MinimumSize = new Size(360, 32);
        box.RightToLeft = RightToLeft.No;
        box.TextAlign = HorizontalAlignment.Left;
        box.Margin = new Padding(0, 4, 0, 10);
    }

    private static Label FieldLabel(string text) => new()
    {
        Text = text,
        AutoSize = true,
        Dock = DockStyle.Top,
        RightToLeft = RightToLeft.Yes,
        TextAlign = ContentAlignment.MiddleRight,
        Margin = Padding.Empty
    };

    private static void Detach(Control control)
        => control.Parent?.Controls.Remove(control);

    private static T? Ancestor<T>(Control control) where T : Control
    {
        for (Control? current = control.Parent; current is not null; current = current.Parent)
            if (current is T match) return match;
        return null;
    }

    private static IEnumerable<Control> Descendants(Control root)
    {
        foreach (Control child in root.Controls)
        {
            yield return child;
            foreach (var nested in Descendants(child)) yield return nested;
        }
    }

    private static void NormalizeVersionText(Control control)
    {
        var raw = Application.ProductVersion ?? "";
        var semantic = raw.Split('+')[0];
        if (!string.IsNullOrWhiteSpace(raw) && raw != semantic && !string.IsNullOrEmpty(control.Text))
            control.Text = control.Text.Replace(raw, semantic, StringComparison.Ordinal);

        foreach (Control child in control.Controls)
            NormalizeVersionText(child);
    }

    private static T? Field<T>(object instance, string name) where T : class
        => instance.GetType().GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)?.GetValue(instance) as T;
}
