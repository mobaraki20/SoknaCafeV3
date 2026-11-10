using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("DISPOSABLE_GITHUB_WINDOWS_QA_ONLY");
if (args.Length != 3) throw new ArgumentException("Usage <original-R153.zip> <preflight-contract-QA.zip> <R149B-qualified.zip>");
static void Need(bool yes, string msg)
{
    if (!yes) throw new InvalidDataException("UNIVERSAL_LOCAL_CONTRACT_" + msg);
    Console.WriteLine("PASS " + msg);
}
static string Sha(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
static byte[] Entry(ZipArchive z, string path)
{
    using var stream=z.GetEntry(path)?.Open()??throw new InvalidDataException("Missing "+path);
    using var result=new MemoryStream();
    stream.CopyTo(result);return result.ToArray();
}
static JsonElement M(string p)
{
    using var z=ZipFile.OpenRead(p);
    return JsonDocument.Parse(Entry(z,"release-set.json")).RootElement.Clone();
}
static ReleaseComponentIdentity Local(JsonElement node) => new(
    node.GetProperty("release_id").GetString()!,
    node.GetProperty("version").GetString()!,
    node.GetProperty("managed_tree_sha256").GetString()!,
    node.GetProperty("schema_fingerprint").GetString()!,
    node.GetProperty("migration_head").GetString()!,
    node.GetProperty("authority_epoch_policy").GetString()!);
static InstalledReleaseIdentity Install(ReleaseComponentIdentity local)
    => new(local,null,"0.3.27","0.3.27",null,null);

var qa=M(args[1]);var original=M(args[0]);var prior=M(args[2]);
Need(Sha(File.ReadAllBytes(args[0]))=="d7761df8002fe15680eddda91f3928bdbf8b4e9f731659210cd8885424aa345d","EXACT_PUBLISHED_R153_SOURCE");
Need(Sha(File.ReadAllBytes(args[2]))=="968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4","EXACT_PREVIOUS_QUALIFIED_RELEASE");
Need(!qa.GetProperty("for_install").GetBoolean()&&!qa.GetProperty("production_pass").GetBoolean(),"DELIVERED_QA_STILL_BLOCKED");
Need(qa.GetProperty("install_plan").GetArrayLength()==3,"THREE_PHASES_PRESENT_IN_EXACT_ZIP");
var steps=qa.GetProperty("install_plan").EnumerateArray().ToArray();
Need(steps[0].GetProperty("phase").GetString()=="preflight"&&!steps[0].GetProperty("mutation").GetBoolean() &&
     steps[1].GetProperty("phase").GetString()=="stage"&&!steps[1].GetProperty("mutation").GetBoolean() &&
     steps[2].GetProperty("phase").GetString()=="activate"&&steps[2].GetProperty("mutation").GetBoolean(),"VALID_NON_MUTATING_PREFLIGHT_AND_STAGE_THEN_ACTIVATE");
Need(qa.GetProperty("supported_baselines").GetArrayLength()==44,"UNIVERSAL_44_EXACT_BASELINES");
Need(qa.GetProperty("release_type").GetString()=="local_only","NO_PUBLIC_PRINT_OR_MAINTENANCE_MUTATION");
Need(qa.GetProperty("signature").ValueKind==JsonValueKind.Null,"NO_FAKE_PUBLISHER_SIGNATURE");
Need(qa.GetProperty("pre_update_backup_policy").GetProperty("required").GetBoolean() &&
     qa.GetProperty("pre_update_backup_policy").GetProperty("on_failure").GetString()=="reject_pre_mutation","HOST_BACKUP_FAIL_CLOSED");
Need(qa.GetProperty("database").GetProperty("local").GetProperty("rollback_after_boundary").GetString()=="forbidden","NO_UNSAFE_DB_ROLLBACK");
using (var source=ZipFile.OpenRead(args[0]))
using (var revised=ZipFile.OpenRead(args[1]))
{
    var a=Entry(source,"artifacts/local/web.zip");
    var b=Entry(revised,"artifacts/local/web.zip");
    Need(Sha(a)==Sha(b) &&
        Sha(b)==qa.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"TARGET_583_FILES_BYTE_IDENTICAL");
}
Need(qa.GetProperty("targets").GetProperty("local").GetProperty("managed_tree_sha256").GetString()==
     original.GetProperty("targets").GetProperty("local").GetProperty("managed_tree_sha256").GetString(),"TARGET_MANAGED_TREE_UNCHANGED");

var root=Path.Combine(Path.GetTempPath(),"sokna-universal-local-r153-"+Guid.NewGuid().ToString("N"));
try
{
    var paths=new MaintenancePaths(root);
    Directory.CreateDirectory(paths.InboxRoot);
    var payload=File.ReadAllBytes(args[1]);
    var inbox=Path.Combine(paths.InboxRoot,Sha(payload)+".zip");
    File.WriteAllBytes(inbox,payload);
    var verifier=new ReleaseSetVerifierStager(paths);
    // Same ZIP must be accepted read-only by all 44 exact Local origins.
    var versions=new HashSet<string>();
    var cafe=false;var home=false;
    foreach(var baseline in qa.GetProperty("supported_baselines").EnumerateArray())
    {
        var id=Local(baseline.GetProperty("local"));
        Need(versions.Add(id.Version),"UNIQUE_"+id.Version);
        var outcome=verifier.VerifyOnly(inbox,Install(id));
        Need(outcome.ExactBaselineVerified==true&&outcome.ArtifactIntegrityVerified&&outcome.OperatorAuthorized&&
             !outcome.ActivationPerformed&&!outcome.DatabaseMutationPerformed&&!outcome.StagePerformed,
             "REAL_MAINTENANCE_READONLY_PREFLIGHT_"+id.Version);
        cafe|=id.Version=="3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat";
        home|=id.Version==prior.GetProperty("targets").GetProperty("local").GetProperty("version").GetString();
    }
    Need(cafe&&home,"CAFE_AND_HOME_NOT_HARDCODED_AS_TARGET_ONLY");
    Need(!Directory.Exists(paths.StagingRoot)&&!File.Exists(paths.ReleaseSequenceStatePath),"ALL_44_PREFLIGHTS_ZERO_PERSISTENT_SIDE_EFFECTS");
    var first=Local(qa.GetProperty("supported_baselines")[0].GetProperty("local"));
    var unknown=first with { Version=first.Version+"-NOT-QUALIFIED" };
    bool badRejected=false;
    try {verifier.VerifyOnly(inbox,Install(unknown));}
    catch(InvalidDataException e) when(e.Message.Contains("exact supported baseline",StringComparison.OrdinalIgnoreCase)){badRejected=true;}
    Need(badRejected,"UNKNOWN_BASELINE_REJECTED_WITHOUT_VERSION_GUESS");
    bool stageRejected=false;
    try{verifier.VerifyAndStage(inbox,Install(first));}
    catch(InvalidDataException e) when(e.Message.Contains("RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN",StringComparison.Ordinal))
    {stageRejected=true;}
    Need(stageRejected&&!Directory.Exists(paths.StagingRoot)&&!File.Exists(paths.ReleaseSequenceStatePath),
         "QA_STAGE_REFUSED_BEFORE ANY REAL OPERATION_OR_SEQUENCE");
    Console.WriteLine("PASS SAME_EXACT_ZIP_44_WINDOWS_PREFLIGHTS_AND_QA_STAGE_DENIAL_NO_DB_MUTATION");
}
finally{try{Directory.Delete(root,true);}catch{}}
