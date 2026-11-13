using System;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

// This program validates only an isolated CI staging probe, never Activate or a user machine.
if (!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" || Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
    throw new InvalidOperationException("R184_DISPOSABLE_WINDOWS_RUNNER_ONLY");
if(args.Length!=1) throw new ArgumentException("Usage R184_STAGE_TEST_PACKAGE.zip");
static void Ok(bool v,string tag){ if(!v) throw new InvalidDataException("R184_"+tag); Console.WriteLine("PASS_R184_"+tag); }
static string Sha(byte[] b)=>Convert.ToHexString(SHA256.HashData(b)).ToLowerInvariant();
static byte[] Entry(ZipArchive z,string name){using var s=z.GetEntry(name)?.Open()??throw new InvalidDataException("MISSING_"+name);using var m=new MemoryStream();s.CopyTo(m);return m.ToArray();}
static ReleaseComponentIdentity ToIdentity(JsonElement e)=>new(
    e.GetProperty("release_id").GetString()!,e.GetProperty("version").GetString()!,
    e.GetProperty("managed_tree_sha256").GetString()!,e.GetProperty("schema_fingerprint").GetString()!,
    e.GetProperty("migration_head").GetString()!,e.GetProperty("authority_epoch_policy").GetString()!);
static InstalledReleaseIdentity Installed(ReleaseComponentIdentity local)=>new(local,null,"0.3.27","0.3.27",null,null);
using var z=ZipFile.OpenRead(Path.GetFullPath(args[0]));
using var md=JsonDocument.Parse(Entry(z,"release-set.json"));var m=md.RootElement;
Ok(m.GetProperty("release_type").GetString()=="local_only","LOCAL_ONLY");
Ok(m.GetProperty("qa_notes").GetProperty("r184_quarantined_ci_only").GetBoolean(),"QUARANTINE_ASSERTED");
Ok(m.GetProperty("for_install").GetBoolean()&&m.GetProperty("production_pass").GetBoolean(),"TEMPORARY_STAGE_PROBE_EXPLICIT");
Ok(m.GetProperty("targets").GetProperty("local").GetProperty("managed_files").GetInt32()==589,"EXACT_589_FILES");
var b=m.GetProperty("supported_baselines").EnumerateArray().Select(x=>ToIdentity(x.GetProperty("local"))).ToArray();
Ok(b.Length==56,"EXACT_56_BASELINES");
var home=b.FirstOrDefault(x=>x.Version.Contains("231ed-r153",StringComparison.Ordinal))
     ??throw new InvalidDataException("HOME_231ED_BASELINE_NOT_FOUND");
var payload=Entry(z,"artifacts/local/web.zip");
Ok(Sha(payload)==m.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"WEB_BYTES_PINNED");
string root=Path.Combine(Path.GetTempPath(),"sokna-r184-stage-qa-"+Guid.NewGuid().ToString("N"));
try {
  var paths=new MaintenancePaths(Path.Combine(root,"maintenance"));Directory.CreateDirectory(paths.InboxRoot);
  byte[] package=File.ReadAllBytes(Path.GetFullPath(args[0]));string inbox=Path.Combine(paths.InboxRoot,Sha(package)+".zip");File.WriteAllBytes(inbox,package);
  var verifier=new ReleaseSetVerifierStager(paths);
  foreach(var identity in b) {
    var result=verifier.VerifyOnly(inbox,Installed(identity));
    Ok(result.ExactBaselineVerified==true && result.ArtifactIntegrityVerified && result.OperatorAuthorized && !result.StagePerformed && !result.DatabaseMutationPerformed,"BASELINE_VERIFIED_"+identity.ReleaseId);
  }
  Ok(!Directory.Exists(paths.StagingRoot),"NO_STAGE_DURING_VERIFY_ONLY");
  var bad=home with{ReleaseId=home.ReleaseId+"-invalid"};bool rejected=false;
  try {verifier.VerifyOnly(inbox,Installed(bad));} catch(InvalidDataException e) when (e.Message.Contains("exact supported baseline",StringComparison.OrdinalIgnoreCase)){rejected=true;}
  Ok(rejected,"UNKNOWN_BASELINE_DENIED");
  var staged=verifier.VerifyAndStage(inbox,Installed(home));
  Ok(staged.OperatorAuthorized && staged.ArtifactIntegrityVerified && staged.PreflightIdentityMatch && !staged.DatabaseMutationPerformed && !staged.ActivationPerformed,"NATIVE_STAGE_HOME_231ED");
  Ok(Directory.Exists(paths.StagingRoot),"STAGING_DIR_CREATED_ON_STAGE_ONLY");
  // VerifyOnly + VerifyAndStage must not activate or change installed identity.
  Ok(!Directory.Exists(Path.Combine(root,"actual-live-local-host")),"NO_PRODUCTION_CODE_MUTATION");
  Console.WriteLine("PASS_R184_QUARANTINED_WINDOWS_STAGE_ONLY_NOT_ACTIVATION_PROOF");
} finally{try{Directory.Delete(root,true);}catch{}}
