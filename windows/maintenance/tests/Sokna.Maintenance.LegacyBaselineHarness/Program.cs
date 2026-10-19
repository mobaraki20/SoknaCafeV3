using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

var root = Path.Combine(Path.GetTempPath(), "sokna-legacy-H300-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(Path.Combine(root, "resources"));
try
{
    const string build = "fixture-local-v1";
    File.WriteAllText(Path.Combine(root, "VERSION.txt"), build + "\n", Encoding.UTF8);
    File.WriteAllText(Path.Combine(root, "README.md"), "synthetic-not-production\n", Encoding.UTF8);
    var entries = new[] { "README.md", "VERSION.txt", "resources/H300_MANAGED_FILES.json" };
    var inventory = JsonSerializer.SerializeToUtf8Bytes(new { files = entries });
    var inventoryFile = Path.Combine(root, "resources", "H300_MANAGED_FILES.json");
    File.WriteAllBytes(inventoryFile, inventory);
    var invSha = Hex(SHA256.HashData(inventory));
    var tree = Tree(root, entries);

    var a = LegacyLocalBaselineAttestation.RequireFixture(root, build, 3, invSha, tree);
    Need(a.ExactBaselineVerified && a.ManagedFiles == 3 &&
         a.ManagedTreeSha256 == tree &&
         a.LegacyToSlotTransitionRequired &&
         !a.SignedStageVerified && !a.TargetBootVerified &&
         !a.ActivationAllowed && !a.PanelUpdateReady, "positive H300 baseline admitted as install-ready");

    NeedDenied(() => LegacyLocalBaselineAttestation.RequireExactPhysicalWeb(root),
        "LEGACY_BASELINE_BUILD_NOT_QUALIFIED", "unknown fixture was trusted as official 231ag");

    File.AppendAllText(Path.Combine(root, "README.md"), "changed");
    NeedDenied(() => LegacyLocalBaselineAttestation.RequireFixture(root, build, 3, invSha, tree),
        "LEGACY_MANAGED_TREE_MISMATCH", "tampered managed file verified");
    File.WriteAllText(Path.Combine(root, "README.md"), "synthetic-not-production\n", Encoding.UTF8);

    File.AppendAllText(inventoryFile, " ");
    NeedDenied(() => LegacyLocalBaselineAttestation.RequireFixture(root, build, 3, invSha, tree),
        "LEGACY_INVENTORY_FINGERPRINT_MISMATCH", "tampered H300 inventory verified");
    File.WriteAllBytes(inventoryFile, inventory);

    NeedDenied(() => LegacyLocalBaselineAttestation.RequireFixture(root, build, 4, invSha, tree),
        "LEGACY_INVENTORY_COUNT_MISMATCH", "mismatched managed file count verified");

    NeedDenied(() => LegacyLocalBaselineAttestation.RequireFixture(root, "different-build", 3, invSha, tree),
        "LEGACY_BASELINE_BUILD_NOT_QUALIFIED", "wrong build identity verified");

    Console.WriteLine("LEGACY_SIGNED_UPDATER_READONLY_BASELINE_PASS 6/6; no host or service mutation; activate=false");
}
finally
{
    try { Directory.Delete(root, recursive:true); } catch { }
}

static string Hex(byte[] bytes) => Convert.ToHexString(bytes).ToLowerInvariant();
static string Tree(string root, IEnumerable<string> entries)
{
    using var hash = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
    foreach (var rel in entries.OrderBy(x => x, StringComparer.Ordinal))
    {
        var bytes = File.ReadAllBytes(Path.Combine(root, rel.Replace('/', Path.DirectorySeparatorChar)));
        var line = rel + "\0" + Hex(SHA256.HashData(bytes)) + "\n";
        hash.AppendData(Encoding.UTF8.GetBytes(line));
    }
    return Hex(hash.GetHashAndReset());
}
static void Need(bool condition,string message)
{
    if (!condition) throw new InvalidOperationException(message);
}
static void NeedDenied(Action work,string code,string message)
{
    try { work(); }
    catch (InvalidDataException ex) when (ex.Message.StartsWith(code,StringComparison.Ordinal)) { return; }
    throw new InvalidOperationException(message);
}
