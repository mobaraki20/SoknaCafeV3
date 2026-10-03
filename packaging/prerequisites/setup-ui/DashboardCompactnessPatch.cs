using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Final compactness guard for the Prerequisites dashboard.
/// The qualification target is an outer 960x650 WinForms window (about 944x611 client area).
/// Header/footer are pinned with Dock.Top/Dock.Bottom so WinForms cannot sacrifice the
/// operation footer when a tab reports a larger minimum height.
/// Presentation-only: no infrastructure state or lifecycle behavior is changed.
/// </summary>
internal static class DashboardCompactnessPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter Filter = new RetryFilter();
    private static readonly Color Canvas = Color.FromArgb(246, 246, 243);

    [ModuleInitializer]
    internal static void Initialize()
    {
        Application.Idle += (_, _) => ApplyOpenForms();
        Application.AddMessageFilter(Filter);
    }

    private sealed class RetryFilter : IMessageFilter
    {
        public bool PreFilterMessage(ref Message m)
        {
            ApplyOpenForms();
            return false;
        }
    }

    private static void ApplyOpenForms()
    {
        foreach (Form form in Application.OpenForms)
        {
            if (form.GetType() != typeof(MainForm) || Applied.TryGetValue(form, out _)) continue;
            if (!TryApply(form)) continue;
            Applied.Add(form, new object());
        }
    }

    private static bool TryApply(Form form)
    {
        var dashboard = Find<TableLayoutPanel>(form, "SoknaPrerequisitesDashboard");
        var header = Find<TableLayoutPanel>(form, "SoknaPrerequisitesHeader");
        var tabs = Find<TabControl>(form, "SoknaPrerequisitesTabs");
        var footer = Find<TableLayoutPanel>(form, "SoknaPrerequisitesFooter");
        var actions = Find<FlowLayoutPanel>(form, "SoknaFooterActions");
        var overview = Find<TableLayoutPanel>(form, "SoknaOverviewLayout");
        if (dashboard is null || header is null || tabs is null || footer is null || actions is null || overview is null)
            return false;

        form.SuspendLayout();
        dashboard.SuspendLayout();
        footer.SuspendLayout();
        actions.SuspendLayout();
        overview.SuspendLayout();
        try
        {
            // The legacy explanatory row duplicates the compact mode summary below.
            // Removing it gives the path controls useful space without hiding behavior.
            if (overview.RowStyles.Count >= 4)
            {
                var redundantHelp = overview.GetControlFromPosition(0, 1);
                if (redundantHelp is not null) redundantHelp.Visible = false;
                overview.RowStyles[1].SizeType = SizeType.Absolute;
                overview.RowStyles[1].Height = 0;
                overview.RowStyles[3].SizeType = SizeType.Absolute;
                overview.RowStyles[3].Height = 26;
            }
            overview.Padding = new Padding(12, 6, 12, 6);

            header.Padding = new Padding(18, 7, 18, 6);
            header.AutoSize = false;
            header.Height = 68;
            header.MinimumSize = Size.Empty;

            footer.Padding = new Padding(14, 1, 14, 1);
            footer.AutoSize = false;
            footer.Height = 54;
            footer.MinimumSize = Size.Empty;
            if (footer.RowStyles.Count >= 3)
            {
                footer.RowStyles[0].SizeType = SizeType.Absolute;
                footer.RowStyles[0].Height = 11;
                footer.RowStyles[1].SizeType = SizeType.Absolute;
                footer.RowStyles[1].Height = 7;
                footer.RowStyles[2].SizeType = SizeType.Percent;
                footer.RowStyles[2].Height = 100;
            }

            actions.Padding = new Padding(0, 1, 0, 0);
            actions.Margin = Padding.Empty;
            actions.AutoSize = false;
            foreach (var button in actions.Controls.OfType<Button>())
            {
                button.AutoSize = false;
                button.Height = 28;
                button.MinimumSize = new Size(button.MinimumSize.Width, 28);
                button.Margin = new Padding(5, 0, 0, 0);
            }

            // Detach the three live regions before disposing the TableLayout shell.
            dashboard.Controls.Remove(header);
            dashboard.Controls.Remove(tabs);
            dashboard.Controls.Remove(footer);
            form.Controls.Remove(dashboard);

            var shell = new Panel
            {
                Name = "SoknaPrerequisitesPinnedShell",
                Dock = DockStyle.Fill,
                BackColor = Canvas,
                Margin = Padding.Empty,
                Padding = Padding.Empty,
                AutoScroll = false
            };
            var content = new Panel
            {
                Name = "SoknaPrerequisitesContentHost",
                Dock = DockStyle.Fill,
                BackColor = Canvas,
                Padding = new Padding(14, 5, 14, 2),
                Margin = Padding.Empty,
                AutoScroll = false
            };

            header.Dock = DockStyle.Top;
            header.Height = 68;
            header.Margin = Padding.Empty;

            footer.Dock = DockStyle.Bottom;
            footer.Height = 54;
            footer.Margin = Padding.Empty;

            tabs.Dock = DockStyle.Fill;
            tabs.Margin = Padding.Empty;
            content.Controls.Add(tabs);

            // Docking is deterministic here: top and bottom reserve their pixels,
            // then the content host receives the remaining client rectangle.
            shell.Controls.Add(content);
            shell.Controls.Add(footer);
            shell.Controls.Add(header);
            header.BringToFront();
            footer.BringToFront();

            form.Controls.Clear();
            form.Controls.Add(shell);
            dashboard.Dispose();

            overview.PerformLayout();
            footer.PerformLayout();
            actions.PerformLayout();
            content.PerformLayout();
            shell.PerformLayout();
            form.PerformLayout();
            return true;
        }
        finally
        {
            overview.ResumeLayout(true);
            actions.ResumeLayout(true);
            footer.ResumeLayout(true);
            if (!dashboard.IsDisposed) dashboard.ResumeLayout(true);
            form.ResumeLayout(true);
        }
    }

    private static T? Find<T>(Control root, string name) where T : Control
        => root.Controls.Find(name, true).OfType<T>().FirstOrDefault();
}
