using System.Drawing.Imaging;

namespace Sokna.SetupUi;

internal static class DashboardEntry
{
    [STAThread]
    private static int Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        TaskbarIdentity.Apply();

        if (args.Length >= 1 && args[0].Equals("--layout-self-test", StringComparison.OrdinalIgnoreCase))
        {
            var output = args.Length >= 2 ? Path.GetFullPath(args[1]) : Path.Combine(Path.GetTempPath(), "sokna-ui-layout-test");
            return DashboardLayoutSelfTest.Run(output);
        }

        using var form = new PersianDashboardFormV2 { RightToLeftLayout = true };
        DashboardLayoutPolicy.Attach(form);
        Application.Run(form);
        return 0;
    }
}

internal static class DashboardLayoutPolicy
{
    internal static void Attach(PersianDashboardFormV2 form)
    {
        form.RightToLeft = RightToLeft.Yes;
        form.RightToLeftLayout = true;
        form.AutoScroll = false;

        ApplyStructuralRules(form);

        var applying = false;
        void Reflow()
        {
            if (applying || form.IsDisposed) return;
            applying = true;
            try
            {
                ApplyAdaptiveRules(form);
                form.PerformLayout();
            }
            finally { applying = false; }
        }

        form.Layout += (_, _) => Reflow();
        form.Resize += (_, _) => Reflow();
        form.Shown += (_, _) => Reflow();
        Reflow();
    }

    internal static void Reflow(PersianDashboardFormV2 form)
    {
        ApplyStructuralRules(form);
        ApplyAdaptiveRules(form);
        ForceLayout(form);
    }

    private static void ApplyStructuralRules(PersianDashboardFormV2 form)
    {
        var root = form.Controls.OfType<TableLayoutPanel>().FirstOrDefault();
        if (root is null) return;

        root.AutoScroll = false;
        if (root.RowStyles.Count >= 4)
        {
            root.RowStyles[0].SizeType = SizeType.Absolute;
            root.RowStyles[0].Height = 86;
            root.RowStyles[1].SizeType = SizeType.Absolute;
            root.RowStyles[1].Height = 104;
            root.RowStyles[3].SizeType = SizeType.Absolute;
            root.RowStyles[3].Height = 54;
        }

        if (root.GetControlFromPosition(0, 0) is TableLayoutPanel header)
        {
            header.Margin = Padding.Empty;
            if (header.GetControlFromPosition(0, 0) is TableLayoutPanel titles && titles.RowStyles.Count >= 2)
            {
                titles.RowStyles[0].SizeType = SizeType.Percent;
                titles.RowStyles[0].Height = 100;
                titles.RowStyles[1].SizeType = SizeType.Absolute;
                titles.RowStyles[1].Height = 0;
                foreach (var subtitle in titles.Controls.OfType<Label>().Where(label => label.Text.Contains("Runtime و Print Agent", StringComparison.Ordinal)))
                    subtitle.Visible = false;
            }
        }

        if (root.GetControlFromPosition(0, 1) is TableLayoutPanel summary)
            summary.Margin = Padding.Empty;

        if (root.GetControlFromPosition(0, 2) is TableLayoutPanel content && content.ColumnStyles.Count >= 2)
        {
            content.ColumnStyles[0].SizeType = SizeType.Percent;
            content.ColumnStyles[0].Width = 55;
            content.ColumnStyles[1].SizeType = SizeType.Percent;
            content.ColumnStyles[1].Width = 45;
        }

        if (root.GetControlFromPosition(0, 3) is TableLayoutPanel footer)
            footer.Padding = new Padding(2, 3, 2, 0);

        foreach (var flow in Descendants<FlowLayoutPanel>(form))
        {
            flow.AutoScroll = false;
            flow.AutoSize = false;
            flow.WrapContents = true;
            flow.Dock = DockStyle.Fill;
            flow.MinimumSize = new Size(0, 42);
            flow.Margin = Padding.Empty;
        }

        foreach (var table in Descendants<TableLayoutPanel>(form))
        {
            table.AutoScroll = false;
            if (table.RowCount == 2 && table.ColumnCount == 1 && table.Controls.OfType<TextBox>().Any())
            {
                table.AutoSize = false;
                table.Dock = DockStyle.Top;
                table.Height = 54;
                table.MinimumSize = new Size(0, 54);
            }

            if (table.RowCount == 4 && table.ColumnCount == 2)
            {
                foreach (var label in table.Controls.OfType<Label>())
                {
                    label.AutoSize = false;
                    label.Dock = DockStyle.Fill;
                    label.TextAlign = ContentAlignment.MiddleRight;
                    label.Margin = new Padding(0, 1, 0, 1);
                }
            }
        }
    }

