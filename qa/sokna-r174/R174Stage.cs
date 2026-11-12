using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("DISPOSABLE_WINDOWS_QA_ONLY");
if (args.Length != 1) throw new ArgumentException("Usage R174_LOCAL_HOME_CANARY.zip");
static void Need(bool ok,string label){if(!ok)throw new InvalidDataException("R174_CANARY_"+label);Console.WriteLine("PASS "+label);}
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
Need(m.GetProperty("supported_baselines").GetArrayLength()==52,"52_EXACT_KNOWN_BASELINES");
Need(m.GetProperty("pre_update_backup_policy").GetProperty("required").GetBoolean()&&
     m.GetProperty("pre_update_backup_policy").GetProperty("on_failure").GetString()=="reject_pre_mutation","HOST_BACKUP_FAIL_CLOSED");
var web=Read(zip,"artifacts/local/web.zip");
Need(Sha(web)==m.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"WEB_BYTES_MATCH_MANIFEST");
using(var inner=new ZipArchive(new MemoryStream(web),ZipArchiveMode.Read))
{
    Need(inner.Entries.Count==589,"SOURCE_TREE_589_FILES");
    Need(System.Text.Encoding.UTF8.GetString(Read(inner,"VERSION.txt")).Trim()==m.GetProperty("targets").GetProperty("local").GetProperty("version").GetString(),"VERSION_FILE_MATCHES_RELEASE_IDENTITY");
}
var baselines=m.GetProperty("supported_baselines").EnumerateArray().Select(x=>ToIdentity(x.GetProperty("local"))).ToArray();
var home=baselines.SingleOrDefault(x=>x.ReleaseId=="sokna-r153-local-44base-home-canary-20261111");
var auditedHome=baselines.SingleOrDefault(x=>x.ReleaseId=="sokna-r153-local-44baseline-audited-source-qa-20261110");
Need(auditedHome is not null&&home is not null,"BOTH_HOME_IDENTITIES_KNOWN_EXACT");
var cafe=baselines.SingleOrDefault(x=>x.Version.Contains("231dg-r112",StringComparison.Ordinal));
Need(home is not null&&auditedHome is not null&&cafe is not null,"HOME_R153_AND_CAFE_R112_BOTH_INCLUDED");
var root=Path.Combine(Path.GetTempPath(),"sokna-r154-52base-"+Guid.NewGuid().ToString("N"));
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
        Need(unique.Add(id.Version+"|"+id.ReleaseId),"UNIQUE_BASELINE_"+id.Version+"_"+id.ReleaseId);
        var v=verifier.VerifyOnly(inbox,Installed(id));
        Need(v.ExactBaselineVerified==true&&v.ArtifactIntegrityVerified&&v.OperatorAuthorized&&
             !v.StagePerformed&&!v.DatabaseMutationPerformed,"READ_ONLY_PREFLIGHT_"+id.Version);
    }
    Need(unique.Count==52 && !Directory.Exists(paths.StagingRoot),"52_PREFLIGHTS_NO_STAGE_OR_DB_MUTATION");
    var invalid=home! with {ReleaseId=home!.ReleaseId+"-UNSUPPORTED"};
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
    var r162=baselines.SingleOrDefault(x=>x.Version.Contains("231em-r162",StringComparison.Ordinal));
    Need(r162 is not null,"PRIOR_R162_TARGET_INCLUDED");
    var r162Dir=new MaintenancePaths(root+"-r162");Directory.CreateDirectory(r162Dir.InboxRoot);
    var r162Inbox=Path.Combine(r162Dir.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(r162Inbox,bytes);
    var r162Stage=new ReleaseSetVerifierStager(r162Dir).VerifyAndStage(r162Inbox,Installed(r162!));
    Need(r162Stage.OperatorAuthorized&&!r162Stage.DatabaseMutationPerformed,"R162_PRIOR_TARGET_EXACT_WINDOWS_STAGE");
    try{Directory.Delete(root+"-r162",true);}catch{}
    var installedR155=baselines.SingleOrDefault(x=>x.Version.Contains("231ef-r155",StringComparison.Ordinal));
    Need(installedR155 is not null,"R155_TARGET_BASELINE_INCLUDED");
    var third=new MaintenancePaths(root+"-r155");Directory.CreateDirectory(third.InboxRoot);
    var thirdInbox=Path.Combine(third.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(thirdInbox,bytes);
    var thirdStage=new ReleaseSetVerifierStager(third).VerifyAndStage(thirdInbox,Installed(installedR155!));
    Need(thirdStage.OperatorAuthorized&&!thirdStage.DatabaseMutationPerformed,"R155_EXACT_WINDOWS_STAGE");
    try{Directory.Delete(root+"-r155",true);}catch{}
    var r158=baselines.SingleOrDefault(x=>x.Version.Contains("231ei-r158",StringComparison.Ordinal));
    Need(r158 is not null,"R158_PREVIOUS_TARGET_INCLUDED");
    var fourth=new MaintenancePaths(root+"-r158");Directory.CreateDirectory(fourth.InboxRoot);
    var fourthInbox=Path.Combine(fourth.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(fourthInbox,bytes);
    var fourthStage=new ReleaseSetVerifierStager(fourth).VerifyAndStage(fourthInbox,Installed(r158!));
    Need(fourthStage.OperatorAuthorized&&!fourthStage.DatabaseMutationPerformed,"R158_EXACT_WINDOWS_STAGE");
    try{Directory.Delete(root+"-r158",true);}catch{}
    var r159=baselines.SingleOrDefault(x=>x.Version.Contains("231ej-r159",StringComparison.Ordinal));
    Need(r159 is not null,"R159_PREVIOUS_TARGET_INCLUDED");
    var fifth=new MaintenancePaths(root+"-r159");Directory.CreateDirectory(fifth.InboxRoot);
    var fifthInbox=Path.Combine(fifth.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(fifthInbox,bytes);
    var fifthStage=new ReleaseSetVerifierStager(fifth).VerifyAndStage(fifthInbox,Installed(r159!));
    Need(fifthStage.OperatorAuthorized&&!fifthStage.DatabaseMutationPerformed,"R159_EXACT_WINDOWS_STAGE");
    try{Directory.Delete(root+"-r159",true);}catch{}
    var r160=baselines.SingleOrDefault(x=>x.Version.Contains("231ek-r160",StringComparison.Ordinal));
    Need(r160 is not null,"R160_PREVIOUS_TARGET_INCLUDED");
    var sixth=new MaintenancePaths(root+"-r160");Directory.CreateDirectory(sixth.InboxRoot);
    var sixthInbox=Path.Combine(sixth.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(sixthInbox,bytes);
    var sixthStage=new ReleaseSetVerifierStager(sixth).VerifyAndStage(sixthInbox,Installed(r160!));
    Need(sixthStage.OperatorAuthorized&&!sixthStage.DatabaseMutationPerformed,"R160_EXACT_WINDOWS_STAGE");
    try{Directory.Delete(root+"-r160",true);}catch{}
    Console.WriteLine("PASS R174_EXACT_PACKAGE_52_BASELINE_VERIFY_AND_R153_R112_R155_R158_R159_R160_STAGE");
    try{Directory.Delete(root+"-r112",true);}catch{}
}
finally{try{Directory.Delete(root,true);}catch{}}
