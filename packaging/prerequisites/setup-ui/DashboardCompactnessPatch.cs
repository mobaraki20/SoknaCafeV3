using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Final compactness guard for the Prerequisites dashboard.
/// The qualification target is an outer 960x650 WinForms window (about 944x611 client area).
/// Header/content/footer use deterministic bounds instead of Dock ordering so WinForms
/// cannot hide the fixed regions behind the fill content at small sizes.
/// Presentation-only: no infrastructure state or lifecycle behavior is changed.
/// </summary>
internal static class DashboardCompactnessPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter Filter = new RetryFilter();
    private static readonly Color Canvas = Color.FromArgb(246, 246, 243);
    private const int HeaderHeight = 68;
    private const int FooterHeight = 54;

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
            header.MinimumSize = Size.Empty;
            header.Dock = DockStyle.None;
            header.Margin = Padding.Empty;

            footer.Padding = new Padding(14, 1, 14, 1);
            footer.AutoSize = false;
            footer.MinimumSize = Size.Empty;
            footer.Dock = DockStyle.None;
            footer.Margin = Padding.Empty;
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

            dashboard.Controls.Remove(header);
            dashboard.Controls.Remove(tabs);
            dashboard.Controls.Remove(footer);
            dashboard.Visible = false;
            dashboard.Dock = DockStyle.None;
            dashboard.Bounds = Rectangle.Empty;

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
                Dock = DockStyle.None,
                BackColor = Canvas,
                Padding = new Padding(14, 5, 14, 2),
                Margin = Padding.Empty,
                AutoScroll = false
            };

            tabs.Dock = DockStyle.Fill;
            tabs.Margin = Padding.Empty;
            content.Controls.Add(tabs);

            shell.Controls.Add(content);
            shell.Controls.Add(header);
            shell.Controls.Add(footer);
            form.Controls.Add(shell);
            shell.BringToFront();

            void LayoutShell()
            {
                var width = Math.Max(0, shell.ClientSize.Width);
                var height = Math.Max(0, shell.ClientSize.Height);
                var footerY = Math.Max(HeaderHeight, height - FooterHeight);
                var contentHeight = Math.Max(0, footerY - HeaderHeight);

                header.SetBounds(0, 0, width, Math.Min(HeaderHeight, height));
                content.SetBounds(0, HeaderHeight, width, contentHeight);
                footer.SetBounds(0, footerY, width, Math.Min(FooterHeight, Math.Max(0, height - footerY)));

                header.Visible = true;
                content.Visible = true;
                footer.Visible = true;
                header.BringToFront();
                footer.BringToFront();

                header.PerformLayout();
                content.PerformLayout();
                footer.PerformLayout();
                actions.PerformLayout();
            }

            shell.SizeChanged += (_, _) => LayoutShell();
            form.ClientSizeChanged += (_, _) => LayoutShell();

            overview.PerformLayout();
            LayoutShell();
            shell.PerformLayout();
            form.PerformLayout();
            LayoutShell();
            form.Invalidate(true);
            form.Update();
            return true;
        }
        finally
        {
            overview.ResumeLayout(true);
            actions.ResumeLayout(true);
            footer.ResumeLayout(true);
            dashboard.ResumeLayout(false);
            form.ResumeLayout(true);
        }
    }

    private static T? Find<T>(Control root, string name) where T : Control
        => root.Controls.Find(name, true).OfType<T>().FirstOrDefault();
}
