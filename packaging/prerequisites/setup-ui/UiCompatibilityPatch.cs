using System.Reflection;
using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// WinForms can collapse percent-column technical inputs when a legacy TableLayoutPanel
/// is hosted by a mirrored top-level form. Keep the Persian top-level RTL behavior while
/// enforcing practical responsive widths for the existing LTR technical islands.
/// This is intentionally presentation-only and never mutates infrastructure state/data.
/// </summary>
internal static class UiCompatibilityPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly ConditionalWeakTable<TableLayoutPanel, object> StabilizedTables = new();
    private static readonly IMessageFilter MessageFilter = new ApplyWhenPumpingFilter();

    [ModuleInitializer]
    internal static void Initialize()
    {
        // The real application has Application.Run and therefore reaches Idle. The
        // qualification renderer intentionally uses Show + DoEvents instead. Register
        // both hooks so the exact same presentation patch is exercised in production
        // and in screenshot evidence.
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
        var root = Field<TextBox>(form, "_root");
        if (root is not null)
        {
            root.MinimumSize = new Size(360, 30);
            root.AutoSize = false;
            root.Height = Math.Max(root.Height, 30);
            root.RightToLeft = RightToLeft.No;
            StabilizeMiddleColumn(root, minWidth: 360, reservedWidth: 245, maxWidth: 760);
        }

        var port = Field<NumericUpDown>(form, "_apachePort");
        if (port is not null)
        {
            port.MinimumSize = new Size(110, 28);
            port.Width = Math.Max(port.Width, 110);
            port.RightToLeft = RightToLeft.No;
        }

        foreach (var name in new[] { "_password", "_password2" })
        {
            var password = Field<TextBox>(form, name);
            if (password is null) continue;
            password.MinimumSize = new Size(300, 30);
            password.AutoSize = false;
            password.Height = Math.Max(password.Height, 30);
            password.RightToLeft = RightToLeft.No;
            StabilizeMiddleColumn(password, minWidth: 300, reservedWidth: 245, maxWidth: 760);
        }

        NormalizeVersionText(form);
    }

    /// <summary>
    /// Mirrored WinForms TableLayoutPanel can resolve a Percent middle column to almost
    /// zero when AutoSize children occupy both outer columns. Convert only that technical
    /// middle column to a responsive absolute width derived from the live client width.
    /// The calculation is repeated after resize and deliberately leaves room for the
    /// Persian label/action columns on both sides.
    /// </summary>
    private static void StabilizeMiddleColumn(Control field, int minWidth, int reservedWidth, int maxWidth)
    {
        if (field.Parent is not TableLayoutPanel table || table.ColumnCount < 3 || table.ColumnStyles.Count < 2) return;

        if (!StabilizedTables.TryGetValue(table, out _))
        {
            void Resize(object? _, EventArgs __)
            {
                var usable = table.ClientSize.Width - reservedWidth;
                var width = Math.Clamp(usable, minWidth, maxWidth);
                var middle = table.ColumnStyles[1];
                middle.SizeType = SizeType.Absolute;
                middle.Width = width;
                table.PerformLayout();
            }

            table.SizeChanged += Resize;
            table.HandleCreated += Resize;
            StabilizedTables.Add(table, new object());
            Resize(null, EventArgs.Empty);
        }

        field.Dock = DockStyle.Fill;
        field.MinimumSize = new Size(minWidth, field.MinimumSize.Height);
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
