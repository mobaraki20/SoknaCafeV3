using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

if(Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" || Environment.GetEnvironmentVariable("RUNNER_OS")!="Windows")throw new InvalidOperationException("CI_ONLY");
var pkg=Path.GetFullPath(args[0]);var root=Path.GetFullPath(args[1]);
if(Directory.Exists(root)||!root.StartsWith(Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP")!)+Path.DirectorySeparatorChar,StringComparison.OrdinalIgnoreCase))throw new InvalidOperationException("DISPOSABLE_ROOT_REQUIRED");
string Hash(string p)=>Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(p))).ToLowerInvariant();
void Need(bool b,string why){if(!b)throw new InvalidDataException(why);}
const string pin="63f7db98d092fc7f40b785fbae0864aab810e3d277598de0794bff0bc92809a5";
Need(Hash(pkg)==pin,"PACKAGE_HASH");
byte[] manifestBytes,web;
using(var z=ZipFile.OpenRead(pkg)){
 using(var s=z.GetEntry("release-set.json")!.Open())using(var b=new MemoryStream()){s.CopyTo(b);manifestBytes=b.ToArray();}
 using(var s=z.GetEntry("artifacts/local/web.zip")!.Open())using(var b=new MemoryStream()){s.CopyTo(b);web=b.ToArray();}
}
var m=JsonSerializer.Deserialize<ReleaseSetManifest>(manifestBytes,AtomicFile.JsonOptions)!;
Need(!m.ForInstall&&!m.ProductionPass&&m.SupportedBaselines.Length==7,"QA_ONLY");
var paths=new MaintenancePaths(root);Directory.CreateDirectory(paths.InboxRoot);
var inbox=Path.Combine(paths.InboxRoot,pin+".zip");File.Copy(pkg,inbox);
var verifier=new ReleaseSetVerifierStager(paths);
var cases=new List<object>();var names=new HashSet<string>();
void Reject(string name,Action action,string expected){
 Need(names.Add(name),"DUPLICATE_CASE");
 try{action();}
 catch(InvalidDataException ex){
  Need(ex.Message.Contains(expected,StringComparison.OrdinalIgnoreCase),"WRONG_REJECTION "+name+" "+ex.Message);
  Need(!Directory.Exists(paths.StagingRoot)&&!File.Exists(paths.ReleaseSequenceStatePath),"STATE_MUTATED "+name);
  cases.Add(new {name,status="PASS",reason=ex.Message});Console.WriteLine("PASS "+name);return;
 }
 throw new InvalidDataException("UNEXPECTED_ADMISSION "+name);
}
var first=new InstalledReleaseIdentity(m.SupportedBaselines[0].Local,null,"0.3.27","0.3.27",null,null);
for(int k=0;k<m.SupportedBaselines.Length;k++){
 var v=m.SupportedBaselines[k].Local!;
 var id=new InstalledReleaseIdentity(v,null,"0.3.27","0.3.27",null,null);
 var variants=new (string name,ReleaseComponentIdentity value)[]{
  ("release_id",v with {ReleaseId=v.ReleaseId+"-wrong"}),
  ("version",v with {Version=v.Version+"-wrong"}),
  ("tree",v with {ManagedTreeSha256=new string('0',64)}),
  ("fingerprint",v with {SchemaFingerprint=v.SchemaFingerprint+"-wrong"}),
  ("migration_head",v with {MigrationHead=v.MigrationHead+"-wrong"}),
  ("authority_policy",v with {AuthorityEpochPolicy="wrong-policy"})};
 foreach(var x in variants){
  if(k==0&&x.name=="tree")continue; // already passed in checkpoint63
  Reject($"baseline_{k}_{x.name}",()=>verifier.VerifyOnly(inbox,id with {Local=x.value}),"does not match any exact supported baseline");
 }
 Reject($"baseline_{k}_supervisor_min",()=>verifier.VerifyOnly(inbox,id with {MaintenanceSupervisorVersion="0.0.1"}),"Maintenance Supervisor does not meet");
 Reject($"baseline_{k}_engine_min",()=>verifier.VerifyOnly(inbox,id with {UpdateEngineVersion="0.0.1"}),"Update Engine does not meet");
}
void Variant(string name,Action<JsonObject> alter,string expected,bool omitWeb=false){
 var obj=JsonNode.Parse(manifestBytes)!.AsObject();alter(obj);
 Need(obj["for_install"]!.GetValue<bool>()==false&&obj["production_pass"]!.GetValue<bool>()==false,"VARIANT_QUARANTINE");
 var tmp=Path.Combine(root,"variant.zip");
 using(var z=ZipFile.Open(tmp,ZipArchiveMode.Create)){
  using(var s=z.CreateEntry("release-set.json").Open())s.Write(JsonSerializer.SerializeToUtf8Bytes(obj));
  if(!omitWeb)using(var s=z.CreateEntry("artifacts/local/web.zip",CompressionLevel.NoCompression).Open())s.Write(web);
 }
 var dst=Path.Combine(paths.InboxRoot,Hash(tmp)+".zip");File.Move(tmp,dst);
 try{Reject("manifest_"+name,()=>verifier.VerifyOnly(dst,first),expected);}finally{File.Delete(dst);}
}
Variant("format",x=>x["format"]="unknown","Unsupported Release Set format/schema");
Variant("schema",x=>x["schema_version"]="9.9","Unsupported Release Set format/schema");
Variant("status",x=>x["status"]="","Design/empty Release Set status");
Variant("release_id",x=>x["release_set_id"]="","release_set_id is invalid");
Variant("sequence",x=>x["sequence"]=0,"sequence must be positive");
Variant("release_type",x=>x["release_type"]="unknown","release_type is unsupported");
Variant("requirements",x=>x.Remove("requirements"),"missing required contract sections");
Variant("empty_artifacts",x=>x["artifacts"]=new JsonObject(),"artifact count is invalid");
Variant("empty_baselines",x=>x["supported_baselines"]=new JsonArray(),"requires at least one exact supported baseline");
Variant("empty_plan",x=>x["install_plan"]=new JsonArray(),"install plan must begin");
Variant("mutating_preflight",x=>x["install_plan"]![0]!["mutation"]=true,"install plan must begin");
Variant("invalid_step",x=>x["install_plan"]![1]!["phase"]="","install plan contains an invalid step");
Variant("guest_policy",x=>x["mutation_policy"]!["guest"]="wrong","mutation policy contains an unsupported value");
Variant("staff_policy",x=>x["mutation_policy"]!["staff_public"]="wrong","mutation policy contains an unsupported value");
Variant("local_policy",x=>x["mutation_policy"]!["local"]="wrong","mutation policy contains an unsupported value");
Variant("backup_policy",x=>x["pre_update_backup_policy"]!["on_failure"]="allow","backup failure policy must reject");
Variant("backup_age",x=>x["pre_update_backup_policy"]!["recent_local_verified_max_age_seconds"]=0,"must be positive");
Variant("local_rollback",x=>x["database"]!["local"]!["rollback_after_boundary"]="allow","Rollback after DB forward boundary must be forbidden");
Variant("public_rollback",x=>x["database"]!["public"]!["rollback_after_boundary"]="allow","Rollback after DB forward boundary must be forbidden");
Variant("postdb",x=>x["recovery"]!["post_db"]="wrong","Post-DB recovery policy");
Variant("predb",x=>x["recovery"]!["pre_db"]="wrong","recovery/restore policy is incompatible");
Variant("business_restore",x=>x["recovery"]!["business_backup_restore"]="wrong","recovery/restore policy is incompatible");
Variant("missing_payload",x=>{},"Declared artifact is missing",true);
Variant("size",x=>x["artifacts"]!["local-web"]!["size_bytes"]=0,"Artifact size mismatch");
Variant("sha",x=>x["artifacts"]!["local-web"]!["sha256"]=new string('0',64),"Artifact SHA256 mismatch");
Need(cases.Count==80&&Hash(inbox)==pin,"MATRIX_COUNT_OR_PIN");
File.WriteAllText(args[2],JsonSerializer.Serialize(new {status="PASS",package_sha256=pin,case_count=cases.Count,cases,scope="actual_readonly_native_verifier_negative_matrix",production_qualification_proven=false,canary_proven=false,distribution_authorized=false},AtomicFile.JsonOptions));
Console.WriteLine("PASS 80 DISTINCT NEW REAL NATIVE VERIFIER NEGATIVE CASES; NOT PRODUCTION APPROVAL");
