using System.IO.Compression;
using System.Text.Json;
using Sokna.Maintenance.Core;

static void Need(bool value, string message)
{
    if (!value) throw new Exception(message);
}
static void Write(string file, string value)
{
    Directory.CreateDirectory(Path.GetDirectoryName(file)!);
    File.WriteAllText(file, value);
}
static void Inventory(string root, params string[] names) =>
    Write(Path.Combine(root, "resources", "local-managed-files-v1.json"),
        JsonSerializer.Serialize(new { format = "sokna-local-managed-files-v1", schema_version = 1, files = names }));
static void MustFail(Action action, string code)
{
    try { action(); }
    catch (InvalidDataException) { return; }
    throw new Exception("unsafe delta/rebind accepted: " + code);
}
string[] Current = {
    "VERSION.txt", "resources/local-managed-files-v1.json",
    "public/keep.php", "public/changed.php", "public/deleted.php"
};
string[] Target = {
    "VERSION.txt", "resources/local-managed-files-v1.json",
    "public/keep.php", "public/changed.php", "public/new.php"
};

var root = Path.Combine(Path.GetTempPath(), "sokna-delta-rebind-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(root);
try
{
    const string oldVersion = "3.0.0.231bz";
    const string newVersion = "3.0.0.231cb";
    var physical = Path.Combine(root, "physical");
    var target = Path.Combine(root, "expected");
    var candidate = Path.Combine(root, "candidate");
    Directory.CreateDirectory(physical);
    Directory.CreateDirectory(target);
    Write(Path.Combine(physical, "VERSION.txt"), oldVersion);
    Write(Path.Combine(physical, "public/keep.php"), "unchanged");
    Write(Path.Combine(physical, "public/changed.php"), "old");
    Write(Path.Combine(physical, "public/deleted.php"), "to-delete");
    Inventory(physical, Current);
    // Actual R8 Web releases also have machine-local (unmanaged) files.
    Write(Path.Combine(physical, ".sokna-installation.json"), "protected-binding");
    Write(Path.Combine(physical, "public/local-recovery.php"), "protected-recovery");
    Write(Path.Combine(physical, "public/local-update-runner.php"), "protected-runner");
    Write(Path.Combine(target, "VERSION.txt"), newVersion);
    Write(Path.Combine(target, "public/keep.php"), "unchanged");
    Write(Path.Combine(target, "public/changed.php"), "new");
    Write(Path.Combine(target, "public/new.php"), "new-added");
    Inventory(target, Target);
    var baseHash = LocalManagedTreeAttestation.Compute(physical, Current.Length);
    var targetHash = LocalManagedTreeAttestation.Compute(target, Target.Length);

    var delta = Path.Combine(root, "delta.zip");
    void BuildDelta(string dest, string baseVersion, bool injectForeign = false)
    {
        if (File.Exists(dest)) File.Delete(dest);
        using var zip = ZipFile.Open(dest, ZipArchiveMode.Create);
        var changed = new [] { "VERSION.txt", "resources/local-managed-files-v1.json", "public/changed.php", "public/new.php" };
        var manifest = new {
            format = "sokna-local-web-delta-v1", schema_version = 1,
            base_version = baseVersion, base_managed_tree_sha256 = baseHash,
            target_version = newVersion, target_managed_tree_sha256 = targetHash,
            changed_paths = changed, deleted_paths = new [] { "public/deleted.php" }
        };
        var metadata = zip.CreateEntry("delta-manifest.json");
        using (var writer = new StreamWriter(metadata.Open()))
            writer.Write(JsonSerializer.Serialize(manifest));
        foreach (var relative in changed)
        {
            var entry = zip.CreateEntry(relative);
            using var writer = entry.Open();
            using var input = File.OpenRead(Path.Combine(target, relative.Replace('/', Path.DirectorySeparatorChar)));
            input.CopyTo(writer);
        }
        if (injectForeign)
        {
            var unexpected = zip.CreateEntry("public/foreign.php");
            using var writer = new StreamWriter(unexpected.Open());
            writer.Write("unlisted");
        }
    }
    BuildDelta(delta, oldVersion);
    Directory.CreateDirectory(candidate);
    LocalDeltaCandidateMaterializer.Materialize(delta, candidate,
        physical, oldVersion, baseHash, newVersion, targetHash, Target.Length);
    Need(LocalManagedTreeAttestation.Compute(candidate, Target.Length) == targetHash,
        "delta rebuilt wrong target managed tree");
    Need(!File.Exists(Path.Combine(candidate, "public/deleted.php")) &&
        File.ReadAllText(Path.Combine(candidate, "public/keep.php")) == "unchanged",
        "delta removal or unchanged file lost");
    Need(LocalManagedTreeAttestation.Compute(physical, Current.Length) == baseHash,
        "delta modified active baseline");
    Directory.CreateDirectory(Path.Combine(root, "bad-base"));
    MustFail(() => LocalDeltaCandidateMaterializer.Materialize(
        delta, Path.Combine(root, "bad-base"), physical, "wrong-old-version",
        baseHash, newVersion, targetHash, Target.Length), "incorrect baseline");
    BuildDelta(Path.Combine(root, "foreign.zip"), oldVersion, injectForeign: true);
    Directory.CreateDirectory(Path.Combine(root, "bad-extra"));
    MustFail(() => LocalDeltaCandidateMaterializer.Materialize(
        Path.Combine(root, "foreign.zip"), Path.Combine(root, "bad-extra"),
        physical, oldVersion, baseHash, newVersion, targetHash, Target.Length), "unexpected entry");
    BuildDelta(Path.Combine(root, "wrong-base.zip"), "wrong-build");
    Directory.CreateDirectory(Path.Combine(root, "wrong-base"));
    MustFail(() => LocalDeltaCandidateMaterializer.Materialize(
        Path.Combine(root, "wrong-base.zip"), Path.Combine(root, "wrong-base"),
        physical, oldVersion, baseHash, newVersion, targetHash, Target.Length), "manifest baseline");

    // Simulate the exact machine-local marker left stale by an external Web copy.
    var package = Path.Combine(root, "package");
    var persistent = Path.Combine(package, "LocalWeb", "persistent");
    var active = Path.Combine(package, "LocalWeb", "releases", "active");
    const string id = "fixture-sokna-install";
    Write(Path.Combine(package, "install.lock"),
        JsonSerializer.Serialize(new { format = "sokna-install-lock-v3", installation_id = id }));
    var binding = JsonSerializer.Serialize(new {
        format = "sokna-local-installation-binding-v1", installation_id = id, web_version = oldVersion
    });
    Write(Path.Combine(persistent, ".sokna-installation.json"), binding);
    Write(Path.Combine(active, ".sokna-installation.json"), binding);
    var persistentBefore = File.ReadAllBytes(Path.Combine(persistent, ".sokna-installation.json"));
    RoutineLocalBinding.AdoptVerifiedExternalBuild(persistent, active, package, oldVersion, newVersion);
    RoutineLocalBinding.RequireCurrent(active, package, newVersion);
    var adopted = File.ReadAllBytes(Path.Combine(active, ".sokna-installation.json"));
    RoutineLocalBinding.AdoptVerifiedExternalBuild(persistent, active, package, oldVersion, newVersion);
    Need(adopted.SequenceEqual(File.ReadAllBytes(Path.Combine(active, ".sokna-installation.json"))),
        "repeated binding adoption not an exact no-op");
    Need(persistentBefore.SequenceEqual(File.ReadAllBytes(Path.Combine(persistent, ".sokna-installation.json"))),
        "persistent binding was mutated");
    MustFail(() => RoutineLocalBinding.AdoptVerifiedExternalBuild(
        persistent, active, package, "other-old", "other-target"), "foreign active build");
    Console.WriteLine("PASS Delta private reconstruction/added/removed/unchanged/negative cases and idempotent verified binding repair");
}
finally { try { Directory.Delete(root, recursive: true); } catch {} }
