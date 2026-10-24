using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Diagnostics;
using Sokna.Maintenance.Core;

var root=Path.GetFullPath(args[0]);Directory.CreateDirectory(root);
void Need(bool ok,string why){if(!ok)throw new Exception(why);}
void Link(string path,string target){if(OperatingSystem.IsWindows()){var p=Process.Start(new ProcessStartInfo("cmd.exe", $"/c mklink /J \"{path}\" \"{target}\""){UseShellExecute=false,RedirectStandardOutput=true});p!.WaitForExit();Need(p.ExitCode==0,"junction failed");}else Directory.CreateSymbolicLink(path,target);}
void Write(string p,string text){Directory.CreateDirectory(Path.GetDirectoryName(p)!);File.WriteAllText(p,text);}
string Hash(string p)=>Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(p))).ToLowerInvariant();
void Seed(string dir,string version){Write(Path.Combine(dir,"VERSION.txt"),version);Write(Path.Combine(dir,"public","index.php"),"<?php echo 'fixture';");Write(Path.Combine(dir,"resources","local-managed-files-v1.json"),JsonSerializer.Serialize(new{format="sokna-local-managed-files-v1",files=new[]{"VERSION.txt","public/index.php","resources/local-managed-files-v1.json"}}));}
var results=new List<object>();
foreach(var scenario in new[]{"both-missing","active-missing","persistent-missing","pair-present","foreign-active","foreign-persistent","redirected-public","managed-drift","pointer-mismatch"}){
 var lab=Path.Combine(root,scenario);Directory.CreateDirectory(lab);var active=Path.Combine(lab,"Web.release.baseline");var source=Path.Combine(lab,"source");var persistent=Path.Combine(lab,"LocalWeb","persistent");Directory.CreateDirectory(persistent);Directory.CreateDirectory(active);Directory.CreateDirectory(source);
 Seed(active,"231bk");Seed(source,"231bk-cycle1");var web=Path.Combine(lab,"Web");Link(web,active);
 var id="fixture-local-installation";Write(Path.Combine(lab,"install.lock"),JsonSerializer.Serialize(new{format="sokna-install-lock-v3",installation_id=id}));
 var bind=JsonSerializer.Serialize(new{format="sokna-local-installation-binding-v1",installation_id=id,web_version="231bk"});Write(Path.Combine(active,".sokna-installation.json"),bind);Write(Path.Combine(persistent,".sokna-installation.json"),bind);
 var paths=new MaintenancePaths(Path.Combine(lab,"maintenance"));Write(paths.InstalledReleaseIdentityPath,JsonSerializer.Serialize(new{local=new{version="231bk",release_id="fixture-baseline",managed_tree_sha256=LocalManagedTreeAttestation.Compute(active,3),authority_epoch_policy="preserved"}}));
 using var stream=typeof(StableLocalRecoveryOverlayRepair).Assembly.GetManifestResourceStream("Sokna.Maintenance.Core.StableLocalRecoveryPhp")!;using var memory=new MemoryStream();stream.CopyTo(memory);var payload=memory.ToArray();
 var a=Path.Combine(active,"public","local-recovery.php");var b=Path.Combine(persistent,"public","local-recovery.php");
 if(scenario is "persistent-missing" or "pair-present")File.WriteAllBytes(a,payload);
 if(scenario is "active-missing" or "pair-present") {Directory.CreateDirectory(Path.GetDirectoryName(b)!);File.WriteAllBytes(b,payload);}
 if(scenario=="foreign-active")Write(a,"foreign-active-do-not-overwrite");
 if(scenario=="foreign-persistent")Write(b,"foreign-persistent-do-not-overwrite");
 if(scenario=="redirected-public"){var outside=Path.Combine(root,"outside");Directory.CreateDirectory(outside);Link(Path.Combine(persistent,"public"),outside);}
 if(scenario=="managed-drift")Write(Path.Combine(active,"public","index.php"),"drift");
 var layout=new LocalInstallLayout(lab,web,Path.Combine(web,"public"),"unused","unused","unused","231bk",3,true,scenario=="pointer-mismatch"?source:active);
 var reject=scenario.StartsWith("foreign")||scenario is "redirected-public" or "managed-drift" or "pointer-mismatch";
 var identityHash=Hash(paths.InstalledReleaseIdentityPath);var lockHash=Hash(Path.Combine(lab,"install.lock"));var bindHash=Hash(Path.Combine(active,".sokna-installation.json"));
 if(scenario=="both-missing"){
  try{ProductionInactiveLocalSlotWriter.CopyToNewSibling(source,Path.Combine(lab,"Web.release.before"),lab,web,"231bk","231bk-cycle1",3,LocalManagedTreeAttestation.Compute(source,3));throw new Exception("missing guard failed open");}
  catch(InvalidDataException ex) when(ex.Message=="INACTIVE_SLOT_STABLE_RECOVERY_MISSING"){}
 }
 try{
  var outcome=StableLocalRecoveryOverlayRepair.EnsureVerifiedInstallation(paths,layout);
  Need(!reject,"unsafe overlay accepted: "+scenario);
  Need(StableLocalRecoveryOverlayRepair.EnsureVerifiedInstallation(paths,layout)=="verified_unchanged","repeat not no-op");
  Need(File.ReadAllBytes(a).SequenceEqual(payload)&&File.ReadAllBytes(b).SequenceEqual(payload),"pair readback");
  var incoming=Path.Combine(lab,"Web.release.next.incoming");
  ProductionInactiveLocalSlotWriter.CopyToNewSibling(source,incoming,lab,web,"231bk","231bk-cycle1",3,LocalManagedTreeAttestation.Compute(source,3));
  Need(File.ReadAllBytes(Path.Combine(incoming,"public","local-recovery.php")).SequenceEqual(payload),"next slot lost recovery");
  RoutineLocalBinding.RequireTarget(persistent,incoming,lab,"231bk-cycle1");
  Need(new DirectoryInfo(web).ResolveLinkTarget(true)!.FullName==active,"active pointer mutated");
  results.Add(new{scenario,result="PASS",outcome,real_sibling_copy="PASS"});
 }catch(InvalidDataException ex) when(reject){
  if(scenario=="foreign-active")Need(File.ReadAllText(a)=="foreign-active-do-not-overwrite"&&!File.Exists(b),"foreign active overwritten/partial write");
  if(scenario=="foreign-persistent")Need(File.ReadAllText(b)=="foreign-persistent-do-not-overwrite"&&!File.Exists(a),"foreign persistent overwritten/partial write");
  results.Add(new{scenario,result="PASS rejected",reason=ex.Message});
 }
 Need(Hash(paths.InstalledReleaseIdentityPath)==identityHash&&Hash(Path.Combine(lab,"install.lock"))==lockHash&&Hash(Path.Combine(active,".sokna-installation.json"))==bindHash,"identity/lock/binding mutated");
}
File.WriteAllText(Path.Combine(root,"RECOVERY-OVERLAY-PROOF.json"),JsonSerializer.Serialize(results,new JsonSerializerOptions{WriteIndented=true}));Console.WriteLine("PASS: nine recovery overlay scenarios; real sibling copy, exact payload, rejection, repeat, binding and pointer invariance.");
