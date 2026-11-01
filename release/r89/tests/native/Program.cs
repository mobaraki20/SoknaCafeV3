using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;
var package=Path.GetFullPath(args[0]);var output=Path.GetFullPath(args[1]);var checks=new List<object>();
void Check(bool value,string name){checks.Add(new {test=name,pass=value});if(!value)throw new Exception(name);}
var hash=Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(package))).ToLowerInvariant();
using var zip=ZipFile.OpenRead(package);using var stream=zip.GetEntry("release-set.json")!.Open();using var buffer=new MemoryStream();stream.CopyTo(buffer);
var manifest=JsonSerializer.Deserialize<ReleaseSetManifest>(buffer.ToArray(),AtomicFile.JsonOptions)!;
var raw=JsonDocument.Parse(buffer.ToArray());var target=raw.RootElement.GetProperty("targets").GetProperty("local");
foreach(var baseline in manifest.SupportedBaselines){
 var old=baseline.Local!;var lab=Path.Combine(Path.GetTempPath(),"sokna-r89-"+Guid.NewGuid().ToString("N"));var paths=new MaintenancePaths(lab);Directory.CreateDirectory(paths.InboxRoot);var uploaded=Path.Combine(paths.InboxRoot,hash+".zip");File.Copy(package,uploaded);
 var installed=new InstalledReleaseIdentity(old,null,"0.3.18","0.3.18",null,null);var service=new ReleaseSetVerifierStager(paths);
 var p=service.VerifyOnly(uploaded,installed);Check(p.ExactBaselineVerified==true&&p.ArtifactIntegrityVerified&&p.OperatorAuthorized&&!p.DatabaseMutationPerformed,"native Local preflight "+old.Version);
 var s=service.VerifyAndStage(uploaded,installed);Check(s.PreflightIdentityMatch&&s.OperatorAuthorized,"native Local stage "+old.Version);
 var inner=Path.Combine(s.StageRoot,"artifacts","local","web.zip");var web=Path.Combine(lab,"candidate");ZipFile.ExtractToDirectory(inner,web);
 Check(LocalManagedTreeAttestation.Compute(web,target.GetProperty("managed_files").GetInt32())==target.GetProperty("managed_tree_sha256").GetString(),"native target materialization and attestation "+old.Version);
 var again=service.VerifyAndStage(uploaded,installed);Check(again.PackageSha256==s.PackageSha256,"native idempotent stage "+old.Version);
 var bad=installed with { Local=old with { ManagedTreeSha256=new string('0',64) } };bool refused=false;try{service.VerifyOnly(uploaded,bad);}catch(InvalidDataException){refused=true;}Check(refused,"native wrong baseline rejected "+old.Version);
}
Directory.CreateDirectory(Path.GetDirectoryName(output)!);File.WriteAllText(output,JsonSerializer.Serialize(checks,new JsonSerializerOptions{WriteIndented=true}));Console.WriteLine("R89_NATIVE_LOCAL_PASS "+checks.Count);