    private static void ApplyAdaptiveRules(PersianDashboardFormV2 form)
    {
        foreach (var label in Descendants<Label>(form))
        {
            if (!label.Visible || label.Parent is null) continue;
            var available = label.Parent.ClientSize.Width - label.Parent.Padding.Horizontal;
            if (available <= 80) continue;

            if (label.MaximumSize.Width > 0 ||
                label.Text.Contains("پیش‌نیاز", StringComparison.Ordinal) ||
                label.Text.Contains("Windows Services", StringComparison.Ordinal) ||
                label.Text.Contains("کد کوتاه", StringComparison.Ordinal))
            {
                label.MaximumSize = new Size(Math.Max(80, available), 0);
            }
        }
    }

    private static void ForceLayout(Control root)
    {
        root.PerformLayout();
        foreach (Control child in root.Controls) ForceLayout(child);
    }

    private static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        foreach (Control child in root.Controls)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}

internal static class DashboardLayoutSelfTest
{
    public static int Run(string outputRoot)
    {
        try
        {
            Directory.CreateDirectory(outputRoot);
            foreach (var size in new[] { new Size(960, 600), new Size(1100, 660), new Size(1280, 720) })
            {
                using var form = new PersianDashboardFormV2 { RightToLeftLayout = true };
                DashboardLayoutPolicy.Attach(form);
                form.StartPosition = FormStartPosition.Manual;
                form.Location = new Point(20, 20);
                form.CreateControl();
                form.Show();
                Application.DoEvents();

                form.ClientSize = size;
                form.ApplyLayoutTestScenario();
                DashboardLayoutPolicy.Reflow(form);
                form.Refresh();
                Application.DoEvents();

                var problems = new List<string>();
                Inspect(form, "form", problems);
                if (form.AutoScroll) problems.Add("Main form AutoScroll must be false.");
                if (form.RightToLeft != RightToLeft.Yes) problems.Add("Main form RightToLeft must be Yes.");
                if (!form.RightToLeftLayout) problems.Add("Main form RightToLeftLayout must be true.");
                if (!form.Font.Name.Contains("Vazirmatn", StringComparison.OrdinalIgnoreCase)) problems.Add($"Persian UI font is not active: {form.Font.Name}");
                if (FindScrollableAutoScroll(form).Any()) problems.Add("A visible child control has AutoScroll enabled.");

                using var bitmap = new Bitmap(size.Width, size.Height);
                var surface = form.Controls.Count > 0 ? form.Controls[0] : form;
                surface.DrawToBitmap(bitmap, new Rectangle(Point.Empty, size));
                if (!HasExpectedVisualContent(bitmap)) problems.Add("Rendered dashboard screenshot is visually empty or missing the SOKNA brand header.");

                var report = Path.Combine(outputRoot, $"layout-{size.Width}x{size.Height}.txt");
                File.WriteAllLines(report, problems.Count == 0 ? new[] { "PASS" } : problems);
                bitmap.Save(Path.Combine(outputRoot, $"layout-{size.Width}x{size.Height}.png"), ImageFormat.Png);

                form.Hide();
                Application.DoEvents();

                if (problems.Count > 0)
                {
                    Console.Error.WriteLine(string.Join(Environment.NewLine, problems));
                    return 9;
                }
            }
            Console.WriteLine("SOKNA Windows Services Persian dashboard layout self-test PASS");
            return 0;
        }
        catch (Exception exception)
        {
            Console.Error.WriteLine(exception.ToString());
            return 10;
        }
    }

    private static bool HasExpectedVisualContent(Bitmap bitmap)
    {
        var brand = Color.FromArgb(20, 91, 84).ToArgb();
        var sampled = 0;
        var brandSamples = 0;
        for (var y = 0; y < bitmap.Height; y += 4)
        {
            for (var x = 0; x < bitmap.Width; x += 4)
            {
                sampled++;
                if (bitmap.GetPixel(x, y).ToArgb() == brand) brandSamples++;
            }
        }
        return brandSamples >= Math.Max(20, sampled / 100);
    }

    private static void Inspect(Control parent, string path, List<string> problems)
    {
        foreach (Control child in parent.Controls)
        {
            if (!child.Visible) continue;
            var name = string.IsNullOrWhiteSpace(child.Name) ? child.GetType().Name : child.Name;
            var childPath = path + "/" + name;
            if (child.Left < -2 || child.Top < -2 || child.Right > parent.ClientSize.Width + 2 || child.Bottom > parent.ClientSize.Height + 2)
                problems.Add($"Overflow {childPath}: bounds={child.Bounds} parent={parent.ClientSize}");
            Inspect(child, childPath, problems);
        }
    }

    private static IEnumerable<ScrollableControl> FindScrollableAutoScroll(Control root)
    {
        foreach (Control child in root.Controls)
        {
            if (child.Visible && child is ScrollableControl scroll && scroll.AutoScroll) yield return scroll;
            foreach (var nested in FindScrollableAutoScroll(child)) yield return nested;
        }
    }
}
