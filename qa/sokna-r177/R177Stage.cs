using System;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if(!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" ||
   Environment.GetEnvironmentVariable("RUNNER_OS")!="Windows")
    throw new InvalidOperationException("DISPOSABLE_WINDOWS_RUNNER_ONLY");
if(args.Length!=1)throw new ArgumentException("Usage R177_CANARY.zip");
static byte[] Read(ZipArchive z,string name){using var s=z.GetEntry(name)?.Open()??throw new InvalidDataException(name);using var o=new MemoryStream();s.CopyTo(o);return o.ToArray();}
static void Check(bool c,string label){if(!c)throw new InvalidDataException("R177_"+label);Console.WriteLine("PASS "+label);}
static string Sha(byte[] b)=>Convert.ToHexString(SHA256.HashData(b)).ToLowerInvariant();
static ReleaseComponentIdentity Identity(JsonElement e)=>new(
    e.GetProperty("release_id").GetString()!,e.GetProperty("version").GetString()!,
    e.GetProperty("managed_tree_sha256").GetString()!,e.GetProperty("schema_fingerprint").GetString()!,
    e.GetProperty("migration_head").GetString()!,e.GetProperty("authority_epoch_policy").GetString()!);
static InstalledReleaseIdentity Installed(ReleaseComponentIdentity local)
    =>new(local,null,"0.3.27","0.3.27",null,null);
var package=Path.GetFullPath(args[0]);
using var archive=ZipFile.OpenRead(package);
using var doc=JsonDocument.Parse(Read(archive,"release-set.json"));
var m=doc.RootElement;
Check(m.GetProperty("release_type").GetString()=="local_only","LOCAL_ONLY");
Check(m.GetProperty("for_install").GetBoolean()&&m.GetProperty("production_pass").GetBoolean(),"CANARY_STAGE_AUTHORIZED");
Check(m.GetProperty("targets").GetProperty("local").GetProperty("managed_files").GetInt32()==589,"TARGET_589_FILES");
Check(m.GetProperty("supported_baselines").GetArrayLength()==55,"EXACT_55_BASELINES");
var web=Read(archive,"artifacts/local/web.zip");
Check(Sha(web)==m.GetProperty("artifacts").GetProperty("local-web").GetProperty("sha256").GetString(),"WEB_SHA_MATCHES_MANIFEST");
var root=Path.Combine(Path.GetTempPath(),"r177-native-"+Guid.NewGuid().ToString("N"));
try{
 Directory.CreateDirectory(root);
 // Exercise the exact private candidate guard which failed on R171.
 var gate=typeof(SupervisorInactiveLocalCandidateCoordinator).GetMethod("ValidateCandidateTree",BindingFlags.NonPublic|BindingFlags.Static)
          ??throw new MissingMethodException("REAL_CANDIDATE_GATE_MISSING");
 var webZip=Path.Combine(root,"web.zip");File.WriteAllBytes(webZip,web);
 var targetDir=Path.Combine(root,"web");Directory.CreateDirectory(targetDir);
 ZipFile.ExtractToDirectory(webZip,targetDir);
 try{
   gate.Invoke(null,new object[]{targetDir,m.GetProperty("targets").GetProperty("local").GetProperty("version").GetString()!,
      m.GetProperty("targets").GetProperty("local").GetProperty("managed_tree_sha256").GetString()!,589});
 }catch(TargetInvocationException e){throw new InvalidOperationException("NATIVE_PRIVATE_CANDIDATE_REJECTED",e.InnerException);}
 Check(true,"ACTUAL_PRIVATE_CANDIDATE_589_OF_589_PASSED");
 var baselines=m.GetProperty("supported_baselines").EnumerateArray().Select(x=>Identity(x.GetProperty("local"))).ToArray();
 var previous=baselines.Single(x=>x.Version.Contains("231er-r176",StringComparison.Ordinal));
 var paths=new MaintenancePaths(Path.Combine(root,"maintenance"));Directory.CreateDirectory(paths.InboxRoot);
 var bytes=File.ReadAllBytes(package);var inbox=Path.Combine(paths.InboxRoot,Sha(bytes)+".zip");File.WriteAllBytes(inbox,bytes);
 var verifier=new ReleaseSetVerifierStager(paths);
 foreach(var id in baselines){
   var outcome=verifier.VerifyOnly(inbox,Installed(id));
   Check(outcome.ExactBaselineVerified == true && outcome.ArtifactIntegrityVerified && outcome.OperatorAuthorized &&
         !outcome.StagePerformed && !outcome.DatabaseMutationPerformed,"EXACT_READ_ONLY_"+id.ReleaseId);
 }
 Check(true,"ALL_55_KNOWN_BASELINES_PASS");
 var unsupported=previous with{ReleaseId=previous.ReleaseId+"-unsupported"};
 bool blocked=false;
 try{verifier.VerifyOnly(inbox,Installed(unsupported));}
 catch(InvalidDataException e)when(e.Message.Contains("exact supported baseline",StringComparison.OrdinalIgnoreCase)){blocked=true;}
 Check(blocked,"UNKNOWN_BASELINE_REJECTED");
 var stage=verifier.VerifyAndStage(inbox,Installed(previous));
 Check(stage.OperatorAuthorized && !stage.DatabaseMutationPerformed,"R176_SOURCE_NATIVE_STAGE_SUCCESS");
 Check(Directory.Exists(paths.StagingRoot),"STAGE_DIRECTORY_ONLY_ON_APPROVAL");
 Console.WriteLine("PASS R177_NATIVE_WINDOWS_CANDIDATE_AND_STAGE_FROM_R176");
}finally{try{Directory.Delete(root,true);}catch{}}
