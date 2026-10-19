using System.Text.Json;

namespace Sokna.SetupUi;

internal static class DashboardPackageVersionBinder
{
    private const string ExpectedManifestFileName = "windows-services-component-manifest-v2.json";

    internal static string ReadCanonicalPackageVersion(string shellRoot)
    {
        if (!string.Equals(ComponentUpdatePlanner.ManifestFileName, ExpectedManifestFileName, StringComparison.Ordinal))
            return "unknown";
        var manifestPath = Path.Combine(Path.GetFullPath(shellRoot), ExpectedManifestFileName);
        if (!File.Exists(manifestPath)) return "unknown";
        try
        {
            using var doc = JsonDocument.Parse(File.ReadAllText(manifestPath));
            var root = doc.RootElement;
            if (root.GetProperty("format").GetString() != "sokna-windows-component-manifest-v2") return "unknown";
            var version = root.GetProperty("package_version").GetString();
            return string.IsNullOrWhiteSpace(version) ? "unknown" : version.Trim();
        }
        catch
        {
            return "unknown";
        }
    }

    internal static string BadgeText(string shellRoot)
        => "Windows Services | بسته " + ReadCanonicalPackageVersion(shellRoot);

    internal static bool Apply(Control root, string packageVersion)
    {
        var labels = Descendants(root).OfType<Label>().ToArray();
        var changed = false;
        foreach (var label in labels)
        {
            if (!label.Text.StartsWith("Windows Services ", StringComparison.Ordinal)) continue;
            var target = "Windows Services | بسته " + packageVersion;
            if (!string.Equals(label.Text, target, StringComparison.Ordinal)) label.Text = target;
            changed = true;
        }
        return changed;
    }

    private static IEnumerable<Control> Descendants(Control root)
    {
        var children = root.Controls.Cast<Control>().ToArray();
        foreach (var child in children)
        {
            yield return child;
            foreach (var nested in Descendants(child)) yield return nested;
        }
    }
}
