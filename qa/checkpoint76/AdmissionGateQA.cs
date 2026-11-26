using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;
if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" || Environment.GetEnvironmentVariable("RUNNER_OS")!="Windows") throw new InvalidOperationException("CI_ONLY");
var zip=Path.GetFullPath(args[0]);var sandbox=Path.GetFullPath(args[1]);
if(Directory.Exists(sandbox) || !sandbox.StartsWith(Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP")!)+Path.DirectorySeparatorChar,StringComparison.OrdinalIgnoreCase))throw new InvalidOperationException("DISPOSABLE_ROOT_REQUIRED");
string Hash(string p)=>Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(p))).ToLowerInvariant();
void Need(bool b,string reason){if(!b)throw new InvalidDataException(reason);}
void Reject(Action f,string reason){try{f();}catch(InvalidDataException e){Need(e.Message.Contains(reason,StringComparison.OrdinalIgnoreCase),"Unexpected rejection: "+e.Message);return;}throw new InvalidDataException("Expected rejection: "+reason);}
const string pin="9f2859e4da6dd6d15ef64dae3b9d9ae24e64734be39a22349e914d6ed0707ac1";
Need(Hash(zip)==pin,"PACKAGE_HASH");
ReleaseSetManifest m;
using(var z=ZipFile.OpenRead(zip))using(var stream=z.GetEntry("release-set.json")!.Open())m=JsonSerializer.Deserialize<ReleaseSetManifest>(stream,AtomicFile.JsonOptions)!;
Need(!m.ForInstall && !m.ProductionPass && m.SupportedBaselines.Length==8,"QUARANTINED_ONLY");
var paths=new MaintenancePaths(sandbox);
Directory.CreateDirectory(paths.InboxRoot);
var inbox=Path.Combine(paths.InboxRoot,pin+".zip");File.Copy(zip,inbox);
var verify=new ReleaseSetVerifierStager(paths);
var checkedVersions=new List<string>();
foreach(var origin in m.SupportedBaselines){
 var id=new InstalledReleaseIdentity(origin.Local,null,"0.3.27","0.3.27",null,null);
 var p=verify.VerifyOnly(inbox,id);
 Need(p.PackageSha256==pin && p.ArtifactIntegrityVerified && p.ExactBaselineVerified==true && p.OperatorAuthorized && !p.SignatureVerified && !p.SignedInstallIntent && !p.PanelActivationReady && !p.StagePerformed && !p.ActivationPerformed && !p.DatabaseMutationPerformed,"PREFLIGHT_FLAGS");
 Reject(()=>verify.VerifyAndStage(inbox,id),"RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN");
 Reject(()=>SupervisorInactiveLocalCandidateCoordinator.RequireInstallableLegacyRelease(m,true),"LEGACY_RELEASE_NOT_QUALIFIED_FOR_INSTALL");
 Need(!Directory.Exists(paths.StagingRoot) && !File.Exists(paths.ReleaseSequenceStatePath),"UNEXPECTED_STAGE_OR_SEQUENCE");
 checkedVersions.Add(origin.Local!.Version);
}
var first=new InstalledReleaseIdentity(m.SupportedBaselines[0].Local,null,"0.3.27","0.3.27",null,null);
var wrong=first with {Local=first.Local! with {ManagedTreeSha256=new string('0',64)}};
Reject(()=>verify.VerifyOnly(inbox,wrong),"does not match any exact supported baseline");
Reject(()=>verify.VerifyOnly(zip,first),"signature metadata is invalid");
Need(Hash(inbox)==pin,"PACKAGE_MUTATED");
Need(!Directory.Exists(paths.StagingRoot) && !File.Exists(paths.ReleaseSequenceStatePath),"MUTATION");
var result=new{status="PASS",package_sha256=pin,verified_declared_baselines=checkedVersions,quarantine_stage_rejected=true,wrong_tree_rejected=true,unsigned_outside_inbox_rejected=true,stage_or_sequence_created=false,actual_panel_activation_tested=false,distribution_authorized=false};
File.WriteAllText(args[2],JsonSerializer.Serialize(result,AtomicFile.JsonOptions));Console.WriteLine("PASS EXACT PACKAGE DIAGNOSTIC PREFLIGHT AND FAIL-CLOSED STAGE ON ALL 8 DECLARED BASELINES");
