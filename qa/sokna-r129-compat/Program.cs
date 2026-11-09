using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

if (args.Length == 3 && args[0] == "verify-installed-maintenance-identity") {
    var paths = new MaintenancePaths(args[2]);
    MaintenanceIdentityVersionProof.Require(File.ReadAllBytes(args[1]), paths.InstalledReleaseIdentityPath, paths.DataRoot);
    Console.WriteLine("PASS actual installed binaries, commit receipt and identity scope"); return;
}
if(Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" || Environment.GetEnvironmentVariable("RUNNER_OS")!="Windows")
    throw new InvalidOperationException("DISPOSABLE_WINDOWS_ONLY");
if(args.Length==3 && args[0] is "exercise-live-r129" or "exercise-integrated-r125"){
    var root=Path.GetFullPath(args[1]);
    var temp=Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP")!)+Path.DirectorySeparatorChar;
    if(!root.StartsWith(temp,StringComparison.OrdinalIgnoreCase)||!File.Exists(Path.Combine(root,".u01-ci-only")))
        throw new InvalidOperationException("REFUSE_NON_CI_NATIVE_ROOT");
    if(args[0]=="exercise-integrated-r125") await IntegratedR125.RunAsync(root,args[2]);
    else LiveR129.Run(root);return;
}
void Need(bool ok,string label){if(!ok)throw new Exception(label);Console.WriteLine("PASS "+label);}
void Reject(Action action,string label){try{action();}catch(Exception e)when(e is IOException or InvalidDataException or InvalidOperationException or System.Text.Json.JsonException){Console.WriteLine("PASS reject "+label);return;}throw new Exception("UNSAFE "+label);}
using(var f=new Fixture()){
 var before=SHA256.HashData(File.ReadAllBytes(f.State));
 f.Check();
 Need(SHA256.HashData(File.ReadAllBytes(f.State)).SequenceEqual(before),"R129 qualification is read only; historical DB flag retained");
 Need(JsonNode.Parse(File.ReadAllText(f.State))!["database_mutation_performed"]!.GetValue<bool>(),"historical mutation remains true");
}
foreach(var test in new[]{"no-receipt","duplicate-receipt","original-tamper","receipt-scope","current-local-change","flag-cleared","extra-history","operation-changed","backup-tampered","intent-changed","original-public-mutation"}){
 using var f=new Fixture();
 switch(test){
 case "no-receipt":File.Delete(f.Receipt);break;
 case "duplicate-receipt":File.Copy(f.Receipt,Path.Combine(f.Paths.LocalSlotStateRoot,"r125-reconcile-intent-other.json"));break;
 case "original-tamper":File.AppendAllText(f.Original," ");break;
 case "receipt-scope":f.Edit(f.Receipt,x=>x["db_schema_head"]="0079_wrong");break;
 case "current-local-change":f.Edit(f.State,x=>x["current_managed_tree_sha256"]="different");break;
 case "flag-cleared":f.Edit(f.State,x=>x["database_mutation_performed"]=false);break;
 case "extra-history":f.Edit(f.State,x=>(x["events"] as JsonArray)!.Add(new JsonObject{["state"]="OTHER"}));break;
 case "operation-changed":f.Edit(f.Operation,x=>x["package_ref"]="wrong");break;
 case "backup-tampered":File.AppendAllText(f.Backup," ");break;
 case "intent-changed":f.Edit(f.Intent,x=>x["reason"]="anything");break;
 case "original-public-mutation":f.Edit(f.Original,x=>x["public_mutation_performed"]=true);f.RehashOriginal();break;
 }
 Reject(f.Check,test);
}
var oldIdentity=Encoding.UTF8.GetBytes("{\"maintenance_supervisor_version\":\"0.3.22\",\"update_engine_version\":\"0.3.22\",\"local\":{\"version\":\"R112\"},\"public\":{\"keep\":true}}");
var current=Encoding.UTF8.GetBytes("{\"maintenance_supervisor_version\":\"0.3.25\",\"update_engine_version\":\"0.3.25\",\"local\":{\"version\":\"R112\"},\"public\":{\"keep\":true}}");
Need(MaintenanceIdentityVersionProof.RequireOnlyVersionChange(oldIdentity,current)=="0.3.25","only two Maintenance identity fields can differ");
Reject(()=>MaintenanceIdentityVersionProof.RequireOnlyVersionChange(oldIdentity,Encoding.UTF8.GetBytes(Encoding.UTF8.GetString(current).Replace("R112","R149"))),"Local identity cannot be masked by Maintenance upgrade");
Need(R125ReadOnlyAssessment.Code(new InvalidDataException("password=secret/private/path"))=="R125_READONLY_EVIDENCE_UNAVAILABLE","unknown error details are redacted");
Reject(()=>MaintenanceIdentityVersionProof.RequireOnlyVersionChange(current,oldIdentity),"component version downgrade");
var missingNullable=Encoding.UTF8.GetBytes("{\"maintenance_supervisor_version\":\"0.3.22\",\"update_engine_version\":\"0.3.22\",\"local\":{\"version\":\"R112\"}}");
var withNullable=Encoding.UTF8.GetBytes("{\"maintenance_supervisor_version\":\"0.3.26\",\"update_engine_version\":\"0.3.26\",\"local\":{\"version\":\"R112\",\"authority_epoch_policy\":null},\"backup_recovery_engine_version\":null}");
Need(MaintenanceIdentityVersionProof.RequireOnlyVersionChange(missingNullable,withNullable)=="0.3.26","known optional absent/null identity fields preserve meaning");
Reject(()=>MaintenanceIdentityVersionProof.RequireOnlyVersionChange(missingNullable,Encoding.UTF8.GetBytes(Encoding.UTF8.GetString(withNullable).Replace("\"authority_epoch_policy\":null","\"authority_epoch_policy\":\"changed\""))),"non-null identity authority cannot be ignored");
var emptyRoot=Path.Combine(Environment.GetEnvironmentVariable("RUNNER_TEMP")!,"sokna-no-r125-"+Guid.NewGuid().ToString("N"));
var noProof=await R125RecoveryWorkflow.RunAsync(new MaintenancePaths(emptyRoot),false);
Need(noProof.ExitCode==0&&(string?)noProof.Report["status"]=="NOT_REQUIRED"&&!Directory.Exists(emptyRoot),"no-proof workflow creates no state and sends no mutation");
Console.WriteLine("PASS R129 full evidence chain and identity compatibility regressions; no physical café approval");

sealed class Fixture:IDisposable{
 public MaintenancePaths Paths{get;} public string State{get;} public string Receipt{get;} public string Original{get;}
 public string Operation{get;} public string Backup{get;} public string Intent{get;}
 readonly string root; readonly RoutineMigrationPlan plan; readonly bool supplied;
 readonly string Build="3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat";
 static string Sha(byte[] b)=>Convert.ToHexString(SHA256.HashData(b)).ToLowerInvariant();
 static void Write(string p,JsonObject d)=>File.WriteAllText(p,d.ToJsonString());
 public Fixture(MaintenancePaths? actualPaths=null,JsonObject? actualRoutine=null,RoutineMigrationPlan? actualPlan=null,RoutineDatabaseBackup? actualBackup=null){
  supplied=actualPaths is not null;
  root=Path.Combine(Environment.GetEnvironmentVariable("RUNNER_TEMP")!,"sokna-r129-evidence-"+Guid.NewGuid().ToString("N"));
  Paths=actualPaths??new MaintenancePaths(Path.Combine(root,"Maintenance"));Directory.CreateDirectory(Paths.LocalSlotStateRoot);Directory.CreateDirectory(Paths.OperationsRoot);
  var suffix="fixture";var id=Guid.NewGuid().ToString("N");
  State=Path.Combine(Paths.LocalSlotStateRoot,"routine-state.json");Original=Path.Combine(Paths.LocalSlotStateRoot,"r125-reconcile-original-"+suffix+".json");
  Receipt=Path.Combine(Paths.LocalSlotStateRoot,"r125-reconcile-intent-"+suffix+".json");
  Operation=Path.Combine(Paths.OperationsRoot,"op-"+id+".json");
  Directory.CreateDirectory(Path.Combine(Paths.LocalSlotStateRoot,"private-db-backups"));
  Backup=actualBackup?.Path??Path.Combine(Paths.LocalSlotStateRoot,"private-db-backups","before.sql");
  if(actualBackup is null)File.WriteAllText(Backup,new string('-',256));var bh=Sha(File.ReadAllBytes(Backup));
  plan=new("migration-ledger-sha256-v1:"+new string('a',64),"migration-ledger-sha256-v1:"+new string('a',64),"0076_late_served_accounting","0076_late_served_accounting",76,76);
  if(actualPlan is not null)plan=actualPlan;
  if(actualRoutine is not null)Build=actualRoutine["current_build"]!.GetValue<string>();
  var old=new JsonObject{["format"]="sokna-local-slot-routine-state-v1",["schema_version"]=1,["phase"]="RECOVERY_REQUIRED",["current_build"]=Build,
   ["target_build"]="R125",["recovery_required"]=true,["database_mutation_performed"]=true,["public_mutation_performed"]=false,
   ["windows_reboot_required"]=false,["activation_performed"]=false,["maintenance_identity_commit_expected"]=true,
   ["updated_at_utc"]="2026-11-06T00:00:00Z",["current_managed_tree_sha256"]="source-tree",
   ["events"]=new JsonArray(new JsonObject{["state"]="RECOVERY_REQUIRED"}),
   ["migrationPlan"]=new JsonObject{["sourceFingerprint"]=plan.SourceFingerprint,["sourceHead"]=plan.SourceHead,["sourceCount"]=76},
   ["databaseBackup"]=new JsonObject{["path"]=Backup,["sha256"]=bh,["bytes"]=256}};
  if(actualRoutine is not null)old=actualRoutine.DeepClone().AsObject();
  old["migrationPlan"]=new JsonObject{["sourceFingerprint"]=plan.SourceFingerprint,["sourceHead"]=plan.SourceHead,["sourceCount"]=plan.SourceCount};
  old["databaseBackup"]=new JsonObject{["path"]=Backup,["sha256"]=bh,["bytes"]=new FileInfo(Backup).Length};
  Write(Original,old);
  var packageRef=Guid.NewGuid().ToString("N");const string priorActor="ci:external-recovery";
  var opRecord=new MaintenanceOperation("sokna-maintenance-operation-v1",1,id,"ci-old-"+id,
   MaintenanceOperationStore.CanonicalRequestHash("update.local-code-only-activate",packageRef,priorActor),
   "update.local-code-only-activate",priorActor,"recovery_required",packageRef,
   DateTimeOffset.Parse("2026-11-06T00:00:00Z"),DateTimeOffset.Parse("2026-11-06T00:00:00Z"),1,
   "CODE_ONLY_EXECUTION_OUTCOME_UNPROVEN","synthetic native recovery incident",null,true,true);
  var originalOp=System.Text.Json.JsonSerializer.SerializeToNode(opRecord,AtomicFile.JsonOptions)!.AsObject();
  var opBefore=Path.Combine(Paths.LocalSlotStateRoot,"r125-reconcile-original-operation-"+suffix+".json");Write(opBefore,originalOp);
  Write(Receipt,new JsonObject{["format"]="sokna-r129-external-recovery-reconciliation-v1",["at_utc"]="2026-11-07T00:00:00Z",["recovered_build"]=Build,
   ["blocked_operation_id"]=id,["db_schema_head"]=plan.SourceHead,["backup_sha256"]=bh,["routine_state_original_sha256"]=Sha(File.ReadAllBytes(Original)),["operation_original_sha256"]=Sha(File.ReadAllBytes(opBefore))});
  old["phase"]="RECOVERED_TO_PREVIOUS_SLOT";old["recovery_required"]=false;old["updated_at_utc"]="2026-11-07T00:00:00Z";
  (old["events"] as JsonArray)!.Add(new JsonObject{["state"]="RECOVERED_TO_PREVIOUS_SLOT",["at_utc"]="2026-11-07T00:00:00Z",["detail"]="R125_EXTERNAL_EXACT_ROLLBACK_R112_PROVEN_BY_R129"});Write(State,old);
  originalOp["state"]="failed";originalOp["result_code"]="CODE_ONLY_ENGINE_ROLLED_BACK_VERIFIED";originalOp["database_mutation_performed"]=false;Write(Operation,originalOp);
  Intent=Path.Combine(Paths.LocalSlotStateRoot,"r125-emergency-recovery-intent.json");Write(Intent,new JsonObject{["format"]="sokna-r125-emergency-exact-revert-v1",["to_version"]=Build,["from_version"]=old["target_build"]!.DeepClone(),["backup_sha256"]=bh,["reason"]="0078_FIRST_GUARD_AND_ONLY_0077_EMPTY_TABLE"});
 }
 public void Edit(string path,Action<JsonObject> mutate){var j=JsonNode.Parse(File.ReadAllText(path))!.AsObject();mutate(j);Write(path,j);}
 public void RehashOriginal()=>Edit(Receipt,x=>x["routine_state_original_sha256"]=Sha(File.ReadAllBytes(Original)));
 public void Check()=>R129ExternalRollbackEvidence.Require(Paths,Build,plan);
 public void Dispose(){
  if(!supplied){Directory.Delete(root,true);return;}
  foreach(var p in new[]{State,Receipt,Original,Operation,Intent,Path.Combine(Paths.LocalSlotStateRoot,"r125-reconcile-original-operation-fixture.json")})File.Delete(p);
 }

}

static class LiveR129{
 public static JsonObject Original(string root,MaintenancePaths paths,string build,InstalledReleaseIdentity identity){
  var layout=new LocalSlotManager(paths).InspectHealthyCurrent();
  var inventory=JsonNode.Parse(File.ReadAllBytes(Path.Combine(layout.WebRoot,"resources","local-managed-files-v1.json")))!.AsObject()["files"]!.AsArray().Count;
  return new JsonObject{["format"]="sokna-local-slot-routine-state-v1",["schema_version"]=1,
   ["phase"]="RECOVERY_REQUIRED",["package_root"]=layout.PackageRoot,["stable_web_root"]=layout.WebRoot,
   ["apache_executable"]=Path.Combine(root,"Infrastructure","Apache","bin","httpd.exe"),["health_url"]="http://127.0.0.1:39831/login.php",
   ["previous_slot"]=layout.ActiveSlotTarget,["target_slot"]=Path.Combine(root,"Web.release.failed"),
   ["current_build"]=build,["current_managed_files"]=inventory,["current_managed_tree_sha256"]=identity.Local!.ManagedTreeSha256,
   ["target_build"]="3.0.0-g6.2-workspace.231dt-r125-local-inventory-repair-uat",["target_managed_files"]=541,["target_managed_tree_sha256"]=new string('b',64),
   ["activation_performed"]=false,["database_mutation_performed"]=true,["public_mutation_performed"]=false,["windows_reboot_required"]=false,
   ["recovery_required"]=true,["maintenance_identity_commit_expected"]=true,["updated_at_utc"]="2026-11-06T00:00:00Z",
   ["events"]=new JsonArray(new JsonObject{["state"]="RECOVERY_REQUIRED",["at_utc"]="2026-11-06T00:00:00Z"})};
 }
 public static void Run(string root){
  var paths=new MaintenancePaths(Path.Combine(root,"Maintenance"));
  Directory.CreateDirectory(paths.LocalSlotStateRoot);Directory.CreateDirectory(paths.OperationsRoot);
  var layout=new LocalSlotManager(paths).InspectHealthyCurrent();
  var build=layout.Build;var identity=AtomicFile.ReadJson<InstalledReleaseIdentity>(paths.InstalledReleaseIdentityPath);
  var plan=RoutineLocalDatabase.Plan(layout.WebRoot,layout.WebRoot);
  if(plan.SourceHead!="0076_late_served_accounting")throw new Exception("REQUIRE_NATIVE_76_CATALOG");
  var original=Original(root,paths,layout.Build,identity);
  var backup=RoutineLocalDatabase.Backup(root,layout.WebRoot,layout.WebRoot,plan,paths.LocalSlotStateRoot);
  var lifecycle=RoutineLocalLifecycle.Open(root,paths.LocalSlotStateRoot,build,paths,"ci-r129-failed-release",identity.Local.ManagedTreeSha256);
  lifecycle.SaveRollbackEvidence();
  var proof=Path.Combine(paths.LocalSlotStateRoot,"routine-local-lifecycle-before.json");
  var sha=Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(proof))).ToLowerInvariant();
  try{
   using var f=new Fixture(paths,original,plan,backup);
   SupervisorR125LifecycleEvidenceReconciler.RequireReadOnlyQualification(paths,proof,build,sha);
   Console.WriteLine("PASS actual native Web, lifecycle, identity, dump and MariaDB qualify R129 history");
   File.Move(f.Receipt,f.Receipt+".held");
   try{
    var report=R125ReadOnlyAssessment.Inspect(paths);
    if((string?)report["live_database_migration_ledger"]!="PASS_SELECT_ONLY"||
       (string?)report["r129_safe_code"]!="R129_RECEIPT_MISSING_OR_AMBIGUOUS")throw new Exception("READONLY_OBSERVATION_WRONGLY_BLOCKED");
    Console.WriteLine("PASS actual native DB remains observable when archive evidence is rejected");
   }finally{File.Move(f.Receipt+".held",f.Receipt);}
   if(Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(proof))).ToLowerInvariant()!=sha)throw new Exception("PROOF_CHANGED");
   Console.WriteLine("PASS actual native R129 qualification preserved proof and historical DB flag");
  }finally{File.Delete(proof);}
 }
}
