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
        Application.Run(form);
        return 0;
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
                form.StartPosition = FormStartPosition.Manual;
                form.Location = new Point(20, 20);
                form.CreateControl();
                form.Show();
                Application.DoEvents();

                form.ClientSize = size;
                form.ApplyLayoutTestScenario();
                ForceLayout(form);
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

    private static void ForceLayout(Control root)
    {
        root.PerformLayout();
        foreach (Control child in root.Controls) ForceLayout(child);
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
