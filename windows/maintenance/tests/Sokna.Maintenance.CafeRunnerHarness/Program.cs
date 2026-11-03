using System.Security.Cryptography;
using Sokna.Maintenance.Core;

static string Hash(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
static string HashFile(string p) => Hash(File.ReadAllBytes(p));
static void Need(bool okay,string why) { if(!okay)throw new Exception(why); }
static void Reject(Action action,string why)
{
    try { action(); }
    catch(InvalidDataException) {return;}
    throw new Exception(why);
}

var root=Path.Combine(Path.GetTempPath(),"SOKNA-CAFE-RUNNER-"+Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(root);
try
{
    var persistent=Path.Combine(root,"LocalWeb","persistent","public");
    var active=Path.Combine(root,"Web.release.fixture","public");
    Directory.CreateDirectory(persistent);
    Directory.CreateDirectory(active);
    var source=Path.Combine(persistent,"local-update-runner.php");
    var target=Path.Combine(active,"local-update-runner.php");
    var bytes=new byte[2048];
    RandomNumberGenerator.Fill(bytes);
    var expected=Hash(bytes);
    File.WriteAllBytes(source,bytes);
    Reject(() => KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,new string('0',64)),"untrusted persistent hash accepted");
    Need(!File.Exists(target),"bad hash created active PHP");

    var restored=KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,expected);
    Need(restored=="restored_known_cafe_missing_active_runner",
        "pinned source + missing active was not repaired");
    Need(HashFile(source)==expected&&HashFile(target)==expected,
        "copy changed source or active digest mismatch");
    Need(KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,expected)=="already_verified_no_mutation",
        "same already-restored source not idempotent");

    File.WriteAllText(target,"foreign_php_content");
    Reject(() => KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,expected),"foreign active PHP was overwritten");
    Need(File.ReadAllText(target)=="foreign_php_content",
        "rejected mutation changed foreign active bytes");

    File.Delete(target);
    File.WriteAllText(source,"modified_persistent_source");
    Reject(() => KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,expected),"tampered persistent PHP copied");
    Need(!File.Exists(target),"tampered source caused PHP creation");

    File.Delete(source);
    Reject(() => KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,expected),"missing persistent source accepted");
    Need(!File.Exists(target),"missing source caused PHP creation");

    File.WriteAllBytes(source,bytes);
    Reject(() => KnownCafeUpdateRunnerOverlayRepair.RestoreExactMissingPair(
        source,target,"not-a-sha256"),"invalid digest accepted");
    Need(!File.Exists(target),"invalid digest caused PHP creation");

    Console.WriteLine("PASS: exact source-only hash, atomic missing-active restore, readback and repeat; foreign, tampered, absent source rejected without mutation");
}
finally { try{Directory.Delete(root,true);}catch{} }
