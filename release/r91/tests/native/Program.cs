using Sokna.Maintenance.Core;
using System.Text.Json;
using System.Security.Cryptography;
var web=Path.GetFullPath(args[0]);var identityFile=Path.GetFullPath(args[1]);var package=Path.GetFullPath(args[2]);var output=Path.GetFullPath(args[3]);var rows=new List<object>();
void Need(bool pass,string name){rows.Add(new{test=name,pass});if(!pass)throw new Exception(name);}
void Denied(Action action,string name){bool rejected=false;try{action();}catch(InvalidDataException){rejected=true;}Need(rejected,name);}
var installed=AtomicFile.ReadJson<InstalledReleaseIdentity>(identityFile);var old=installed.Local!;
using var zip=System.IO.Compression.ZipFile.OpenRead(package);using var stream=zip.GetEntry("release-set.json")!.Open();var manifest=JsonSerializer.Deserialize<ReleaseSetManifest>(stream,AtomicFile.JsonOptions)!;
var build=File.ReadAllText(Path.Combine(web,"VERSION.txt")).Trim();var candidates=manifest.SupportedBaselines.Select(b=>b.Local).Where(b=>b?.Version==build).ToArray();Need(candidates.Length==1,"one exact Cafe baseline in universal archive");var current=candidates[0]!;
Need(LocalManagedTreeAttestation.Compute(web,534)==current.ManagedTreeSha256,"physical Cafe source attests to complete 534-file baseline");
Need(old.MigrationHead=="0074_iran_time_notification_boundaries"&&old.AuthorityEpochPolicy is null,"actual Cafe input retains historical head and null policy");
Need(SupervisorSignedLocalIdentityRebind.RequiresLiveDatabaseReconciliation(old,current,web),"actual stale Cafe metadata requires independent live database proof");
RoutineLocalDatabase.RequireHistoricalPrefix(web,old.SchemaFingerprint,old.MigrationHead);Need(true,"actual trusted historical fingerprint is exact prefix of physical catalog");
Denied(()=>SupervisorSignedLocalIdentityRebind.RequiresLiveDatabaseReconciliation(old with{SchemaFingerprint="wrong"},current,web),"unknown historical schema rejected");
Denied(()=>SupervisorSignedLocalIdentityRebind.RequiresLiveDatabaseReconciliation(old with{MigrationHead="9999_future"},current,web),"future historical head rejected");
Denied(()=>SupervisorSignedLocalIdentityRebind.RequiresLiveDatabaseReconciliation(old,current with{AuthorityEpochPolicy="rotate"},web),"authority rotation never adopted as preservation");
Denied(()=>SupervisorSignedLocalIdentityRebind.RequiresLiveDatabaseReconciliation(old,current with{SchemaFingerprint="wrong"},web),"unproven current schema rejected");
Need(!SupervisorSignedLocalIdentityRebind.RequiresLiveDatabaseReconciliation(current,current,web),"already current schema does not invent database reconciliation");
// Consume the actual installer-produced identity. This mode is supplied only after
// the final scoped EXE has preserved the uploaded Cafe tuple and updated engine versions.
Need(installed.MaintenanceSupervisorVersion=="0.3.19"&&installed.UpdateEngineVersion=="0.3.19","actual installed engine metadata readback is 0.3.19");
var lab=Path.Combine(Path.GetTempPath(),"sokna-r91-native-"+Guid.NewGuid().ToString("N"));var paths=new MaintenancePaths(lab);Directory.CreateDirectory(paths.InboxRoot);var hash=Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(package))).ToLowerInvariant();var inbox=Path.Combine(paths.InboxRoot,hash+".zip");File.Copy(package,inbox);
var verifier=new ReleaseSetVerifierStager(paths);Denied(()=>verifier.VerifyOnly(inbox,installed),"actual stale identity cannot directly bypass preflight");
// The tuple below is the exact verified physical baseline; live SQL qualification
// of this transition runs separately against the embedded production PHP helper.
var qualified=installed with{Local=current};var pre=verifier.VerifyOnly(inbox,qualified);Need(pre.ExactBaselineVerified==true&&pre.OperatorAuthorized&&!pre.DatabaseMutationPerformed,"Core consumes reconciled exact tuple without DB mutation");
var staged=verifier.VerifyAndStage(inbox,qualified);Need(staged.PreflightIdentityMatch&&staged.OperatorAuthorized,"Core Stage accepts only verified physical tuple");
Directory.CreateDirectory(Path.GetDirectoryName(output)!);File.WriteAllText(output,JsonSerializer.Serialize(rows,new JsonSerializerOptions{WriteIndented=true}));Console.WriteLine("R91_NATIVE_PASS "+rows.Count);
