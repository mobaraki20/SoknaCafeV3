using Sokna.Maintenance.Core;
using System.Text.Json;
using System.IO.Compression;
var web=Path.GetFullPath(args[0]);using var zip=ZipFile.OpenRead(Path.GetFullPath(args[1]));using var stream=zip.GetEntry("release-set.json")!.Open();var m=JsonSerializer.Deserialize<ReleaseSetManifest>(stream,AtomicFile.JsonOptions)!;var installed=AtomicFile.ReadJson<InstalledReleaseIdentity>(Path.GetFullPath(args[2]));var current=m.SupportedBaselines.Single(b=>b.Local?.Version==File.ReadAllText(Path.Combine(web,"VERSION.txt")).Trim()).Local!;
if(LocalManagedTreeAttestation.Compute(web,534)!=current.ManagedTreeSha256)throw new Exception("Physical fixture differs from authorized baseline");
bool rejected=false;try{if(!SupervisorSignedLocalIdentityRebind.AttestExternalBaseline(installed.Local!,current,web,Path.GetFullPath(args[3])))throw new Exception("Expected reconciliation");}catch(InvalidDataException e){if(!e.Message.StartsWith("LOCAL_DATABASE_INSPECT_FAILED_STATUS_"))throw;rejected=true;}
if(rejected!=(args[4]=="reject"))throw new Exception("Unexpected live database attestation outcome");Console.WriteLine("CSHARP_LIVE_DATABASE_"+args[4].ToUpperInvariant()+"_PASS");
