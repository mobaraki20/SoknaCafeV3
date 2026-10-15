namespace Sokna.SetupUi;

internal static class DashboardUiPolish
{
    internal static void Apply(WindowsServicesDashboardV3 form)
    {
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

            table.RowStyles.Clear();
            table.RowStyles.Add(new RowStyle(SizeType.Percent, 58));
            table.RowStyles.Add(new RowStyle(SizeType.Percent, 42));
            title.AutoSize = false;
            title.Dock = DockStyle.Fill;
            title.TextAlign = ContentAlignment.MiddleRight;
            title.Margin = Padding.Empty;
            subtitle.AutoSize = false;
            subtitle.Dock = DockStyle.Fill;
            subtitle.TextAlign = ContentAlignment.MiddleRight;
            subtitle.Margin = Padding.Empty;
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
