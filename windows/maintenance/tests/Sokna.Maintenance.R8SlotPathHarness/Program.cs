using Sokna.Maintenance.Core;

var root = Path.Combine(Path.GetTempPath(), "sokna-r8-slot-path-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(root);
try
{
    const string build = "3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory";
    const string tree = "b0e252b62b804c00e25c925d31ded8986fa23c3ab0d58b668eb79a79b961f56a";

    var sibling = ProductionLocalSlotMigrationBridge.BuildProductionSlotPath(root, build, tree);
    Need(Path.GetFullPath(Path.GetDirectoryName(sibling)!) == Path.GetFullPath(root), "R8 release must be a direct child of package root");
    Need(Path.GetFileName(sibling).StartsWith(ProductionLocalSlotMigrationBridge.ReleaseSlotPrefix, StringComparison.OrdinalIgnoreCase), "R8 release prefix mismatch");
    Directory.CreateDirectory(sibling);
    ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(sibling, root);

    var nested = Path.Combine(root, "LocalWeb", "releases", Path.GetFileName(sibling));
    Directory.CreateDirectory(nested);
    NeedThrows(() => ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(nested, root), "legacy nested LocalWeb/releases slot was accepted");

    var arbitrary = Path.Combine(root, "Release-231ai");
    Directory.CreateDirectory(arbitrary);
    NeedThrows(() => ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(arbitrary, root), "arbitrary direct sibling release name was accepted");

    // This encodes the real Home finding: PHP resolves __DIR__ through the Web junction
    // to the physical release. Only a direct sibling keeps dirname(release)==package root.
    Need(Path.GetFullPath(Path.GetDirectoryName(sibling)!) == Path.GetFullPath(root), "PHP package-root invariant is not preserved");
    Need(Path.GetFullPath(Path.GetDirectoryName(nested)!) != Path.GetFullPath(root), "nested test fixture unexpectedly preserves package root");

    Console.WriteLine("R8 sibling-slot/PHP-root regression harness: PASS");
}
finally
{
    try { Directory.Delete(root, recursive: true); } catch { }
}

static void Need(bool condition, string message)
{
    if (!condition) throw new InvalidOperationException(message);
}

static void NeedThrows(Action action, string message)
{
    try
    {
        action();
    }
    catch (Exception ex) when (ex is InvalidDataException or DirectoryNotFoundException)
    {
        return;
    }
    throw new InvalidOperationException(message);
}
