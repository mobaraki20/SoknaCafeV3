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

        foreach (var button in Descendants<Button>(form))
        {
            if (string.Equals(button.Text, "بسته عیب‌یابی", StringComparison.Ordinal))
                button.Text = "عیب‌یابی";
        }

        foreach (var table in Descendants<TableLayoutPanel>(form))
        {
            if (table.RowCount != 2 || table.ColumnCount != 1) continue;
            var labels = table.Controls.OfType<Label>().ToArray();
            var title = labels.FirstOrDefault(x => string.Equals(x.Text, "سرویس‌های ویندوز سکنا", StringComparison.Ordinal));
            var subtitle = labels.FirstOrDefault(x => x.Text.Contains("Runtime، Print و Maintenance", StringComparison.Ordinal));
            if (title is null || subtitle is null) continue;

            table.AutoSize = false;
            table.Dock = DockStyle.Fill;
            table.RowStyles.Clear();
            table.RowStyles.Add(new RowStyle(SizeType.Absolute, 30));
            table.RowStyles.Add(new RowStyle(SizeType.Absolute, 20));

            title.AutoSize = false;
            title.Dock = DockStyle.Fill;
            title.TextAlign = ContentAlignment.BottomRight;
            title.Margin = Padding.Empty;

            subtitle.AutoSize = false;
            subtitle.Dock = DockStyle.Fill;
            subtitle.TextAlign = ContentAlignment.TopRight;
            subtitle.Margin = Padding.Empty;
            subtitle.Visible = true;
            subtitle.BringToFront();
            break;
        }
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
