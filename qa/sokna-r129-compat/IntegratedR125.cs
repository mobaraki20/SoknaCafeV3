using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

// Actual disposable Apache/PHP/MariaDB, installed Windows service, exact Inno
// payload, actual historical receipt and HMAC HTTP route. No fake qualifier.
static class IntegratedR125
{
    static string Sha(string p)=>Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(p))).ToLowerInvariant();
    static void Need(bool ok,string name){if(!ok)throw new Exception(name);Console.WriteLine("PASS "+name);}
    static void Run(string exe,params string[] args){
        var info=new ProcessStartInfo(exe){UseShellExecute=false};
        foreach(var arg in args)info.ArgumentList.Add(arg);
        using var p=Process.Start(info)!;
        if(!p.WaitForExit(240_000)){p.Kill(true);throw new Exception("CI_PROCESS_TIMEOUT");}
        Need(p.ExitCode==0,"native command "+Path.GetFileName(exe));
    }
    static JsonObject Install(string installer,bool confirm){
        var desktop=Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
        Directory.CreateDirectory(desktop);
        var before=Directory.GetFiles(desktop,"SOKNA-MAINTENANCE-WORKFLOW-*.json").ToHashSet();
        var args=new List<string>{"/VERYSILENT","/SUPPRESSMSGBOXES","/NORESTART","/SP-"};
        if(confirm)args.Add("/CONFIRM-R125-ARCHIVE=YES");
        Run(installer,args.ToArray());
        var fresh=Directory.GetFiles(desktop,"SOKNA-MAINTENANCE-WORKFLOW-*.json").Where(p=>!before.Contains(p)).ToArray();
        Need(fresh.Length==1,"one integrated Desktop report per invocation");
        return JsonNode.Parse(File.ReadAllBytes(fresh[0]))!.AsObject();
    }
    public static async Task RunAsync(string root,string package){
        var paths=new MaintenancePaths(MaintenancePaths.DefaultDataRoot());
        var installer=Environment.GetEnvironmentVariable("SOKNA_TEST_INTEGRATED_INSTALLER")!;
        Need(File.Exists(installer)&&File.Exists(package),"exact installer and next package supplied");
        var layout=new LocalSlotManager(paths).InspectHealthyCurrent();
        var doc=JsonNode.Parse(File.ReadAllBytes(paths.InstalledReleaseIdentityPath))!.AsObject();
        doc["maintenance_supervisor_version"]="0.3.21";doc["update_engine_version"]="0.3.21";
        File.WriteAllText(paths.InstalledReleaseIdentityPath,doc.ToJsonString());
        var identity=AtomicFile.ReadJson<InstalledReleaseIdentity>(paths.InstalledReleaseIdentityPath);
        var original=LiveR129.Original(root,paths,layout.Build,identity);
        var plan=RoutineLocalDatabase.Plan(layout.WebRoot,layout.WebRoot);
        Need(plan.SourceCount==76,"native source has 76 migrations");
        // Reuse the independently restored native dump byte-for-byte. Preserve
        // its private location in this supervisor's evidence root.
        var dumps=Directory.GetFiles(Path.Combine(root,"Maintenance","local-slots","private-db-backups"),"*.sql");
        Need(dumps.Length==1,"native R129 dump unambiguous");
        var backupDir=Path.Combine(paths.LocalSlotStateRoot,"private-db-backups");Directory.CreateDirectory(backupDir);
        var backupFile=Path.Combine(backupDir,"native-r129-before.sql");File.Copy(dumps[0],backupFile);
        var backup=new RoutineDatabaseBackup(backupFile,Sha(backupFile),new FileInfo(backupFile).Length);
        var lifecycle=RoutineLocalLifecycle.Open(root,paths.LocalSlotStateRoot,layout.Build,paths,"ci-integrated-r125",identity.Local!.ManagedTreeSha256);
        lifecycle.SaveRollbackEvidence();
        var proof=Path.Combine(paths.LocalSlotStateRoot,"routine-local-lifecycle-before.json");var sha=Sha(proof);
        using var f=new Fixture(paths,original,plan,backup);
        var stateHash=Sha(f.State);var identityBefore=File.ReadAllBytes(paths.InstalledReleaseIdentityPath);
        Run("pwsh","-NoProfile","-File","qa/sokna-r129-compat/test-integrated-host.ps1","-Mode","Sync");
        MaintenanceIdentityVersionProof.Require(identityBefore,paths.InstalledReleaseIdentityPath,paths.DataRoot);
        var ready=await R125RecoveryWorkflow.RunAsync(paths,false);
        Need(ready.ExitCode==0&&(string?)ready.Report["status"]=="READY_AWAITING_CONFIRMATION"&&File.Exists(proof),"legacy 0322 receipt accepted without archive consent");
        var noConsent=Install(installer,false);
        Need(noConsent["status"]!.GetValue<string>()=="READY_AWAITING_CONFIRMATION"&&File.Exists(proof),"integrated installer upgrades service and preserves unconfirmed proof");
        Need(AtomicFile.ReadJson<InstalledReleaseIdentity>(paths.InstalledReleaseIdentityPath).MaintenanceSupervisorVersion=="0.3.26","actual identity upgraded to 0326");
        var operations=new MaintenanceOperationStore(paths);var journal=new DurableJournal(paths.JournalPath);
        var supervisor=new SupervisorStateStore(paths).LoadOrCreate();
        var actor="operator:integrated-maintenance";
        var key="r125-integrated-"+Convert.ToHexString(SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(layout.Build+"\n"+sha))).ToLowerInvariant();
        var crashed=new SupervisorR125LifecycleEvidenceReconciler(paths,operations,journal,supervisor,
            (p,b,h)=>SupervisorR125LifecycleEvidenceReconciler.RequireReadOnlyQualification(paths,p,b,h),
            ()=>throw new IOException("CI_CRASH_AFTER_MOVE")).Reconcile(new(actor,layout.Build,sha),key);
        Need(crashed.Operation.State=="recovery_required"&&!File.Exists(proof),"real qualification then interrupted durable archive");
        var completed=Install(installer,true);
        Need(completed["status"]!.GetValue<string>()=="RECONCILED"&&completed["resuming_interrupted_archive"]!.GetValue<bool>(),"same exact installer resumes through actual signed Supervisor HTTP route");
        var committed=operations.GetByIdempotencyKey(key)!;
        SupervisorR125LifecycleEvidenceReconciler.VerifyCommittedArchive(paths,committed,layout.Build,sha);
        Need(Sha(f.State)==stateHash&&JsonNode.Parse(File.ReadAllBytes(f.State))!["database_mutation_performed"]!.GetValue<bool>(),"original historical database flag and all routine-state bytes retained");
        RoutineLocalDatabase.RequireExternalRollbackBaseline(root,layout.WebRoot,plan);
        Need(Sha(backupFile)==backup.Sha256,"actual native database and backup unchanged");
        _=RoutineLocalLifecycle.Open(root,paths.LocalSlotStateRoot,layout.Build);
        var replay=Install(installer,true);
        Need(replay["status"]!.GetValue<string>()=="ALREADY_RECONCILED"&&operations.ListBlocking().Count==0,"same integrated installer replay verifies committed archive without duplicate operation");
        var packageRef=Sha(package)+".zip";
        Directory.CreateDirectory(paths.InboxRoot);File.Copy(package,Path.Combine(paths.InboxRoot,packageRef));
        var outcome=new SupervisorLocalPackagePreflightCoordinator(paths,operations,journal,supervisor).Verify(packageRef,"ci:integrated","ci-integrated-next");
        Console.WriteLine("INTEGRATED_NEXT_PREFLIGHT "+System.Text.Json.JsonSerializer.Serialize(outcome.Operation,AtomicFile.JsonOptions));
        Need(outcome.Operation.State=="verified"&&outcome.Receipt is not null,"next actual Core package preflight accepts installed 0326 identity");
        var evidence=new JsonObject{["status"]="PASS",["exact_installer_sha256"]=Sha(installer),["native_mariadb_source_migrations"]=76,
            ["legacy_0322_receipt"]=true,["no_consent_preserved"]=true,["interrupted_archive_signed_resume"]=true,
            ["idempotent_replay"]=true,["next_core_preflight"]=true,["physical_cafe_verified"]=false,["production_approved"]=false};
        File.WriteAllText(Path.Combine(Environment.GetEnvironmentVariable("RUNNER_TEMP")!,"integrated-r125-native.json"),evidence.ToJsonString());
    }
}
