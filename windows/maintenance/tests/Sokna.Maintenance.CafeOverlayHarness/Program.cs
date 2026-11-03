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
    // Cafe 2026-11-03 diagnostic: persistent has the exact approved legacy
    // overlay, active Web counterpart is absent. Synthetic old bytes allow the
    // actual transition and durable evidence to be exercised without bundling
    // the historical 6691-byte PHP in the test.
    static string[] CasePaths(string root) {
        var p = Path.Combine(root, "LocalWeb", "persistent", "public", "local-recovery.php");
        var a = Path.Combine(root, "Web.release.fixture", "public", "local-recovery.php");
        Directory.CreateDirectory(Path.GetDirectoryName(p)!);
        Directory.CreateDirectory(Path.GetDirectoryName(a)!);
        return new[] { p, a };
    }
    var bz = KnownCafeRecoveryOverlayMigration.Cafe231BzVersion;
    var bzTree = KnownCafeRecoveryOverlayMigration.Cafe231BzTree;
    Need(KnownCafeRecoveryOverlayMigration.Qualifies(bz, bzTree), "current Cafe 231bz must be exact-qualified");
    Need(!KnownCafeRecoveryOverlayMigration.Qualifies(bz, new string('0',64)),
        "version alone qualified untrusted tree");
    Need(!KnownCafeRecoveryOverlayMigration.Qualifies("3.0.0.231bc", bzTree),
        "unknown version accepted on known tree");

    foreach (var existingLegacyIndex in new[] { 0, 1 }) {
        var dir = Path.Combine(temp, "one-missing-"+existingLegacyIndex);
        var pair = CasePaths(dir);
        var absentIndex = 1-existingLegacyIndex;
        File.WriteAllBytes(pair[existingLegacyIndex], old);
        Need(!KnownCafeRecoveryOverlayMigration.TryMigrateMissingPartnerCore(dir,bz,
            new string('0',64), pair,trusted,approved,old.Length),
            "mismatched managed tree was accepted");
        Need(!File.Exists(pair[absentIndex]) && HashFile(pair[existingLegacyIndex])==approved,
            "wrong baseline modified the legacy file");

        Need(KnownCafeRecoveryOverlayMigration.TryMigrateMissingPartnerCore(dir,bz,
            bzTree,pair,trusted,approved,old.Length),
            "exact one-old/one-missing Cafe overlay was not migrated");
        Need(pair.All(f=>File.Exists(f)&&HashFile(f)==Hash(trusted)),
            "one-missing migration did not restore both trusted overlays");
        var evidence=Path.Combine(dir,"LocalWeb","recovery-overlay-migration-missing-"+
            approved[..16]+"-"+bzTree[..12]);
        var labels=new[] { "persistent", "active" };
        Need(HashFile(Path.Combine(evidence,labels[existingLegacyIndex]+".before.bin"))==approved,
            "original legacy bytes were not preserved in off-webroot backup");
        Need(File.Exists(Path.Combine(evidence,labels[absentIndex]+".before.absent")),
            "missing counterpart was not recorded as originally absent");
        Need(File.Exists(Path.Combine(evidence,"missing-partner-migration-receipt.json")),
            "missing-partner migration receipt was not durably persisted");
        Need(!KnownCafeRecoveryOverlayMigration.TryMigrateMissingPartnerCore(dir,bz,
            bzTree,pair,trusted,approved,old.Length),
            "successful migration was not idempotent");
    }

    var badDir=Path.Combine(temp,"one-missing-foreign");
    var bad=CasePaths(badDir);
    File.WriteAllText(bad[0],"foreign PHP should remain untouched");
    Need(!KnownCafeRecoveryOverlayMigration.TryMigrateMissingPartnerCore(badDir,bz,
        bzTree,bad,trusted,approved,old.Length),"foreign overlay accepted");
    Need(File.ReadAllText(bad[0])=="foreign PHP should remain untouched" &&
        !File.Exists(bad[1]) && !Directory.Exists(Path.Combine(badDir,
        "LocalWeb","recovery-overlay-migration-missing-"+approved[..16]+"-"+bzTree[..12])),
        "foreign or unsupported pair mutated or created evidence");
    var bothMissingDir=Path.Combine(temp,"one-missing-empty");
    var emptyPair=CasePaths(bothMissingDir);
    Need(!KnownCafeRecoveryOverlayMigration.TryMigrateMissingPartnerCore(bothMissingDir,bz,
        bzTree,emptyPair,trusted,approved,old.Length),"both-missing incorrectly migrated");
    var conflictDir=Path.Combine(temp,"one-missing-conflicting-proof");
    var conflict=CasePaths(conflictDir);
    File.WriteAllBytes(conflict[0],old);
    var conflictRoot=Path.Combine(conflictDir,"LocalWeb",
        "recovery-overlay-migration-missing-"+approved[..16]+"-"+bzTree[..12]);
    Directory.CreateDirectory(conflictRoot);
    File.WriteAllText(Path.Combine(conflictRoot,"active.before.absent"),"tampered proof");
    var blocked=false;
    try { KnownCafeRecoveryOverlayMigration.TryMigrateMissingPartnerCore(conflictDir,bz,
        bzTree,conflict,trusted,approved,old.Length); }
    catch (InvalidDataException ex) when (ex.Message=="STABLE_RECOVERY_MIGRATION_ABSENCE_PROOF_CONFLICT") {
        blocked=true;
    }
    Need(blocked && HashFile(conflict[0])==approved && !File.Exists(conflict[1]),
        "tampered absence marker did not fail closed");
    Console.WriteLine("PASS: Cafe 231bz known-tree one-missing+legacy; both orientations; durable old/absent evidence; idempotent; foreign/wrong-tree/tamper fail-closed");
    Console.WriteLine("PASS: exact pair migration; backup/readback; idempotence; safe partial resume; foreign/wrong baseline fail-closed");
}
finally { try { Directory.Delete(temp, recursive:true); } catch {} }
