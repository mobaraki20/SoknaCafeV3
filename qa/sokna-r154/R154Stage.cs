using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("DISPOSABLE_WINDOWS_QA_ONLY");
if (args.Length != 1) throw new ArgumentException("Usage R154_LOCAL_HOME_CANARY.zip");
static void Need(bool ok,string label){if(!ok)throw new InvalidDataException("R154_CANARY_"+label);Console.WriteLine("PASS "+label);}
static string Sha(byte[] b)=>Convert.ToHexString(SHA256.HashData(b)).ToLowerInvariant();
static byte[] Read(ZipArchive z,string p){using var s=z.GetEntry(p)?.Open()??throw new InvalidDataException(p);using var m=new MemoryStream();s.CopyTo(m);return m.ToArray();}
static ReleaseComponentIdentity ToIdentity(JsonElement m)=>new(
    m.GetProperty("release_id").GetString()!,m.GetProperty("version").GetString()!,
    m.GetProperty("managed_tree_sha256").GetString()!,m.GetProperty("schema_fingerprint").GetString()!,
    m.GetProperty("migration_head").GetString()!,m.GetProperty("authority_epoch_policy").GetString()!);
static InstalledReleaseIdentity Installed(ReleaseComponentIdentity local)
    =>new(local,null,"0.3.27","0.3.27",null,null);
using var zip=ZipFile.OpenRead(args[0]);
var m=JsonDocument.Parse(Read(zip,"release-set.json")).RootElement;
Need(m.GetProperty("release_type").GetString()=="local_only","NO_MAINTENANCE_OR_PUBLIC_SCOPE");
Need(m.GetProperty("for_install").GetBoolean()&&m.GetProperty("production_pass").GetBoolean(),"CANARY_INSTALLABLE_META");
Need(m.GetProperty("qa_notes").GetProperty("actual_cafe_business_restore_proven").GetBoolean()==false,"NO_FAKE_CAFE_RESTORE");
Need(m.GetProperty("supported_baselines").GetArrayLength()==45,"45_EXACT_KNOWN_BASELINES");
Need(m.GetProperty("pre_update_backup_policy").GetProperty("required").GetBoolean()&&
     m.GetProperty("pre_update_backup_policy").GetProperty("on_failure").GetString()=="reject_pre_mutation","HOST_BACKUP_FAIL_CLOSED");
var web=Read(zip,"artifacts/local/web.zip");
Need(Sha(web)==m.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"WEB_BYTES_MATCH_MANIFEST");
using(var inner=new ZipArchive(new MemoryStream(web),ZipArchiveMode.Read))
{
    Need(inner.Entries.Count==583,"SOURCE_TREE_583_FILES");
    Need(System.Text.Encoding.UTF8.GetString(Read(inner,"VERSION.txt")).Trim()==m.GetProperty("targets").GetProperty("local").GetProperty("version").GetString(),"VERSION_FILE_MATCHES_RELEASE_IDENTITY");
}
var baselines=m.GetProperty("supported_baselines").EnumerateArray().Select(x=>ToIdentity(x.GetProperty("local"))).ToArray();
var home=baselines.SingleOrDefault(x=>x.Version.Contains("231ed-r153",StringComparison.Ordinal));
var cafe=baselines.SingleOrDefault(x=>x.Version.Contains("231dg-r112",StringComparison.Ordinal));
Need(home is not null&&cafe is not null,"HOME_R153_AND_CAFE_R112_BOTH_INCLUDED");
var root=Path.Combine(Path.GetTempPath(),"sokna-r154-45base-"+Guid.NewGuid().ToString("N"));
try
{
    var paths=new MaintenancePaths(root);
    Directory.CreateDirectory(paths.InboxRoot);
    var bytes=File.ReadAllBytes(args[0]);
    var inbox=Path.Combine(paths.InboxRoot,Sha(bytes)+".zip");
    File.WriteAllBytes(inbox,bytes);
    var verifier=new ReleaseSetVerifierStager(paths);
    var unique=new HashSet<string>();
    foreach(var id in baselines)
    {
        Need(unique.Add(id.Version),"UNIQUE_BASELINE_"+id.Version);
        var v=verifier.VerifyOnly(inbox,Installed(id));
        Need(v.ExactBaselineVerified==true&&v.ArtifactIntegrityVerified&&v.OperatorAuthorized&&
             !v.StagePerformed&&!v.DatabaseMutationPerformed,"READ_ONLY_PREFLIGHT_"+id.Version);
    }
    Need(unique.Count==45 && !Directory.Exists(paths.StagingRoot),"45_PREFLIGHTS_NO_STAGE_OR_DB_MUTATION");
    var invalid=home! with {Version=home!.Version+"-UNSUPPORTED"};
    var rejected=false;
    try{verifier.VerifyOnly(inbox,Installed(invalid));}
    catch(InvalidDataException e) when(e.Message.Contains("exact supported baseline",StringComparison.OrdinalIgnoreCase)){rejected=true;}
    Need(rejected,"UNKNOWN_SOURCE_FAIL_CLOSED");
    var stage=verifier.VerifyAndStage(inbox,Installed(home));
    Need(stage.OperatorAuthorized&&!stage.SignatureVerified,"SAME_EXACT_ZIP_NATIVE_WINDOWS_STAGE_FROM_HOME_R153");
    Need(Directory.Exists(paths.StagingRoot),"NATIVE_STAGE_DIRECTORY_CREATED_AFTER_APPROVAL");
    // Independent test from café R112 without modifying any application tree.
    var second=new MaintenancePaths(root+"-r112");Directory.CreateDirectory(second.InboxRoot);
    var secondInbox=Path.Combine(second.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(secondInbox,bytes);
    var secondStage=new ReleaseSetVerifierStager(second).VerifyAndStage(secondInbox,Installed(cafe!));
    Need(secondStage.OperatorAuthorized,"NATIVE_WINDOWS_STAGE_FROM_CAFE_R112_DISPOSABLE_ONLY");
    Console.WriteLine("PASS R154_EXACT_PACKAGE_45_BASELINE_VERIFY_AND_R153_R112_STAGE");
    try{Directory.Delete(root+"-r112",true);}catch{}
}
finally{try{Directory.Delete(root,true);}catch{}}