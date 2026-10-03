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
        SelfServiceBootstrap.AttachOnce(form);
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
            header.Padding = new Padding(18, 6, 18, 6);
            header.AutoSize = false;
            header.RowStyles.Clear();
            header.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

            if (header.GetControlFromPosition(0, 0) is TableLayoutPanel titles && titles.RowStyles.Count >= 2)
            {
                titles.AutoSize = false;
                titles.Dock = DockStyle.Fill;
                titles.Margin = Padding.Empty;
                titles.MinimumSize = Size.Empty;
                titles.MaximumSize = Size.Empty;
                titles.RowStyles[0].SizeType = SizeType.Percent;
                titles.RowStyles[0].Height = 100;
                titles.RowStyles[1].SizeType = SizeType.Absolute;
                titles.RowStyles[1].Height = 0;

                foreach (var label in titles.Controls.OfType<Label>())
                {
                    if (SelfServiceDashboardUx.RemoveIsolation(label.Text).Contains("Runtime و Print Agent", StringComparison.Ordinal))
                    {
                        label.Visible = false;
                        continue;
                    }

                    label.AutoSize = false;
                    label.Dock = DockStyle.Fill;
                    label.TextAlign = ContentAlignment.MiddleRight;
                    label.Margin = Padding.Empty;
                }
            }

            if (header.GetControlFromPosition(1, 0) is Label version)
            {
                version.AutoSize = false;
                version.Size = new Size(72, 36);
                version.MinimumSize = new Size(72, 36);
                version.MaximumSize = new Size(72, 36);
                version.TextAlign = ContentAlignment.MiddleCenter;
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

        TableLayoutPanel? footerLayout = null;
        if (root.GetControlFromPosition(0, 3) is TableLayoutPanel footer)
        {
            footerLayout = footer;
            footer.AutoSize = false;
            footer.Padding = new Padding(2, 4, 2, 2);
            footer.RowStyles.Clear();
            footer.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

            if (footer.ColumnStyles.Count >= 3)
            {
                footer.ColumnStyles[0].SizeType = SizeType.Percent;
                footer.ColumnStyles[0].Width = 100;
                footer.ColumnStyles[1].SizeType = SizeType.Absolute;
                footer.ColumnStyles[1].Width = 110;
                footer.ColumnStyles[2].SizeType = SizeType.Absolute;
                footer.ColumnStyles[2].Width = 390;
            }
        }

        foreach (var flow in Descendants<FlowLayoutPanel>(form))
        {
            flow.AutoScroll = false;
            flow.AutoSize = false;
            flow.WrapContents = false;
            flow.Dock = DockStyle.Fill;
            flow.MinimumSize = Size.Empty;
            flow.Margin = Padding.Empty;
        }

        if (footerLayout?.GetControlFromPosition(2, 0) is FlowLayoutPanel footerActions)
        {
            footerActions.AutoSize = false;
            footerActions.WrapContents = false;
            footerActions.MinimumSize = Size.Empty;
            footerActions.FlowDirection = FlowDirection.RightToLeft;
            footerActions.Padding = new Padding(0, 2, 0, 0);
            footerActions.Margin = Padding.Empty;
            footerActions.Dock = DockStyle.Fill;

            foreach (var button in footerActions.Controls.OfType<Button>())
            {
                button.AutoSize = false;
                button.Size = new Size(92, 36);
                button.MinimumSize = new Size(92, 36);
                button.MaximumSize = new Size(92, 36);
                button.Margin = new Padding(4, 0, 0, 0);
            }
        }

        if (footerLayout?.GetControlFromPosition(0, 0) is Label footerStatus)
        {
            footerStatus.AutoSize = false;
            footerStatus.Dock = DockStyle.Fill;
            footerStatus.Margin = Padding.Empty;
            footerStatus.TextAlign = ContentAlignment.MiddleRight;
        }

        if (footerLayout?.GetControlFromPosition(1, 0) is ProgressBar progress)
        {
            progress.Dock = DockStyle.Fill;
            progress.Margin = new Padding(6, 7, 6, 7);
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

            var plain = SelfServiceDashboardUx.RemoveIsolation(label.Text);
            if (label.MaximumSize.Width > 0 ||
                plain.Contains("پیش‌نیاز", StringComparison.Ordinal) ||
                plain.Contains("Windows Services", StringComparison.Ordinal) ||
                plain.Contains("کد کوتاه", StringComparison.Ordinal))
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
                SelfServiceBootstrap.AttachOnce(form);
                form.StartPosition = FormStartPosition.Manual;
                form.Location = new Point(20, 20);
                form.CreateControl();
                form.Show();
                Application.DoEvents();

                form.ClientSize = size;
                form.ApplyLayoutTestScenario();
                SelfServiceDashboardUx.RefreshPresentation(form);
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

                var diagnosticButton = SelfServiceDashboardUx.Descendants<Button>(form)
                    .FirstOrDefault(button => button.Visible && string.Equals(button.Name, "selfServiceDiagnosticButton", StringComparison.Ordinal));
                if (diagnosticButton is null || SelfServiceDashboardUx.RemoveIsolation(diagnosticButton.Text) != "عیب‌یابی")
                    problems.Add("User-facing diagnostic button is missing from the dashboard.");
                if (SelfServiceDashboardUx.Descendants<Button>(form).Any(button => button.Visible && SelfServiceDashboardUx.RemoveIsolation(button.Text) == "بسته عیب‌یابی"))
                    problems.Add("Legacy support-bundle button is still visible instead of the self-service diagnostic entry point.");

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

            if (child is Button button && !string.IsNullOrWhiteSpace(button.Text))
            {
                var plain = SelfServiceDashboardUx.RemoveIsolation(button.Text);
                var measured = TextRenderer.MeasureText(plain, button.Font, Size.Empty, TextFormatFlags.SingleLine | TextFormatFlags.NoPadding);
                if (measured.Width + button.Padding.Horizontal + 6 > button.ClientSize.Width)
                    problems.Add($"Button text clipping {childPath}: text={plain}, required={measured.Width + button.Padding.Horizontal + 6}, actual={button.ClientSize.Width}");
            }

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
