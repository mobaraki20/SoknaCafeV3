using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("CI_DISPOSABLE_WINDOWS_ONLY");
if (args.Length != 2) throw new ArgumentException("Expected R75 and final R140 ZIP paths");
static void Need(bool condition, string detail)
{
    if (!condition) throw new InvalidDataException("R140_FINAL_STAGE_" + detail);
    Console.WriteLine("PASS " + detail);
}
static JsonElement JsonFromZip(string path)
{
    using var z = ZipFile.OpenRead(path);
    using var s = z.GetEntry("release-set.json")?.Open() ?? throw new InvalidDataException("MANIFEST_MISSING");
    return JsonDocument.Parse(s).RootElement.Clone();
}
static string Hash(byte[] input) => Convert.ToHexString(SHA256.HashData(input)).ToLowerInvariant();
var old=JsonFromZip(args[0]).GetProperty("targets").GetProperty("local");
var target=JsonFromZip(args[1]);
Need(target.GetProperty("release_type").GetString()=="local_only", "LOCAL_ONLY_SCOPE");
Need(target.GetProperty("for_install").GetBoolean(),"FOR_INSTALL_TRUE");
Need(target.GetProperty("production_pass").GetBoolean(),"RELEASE_QUALITY_GATE_SET");
var q=target.GetProperty("qualification");
Need(q.GetProperty("failure_matrix_cases_required").GetInt32()>=308,"REAL_308_CASE_QUALITY_MATRIX");
Need(q.GetProperty("canary").GetBoolean() && q.GetProperty("real_windows").GetBoolean() &&
     q.GetProperty("real_mariadb").GetBoolean() && q.GetProperty("recovery_console_web_down").GetBoolean() &&
     q.GetProperty("business_backup_consistency_real_mariadb").GetBoolean(),"SCOPED_LOCAL_SAFETY_GATES");
var identities=target.GetProperty("supported_baselines").EnumerateArray().ToArray();
Need(identities.Length==42,"42_MULTIBASE_IDENTITIES");
var matching=identities.Where(v=>v.GetProperty("local").GetProperty("version").GetString()==old.GetProperty("version").GetString() &&
    v.GetProperty("local").GetProperty("managed_tree_sha256").GetString()==old.GetProperty("managed_tree_sha256").GetString() &&
    v.GetProperty("local").GetProperty("schema_fingerprint").GetString()==old.GetProperty("schema_fingerprint").GetString()).ToArray();
Need(matching.Length==1,"HOME_R75_UNIQUE_BASELINE");
var installed=new ReleaseComponentIdentity(
    old.GetProperty("release_id").GetString()!,old.GetProperty("version").GetString()!,
    old.GetProperty("managed_tree_sha256").GetString()!,old.GetProperty("schema_fingerprint").GetString()!,
    old.GetProperty("migration_head").GetString()!,old.GetProperty("authority_epoch_policy").GetString()!);
var identity=new InstalledReleaseIdentity(installed,null,"0.3.22","0.3.22",null,null);
var root=Path.Combine(Path.GetTempPath(),"sokna-r140-qualified-stage-"+Guid.NewGuid().ToString("N"));
try {
    var paths=new MaintenancePaths(root);
    Directory.CreateDirectory(paths.InboxRoot);
    var zip=File.ReadAllBytes(args[1]);
    var path=Path.Combine(paths.InboxRoot,Hash(zip)+".zip");
    File.WriteAllBytes(path,zip);
    var verifier=new ReleaseSetVerifierStager(paths);
    var verified=verifier.VerifyOnly(path,identity);
    Need(verified.ArtifactIntegrityVerified && verified.ExactBaselineVerified==true && verified.OperatorAuthorized,
        "EXACT_FINAL_PACKAGE_OPERATOR_AUTHENTICATED_PREFLIGHT");
    var staged=verifier.VerifyAndStage(path,identity);
    Need(staged.ArtifactIntegrityVerified && staged.OperatorAuthorized &&
         File.Exists(Path.Combine(staged.StageRoot,"stage-receipt.json")),
         "EXACT_FINAL_PACKAGE_STAGE_AND_DURABLE_RECEIPT");
    Console.WriteLine("R140_REAL_FINAL_PANEL_ZIP_MAINTENANCE_0322_STAGE_PASS");
}
finally {try{Directory.Delete(root,true);}catch{}}