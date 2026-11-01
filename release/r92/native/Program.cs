using Sokna.Maintenance.Core;
using System.Text.Json;
using System.Security.Cryptography;
using System.IO.Compression;
var package=Path.GetFullPath(args[0]);var rows=new List<object>();
void Need(bool pass,string test){rows.Add(new{test,pass});if(!pass)throw new Exception(test);}
using var z=ZipFile.OpenRead(package);using var s=z.GetEntry("release-set.json")!.Open();var m=JsonSerializer.Deserialize<ReleaseSetManifest>(s,AtomicFile.JsonOptions)!;
var seed=AtomicFile.ReadJson<InstalledReleaseIdentity>("release/r91/fixtures/installed-cafe-identity.json");var hash=Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(package))).ToLowerInvariant();
foreach(var baseline in m.SupportedBaselines){var paths=new MaintenancePaths(Path.Combine(Path.GetTempPath(),"r92-"+Guid.NewGuid().ToString("N")));Directory.CreateDirectory(paths.InboxRoot);var p=Path.Combine(paths.InboxRoot,hash+".zip");File.Copy(package,p);var identity=seed with{Local=baseline.Local,MaintenanceSupervisorVersion="0.3.19",UpdateEngineVersion="0.3.19"};var core=new ReleaseSetVerifierStager(paths);Need(core.VerifyOnly(p,identity).ExactBaselineVerified==true,"exact preflight "+baseline.Local!.Version);Need(core.VerifyAndStage(p,identity).PreflightIdentityMatch,"stage "+baseline.Local.Version);bool rejected=false;try{core.VerifyOnly(p,identity with{Local=baseline.Local with{ManagedTreeSha256=new string('0',64)}});}catch(InvalidDataException){rejected=true;}Need(rejected,"drift rejected "+baseline.Local.Version);}
File.WriteAllText(args[1],JsonSerializer.Serialize(rows));Console.WriteLine("R92_NATIVE_PASS "+rows.Count);
