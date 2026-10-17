using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

static void Check(bool ok, string label) { if(!ok) throw new InvalidOperationException("FAIL: "+label); Console.WriteLine("PASS "+label); }
static void ExpectBlocked(Action a, string label) { try { a(); } catch (InvalidDataException) { Console.WriteLine("PASS blocked "+label); return; } throw new InvalidOperationException("FAIL allow "+label); }
var baseDir=Path.Combine(Path.GetTempPath(),"sokna-cross-version-"+Guid.NewGuid().ToString("N"));
const string oldBuild="3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory";
const string newBuild="3.0.0-g6.2-workspace.231ap-connections-device-lifecycle-closure";
const string identity="home-fixture-installation-01";
Directory.CreateDirectory(baseDir);
try {
 var persistent=Path.Combine(baseDir,"persistent");var target=Path.Combine(baseDir,"Web.release.target");
 Directory.CreateDirectory(persistent);Directory.CreateDirectory(target);
 File.WriteAllText(Path.Combine(baseDir,"install.lock"),JsonSerializer.Serialize(new {format="sokna-install-lock-v3",installation_id=identity}));
 var binding=JsonSerializer.Serialize(new {format="sokna-local-installation-binding-v1",installation_id=identity,web_version=oldBuild,bound_at="2026-10-18T00:00:00Z",source="real-home-fixture"});
 File.WriteAllText(Path.Combine(persistent,".sokna-installation.json"),binding);
 File.WriteAllText(Path.Combine(target,".sokna-installation.json"),binding);
 var original=SHA256.HashData(File.ReadAllBytes(Path.Combine(persistent,".sokna-installation.json")));
 RoutineLocalBinding.PrepareTarget(persistent,target,baseDir,oldBuild,newBuild);
 RoutineLocalBinding.RequireTarget(persistent,target,baseDir,newBuild);
 Check(SHA256.HashData(File.ReadAllBytes(Path.Combine(persistent,".sokna-installation.json"))).SequenceEqual(original),"persistent binding unchanged");
 var dest=JsonNode.Parse(File.ReadAllText(Path.Combine(target,".sokna-installation.json")))!.AsObject();
 Check((string?)dest["web_version"]==newBuild && (string?)dest["installation_id"]==identity,"target binding identity/version");
 File.WriteAllText(Path.Combine(persistent,".sokna-installation.json"),binding.Replace(identity,"foreign"));
 ExpectBlocked(()=>RoutineLocalBinding.PrepareTarget(persistent,target,baseDir,oldBuild,newBuild),"foreign identity");
 File.WriteAllText(Path.Combine(persistent,".sokna-installation.json"),binding);
 ExpectBlocked(()=>RoutineLocalBinding.RequireCurrent(persistent,baseDir,newBuild),"source build mismatch");
 // Exercise lifecycle commit/restore through reflection without creating a fake Windows service.
 var life=Path.Combine(baseDir,"data","lifecycle","local");var evidence=Path.Combine(baseDir,"evidence");
 Directory.CreateDirectory(life); Directory.CreateDirectory(evidence);
 var statePath=Path.Combine(life,"state.json");var managedPath=Path.Combine(life,"managed-files.json");
 var state=JsonSerializer.Serialize(new {format="sokna-local-update-state-v1",active_version=oldBuild,staged=(string?)null,activation_journal=(string?)null,recovery_mode=(string?)null,history=new object[0]});
 File.WriteAllText(statePath,state);File.WriteAllText(managedPath,"{\"old\":true}");
 var stateHash=SHA256.HashData(File.ReadAllBytes(statePath)); var managedHash=SHA256.HashData(File.ReadAllBytes(managedPath));
 var resources=Path.Combine(target,"resources");Directory.CreateDirectory(resources);
 File.WriteAllText(Path.Combine(resources,"local-managed-files-v1.json"),"{\"format\":\"sokna-local-managed-files-v1\",\"files\":[{\"path\":\"VERSION.txt\"},{\"path\":\"bootstrap.php\"}]}");
 var typ=typeof(RoutineLocalBinding).Assembly.GetType("Sokna.Maintenance.Core.RoutineLocalLifecycle",true)!;
 var ctor=typ.GetConstructor(BindingFlags.NonPublic|BindingFlags.Instance,null,new[]{typeof(string),typeof(string),typeof(string),typeof(string)},null)!;
 var obj=ctor.Invoke(new object[]{statePath,managedPath,oldBuild,Path.Combine(evidence,"routine-local-lifecycle-before.json")});
 void Invoke(string method,params object[] args) {try {typ.GetMethod(method,BindingFlags.Public|BindingFlags.Instance)!.Invoke(obj,args);} catch(TargetInvocationException e) {throw e.InnerException!;} }
 Invoke("SaveRollbackEvidence"); Invoke("Commit",newBuild,target,2);
 Check((string?)JsonNode.Parse(File.ReadAllText(statePath))!["active_version"]==newBuild,"Local lifecycle committed");
 Invoke("Restore");
 Check(SHA256.HashData(File.ReadAllBytes(statePath)).SequenceEqual(stateHash) && SHA256.HashData(File.ReadAllBytes(managedPath)).SequenceEqual(managedHash),"Local lifecycle rollback byte exact");
 Invoke("Complete");Check(!File.Exists(Path.Combine(evidence,"routine-local-lifecycle-before.json")),"rollback evidence finalized");
 // Busy Local must never pass preflight.
 File.WriteAllText(statePath,JsonSerializer.Serialize(new{format="sokna-local-update-state-v1",active_version=oldBuild,staged=new {version=newBuild},activation_journal=(string?)null,recovery_mode=(string?)null}));
 try { ctor.Invoke(new object[]{statePath,managedPath,oldBuild,Path.Combine(evidence,"busy.json")}); throw new Exception("FAIL staged allowed"); }
 catch(TargetInvocationException ex) when(ex.InnerException is InvalidDataException) {Console.WriteLine("PASS busy Local rejected");}
 Console.WriteLine("WINDOWS_CROSS_VERSION_CONTRACT_PASS");
} finally { try {Directory.Delete(baseDir,recursive:true);}catch {} }