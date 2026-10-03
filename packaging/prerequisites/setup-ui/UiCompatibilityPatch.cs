using System.Reflection;
using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// WinForms can collapse percent-column technical inputs when a legacy TableLayoutPanel
/// is hosted by a mirrored top-level form. Keep the Persian top-level RTL behavior while
/// enforcing practical minimum widths for the existing LTR technical islands.
/// This is intentionally presentation-only and never mutates infrastructure state/data.
/// </summary>
internal static class UiCompatibilityPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();

    [ModuleInitializer]
    internal static void Initialize()
    {
        Application.Idle += (_, _) =>
        {
            foreach (Form form in Application.OpenForms)
            {
                if (form.GetType() != typeof(MainForm) || Applied.TryGetValue(form, out _)) continue;
                Apply(form);
                Applied.Add(form, new object());
            }
        };
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
        }

        NormalizeVersionText(form);
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
