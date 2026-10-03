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

        Application.Run(new PersianDashboardForm());
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
            foreach (var size in new[] { new Size(980, 620), new Size(1120, 680), new Size(1280, 720) })
            {
                using var form = new PersianDashboardForm();
                form.StartPosition = FormStartPosition.Manual;
                form.Location = new Point(20, 20);
                form.ClientSize = size;
                form.CreateControl();
                ForceLayout(form);
                var problems = new List<string>();
                Inspect(form, "form", problems);
                if (form.AutoScroll) problems.Add("Main form AutoScroll must be false.");
                if (FindScrollableAutoScroll(form).Any()) problems.Add("A visible child control has AutoScroll enabled.");

                var report = Path.Combine(outputRoot, $"layout-{size.Width}x{size.Height}.txt");
                File.WriteAllLines(report, problems.Count == 0 ? new[] { "PASS" } : problems);

                using var bitmap = new Bitmap(size.Width, size.Height);
                form.DrawToBitmap(bitmap, new Rectangle(Point.Empty, size));
                bitmap.Save(Path.Combine(outputRoot, $"layout-{size.Width}x{size.Height}.png"), ImageFormat.Png);

                if (problems.Count > 0)
                {
                    Console.Error.WriteLine(string.Join(Environment.NewLine, problems));
                    return 9;
                }
            }
            Console.WriteLine("SOKNA Windows Services Persian dashboard layout self-test PASS");
            return 0;
        }
        catch (Exception e)
        {
            Console.Error.WriteLine(e.ToString());
            return 10;
        }
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
