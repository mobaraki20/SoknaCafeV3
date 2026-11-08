using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("DISPOSABLE_WINDOWS_QA_ONLY");
if (args.Length != 3)
    throw new ArgumentException("usage: <R140 qualified ZIP> <R149B candidate ZIP> <R149B qualified ZIP>");

static void Need(bool condition,string detail)
{
    if(!condition) throw new InvalidDataException("R149B_WINDOWS_STAGE_"+detail);
    Console.WriteLine("PASS "+detail);
}
static byte[] Entry(ZipArchive zip,string name)
{
    using var s=zip.GetEntry(name)?.Open()??throw new InvalidDataException("Missing ZIP entry "+name);
    using var m=new MemoryStream();s.CopyTo(m);return m.ToArray();
}
static string Sha(byte[] data) => Convert.ToHexString(SHA256.HashData(data)).ToLowerInvariant();
static JsonElement Manifest(string filename)
{
    using var a=ZipFile.OpenRead(filename);
    return JsonDocument.Parse(Entry(a,"release-set.json")).RootElement.Clone();
}
static string ManagedTree(byte[] nested)
{
    using var m=new MemoryStream(nested);
    using var z=new ZipArchive(m,ZipArchiveMode.Read);
    var b=new StringBuilder();
    foreach(var e in z.Entries.OrderBy(e=>e.FullName,StringComparer.Ordinal))
    {
        b.Append(e.FullName).Append('\0').Append(Sha(Entry(z,e.FullName))).Append('\n');
    }
    return Sha(Encoding.UTF8.GetBytes(b.ToString()));
}
static string MigrationFingerprint(byte[] nested)
{
    using var m=new MemoryStream(nested);
    using var z=new ZipArchive(m,ZipArchiveMode.Read);
    var b=new StringBuilder();
    foreach(var e in z.Entries.Where(e=>e.FullName.StartsWith("database/migrations/",StringComparison.Ordinal) && e.FullName.EndsWith(".sql",StringComparison.Ordinal)).OrderBy(e=>e.FullName,StringComparer.Ordinal))
    {
        b.Append(Path.GetFileNameWithoutExtension(e.FullName)).Append('\0').Append(Sha(Entry(z,e.FullName))).Append('\n');
    }
    return "migration-ledger-sha256-v1:"+Sha(Encoding.UTF8.GetBytes(b.ToString()));
}
static ReleaseComponentIdentity Identity(JsonElement local)
    => new(local.GetProperty("release_id").GetString()!,local.GetProperty("version").GetString()!,
       local.GetProperty("managed_tree_sha256").GetString()!,local.GetProperty("schema_fingerprint").GetString()!,
       local.GetProperty("migration_head").GetString()!,local.GetProperty("authority_epoch_policy").GetString()!);
static InstalledReleaseIdentity Installed(ReleaseComponentIdentity local)
    => new(local,null,"0.3.22","0.3.22",null,null);

using var rz=ZipFile.OpenRead(args[0]);
var r140=JsonDocument.Parse(Entry(rz,"release-set.json")).RootElement;
var baseline=Identity(r140.GetProperty("targets").GetProperty("local"));
var candidate=Manifest(args[1]);var qualified=Manifest(args[2]);
Need(candidate.GetProperty("for_install").GetBoolean()==false && qualified.GetProperty("for_install").GetBoolean(),"CANDIDATE_DISABLED_QUALIFIED_ENABLED");
Need(candidate.GetProperty("production_pass").GetBoolean()==false && qualified.GetProperty("production_pass").GetBoolean(),"STAGE_GATE_DIFFERS");
var target=qualified.GetProperty("targets").GetProperty("local");
Need(target.GetProperty("managed_files").GetInt32()==581,"TARGET_MANAGED_FILES_581");
Need(target.GetProperty("migration_head").GetString()=="0080_tea_herbal_legacy_family_repair","TARGET_MIGRATION_0080");
Need(target.GetProperty("version").GetString()=="3.0.0-g6.2-workspace.231eb-r149-catalog-ui-multibase-uat","TARGET_VERSION_R149_ACTUAL");
Need(qualified.GetProperty("supported_baselines").GetArrayLength()==43,"43_KNOWN_BASELINES");
Need(qualified.GetProperty("sequence").GetInt64()>r140.GetProperty("sequence").GetInt64(),"ANTI_ROLLBACK_SEQUENCE_RAISED");
Need(qualified.GetProperty("release_type").GetString()=="local_only","SCOPED_TO_LOCAL_ONLY");
using(var z=ZipFile.OpenRead(args[2]))
{
    var web=Entry(z,"artifacts/local/web.zip");
    Need(Sha(web)==qualified.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"EXACT_NESTED_WEB_SHA");
    Need(ManagedTree(web)==target.GetProperty("managed_tree_sha256").GetString(),"EXACT_TARGET_581_FILE_TREE");
    Need(MigrationFingerprint(web)==target.GetProperty("schema_fingerprint").GetString(),"EXACT_80_MIGRATION_FINGERPRINT");
    using var m=new MemoryStream(web);
    using var inner=new ZipArchive(m,ZipArchiveMode.Read);
    Need(Encoding.UTF8.GetString(Entry(inner,"VERSION.txt")).Trim()==target.GetProperty("version").GetString(),"INNER_VERSION_TARGET_MATCH");
}
var baseCount=qualified.GetProperty("supported_baselines").EnumerateArray().Count(x=>
    x.GetProperty("local").GetProperty("version").GetString()==baseline.Version &&
    x.GetProperty("local").GetProperty("schema_fingerprint").GetString()==baseline.SchemaFingerprint &&
    x.GetProperty("local").GetProperty("managed_tree_sha256").GetString()==baseline.ManagedTreeSha256);
