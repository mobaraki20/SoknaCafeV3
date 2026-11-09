using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Sokna.Maintenance.Core;

/// <summary>One operator workflow, existing signed Supervisor operation.
/// No DB restore/apply or direct proof movement is implemented in this client.</summary>
public static class R125RecoveryWorkflow
{
    public static async Task<(int ExitCode, Dictionary<string,object?> Report)> RunAsync(MaintenancePaths paths,bool confirmed)
    {
        var report=new Dictionary<string,object?>{
            ["format"]="sokna-maintenance-integrated-recovery-v1",["collected_at_utc"]=DateTimeOffset.UtcNow,
            ["status"]="BLOCKED",["code"]="R125_WORKFLOW_NOT_STARTED",["operator_confirmed"]=confirmed,
            ["reconciliation_performed"]=false,["database_schema_data_mutated"]=false,["public_mutated"]=false,
            ["backup_restore_proven"]=false,["production_approved"]=false};
        var step="SOURCE_DISCOVERY";
        try{
            var source=Path.Combine(paths.LocalSlotStateRoot,"routine-local-lifecycle-before.json");
            MaintenanceOperation? resume=null;
            string? resumedBuild=null,resumedSha=null;
            if(!File.Exists(source)){
                var archive=Path.Combine(paths.LocalSlotStateRoot,"reconciled-lifecycle-evidence");
                var store=Directory.Exists(paths.OperationsRoot)?new MaintenanceOperationStore(paths):null;
                var unfinished=store?.ListBlocking().Where(o=>o.OperationType=="update.local-r125-lifecycle-reconcile").ToArray()
                    ??Array.Empty<MaintenanceOperation>();
                if(unfinished.Length>1)throw new InvalidDataException("R125_UNFINISHED_ARCHIVE_STATE");
                if(unfinished.Length==1){
                    resume=unfinished[0];
                    var parts=resume.PackageRef.Split(':',2);
                    if(parts.Length!=2)throw new InvalidDataException("R125_UNFINISHED_ARCHIVE_STATE");
                    resumedBuild=parts[0];resumedSha=parts[1];
                    source=Path.Combine(archive,"proof-"+resume.OperationId+".json");
                    if(!File.Exists(source))throw new InvalidDataException("R125_UNFINISHED_ARCHIVE_STATE");
                    report["resuming_interrupted_archive"]=true;
                }else{
                    var receipts=Directory.Exists(archive)?Directory.GetFiles(archive,"proof-*.receipt.json"):Array.Empty<string>();
                    var intents=Directory.Exists(archive)?Directory.GetFiles(archive,"proof-*.intent.json"):Array.Empty<string>();
                    if(receipts.Length>1000||intents.Length>1000)throw new InvalidDataException("R125_ARCHIVE_HISTORY_LIMIT");
                    if(receipts.Length!=intents.Length)throw new InvalidDataException("R125_UNFINISHED_ARCHIVE_STATE");
                    foreach(var p in receipts){
                        if((File.GetAttributes(p)&FileAttributes.ReparsePoint)!=0||new FileInfo(p).Length>100_000)
                            throw new InvalidDataException("R125_COMMITTED_ARCHIVE_RECEIPT_UNQUALIFIED");
                        var node=JsonNode.Parse(File.ReadAllBytes(p))!.AsObject();
                        var id=node["operationId"]?.GetValue<string>()??"";
                        var build=node["expectedBuild"]?.GetValue<string>()??"";
                        var sha=node["proofSha256"]?.GetValue<string>()??"";
                        var op=store?.Get(id)??throw new InvalidDataException("R125_COMMITTED_ARCHIVE_RECEIPT_UNQUALIFIED");
                        SupervisorR125LifecycleEvidenceReconciler.VerifyCommittedArchive(paths,op,build,sha);
                    }
                    report["status"]=receipts.Length==0?"NOT_REQUIRED":"ALREADY_RECONCILED";
                    report["code"]=receipts.Length==0?"R125_NO_ACTIVE_PROOF":"R125_ARCHIVE_READBACK_VERIFIED";
                    return(0,report);
                }
            }
            step="READ_ONLY_ASSESSMENT";
            report["assessment"]=R125ReadOnlyAssessment.Inspect(paths);
            var layout=new LocalSlotManager(paths).InspectHealthyCurrent();
            if((File.GetAttributes(source)&FileAttributes.ReparsePoint)!=0||new FileInfo(source).Length is <=0 or >8_000_000)
                throw new InvalidDataException("R125_PROOF_UNAVAILABLE_OR_UNSAFE");
            using var input=File.OpenRead(source);
            var sha256=Convert.ToHexString(SHA256.HashData(input)).ToLowerInvariant();
            input.Close();
            if(resumedBuild is not null && (resumedBuild!=layout.Build||resumedSha!=sha256))
                throw new InvalidDataException("R125_UNFINISHED_ARCHIVE_STATE");
            step="FULL_IDENTITY_AND_DATABASE_QUALIFICATION";
            SupervisorR125LifecycleEvidenceReconciler.RequireReadOnlyQualification(paths,source,layout.Build,sha256);
            report["proof_sha256"]=sha256;report["local_build"]=layout.Build;
            if(!confirmed){report["status"]="READY_AWAITING_CONFIRMATION";report["code"]="R125_OPERATOR_CONFIRMATION_REQUIRED";return(0,report);}
            step="SIGNED_SUPERVISOR_RECONCILIATION";
            var descriptor=AtomicFile.ReadJson<ControlPlaneDescriptor>(paths.DescriptorPath);
            if(descriptor.Protocol!=HmacProtocol.Protocol||descriptor.ReadOnlyP1||
                !Uri.TryCreate(descriptor.BaseUrl,UriKind.Absolute,out var uri)||!uri.IsLoopback)
                throw new InvalidDataException("R125_SIGNED_CONTROL_PLANE_UNQUALIFIED");
            var idempotency=resume?.IdempotencyKey??"r125-integrated-"+Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(layout.Build+"\n"+sha256))).ToLowerInvariant();
            var actor=resume?.OperatorId??"operator:integrated-maintenance";
            var key=new SecretStore(paths).LoadKey("recovery-console");
            try{
                using var client=new HttpClient{Timeout=TimeSpan.FromSeconds(120)};
                using var request=HmacProtocol.CreateSignedJson(uri,"v1/local-updates/reconcile-r125-lifecycle","recovery-console",key,
                    JsonSerializer.SerializeToUtf8Bytes(new R125LifecycleReconciliationRequest(actor,layout.Build,sha256)),idempotency);
                report["reconciliation_performed"]=null;
                report["archive_outcome"]="PENDING_OR_UNPROVEN";
                using var response=await client.SendAsync(request);
                var body=await response.Content.ReadAsByteArrayAsync();
                if(body.Length>1_000_000)throw new InvalidDataException("R125_SIGNED_RESPONSE_UNQUALIFIED");
                var envelope=JsonNode.Parse(body)?.AsObject();
                if(!response.IsSuccessStatusCode||envelope?["ok"]?.GetValue<bool>()!=true)
                    throw new InvalidDataException("R125_SIGNED_RECONCILIATION_BLOCKED");
            }finally{CryptographicOperations.ZeroMemory(key);}
            step="COMMITTED_ARCHIVE_AND_IDLE_READBACK";
            var committed=new MaintenanceOperationStore(paths).GetByIdempotencyKey(idempotency)
                ??throw new InvalidDataException("R125_COMMITTED_ARCHIVE_RECEIPT_UNQUALIFIED");
            SupervisorR125LifecycleEvidenceReconciler.VerifyCommittedArchive(paths,committed,layout.Build,sha256);
            _=RoutineLocalLifecycle.Open(layout.PackageRoot,paths.LocalSlotStateRoot,layout.Build);
            report["status"]="RECONCILED";report["code"]="R125_ARCHIVED_AND_LOCAL_LIFECYCLE_IDLE";
            report["archive_outcome"]="VERIFIED_COMMITTED";report["reconciliation_performed"]=true;report["local_lifecycle_idle"]=true;
            report["operation_id"]=committed.OperationId;return(0,report);
        }catch(Exception e)when(R125ReadOnlyAssessment.SafeException(e)||e is HttpRequestException or TaskCanceledException){
            report["blocked_step"]=step;
            report["code"]=e is TaskCanceledException?"R125_CONTROL_PLANE_TIMEOUT_OUTCOME_UNKNOWN":
                e is HttpRequestException?"R125_CONTROL_PLANE_UNAVAILABLE":R125ReadOnlyAssessment.Code(e);
            return(2,report);
        }
    }
}
