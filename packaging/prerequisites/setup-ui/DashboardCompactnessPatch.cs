using System.Runtime.CompilerServices;

namespace Sokna.Prerequisites.Setup;

/// <summary>
/// Final compactness guard for the Prerequisites dashboard.
/// The qualification target is an outer 960x650 WinForms window (about 944x611 client area).
/// Header/content/footer use deterministic bounds instead of legacy TableLayout minimums.
/// Existing buttons and event handlers are re-parented, never recreated.
/// Presentation-only: no infrastructure state or lifecycle behavior is changed.
/// </summary>
internal static class DashboardCompactnessPatch
{
    private static readonly ConditionalWeakTable<Form, object> Applied = new();
    private static readonly IMessageFilter Filter = new RetryFilter();
    private static readonly Color Canvas = Color.FromArgb(246, 246, 243);
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
        var legacyFooter = Find<TableLayoutPanel>(form, "SoknaPrerequisitesFooter");
        var actions = Find<FlowLayoutPanel>(form, "SoknaFooterActions");
        var overview = Find<TableLayoutPanel>(form, "SoknaOverviewLayout");
        if (dashboard is null || header is null || tabs is null || legacyFooter is null || actions is null || overview is null)
            return false;

        var progressText = legacyFooter.Controls.OfType<Label>().FirstOrDefault();
        var progress = legacyFooter.Controls.OfType<ProgressBar>().FirstOrDefault();
        var buttons = actions.Controls.OfType<Button>().ToArray();
        if (progressText is null || progress is null || buttons.Length == 0) return false;

        form.SuspendLayout();
        dashboard.SuspendLayout();
        legacyFooter.SuspendLayout();
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

            // Detach the live footer controls from the legacy TableLayout. Their existing
            // click/cancel/log/support handlers remain attached because the controls
            // themselves are reused.
            legacyFooter.Controls.Remove(progressText);
            legacyFooter.Controls.Remove(progress);
            foreach (var button in buttons) actions.Controls.Remove(button);

            dashboard.Controls.Remove(header);
            dashboard.Controls.Remove(tabs);
            dashboard.Controls.Remove(legacyFooter);
            dashboard.Visible = false;
            dashboard.Dock = DockStyle.None;
            dashboard.Bounds = Rectangle.Empty;
            legacyFooter.Visible = false;

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
            var compactFooter = new Panel
            {
                Name = "SoknaPrerequisitesCompactFooter",
                Dock = DockStyle.None,
                BackColor = Color.White,
                Margin = Padding.Empty,
                Padding = Padding.Empty,
                AutoScroll = false
            };

            tabs.Dock = DockStyle.Fill;
            tabs.Margin = Padding.Empty;
            content.Controls.Add(tabs);

            progressText.AutoSize = false;
            progressText.Dock = DockStyle.None;
            progressText.TextAlign = ContentAlignment.MiddleRight;
            progressText.Margin = Padding.Empty;

            progress.AutoSize = false;
            progress.Dock = DockStyle.None;
            progress.Margin = Padding.Empty;

            compactFooter.Controls.Add(progressText);
            compactFooter.Controls.Add(progress);
            foreach (var button in buttons)
            {
                button.AutoSize = false;
                button.Dock = DockStyle.None;
                button.Height = 28;
                button.MinimumSize = new Size(0, 0);
                button.Margin = Padding.Empty;
                compactFooter.Controls.Add(button);
            }

            shell.Controls.Add(content);
            shell.Controls.Add(header);
            shell.Controls.Add(compactFooter);
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
                compactFooter.SetBounds(0, footerY, width, Math.Min(FooterHeight, Math.Max(0, height - footerY)));

                var innerWidth = Math.Max(0, width - 28);
                progressText.SetBounds(14, 1, innerWidth, 12);
                progress.SetBounds(14, 15, innerWidth, 7);

                var x = width - 14;
                foreach (var button in buttons)
                {
                    var buttonWidth = string.Equals(button.Name, "SoknaDiagnosticsButton", StringComparison.Ordinal)
                        ? 138
                        : Math.Clamp(button.Width > 0 ? button.Width : 112, 100, 118);
                    x -= buttonWidth;
                    button.SetBounds(Math.Max(14, x), 27, buttonWidth, 28);
                    x -= 5;
                }

                header.Visible = true;
                content.Visible = true;
                compactFooter.Visible = true;
                header.BringToFront();
                compactFooter.BringToFront();

                header.PerformLayout();
                content.PerformLayout();
                compactFooter.PerformLayout();
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
            actions.ResumeLayout(false);
            legacyFooter.ResumeLayout(false);
            dashboard.ResumeLayout(false);
            form.ResumeLayout(true);
        }
    }

    private static T? Find<T>(Control root, string name) where T : Control
        => root.Controls.Find(name, true).OfType<T>().FirstOrDefault();
}
