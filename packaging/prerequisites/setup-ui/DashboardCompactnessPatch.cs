using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Final compactness guard for the Prerequisites dashboard.
/// The qualification target is an outer 960x650 WinForms window (about 944x611 client area).
/// Keep the primary actions fully visible without adding vertical/horizontal scrolling.
/// Presentation-only: no infrastructure state or lifecycle behavior is changed.
/// </summary>
internal static class DashboardCompactnessPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter Filter = new RetryFilter();

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
            // 68 + 54 leaves materially more height for the tab content than the
            // previous 80 + 72 shell, while preserving a clear branded header and
            // always-visible operation controls.
            if (dashboard.RowStyles.Count >= 3)
            {
                dashboard.RowStyles[0].SizeType = SizeType.Absolute;
                dashboard.RowStyles[0].Height = 68;
                dashboard.RowStyles[2].SizeType = SizeType.Absolute;
                dashboard.RowStyles[2].Height = 54;
            }

            header.Padding = new Padding(18, 7, 18, 6);
            tabs.Margin = new Padding(14, 5, 14, 2);

            // BuildOverview already has a compact bottom mode summary. The legacy
            // explanatory paragraph above the path section duplicates that information
            // and was the remaining minimum-height pressure at 960x650. Collapse only
            // that row; detailed explanations remain in the dedicated tabs/diagnostics.
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

            actions.Padding = new Padding(0, 1, 0, 0);
            actions.Margin = Padding.Empty;
            foreach (var button in actions.Controls.OfType<Button>())
            {
                button.AutoSize = false;
                button.Height = 28;
                button.MinimumSize = new Size(button.MinimumSize.Width, 28);
                button.Margin = new Padding(5, 0, 0, 0);
            }

            overview.PerformLayout();
            dashboard.PerformLayout();
            footer.PerformLayout();
            actions.PerformLayout();
            form.PerformLayout();
            return true;
        }
        finally
        {
            overview.ResumeLayout(true);
            actions.ResumeLayout(true);
            footer.ResumeLayout(true);
            dashboard.ResumeLayout(true);
            form.ResumeLayout(true);
        }
    }

    private static T? Find<T>(Control root, string name) where T : Control
        => root.Controls.Find(name, true).OfType<T>().FirstOrDefault();
}
