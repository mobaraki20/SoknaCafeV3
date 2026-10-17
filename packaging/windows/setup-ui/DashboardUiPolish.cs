namespace Sokna.SetupUi;

internal static class DashboardUiPolish
{
    internal static void Apply(WindowsServicesDashboardV3 form)
    {
        if (form.Controls.OfType<TableLayoutPanel>().FirstOrDefault() is { RowCount: 5 } root && root.RowStyles.Count >= 5)
        {
            root.RowStyles[0].SizeType = SizeType.Absolute;
            root.RowStyles[0].Height = 82;
            root.RowStyles[1].SizeType = SizeType.Absolute;
            root.RowStyles[1].Height = 80;
            root.RowStyles[2].SizeType = SizeType.Absolute;
            root.RowStyles[2].Height = 140;
            root.RowStyles[4].SizeType = SizeType.Absolute;
            root.RowStyles[4].Height = 46;
        }

        foreach (var button in Descendants<Button>(form).ToArray())
        {
            if (string.Equals(button.Text, "بسته عیب‌یابی", StringComparison.Ordinal))
                button.Text = "عیب‌یابی";
        }

        var canonicalBadge = DashboardPackageVersionBinder.BadgeText(AppContext.BaseDirectory);
        foreach (var label in Descendants<Label>(form).ToArray())
        {
            if (label.Text.StartsWith("Windows Services ", StringComparison.Ordinal))
                label.Text = canonicalBadge;
            NormalizeLabel(label);
            label.TextChanged += (_, _) => NormalizeLabel(label);
        }
        AttachDynamicNormalization(form);

        foreach (var table in Descendants<TableLayoutPanel>(form).ToArray())
        {
            if (table.RowCount == 1 && table.ColumnCount == 2 && table.BackColor == SetupPalette.Brand)
            {
                table.RowStyles.Clear();
                table.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
            }

            if (table.RowCount == 1 && table.ColumnCount == 3 && table.Controls.OfType<Panel>().Count() == 3)
            {
                table.RowStyles.Clear();
                table.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
                foreach (var panel in table.Controls.OfType<Panel>().ToArray())
                {
                    panel.Dock = DockStyle.Fill;
                    panel.MinimumSize = Size.Empty;
                    panel.MaximumSize = Size.Empty;
                }
            }

            if (table.RowCount == 5 && table.ColumnCount == 1 && table.Controls.OfType<Label>().Count() >= 3 && table.Controls.OfType<FlowLayoutPanel>().Any())
            {
                table.AutoSize = false;
                table.Dock = DockStyle.Fill;
                table.MinimumSize = Size.Empty;
                table.MaximumSize = Size.Empty;
                table.RowStyles.Clear();
                table.RowStyles.Add(new RowStyle(SizeType.Absolute, 30));
                table.RowStyles.Add(new RowStyle(SizeType.Absolute, 42));
                table.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
                table.RowStyles.Add(new RowStyle(SizeType.Absolute, 24));
                table.RowStyles.Add(new RowStyle(SizeType.Absolute, 36));

                foreach (var label in table.Controls.OfType<Label>().ToArray())
                {
                    label.AutoSize = false;
                    label.Dock = DockStyle.Fill;
                    label.MaximumSize = Size.Empty;
                    label.TextAlign = ContentAlignment.MiddleRight;
                    label.Margin = Padding.Empty;
                }
                foreach (var flow in table.Controls.OfType<FlowLayoutPanel>().ToArray())
                {
                    flow.AutoSize = false;
                    flow.Dock = DockStyle.Fill;
                    flow.WrapContents = false;
                    flow.MinimumSize = Size.Empty;
                    flow.MaximumSize = Size.Empty;
                    flow.Margin = Padding.Empty;
                }
                foreach (var panel in table.Controls.OfType<Panel>().ToArray())
                {
                    panel.Dock = DockStyle.Fill;
                    panel.MinimumSize = Size.Empty;
                    panel.MaximumSize = Size.Empty;
                }
            }

            if (table.RowCount == 2 && table.ColumnCount == 1 && table.Controls.OfType<TextBox>().Any())
            {
                table.AutoSize = false;
                table.Dock = DockStyle.Top;
                table.Height = 48;
                table.MinimumSize = new Size(0, 48);
                table.RowStyles.Clear();
                table.RowStyles.Add(new RowStyle(SizeType.Absolute, 17));
                table.RowStyles.Add(new RowStyle(SizeType.Absolute, 31));
            }

            if (table.RowCount != 2 || table.ColumnCount != 1) continue;
            var labels = table.Controls.OfType<Label>().ToArray();
            var title = labels.FirstOrDefault(x => string.Equals(x.Text, "سرویس‌های ویندوز سکنا", StringComparison.Ordinal));
            var subtitle = labels.FirstOrDefault(x => x.Text.Contains("Runtime، Print و Maintenance", StringComparison.Ordinal));
            if (title is null || subtitle is null) continue;

            table.AutoSize = false;
            table.Dock = DockStyle.Fill;
            table.MinimumSize = Size.Empty;
            table.MaximumSize = Size.Empty;
            table.RowStyles.Clear();
            table.RowStyles.Add(new RowStyle(SizeType.Absolute, 30));
            table.RowStyles.Add(new RowStyle(SizeType.Absolute, 20));

            title.AutoSize = false;
            title.Dock = DockStyle.Fill;
            title.TextAlign = ContentAlignment.BottomRight;
            title.Margin = Padding.Empty;

            subtitle.Text = "نصب، وضعیت و عیب‌یابی سه سرویس اصلی سکنا";
            subtitle.AutoSize = false;
            subtitle.Dock = DockStyle.Fill;
            subtitle.TextAlign = ContentAlignment.TopRight;
            subtitle.Margin = Padding.Empty;
            subtitle.Visible = true;
            subtitle.BringToFront();
        }
    }

