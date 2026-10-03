using System.Diagnostics;
using System.Drawing.Drawing2D;
using System.Runtime.InteropServices;

namespace Sokna.SetupUi;

internal static class ModernProgram
{
    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();
        TaskbarIdentity.Apply();
        using var form = new SetupForm();
        ModernTheme.Apply(form);
        Application.Run(form);
    }
}

internal static class ModernTheme
{
    private static readonly Color Canvas = Color.FromArgb(246, 244, 240);
    private static readonly Color Surface = Color.FromArgb(255, 255, 255);
    private static readonly Color Brand = Color.FromArgb(18, 86, 82);
    private static readonly Color BrandDark = Color.FromArgb(11, 63, 60);
    private static readonly Color BrandSoft = Color.FromArgb(232, 243, 241);
    private static readonly Color Text = Color.FromArgb(34, 46, 45);
    private static readonly Color Muted = Color.FromArgb(92, 106, 104);
    private static readonly Color Border = Color.FromArgb(214, 222, 220);
    private static readonly Color Danger = Color.FromArgb(162, 47, 47);
    private static readonly Color DangerSoft = Color.FromArgb(255, 242, 242);

    public static void Apply(Form form)
    {
        var version = DisplayVersion();
        form.SuspendLayout();
        try
        {
            form.Text = $"سکنا | سرویس‌های ویندوز — نسخه {version}";
            form.BackColor = Canvas;
            form.ForeColor = Text;
            form.Font = PreferredFont(10.25f, FontStyle.Regular);
            form.MinimumSize = new Size(980, 700);
            form.Size = new Size(1180, 800);

            var root = form.Controls.OfType<TableLayoutPanel>().FirstOrDefault();
            if (root is not null)
            {
                root.BackColor = Canvas;
                root.Padding = new Padding(22, 18, 22, 16);
                ReplaceHeader(root, version);
                StyleBottomStatus(root);
            }

            StyleTree(form);
            StyleTabs(form);
            ApplyWindowIcon(form);
        }
        finally
        {
            form.ResumeLayout(true);
        }

        form.Shown += (_, _) =>
        {
            ApplyWindowIcon(form);
            form.Activate();
        };
    }

    private static void ReplaceHeader(TableLayoutPanel root, string version)
    {
        if (root.RowCount < 1) return;
        var oldHeader = root.GetControlFromPosition(0, 0);
        if (oldHeader is not null) root.Controls.Remove(oldHeader);

        var header = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 3,
            BackColor = Brand,
            ForeColor = Color.White,
            Padding = new Padding(22, 16, 22, 15),
            Margin = new Padding(0, 0, 0, 14),
            RightToLeft = RightToLeft.Yes
        };