Need(baseCount==1,"R140_HOME_BASELINE_UNIQUE");
var root=Path.Combine(Path.GetTempPath(),"sokna-r149b-exact-stage-"+Guid.NewGuid().ToString("N"));
try
{
    var paths=new MaintenancePaths(root);
    Directory.CreateDirectory(paths.InboxRoot);
    string Inbox(string file)
    {
        var bytes=File.ReadAllBytes(file);
        var targetPath=Path.Combine(paths.InboxRoot,Sha(bytes)+".zip");
        File.WriteAllBytes(targetPath,bytes);
        return targetPath;
    }
    var cq=Inbox(args[1]);var fq=Inbox(args[2]);
    var verifier=new ReleaseSetVerifierStager(paths);
    var check=verifier.VerifyOnly(cq,Installed(baseline));
    Need(check.ArtifactIntegrityVerified && check.ExactBaselineVerified==true && check.OperatorAuthorized,"DISABLED_CANDIDATE_VERIFY_ONLY_ALLOWED");
    var blocked=false;
    try {verifier.VerifyAndStage(cq,Installed(baseline));}
    catch(InvalidDataException ex) when(ex.Message.Contains("RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN",StringComparison.Ordinal)) {blocked=true;}
    Need(blocked && !Directory.Exists(paths.StagingRoot),"DISABLED_CANDIDATE_STAGE_REFUSED_NO_SIDE_EFFECT");
    var verify=verifier.VerifyOnly(fq,Installed(baseline));
    Need(verify.ArtifactIntegrityVerified && verify.ExactBaselineVerified==true && verify.OperatorAuthorized,"EXACT_QUALIFIED_VERIFY_ONLY_REAL_ENGINE_0322");
    foreach(var row in qualified.GetProperty("supported_baselines").EnumerateArray())
    {
        var id=Identity(row.GetProperty("local"));
        var test=verifier.VerifyOnly(fq,Installed(id));
        Need(test.ExactBaselineVerified==true,"SUPPORTED_KNOWN_BASELINE_"+id.Version);
    }
    var invalid=new ReleaseComponentIdentity(baseline.ReleaseId,baseline.Version+"-unknown",baseline.ManagedTreeSha256,baseline.SchemaFingerprint,baseline.MigrationHead,baseline.AuthorityEpochPolicy);
    var refused=false;
    try{verifier.VerifyOnly(fq,Installed(invalid));}
    catch(InvalidDataException){refused=true;}
    Need(refused,"UNKNOWN_OR_MODIFIED_SOURCE_REFUSED");
    var staged=verifier.VerifyAndStage(fq,Installed(baseline));
    Need(staged.ArtifactIntegrityVerified && staged.OperatorAuthorized,"QUALIFIED_STAGE_AUTHENTICATED");
    Need(File.Exists(Path.Combine(staged.StageRoot,"stage-receipt.json")),"DURABLE_STAGE_RECEIPT_WRITTEN");
    Need(Sha(File.ReadAllBytes(Path.Combine(staged.StageRoot,"artifacts/local/web.zip")))==qualified.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"STAGED_SOURCE_EXACT_PAYLOAD");
    Need(!staged.DatabaseMutationPerformed && !staged.ActivationPerformed,"VERIFY_STAGE_DOES_NOT_MUTATE_LIVE_DB");
    Console.WriteLine("SOKNA_R149B_EXACT_WINDOWS_MAINTENANCE_0322_QUALIFIED_STAGE_PASS");
}
finally{try{Directory.Delete(root,true);}catch{}}