    private static void AttachDynamicNormalization(Control root)
    {
        root.ControlAdded += (_, eventArgs) =>
        {
            if (eventArgs.Control is not Control added) return;
            if (added is Label label)
            {
                NormalizeLabel(label);
                label.TextChanged += (_, _) => NormalizeLabel(label);
            }
            AttachDynamicNormalization(added);
        };
        var children = root.Controls.Cast<Control>().ToArray();
        foreach (var child in children)
            AttachDynamicNormalization(child);
    }

    private static void NormalizeLabel(Label label)
    {
        var text = label.Text;
        var normalized = text
            .Replace("componentها", "اجزا", StringComparison.Ordinal)
            .Replace("componentهای", "اجزای", StringComparison.Ordinal)
            .Replace("هر component", "هر جزء", StringComparison.Ordinal)
            .Replace("سلامت سرویس‌ها", "وضعیت سرویس‌ها", StringComparison.Ordinal)
            .Replace("سلامت", "وضعیت سرویس", StringComparison.Ordinal);

        // Package identity is owned by the canonical component manifest and must never
        // be rewritten from executable metadata or another process-level version source.

        if (string.Equals(normalized, "فعال", StringComparison.Ordinal))
            normalized = "در حال اجرا";

        var parts = normalized.Split(' ', StringSplitOptions.RemoveEmptyEntries);
        if (parts.Length == 4 && parts[1] == "از" && parts[3] == "آماده" && int.TryParse(parts[0], out _) && int.TryParse(parts[2], out _))
            normalized = $"{parts[0]} از {parts[2]} در حال اجرا";

        if (!string.Equals(text, normalized, StringComparison.Ordinal))
            label.Text = normalized;
    }

    private static IEnumerable<T> Descendants<T>(Control root) where T : Control
    {
        var children = root.Controls.Cast<Control>().ToArray();
        foreach (var child in children)
        {
            if (child is T typed) yield return typed;
            foreach (var nested in Descendants<T>(child)) yield return nested;
        }
    }
}
