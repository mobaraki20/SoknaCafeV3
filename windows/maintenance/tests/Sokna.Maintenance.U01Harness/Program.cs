using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

// Full production bridge/coordinators against disposable Windows SCM, NTFS,
// Apache/PHP and MariaDB. Never accept a real host root.
if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows") throw new InvalidOperationException("CI_ONLY");
var command=args[0]; var root=Path.GetFullPath(args[1]);
if (!File.Exists(Path.Combine(root,".u01-ci-only"))) throw new InvalidOperationException("CI_MARKER_REQUIRED");
var paths=new MaintenancePaths(Path.Combine(root,"Maintenance"));
var baseline=AtomicFile.ReadJson<ReleaseComponentIdentity>(args[2]);
using var targetDoc=JsonDocument.Parse(File.ReadAllBytes(args[3])); var t=targetDoc.RootElement;
var version=t.GetProperty("version").GetString()!; var tree=t.GetProperty("managed_tree_sha256").GetString()!;
var count=t.GetProperty("managed_files").GetInt32();
var target=ProductionLocalSlotMigrationBridge.BuildProductionSlotPath(root,version,tree);
var old=Directory.EnumerateDirectories(root,"Web.release.*").First(p=>File.ReadAllText(Path.Combine(p,"VERSION.txt")).Trim()==baseline.Version);
var oldCount=JsonDocument.Parse(File.ReadAllBytes(Path.Combine(old,"resources","local-managed-files-v1.json"))).RootElement.GetProperty("files").GetArrayLength();
var fault=args.Length>5?args[5]:"none";
var bridge=new ProductionLocalSlotMigrationBridge(paths,TimeSpan.FromSeconds(20),TimeSpan.FromSeconds(20),
    phase=>{if(phase=="ROUTINE_"+fault)Environment.Exit(97);});
var operations=new MaintenanceOperationStore(paths);var journal=new DurableJournal(paths.JournalPath);
var state=new SupervisorStateStore(paths).LoadOrCreate();
var stages=new SupervisorLocalStageRecoveryCoordinator(paths,operations,journal,state);
var candidates=new SupervisorInactiveLocalCandidateCoordinator(paths,operations,stages,journal,state);
var slots=new SupervisorInactiveLocalSlotCoordinator(paths,operations,candidates,journal,state);
var proof=new SupervisorLocalCodeOnlyPreExecution(paths,operations,stages);
var execution=new SupervisorLocalCodeOnlyExecutionCoordinator(paths,operations,proof,journal,state,args[4],candidates,stages);
switch(command)
{
    case "prepare":
        Directory.CreateDirectory(paths.InboxRoot);
        AtomicFile.WriteJson(paths.InstalledReleaseIdentityPath,new InstalledReleaseIdentity(baseline,null,"0.3.12","0.3.12",null,null));
        new SecretStore(paths).EnsureKeys();
        var package=args[6];var sha=Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(package))).ToLowerInvariant();
        File.Copy(package,Path.Combine(paths.InboxRoot,sha+".zip"));
        var preflight=new SupervisorLocalPackagePreflightCoordinator(paths,operations,journal,state).Verify(sha+".zip","user:1","u01-preflight");
        var stage=new SupervisorLocalReleaseStageCoordinator(paths,operations,journal,state).Stage(preflight.Operation.OperationId,"user:1","u01-stage");
        _=slots.Prepare(stage.Operation.OperationId,"user:1","u01-slot","u01-candidate");
        var evidence=proof.Inspect(stage.Operation.OperationId);
        if(evidence.MigrationPlan?.RequiresMigration!=true)throw new InvalidDataException("APPEND_ONLY_PLAN_NOT_PROVEN");
        File.WriteAllText(Path.Combine(root,"stage-id.txt"),stage.Operation.OperationId);
        Console.WriteLine("U01_REAL_PACKAGE_PREFLIGHT_STAGE_INACTIVE_PASS");break;
    case "activate":
        var result=bridge.ActivateRoutineSiblingRelease(target,baseline.Version,oldCount,baseline.ManagedTreeSha256,
            version,count,tree,t.GetProperty("release_id").GetString(),t.GetProperty("schema_fingerprint").GetString(),t.GetProperty("migration_head").GetString());
        if(!result.Ok || !result.DatabaseMutationPerformed)throw new InvalidDataException("U01_ACTIVATE_FAILED");
        Console.WriteLine(JsonSerializer.Serialize(result,AtomicFile.JsonOptions));break;
    case "recover":
        var recovered=bridge.RecoverRoutine();
        if(!recovered.Ok)throw new InvalidDataException("U01_RECOVERY_FAILED");
        Console.WriteLine(JsonSerializer.Serialize(recovered,AtomicFile.JsonOptions));break;
    case "panel-activate":
        var stageId=File.ReadAllText(Path.Combine(root,"stage-id.txt"));
        var accepted=execution.ReserveActivation(stageId,"user:1","u01-activate");
        if(accepted.Operation.State!="accepted")throw new InvalidDataException("U01_NOT_ACCEPTED");
        var committed=execution.ExecuteReservedActivation(stageId,"user:1","u01-activate");
        if(committed.Operation.State!="committed" || !committed.Operation.DatabaseMutationPerformed)
            throw new InvalidDataException(JsonSerializer.Serialize(committed,AtomicFile.JsonOptions));
        var replay=execution.ReserveActivation(stageId,"user:1","u01-activate");
        if(!replay.Reconciled || replay.Operation.OperationId!=committed.Operation.OperationId)
            throw new InvalidDataException("U01_IDEMPOTENCY_REPLAY_FAILED");
        Console.WriteLine("U01_PANEL_COORDINATORS_DELEGATED_ENGINE_COMMITTED_AND_REPLAY_PASS");break;
    default:throw new InvalidDataException("Unknown CI mode");
}
