using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Final compactness guard for the Prerequisites dashboard.
/// Keeps the existing dashboard tree intact and only relaxes legacy minimum-size pressure.
/// The qualification target is an outer 960x650 WinForms window with no main-page scroll.
/// Presentation-only: no infrastructure state, lifecycle behavior or event handlers change.
/// </summary>
internal static class DashboardCompactnessPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter Filter = new RetryFilter();
    private const int HeaderHeight = 68;
    private const int FooterHeight = 58;

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
        header.SuspendLayout();
        tabs.SuspendLayout();
        footer.SuspendLayout();
        actions.SuspendLayout();
        overview.SuspendLayout();
        try
        {
            form.AutoScroll = false;
            form.Padding = Padding.Empty;

            dashboard.AutoSize = false;
            dashboard.AutoScroll = false;
            dashboard.MinimumSize = Size.Empty;
            dashboard.MaximumSize = Size.Empty;
            dashboard.Dock = DockStyle.Fill;
            dashboard.Margin = Padding.Empty;
            dashboard.GrowStyle = TableLayoutPanelGrowStyle.FixedSize;

            header.AutoSize = false;
            header.MinimumSize = Size.Empty;
            header.MaximumSize = Size.Empty;
            header.Dock = DockStyle.Fill;
            header.Margin = Padding.Empty;
            header.Padding = new Padding(18, 7, 18, 6);

            tabs.AutoSize = false;
            tabs.MinimumSize = Size.Empty;
            tabs.MaximumSize = Size.Empty;
            tabs.Dock = DockStyle.Fill;
            tabs.Margin = new Padding(14, 5, 14, 2);
            foreach (TabPage page in tabs.TabPages)
            {
                page.AutoScroll = false;
                page.MinimumSize = Size.Empty;
                page.MaximumSize = Size.Empty;
                RelaxContainerMinimums(page);
            }

            // The long explanatory label duplicates the compact mode summary and is the
            // main legacy height pressure on 960x650. Keep behavior/status, remove only
            // the duplicated prose row from the primary dashboard.
            overview.AutoScroll = false;
            overview.MinimumSize = Size.Empty;
            overview.MaximumSize = Size.Empty;
            overview.Padding = new Padding(12, 6, 12, 6);
            if (overview.RowStyles.Count >= 4)
            {
                var redundantHelp = overview.GetControlFromPosition(0, 1);
                if (redundantHelp is not null) redundantHelp.Visible = false;
                overview.RowStyles[1].SizeType = SizeType.Absolute;
                overview.RowStyles[1].Height = 0;
                overview.RowStyles[2].SizeType = SizeType.Percent;
                overview.RowStyles[2].Height = 100;
                overview.RowStyles[3].SizeType = SizeType.Absolute;
                overview.RowStyles[3].Height = 26;
            }

            footer.AutoSize = false;
            footer.AutoScroll = false;
            footer.MinimumSize = Size.Empty;
            footer.MaximumSize = Size.Empty;
            footer.Dock = DockStyle.Fill;
            footer.Margin = Padding.Empty;
            footer.Padding = new Padding(14, 1, 14, 1);
            if (footer.RowStyles.Count >= 3)
            {
                footer.RowStyles[0].SizeType = SizeType.Absolute;
                footer.RowStyles[0].Height = 11;
                footer.RowStyles[1].SizeType = SizeType.Absolute;
                footer.RowStyles[1].Height = 7;
                footer.RowStyles[2].SizeType = SizeType.Percent;
                footer.RowStyles[2].Height = 100;
            }

            actions.AutoSize = false;
            actions.AutoScroll = false;
            actions.MinimumSize = Size.Empty;
            actions.MaximumSize = Size.Empty;
            actions.Dock = DockStyle.Fill;
            actions.Padding = new Padding(0, 1, 0, 0);
            actions.Margin = Padding.Empty;
            foreach (var button in actions.Controls.OfType<Button>())
            {
                button.AutoSize = false;
                button.Height = 28;
                button.MinimumSize = new Size(button.MinimumSize.Width, 28);
                button.Margin = new Padding(5, 0, 0, 0);
            }

            void PinRowsToClientHeight()
            {
                if (dashboard.RowStyles.Count < 3) return;
                var available = Math.Max(0, dashboard.ClientSize.Height);
                var middle = Math.Max(180, available - HeaderHeight - FooterHeight);

                dashboard.RowStyles[0].SizeType = SizeType.Absolute;
                dashboard.RowStyles[0].Height = HeaderHeight;
                dashboard.RowStyles[1].SizeType = SizeType.Absolute;
                dashboard.RowStyles[1].Height = middle;
                dashboard.RowStyles[2].SizeType = SizeType.Absolute;
                dashboard.RowStyles[2].Height = FooterHeight;

                dashboard.PerformLayout();
                footer.PerformLayout();
            }

            dashboard.SizeChanged += (_, _) => PinRowsToClientHeight();
            form.ClientSizeChanged += (_, _) => PinRowsToClientHeight();

            overview.PerformLayout();
            actions.PerformLayout();
            footer.PerformLayout();
            tabs.PerformLayout();
            header.PerformLayout();
            dashboard.PerformLayout();
            form.PerformLayout();
            PinRowsToClientHeight();
            form.PerformLayout();
            form.Invalidate(true);
            form.Update();
            return true;
        }
        finally
        {
            overview.ResumeLayout(true);
            actions.ResumeLayout(true);
            footer.ResumeLayout(true);
            tabs.ResumeLayout(true);
            header.ResumeLayout(true);
            dashboard.ResumeLayout(true);
            form.ResumeLayout(true);
        }
    }

    private static void RelaxContainerMinimums(Control root)
    {
        foreach (Control child in root.Controls)
        {
            if (child is Panel or TableLayoutPanel or FlowLayoutPanel or GroupBox)
            {
                child.MinimumSize = Size.Empty;
                child.MaximumSize = Size.Empty;
            }
            RelaxContainerMinimums(child);
        }
    }

    private static T? Find<T>(Control root, string name) where T : Control
        => root.Controls.Find(name, true).OfType<T>().FirstOrDefault();
}
