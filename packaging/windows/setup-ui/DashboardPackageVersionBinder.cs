using System.Runtime.CompilerServices;
using System.Text.Json;

namespace Sokna.SetupUi;

internal static class DashboardPackageVersionBinder
{
    private static bool applied;

    [ModuleInitializer]
    internal static void Initialize()
    {
        Application.Idle += OnIdle;
    }

    internal static string ReadCanonicalPackageVersion(string shellRoot)
    {
        var manifestPath = Path.Combine(Path.GetFullPath(shellRoot), ComponentUpdatePlanner.ManifestFileName);
        if (!File.Exists(manifestPath)) return "unknown";
        try
        {
            using var doc = JsonDocument.Parse(File.ReadAllText(manifestPath));
            var root = doc.RootElement;
            if (root.GetProperty("format").GetString() != "sokna-windows-component-manifest-v2") return "unknown";
            return root.GetProperty("package_version").GetString() ?? "unknown";
        }
        catch
        {
            return "unknown";
        }
    }

    internal static bool Apply(Control root, string packageVersion)
    {
        var changed = false;
        foreach (var label in Descendants(root).OfType<Label>())
        {
            if (!label.Text.StartsWith("Windows Services ", StringComparison.Ordinal)) continue;
            label.Text = "Windows Services " + packageVersion;
            changed = true;
        }
        return changed;
    }

    private static void OnIdle(object? sender, EventArgs e)
    {
        if (applied) return;
        var version = ReadCanonicalPackageVersion(AppContext.BaseDirectory);
        foreach (Form form in Application.OpenForms)
        {
            if (!Apply(form, version)) continue;
            applied = true;
            Application.Idle -= OnIdle;
            break;
        }
    }

    private static IEnumerable<Control> Descendants(Control root)
    {
        foreach (Control child in root.Controls)
        {
            yield return child;
            foreach (var nested in Descendants(child)) yield return nested;
        }
    }
}
