using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Final compactness guard for the Prerequisites dashboard.
/// Keeps the existing dashboard tree intact and sizes it from the real Form client rectangle.
/// The guard is idempotent and reasserts geometry only when WinForms legacy preferred-size
/// negotiation moves the dashboard/footer away from the qualified bounds.
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
        Application.Idle += (_, _) => MaintainOpenForms();
        Application.AddMessageFilter(Filter);
    }

    private sealed class RetryFilter : IMessageFilter
    {
        public bool PreFilterMessage(ref Message m)
        {
            MaintainOpenForms();
            return false;
        }
    }

    private static void MaintainOpenForms()
    {
        foreach (Form form in Application.OpenForms)
        {
            if (form.GetType() != typeof(MainForm)) continue;
            if (!Applied.TryGetValue(form, out _))
            {
                if (!Prepare(form)) continue;
                Applied.Add(form, new object());
            }
            MaintainGeometry(form);
        }
    }

    private static bool Prepare(Form form)
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
            dashboard.Dock = DockStyle.None;
            dashboard.Anchor = AnchorStyles.None;
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

            form.ClientSizeChanged += (_, _) => MaintainGeometry(form, force: true);
            MaintainGeometry(form, force: true);
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

    private static void MaintainGeometry(Form form, bool force = false)
    {
        if (form.IsDisposed) return;
        var dashboard = Find<TableLayoutPanel>(form, "SoknaPrerequisitesDashboard");
        var footer = Find<TableLayoutPanel>(form, "SoknaPrerequisitesFooter");
        if (dashboard is null || footer is null || dashboard.RowStyles.Count < 3) return;

        var width = Math.Max(0, form.ClientSize.Width);
        var height = Math.Max(0, form.ClientSize.Height);
        var middle = Math.Max(180, height - HeaderHeight - FooterHeight);
        var targetBounds = new Rectangle(0, 0, width, height);

        var mismatch = force || dashboard.Bounds != targetBounds ||
                       dashboard.RowStyles[0].SizeType != SizeType.Absolute || Math.Abs(dashboard.RowStyles[0].Height - HeaderHeight) > 0.1f ||
                       dashboard.RowStyles[1].SizeType != SizeType.Absolute || Math.Abs(dashboard.RowStyles[1].Height - middle) > 0.1f ||
                       dashboard.RowStyles[2].SizeType != SizeType.Absolute || Math.Abs(dashboard.RowStyles[2].Height - FooterHeight) > 0.1f ||
                       footer.Bottom > height || footer.Height < FooterHeight - 2;
        if (!mismatch) return;

        dashboard.SuspendLayout();
        try
        {
            dashboard.SetBounds(0, 0, width, height);
            dashboard.RowStyles[0].SizeType = SizeType.Absolute;
            dashboard.RowStyles[0].Height = HeaderHeight;
            dashboard.RowStyles[1].SizeType = SizeType.Absolute;
            dashboard.RowStyles[1].Height = middle;
            dashboard.RowStyles[2].SizeType = SizeType.Absolute;
            dashboard.RowStyles[2].Height = FooterHeight;
            dashboard.PerformLayout();
            footer.PerformLayout();
        }
        finally
        {
            dashboard.ResumeLayout(true);
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
