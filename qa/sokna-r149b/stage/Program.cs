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
    using var inventoryJson=JsonDocument.Parse(Entry(inner,"resources/local-managed-files-v1.json"));
    var listed=inventoryJson.RootElement.GetProperty("files").EnumerateArray().Select(x=>x.GetString()!).ToArray();
    var actual=inner.Entries.Select(x=>x.FullName).OrderBy(x=>x,StringComparer.Ordinal).ToArray();
    Need(listed.SequenceEqual(actual,StringComparer.Ordinal),"INTERNAL_MANAGED_INVENTORY_MATCHES_ALL_581_FILES");
}
var baseCount=qualified.GetProperty("supported_baselines").EnumerateArray().Count(x=>
    x.GetProperty("local").GetProperty("version").GetString()==baseline.Version &&
    x.GetProperty("local").GetProperty("schema_fingerprint").GetString()==baseline.SchemaFingerprint &&
    x.GetProperty("local").GetProperty("managed_tree_sha256").GetString()==baseline.ManagedTreeSha256);
Need(baseCount==1,"R140_HOME_BASELINE_UNIQUE");

// A different actual host identity from the 2026-11-09 Café One Support
// archive must be recognized by this SAME immutable target ZIP. It does not
// authorize activation of a host with unresolved R125 recovery evidence.
const string cafeVersion = "3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat";
const string cafeRelease = "sokna-r112-guest-safe-append-uat-20261105";
const string cafeTree = "04ee10c8315c20b7c046c2fd954589f271934530d4c92d701405a98f938c0770";
const string cafeSchema = "migration-ledger-sha256-v1:deb5af9ad17f91af67360dd7a89de3b00b84be171675f4fb710ca1dd73e52ed9";
var legacyRows=qualified.GetProperty("supported_baselines").EnumerateArray()
    .Where(x=>x.GetProperty("local").GetProperty("version").GetString()==cafeVersion)
    .ToArray();
Need(legacyRows.Length==1,"CAFE_R112_EXACT_BASELINE_PRESENT_ON_SAME_ZIP");
var cafeId=Identity(legacyRows[0].GetProperty("local"));
Need(cafeId.Version==cafeVersion &&
     cafeId.ReleaseId==cafeRelease &&
     cafeId.ManagedTreeSha256==cafeTree &&
     cafeId.SchemaFingerprint==cafeSchema &&
     cafeId.MigrationHead=="0076_late_served_accounting" &&
     cafeId.AuthorityEpochPolicy=="preserved",
     "CAFE_R112_ARCHIVED_IDENTITY_MATCHES_MANIFEST");

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

// Same exact ZIP and same real 0.3.22 ReleaseSetVerifierStager on the archived
// R112 identity. This is Verify+Stage only, NOT real R112->R149B activation.
var cafeRoot=Path.Combine(root,"cafe-r112-isolated");
var cafePaths=new MaintenancePaths(cafeRoot);
Directory.CreateDirectory(cafePaths.InboxRoot);
var qualifiedBytes=File.ReadAllBytes(args[2]);
var cafeCandidate=Path.Combine(cafePaths.InboxRoot,Sha(qualifiedBytes)+".zip");
File.WriteAllBytes(cafeCandidate,qualifiedBytes);
var cafeVerifier=new ReleaseSetVerifierStager(cafePaths);
var cafeVerified=cafeVerifier.VerifyOnly(cafeCandidate,Installed(cafeId));
Need(cafeVerified.ArtifactIntegrityVerified && cafeVerified.ExactBaselineVerified==true && cafeVerified.OperatorAuthorized,
    "CAFE_R112_VERIFY_ONLY_EXACT_PACKAGE_ON_0322");
var cafeStaged=cafeVerifier.VerifyAndStage(cafeCandidate,Installed(cafeId));
Need(cafeStaged.ArtifactIntegrityVerified && cafeStaged.OperatorAuthorized &&
     !cafeStaged.ActivationPerformed && !cafeStaged.DatabaseMutationPerformed,
     "CAFE_R112_STAGE_NO_DB_OR_ACTIVATION");
Need(File.Exists(Path.Combine(cafeStaged.StageRoot,"stage-receipt.json")),
    "CAFE_R112_DURABLE_STAGE_RECEIPT");
Need(Sha(File.ReadAllBytes(Path.Combine(cafeStaged.StageRoot,"artifacts/local/web.zip"))) ==
     qualified.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),
    "HOME_R140_AND_CAFE_R112_STAGE_THE_IDENTICAL_TARGET");
var badCafe=new ReleaseComponentIdentity(cafeId.ReleaseId,cafeId.Version,cafeId.ManagedTreeSha256,
    "migration-ledger-sha256-v1:"+new string('0',64),cafeId.MigrationHead,cafeId.AuthorityEpochPolicy);
var changedSchemaRefused=false;
try{cafeVerifier.VerifyOnly(cafeCandidate,Installed(badCafe));}
catch(InvalidDataException){changedSchemaRefused=true;}
Need(changedSchemaRefused,"CAFE_R112_UNRECOGNIZED_SCHEMA_REFUSED_BEFORE_MUTATION");
Console.WriteLine("PASS identical SHA R149B verified and staged for actual recorded R112 and known R140 identities; R125 proof and live DB still NOT tested");

    Console.WriteLine("SOKNA_R149B_EXACT_WINDOWS_MAINTENANCE_0322_QUALIFIED_STAGE_PASS");
}
finally{try{Directory.Delete(root,true);}catch{}}