        var title = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleRight,
            Text = "مدیریت سرویس‌های ویندوز سکنا",
            ForeColor = Color.White,
            Font = PreferredFont(17f, FontStyle.Bold),
            Margin = new Padding(0, 0, 0, 2)
        };
        var subtitle = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleRight,
            Text = "Runtime و Print Agent  •  نصب، اتصال و عیب‌یابی",
            ForeColor = Color.FromArgb(224, 239, 237),
            Font = PreferredFont(10.25f, FontStyle.Regular),
            Margin = new Padding(0, 0, 0, 6)
        };
        var versionLabel = new Label
        {
            AutoSize = true,
            Anchor = AnchorStyles.Right,
            TextAlign = ContentAlignment.MiddleCenter,
            Text = $"نسخه {version}",
            ForeColor = BrandDark,
            BackColor = Color.White,
            Font = PreferredFont(9.25f, FontStyle.Bold),
            Padding = new Padding(9, 4, 9, 4),
            Margin = new Padding(0)
        };
        header.Controls.Add(title, 0, 0);
        header.Controls.Add(subtitle, 0, 1);
        header.Controls.Add(versionLabel, 0, 2);
        root.Controls.Add(header, 0, 0);
    }

    private static void StyleBottomStatus(TableLayoutPanel root)
    {
        if (root.RowCount < 3) return;
        if (root.GetControlFromPosition(0, 2) is not TableLayoutPanel bottom) return;
        bottom.BackColor = Surface;
        bottom.Padding = new Padding(14, 10, 14, 10);
        bottom.Margin = new Padding(0, 14, 0, 0);
        bottom.CellBorderStyle = TableLayoutPanelCellBorderStyle.None;
        foreach (var label in bottom.Controls.OfType<Label>())
        {
            label.ForeColor = Muted;
            label.Font = PreferredFont(9.5f, FontStyle.Regular);
        }
    }

    private static void StyleTree(Control parent)
    {
        foreach (Control control in parent.Controls)
        {
            switch (control)
            {
                case TabPage page:
                    page.BackColor = Canvas;
                    page.ForeColor = Text;
                    page.Padding = new Padding(10);
                    break;

                case TableLayoutPanel table when IsInfoCard(table):
                    table.BackColor = Surface;
                    table.ForeColor = Text;
                    table.Padding = new Padding(16, 13, 16, 13);
                    table.Margin = new Padding(0, 7, 0, 12);
                    table.CellBorderStyle = TableLayoutPanelCellBorderStyle.None;
                    var directLabels = table.Controls.OfType<Label>().ToList();
                    if (directLabels.Count > 0)
                    {
                        directLabels[0].Font = PreferredFont(10.75f, FontStyle.Bold);
                        directLabels[0].ForeColor = BrandDark;
                    }
                    if (directLabels.Count > 1)
                    {
                        directLabels[1].Font = PreferredFont(9.75f, FontStyle.Regular);
                        directLabels[1].ForeColor = Muted;
                    }
                    break;

                case Button button:
                    StyleButton(button);
                    break;

                case TextBox textBox:
                    textBox.BackColor = Surface;
                    textBox.ForeColor = Text;
                    textBox.BorderStyle = BorderStyle.FixedSingle;
                    textBox.Font = PreferredFont(10.25f, FontStyle.Regular);
                    textBox.Margin = new Padding(8, 5, 8, 5);
                    break;

                case ListView list:
                    list.BackColor = Surface;
                    list.ForeColor = Text;
                    list.BorderStyle = BorderStyle.FixedSingle;
                    list.GridLines = false;
                    list.Font = PreferredFont(9.75f, FontStyle.Regular);
                    list.HeaderStyle = ColumnHeaderStyle.Nonclickable;
                    list.Margin = new Padding(0, 8, 0, 8);
                    break;

                case CheckBox check:
                    check.ForeColor = Text;
                    check.Font = PreferredFont(9.75f, FontStyle.Regular);
                    check.Padding = new Padding(5, 7, 5, 7);
                    break;

                case Label label:
                    if (label.BackColor == Color.Transparent || label.BackColor == SystemColors.Control)
                        label.BackColor = Color.Transparent;
                    if (label.ForeColor == SystemColors.ControlText)
                        label.ForeColor = Text;
                    break;

                case FlowLayoutPanel flow:
                    flow.BackColor = Color.Transparent;
                    flow.Padding = new Padding(0, 7, 0, 7);
                    break;
            }

            StyleTree(control);
        }
    }

    private static bool IsInfoCard(TableLayoutPanel table)
    {
        if (table.Controls.Count != 2) return false;
        if (table.Controls.OfType<Label>().Count() != 2) return false;
        return table.Padding.Left >= 8 && table.Padding.Right >= 8;
    }

    private static void StyleButton(Button button)
    {
        button.UseVisualStyleBackColor = false;
        button.FlatStyle = FlatStyle.Flat;
        button.FlatAppearance.BorderSize = 1;
        button.Cursor = Cursors.Hand;
        button.Font = PreferredFont(9.75f, FontStyle.Bold);
        button.Padding = new Padding(13, 5, 13, 5);
        button.MinimumSize = new Size(Math.Max(button.MinimumSize.Width, 120), 38);

        var text = button.Text ?? string.Empty;
        if (text.Contains("نصب / به‌روزرسانی", StringComparison.Ordinal))
        {
            button.BackColor = Brand;
            button.ForeColor = Color.White;
            button.FlatAppearance.BorderColor = Brand;
            button.FlatAppearance.MouseOverBackColor = BrandDark;
            button.FlatAppearance.MouseDownBackColor = BrandDark;
            return;
        }
        if (text.Contains("حذف سرویس", StringComparison.Ordinal))
        {
            button.BackColor = DangerSoft;
            button.ForeColor = Danger;
            button.FlatAppearance.BorderColor = Color.FromArgb(226, 181, 181);
            button.FlatAppearance.MouseOverBackColor = Color.FromArgb(251, 229, 229);
            return;
        }
        if (text.Contains("ساخت بسته عیب‌یابی", StringComparison.Ordinal))
        {
            button.BackColor = BrandSoft;
            button.ForeColor = BrandDark;
            button.FlatAppearance.BorderColor = Color.FromArgb(176, 207, 203);
            button.FlatAppearance.MouseOverBackColor = Color.FromArgb(219, 237, 234);
            return;
        }

        button.BackColor = Surface;
        button.ForeColor = BrandDark;
        button.FlatAppearance.BorderColor = Border;
        button.FlatAppearance.MouseOverBackColor = BrandSoft;
        button.FlatAppearance.MouseDownBackColor = Color.FromArgb(213, 233, 230);
    }

    private static void StyleTabs(Control root)
    {
        foreach (var tabs in Descendants<TabControl>(root))
        {
            tabs.DrawMode = TabDrawMode.OwnerDrawFixed;
            tabs.SizeMode = TabSizeMode.Fixed;
            tabs.ItemSize = new Size(190, 42);
            tabs.Padding = new Point(16, 8);
            tabs.Font = PreferredFont(10f, FontStyle.Bold);
            tabs.DrawItem += DrawTab;
        }
    }

    private static void DrawTab(object? sender, DrawItemEventArgs e)
    {
        if (sender is not TabControl tabs || e.Index < 0 || e.Index >= tabs.TabCount) return;
        var selected = tabs.SelectedIndex == e.Index;
        var rect = e.Bounds;
        rect.Inflate(-2, -2);
        e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
        using var background = new SolidBrush(selected ? Brand : Surface);
        using var border = new Pen(selected ? Brand : Border);
        e.Graphics.FillRectangle(background, rect);
        e.Graphics.DrawRectangle(border, rect);
        TextRenderer.DrawText(
            e.Graphics,
            tabs.TabPages[e.Index].Text,
            tabs.Font,
            rect,
            selected ? Color.White : Text,
            TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.RightToLeft | TextFormatFlags.EndEllipsis);
    }

    private static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }

    private static Font PreferredFont(float size, FontStyle style)
    {
        foreach (var name in new[] { "Segoe UI", "Tahoma" })
        {
            try
            {
                using var probe = new Font(name, size, style, GraphicsUnit.Point);
                if (string.Equals(probe.Name, name, StringComparison.OrdinalIgnoreCase))
                    return new Font(name, size, style, GraphicsUnit.Point);
            }
            catch { }
        }
        return new Font(SystemFonts.MessageBoxFont.FontFamily, size, style, GraphicsUnit.Point);
    }

    private static string DisplayVersion()
    {
        try
        {
            var executable = Environment.ProcessPath;
            if (!string.IsNullOrWhiteSpace(executable))
            {
                var raw = FileVersionInfo.GetVersionInfo(executable).FileVersion;
                if (!string.IsNullOrWhiteSpace(raw))
                {
                    var pieces = raw.Split('.', StringSplitOptions.RemoveEmptyEntries);
                    if (pieces.Length >= 3) return string.Join('.', pieces.Take(3));
                    return raw;
                }
            }
        }
        catch { }
        var product = Application.ProductVersion;
        var plus = product.IndexOf('+');
        return plus > 0 ? product[..plus] : product;
    }

    private static void ApplyWindowIcon(Form form)
    {
        try
        {
            var iconPath = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            if (!File.Exists(iconPath)) return;
            using var source = new Icon(iconPath);
            var icon = (Icon)source.Clone();
            form.Icon = icon;
            if (!form.IsHandleCreated) return;
            TaskbarIdentity.SetWindowIcon(form.Handle, icon.Handle);
        }
        catch { }
    }
}

internal static class TaskbarIdentity
{
    private const uint WmSetIcon = 0x0080;
    private static readonly IntPtr IconSmall = IntPtr.Zero;
    private static readonly IntPtr IconBig = new(1);

    public static void Apply()
    {
        try { _ = SetCurrentProcessExplicitAppUserModelID("SOKNA.WindowsServices.SetupUi"); }
        catch { }
    }

    public static void SetWindowIcon(IntPtr window, IntPtr icon)
    {
        if (window == IntPtr.Zero || icon == IntPtr.Zero) return;
        try
        {
            _ = SendMessage(window, WmSetIcon, IconBig, icon);
            _ = SendMessage(window, WmSetIcon, IconSmall, icon);
        }
        catch { }
    }

    [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
    private static extern int SetCurrentProcessExplicitAppUserModelID(string appId);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    private static extern IntPtr SendMessage(IntPtr hWnd, uint msg, IntPtr wParam, IntPtr lParam);
}
