using System.Security.Cryptography;
using Sokna.Maintenance.Core;

// Native Windows test: exact-match legacy fixture (synthetic bytes), backup
// and readback, idempotence, interrupted pair, foreign hash and baseline gate.
static void Need(bool ok, string why) { if (!ok) throw new Exception(why); }
static string Hash(byte[] v) => Convert.ToHexString(SHA256.HashData(v)).ToLowerInvariant();
static string HashFile(string p) => Hash(File.ReadAllBytes(p));
var temp = Path.Combine(Path.GetTempPath(), "SOKNA-CAFE-OVERLAY-UAT-"+Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(temp);
try {
    var localWeb = Path.Combine(temp, "LocalWeb");
    var active = Path.Combine(temp, "Web.release.fixture", "public");
    var persistent = Path.Combine(localWeb, "persistent", "public");
    Directory.CreateDirectory(active); Directory.CreateDirectory(persistent);
    var files = new[] { Path.Combine(persistent, "local-recovery.php"),
                        Path.Combine(active, "local-recovery.php") };
    var old = new byte[6691]; RandomNumberGenerator.Fill(old);
    var approved = Hash(old);
    using var res = typeof(StableLocalRecoveryOverlayRepair).Assembly
        .GetManifestResourceStream("Sokna.Maintenance.Core.StableLocalRecoveryPhp")!;
    using var mem = new MemoryStream(); res.CopyTo(mem);
    var trusted = mem.ToArray();
    Need(trusted.Length == 7303, "unexpected current trusted source size");
    Need(Hash(trusted) == "fd36bf0170a357aa3d0a283e9989fa0b442607f238bb70b659c2def8f5791598",
        "trusted embedded PHP identity drift");

    foreach (var f in files) File.WriteAllBytes(f, old);
    Need(!KnownCafeRecoveryOverlayMigration.TryMigrateCore(temp, "wrong baseline",
        KnownCafeRecoveryOverlayMigration.BaselineTree, files, trusted, approved, old.Length),
        "wrong baseline not blocked");
    Need(files.All(f => HashFile(f) == approved), "wrong-baseline changed a file");

    Need(KnownCafeRecoveryOverlayMigration.TryMigrateCore(temp,
        KnownCafeRecoveryOverlayMigration.BaselineVersion,
        KnownCafeRecoveryOverlayMigration.BaselineTree, files, trusted, approved, old.Length),
        "exact synthetic pair not migrated");
    Need(files.All(f => HashFile(f) == Hash(trusted)), "new overlay readback mismatch");
    var backup = Path.Combine(localWeb, "recovery-overlay-migration-"+approved[..16]);
    Need(File.Exists(Path.Combine(backup, "persistent.before.bin")) &&
         File.Exists(Path.Combine(backup, "active.before.bin")), "durable backups missing");
    Need(HashFile(Path.Combine(backup, "persistent.before.bin")) == approved &&
         HashFile(Path.Combine(backup, "active.before.bin")) == approved,
        "backup content changed");
    Need(File.Exists(Path.Combine(backup, "migration-receipt.json")), "receipt missing");

    Need(!KnownCafeRecoveryOverlayMigration.TryMigrateCore(temp,
        KnownCafeRecoveryOverlayMigration.BaselineVersion,
        KnownCafeRecoveryOverlayMigration.BaselineTree, files, trusted, approved, old.Length),
        "idempotent repeat should not rewrite");

    // Model a crash after only the first atomic replacement. Verified old
    // backups must let the second file safely complete on resume.
    File.WriteAllBytes(files[1], old);
    Need(KnownCafeRecoveryOverlayMigration.TryMigrateCore(temp,
        KnownCafeRecoveryOverlayMigration.BaselineVersion,
        KnownCafeRecoveryOverlayMigration.BaselineTree, files, trusted, approved, old.Length),
        "interrupted pair did not resume");
    Need(files.All(f => HashFile(f) == Hash(trusted)), "resume readback mismatch");

    File.WriteAllBytes(files[0], old);
    File.WriteAllText(files[1], "foreign-content");
    Need(!KnownCafeRecoveryOverlayMigration.TryMigrateCore(temp,
        KnownCafeRecoveryOverlayMigration.BaselineVersion,
        KnownCafeRecoveryOverlayMigration.BaselineTree, files, trusted, approved, old.Length),
        "foreign file accepted");
    Need(HashFile(files[0]) == approved && File.ReadAllText(files[1]) == "foreign-content",
        "foreign pair was mutated");
    foreach(var scenario in new[]{"persistent-only","active-only","both-legacy","mixed-trusted","foreign"})
    {
        var lab=Path.Combine(temp,"verified-"+scenario);var per=Path.Combine(lab,"LocalWeb","persistent","public");var act=Path.Combine(lab,"Web.release.new","public");Directory.CreateDirectory(per);Directory.CreateDirectory(act);
        var pair=new[]{Path.Combine(per,"local-recovery.php"),Path.Combine(act,"local-recovery.php")};
        if(scenario!="active-only")File.WriteAllBytes(pair[0],old);
        if(scenario is "active-only" or "both-legacy")File.WriteAllBytes(pair[1],old);
        if(scenario=="mixed-trusted")File.WriteAllBytes(pair[1],trusted);
        if(scenario=="foreign")File.WriteAllText(pair[1],"never-overwrite-foreign");
        bool denied=false;bool migrated=false;
        try{migrated=KnownCafeRecoveryOverlayMigration.TryMigrateVerifiedCurrentCore(lab,"3.0.0-verified-current",new string('a',64),pair,trusted,approved,old.Length);}catch(InvalidDataException){denied=true;}
        if(scenario=="foreign") {Need(denied&&HashFile(pair[0])==approved&&File.ReadAllText(pair[1])=="never-overwrite-foreign","verified-current foreign pair was changed");continue;}
        Need(!denied&&migrated,"verified-current known legacy did not migrate "+scenario);
        foreach(var file in pair.Where(File.Exists))Need(HashFile(file)==Hash(trusted),"verified-current trusted readback failed");
        if(scenario=="persistent-only")Need(!File.Exists(pair[1]),"legacy migration fabricated a missing file instead of using owner provisioning");
        if(scenario=="active-only")Need(!File.Exists(pair[0]),"legacy migration fabricated a missing file instead of using owner provisioning");
        Need(!KnownCafeRecoveryOverlayMigration.TryMigrateVerifiedCurrentCore(lab,"3.0.0-verified-current",new string('a',64),pair,trusted,approved,old.Length),"verified-current repeated migration rewrote bytes");
    }
    Console.WriteLine("PASS: exact pair migration; backup/readback; idempotence; safe partial resume; foreign/wrong baseline fail-closed");
}
finally { try { Directory.Delete(temp, recursive:true); } catch {} }
