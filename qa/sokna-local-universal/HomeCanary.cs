using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("DISPOSABLE_GITHUB_WINDOWS_ONLY");
if (args.Length != 1) throw new ArgumentException("Usage: HOME-CANARY-PANEL-UPDATE.zip");
static void Need(bool ok,string label)
{
    if(!ok) throw new InvalidDataException("HOME_CANARY_FAIL_"+label);
    Console.WriteLine("PASS "+label);
}
static string Sha(byte[] b)=>Convert.ToHexString(SHA256.HashData(b)).ToLowerInvariant();
static byte[] ReadEntry(ZipArchive z,string name)
{
    using var s=z.GetEntry(name)?.Open()??throw new InvalidDataException("Missing "+name);
    using var b=new MemoryStream();s.CopyTo(b);return b.ToArray();
}
static ReleaseComponentIdentity Identity(JsonElement m)=>new(
    m.GetProperty("release_id").GetString()!, m.GetProperty("version").GetString()!,
    m.GetProperty("managed_tree_sha256").GetString()!,
    m.GetProperty("schema_fingerprint").GetString()!,
    m.GetProperty("migration_head").GetString()!,
    m.GetProperty("authority_epoch_policy").GetString()!);
static InstalledReleaseIdentity Installed(ReleaseComponentIdentity local)
    =>new(local,null,"0.3.27","0.3.27",null,null);

using var zip=ZipFile.OpenRead(args[0]);
var manifest=JsonDocument.Parse(ReadEntry(zip,"release-set.json")).RootElement;
Need(manifest.GetProperty("release_type").GetString()=="local_only","LOCAL_ONLY_SCOPE");
Need(manifest.GetProperty("for_install").GetBoolean()&&manifest.GetProperty("production_pass").GetBoolean(),"INSTALLABLE_METADATA");
Need(manifest.GetProperty("qa_notes").GetProperty("cafe_production_approved").GetBoolean()==false,"CAFE_PRODUCTION_NOT_CLAIMED");
Need(manifest.GetProperty("supported_baselines").GetArrayLength()==44,"44_EXACT_BASELINES");
Need(manifest.GetProperty("pre_update_backup_policy").GetProperty("required").GetBoolean()&&
    manifest.GetProperty("pre_update_backup_policy").GetProperty("on_failure").GetString()=="reject_pre_mutation","HOST_BACKUP_REQUIRED");
var inner=ReadEntry(zip,"artifacts/local/web.zip");
Need(Sha(inner)=="1d39bbe406929089eac9880a0aec794947112b74c17359c48c4d49893e0183b8","EXACT_R153_WEB_SOURCE");
var root=Path.Combine(Path.GetTempPath(),"sokna-home-canary-r153-"+Guid.NewGuid().ToString("N"));
try
{
    var paths=new MaintenancePaths(root);
    Directory.CreateDirectory(paths.InboxRoot);
    var bytes=File.ReadAllBytes(args[0]);
    var inbox=Path.Combine(paths.InboxRoot,Sha(bytes)+".zip");
    File.WriteAllBytes(inbox,bytes);
    var verifier=new ReleaseSetVerifierStager(paths);
    ReleaseComponentIdentity? first=null;
    var count=0;
    foreach(var row in manifest.GetProperty("supported_baselines").EnumerateArray())
    {
        var id=Identity(row.GetProperty("local"));
        first??=id;
        var result=verifier.VerifyOnly(inbox,Installed(id));
        Need(result.ExactBaselineVerified == true && result.ArtifactIntegrityVerified &&
            result.OperatorAuthorized && !result.DatabaseMutationPerformed && !result.StagePerformed,
            "NATIVE_PREFLIGHT_BASELINE_"+(++count));
    }
    Need(count==44 && !Directory.Exists(paths.StagingRoot),"ZERO_STAGE_BEFORE_AUTHORISED_STAGE");
    var unknown=first! with {Version=first!.Version+"-unknown"};
    var denied=false;
    try{verifier.VerifyOnly(inbox,Installed(unknown));}
    catch(InvalidDataException e) when(e.Message.Contains("exact supported baseline",StringComparison.OrdinalIgnoreCase)){denied=true;}
    Need(denied,"UNKNOWN_IDENTITY_FAILS_CLOSED");
    var receipt=verifier.VerifyAndStage(inbox,Installed(first!));
    Need(receipt.OperatorAuthorized && !receipt.SignatureVerified,"NATIVE_OPERATOR_SHA256_INBOX_STAGE_ACCEPTED");
    Need(Directory.Exists(paths.StagingRoot),"NATIVE_STAGE_WRITES_ONLY_TO_PRIVATE_STAGING_ROOT");
    Console.WriteLine("PASS EXACT_INSTALLABLE_HOME_CANARY_ZIP_NATIVE_WINDOWS_VERIFIED_AND_STAGED");
}
finally{try{Directory.Delete(root,true);}catch{}}
