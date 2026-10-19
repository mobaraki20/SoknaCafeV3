using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalActivationPlanRequest(
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record LocalActivationPlanStep(
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("requires_evidence")] string[] RequiresEvidence,
    [property: JsonPropertyName("possible_effect")] string PossibleEffect,
    [property: JsonPropertyName("on_failure")] string OnFailure,
    [property: JsonPropertyName("executable")] bool Executable);

public sealed record LocalActivationDryRunPlan(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("plan_id")] string PlanId,
    [property: JsonPropertyName("stage_operation_id")] string StageOperationId,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("idempotency_key")] string IdempotencyKey,
    [property: JsonPropertyName("request_sha256")] string RequestSha256,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("release_sequence")] long ReleaseSequence,
    [property: JsonPropertyName("release_type")] string ReleaseType,
    [property: JsonPropertyName("stage_receipt_sha256")] string StageReceiptSha256,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("public_execution_owner")] string PublicExecutionOwner,
    [property: JsonPropertyName("public_release_included")] bool PublicReleaseIncluded,
    [property: JsonPropertyName("paired_compatibility_qualified")] bool PairedCompatibilityQualified,
    [property: JsonPropertyName("steps")] LocalActivationPlanStep[] Steps,
    [property: JsonPropertyName("blockers")] string[] Blockers,
    [property: JsonPropertyName("created_at_utc")] DateTimeOffset CreatedAtUtc,
    [property: JsonPropertyName("plan_sha256")] string PlanSha256,
    [property: JsonPropertyName("dry_run_only")] bool DryRunOnly,
    [property: JsonPropertyName("activation_allowed")] bool ActivationAllowed,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed);

public sealed record LocalActivationPlanOutcome(
    [property: JsonPropertyName("plan")] LocalActivationDryRunPlan Plan,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

/// <summary>
/// F1-S7B immutable, write-once DRY-RUN plan only. The plan records intended
/// boundaries and blockers; nothing in it is an executable activation token.
/// Plan creation journals intent before metadata write, then commit with a
/// fsynced marker; a crash in between requires explicit reconciliation.
/// The public host is a SEPARATE execution owner, never a Windows target.
/// </summary>
public sealed class SupervisorLocalActivationPlanCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly SupervisorLocalStageRecoveryCoordinator _stageInspector;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;

    public SupervisorLocalActivationPlanCoordinator(MaintenancePaths paths,
        MaintenanceOperationStore operations, SupervisorLocalStageRecoveryCoordinator stageInspector,
        DurableJournal journal, SupervisorState state)
    {
        _paths = paths;
        _operations = operations;
        _stageInspector = stageInspector;
        _journal = journal;
        _state = state;
    }

    public LocalActivationPlanOutcome Create(string stageOperationId, string operatorId, string idempotencyKey)
    {
        if (!MaintenanceOperationStore.IsValidIdempotencyKey(idempotencyKey))
            throw new InvalidDataException("ACTIVATION_PLAN_IDEMPOTENCY_INVALID");
        if (string.IsNullOrWhiteSpace(operatorId) || operatorId.Length > 128 ||
            operatorId.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("ACTIVATION_PLAN_OPERATOR_INVALID");
        if (stageOperationId is not { Length: 32 } || !stageOperationId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("ACTIVATION_PLAN_STAGE_ID_INVALID");

        lock (LocalUpdateOperationGate.Sync)
        {
            // Inspect the exact installed baseline and write the immutable
            // snapshot while independent Engine/Setup Host mutations are fenced.
            using var machineLease = MachineMutationLease.Acquire(_paths, "supervisor-activation-dryrun");
            if (_operations.ListBlocking().Count != 0)
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            var stage = _operations.Get(stageOperationId)
                ?? throw new InvalidDataException("ACTIVATION_PLAN_STAGE_UNPROVEN");
            if (stage.OperationType != "update.local-stage" || stage.State != "staged" ||
                stage.OperatorId != operatorId || stage.ActivationPerformed || stage.DatabaseMutationPerformed)
                throw new InvalidDataException("ACTIVATION_PLAN_STAGE_UNPROVEN");

            // Full independent signed package and staged artifact attestation on
            // EVERY invocation, including an idempotent retry.
            var assessment = _stageInspector.InspectActivationReadiness(stageOperationId);
            if (!assessment.StageEvidenceVerified ||
                string.IsNullOrWhiteSpace(stage.StageReceiptPath) ||
                !File.Exists(stage.StageReceiptPath))
                throw new InvalidDataException("ACTIVATION_PLAN_STAGE_UNPROVEN");

            var stageReceiptSha = Sha256File(stage.StageReceiptPath);
            var installedSha = Sha256File(_paths.InstalledReleaseIdentityPath);
            var requestSha = Sha256($"activation-dry-run-v1\n{stageOperationId}\n{operatorId}");
            var id = Sha256("sokna-local-activation-plan-v1\n" + idempotencyKey)[..32];
            var file = PlanPath(id);
            var marker = MarkerPath(id);
            if (File.Exists(file) || File.Exists(marker))
            {
                if (!File.Exists(file) || !File.Exists(marker))
                    throw new InvalidOperationException("ACTIVATION_PLAN_RECONCILIATION_REQUIRED");
                var existing = ReadCommitted(id, file, marker);
                if (existing.IdempotencyKey != idempotencyKey ||
                    existing.RequestSha256 != requestSha ||
                    existing.StageOperationId != stageOperationId ||
                    existing.OperatorId != operatorId)
                    throw new InvalidOperationException("ACTIVATION_PLAN_IDEMPOTENCY_CONFLICT");
                if (existing.StageReceiptSha256 != stageReceiptSha ||
                    existing.InstalledIdentitySha256 != installedSha)
                    throw new InvalidOperationException("ACTIVATION_PLAN_BASELINE_CHANGED");
                return new(existing, false, true);
            }

            var blockers = assessment.Blockers
                .Concat(new[] { "DRY_RUN_NO_EXECUTION_PERMIT",
                    "PUBLIC_PAIR_PROTOCOL_COMPATIBILITY_NOT_ATTESTED" })
                .Distinct(StringComparer.Ordinal).OrderBy(static x => x, StringComparer.Ordinal).ToArray();
            var candidate = new LocalActivationDryRunPlan(
                "sokna-local-activation-dry-run-v1", 1, id,
                stageOperationId, operatorId, idempotencyKey, requestSha,
                assessment.ReleaseSetId, assessment.Sequence, assessment.ReleaseType,
                stageReceiptSha, installedSha,
                "public-maintenance-kernel", PublicReleaseIncluded: false,
                PairedCompatibilityQualified: false,
                Steps: BuildSteps(), Blockers: blockers,
                CreatedAtUtc: DateTimeOffset.UtcNow, PlanSha256: "",
                DryRunOnly: true, ActivationAllowed: false, ActivationPerformed: false,
                DatabaseMutationPerformed: false, PublicMutationPerformed: false);
            var plan = candidate with { PlanSha256 = HashPlan(candidate) };

            _journal.Append("update.local_activation_plan.write_intent",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?>
                {
                    ["plan_id"] = plan.PlanId,
                    ["stage_operation_id"] = stageOperationId,
                    ["plan_sha256"] = plan.PlanSha256,
                    ["mutation_performed"] = false
                });
            WriteOnce(file, JsonSerializer.Serialize(plan, AtomicFile.JsonOptions));
            _journal.Append("update.local_activation_plan.metadata_written",
                _state.InstanceId, _state.InstallGeneration,
                new Dictionary<string, object?>
                {
                    ["plan_id"] = plan.PlanId,
                    ["plan_sha256"] = plan.PlanSha256,
                    ["activation_allowed"] = false,
                    ["public_mutation_performed"] = false
                });
            WriteOnce(marker, plan.PlanSha256 + "\n");
            return new(plan, true, false);
        }
    }

    /// <summary>
    /// Reopen a committed dry-run after browser/adapter or Supervisor restart.
    /// A plan is NEVER an activation permit, and stale Stage/baseline evidence
    /// must not be presented as a current snapshot.
    /// </summary>
    public LocalActivationDryRunPlan Inspect(string planId)
    {
        if (planId is not { Length: 32 } || !planId.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("ACTIVATION_PLAN_ID_INVALID");
        lock (LocalUpdateOperationGate.Sync)
        {
            var file = PlanPath(planId);
            var marker = MarkerPath(planId);
            if (!File.Exists(file) || !File.Exists(marker))
                throw new InvalidDataException("ACTIVATION_PLAN_NOT_COMMITTED");
            var plan = ReadCommitted(planId, file, marker);
            if (_operations.ListBlocking().Count != 0)
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");
            var stage = _operations.Get(plan.StageOperationId)
                ?? throw new InvalidDataException("ACTIVATION_PLAN_STAGE_UNPROVEN");
            if (stage.OperationType != "update.local-stage" ||
                stage.State != "staged" || stage.OperatorId != plan.OperatorId ||
                stage.StageReceiptPath is null)
                throw new InvalidDataException("ACTIVATION_PLAN_STAGE_UNPROVEN");
            var current = _stageInspector.InspectActivationReadiness(plan.StageOperationId);
            if (!current.StageEvidenceVerified ||
                !string.Equals(current.ReleaseSetId, plan.ReleaseSetId, StringComparison.Ordinal) ||
                current.Sequence != plan.ReleaseSequence ||
                !string.Equals(Sha256File(stage.StageReceiptPath), plan.StageReceiptSha256,
                    StringComparison.Ordinal) ||
                !string.Equals(Sha256File(_paths.InstalledReleaseIdentityPath),
                    plan.InstalledIdentitySha256, StringComparison.Ordinal))
                throw new InvalidDataException("ACTIVATION_PLAN_EVIDENCE_CHANGED");
            return plan;
        }
    }

    private LocalActivationDryRunPlan ReadCommitted(string id, string file, string marker)
    {
        if (IsReparse(file) || IsReparse(marker))
            throw new InvalidDataException("Activation plan evidence is unsafe.");
        var plan = AtomicFile.ReadJson<LocalActivationDryRunPlan>(file);
        if (plan.Format != "sokna-local-activation-dry-run-v1" ||
            plan.SchemaVersion != 1 || plan.PlanId != id ||
            !plan.DryRunOnly || plan.ActivationAllowed ||
            plan.ActivationPerformed || plan.DatabaseMutationPerformed || plan.PublicMutationPerformed ||
            plan.PublicReleaseIncluded || plan.PairedCompatibilityQualified ||
            plan.PublicExecutionOwner != "public-maintenance-kernel" ||
            plan.Steps is null || plan.Steps.Length != BuildSteps().Length ||
            plan.Steps.Any(static s => s.Executable))
            throw new InvalidDataException("Activation plan is not an immutable non-mutating plan.");
        if (!string.Equals(plan.PlanSha256, HashPlan(plan), StringComparison.Ordinal) ||
            !string.Equals(File.ReadAllText(marker).Trim(), plan.PlanSha256, StringComparison.Ordinal))
            throw new InvalidDataException("Activation plan and committed hash disagree.");
        return plan;
    }

    private string PlanPath(string id)
    {
        var root = Path.GetFullPath(_paths.ActivationPlansRoot);
        if (Directory.Exists(root) && IsReparse(root))
            throw new InvalidDataException("Activation plan root is unsafe.");
        return Path.Combine(root, "plan-" + id + ".json");
    }

    private string MarkerPath(string id)
        => Path.Combine(Path.GetFullPath(_paths.ActivationPlansRoot), "plan-" + id + ".committed");

    private static void WriteOnce(string path, string content)
    {
        var directory = Path.GetDirectoryName(path)
            ?? throw new InvalidDataException("Activation plan destination is invalid.");
        Directory.CreateDirectory(directory);
        if (IsReparse(directory) || File.Exists(path))
            throw new InvalidOperationException("ACTIVATION_PLAN_RECONCILIATION_REQUIRED");
        var temp = path + ".tmp." + Guid.NewGuid().ToString("N");
        try
        {
            using (var stream = new FileStream(temp, FileMode.CreateNew, FileAccess.Write,
                FileShare.None, 4096, FileOptions.WriteThrough))
            {
                var bytes = Encoding.UTF8.GetBytes(content);
                stream.Write(bytes);
                stream.Flush(flushToDisk: true);
            }
            File.Move(temp, path, overwrite: false);
        }
        finally
        {
            if (File.Exists(temp)) File.Delete(temp);
        }
    }

    private static string HashPlan(LocalActivationDryRunPlan plan)
        => Sha256(JsonSerializer.Serialize(plan with { PlanSha256 = "" }, AtomicFile.JsonOptions));
    private static bool IsReparse(string path)
        => (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static string Sha256(string text)
        => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(text))).ToLowerInvariant();
    private static string Sha256File(string path)
    {
        using var file = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(file)).ToLowerInvariant();
    }

    private static LocalActivationPlanStep[] BuildSteps() =>
    [
        new("VERIFY_RELEASE_AND_PAIR",
            ["signed_release_identity", "exact_local_baseline", "public_protocol_window",
                "public_active_identity_if_paired"],
            "none", "reject_pre_mutation", false),
        new("QUIESCE_AND_BACKUP",
            ["local_and_remote_verified_business_backups", "system_recovery_snapshot",
                "apache_mariadb_consistency", "durable_outbox_and_public_queue_policy"],
            "service_drain_and_backup", "reject_pre_mutation", false),
        new("PREPARE_SIBLING",
            ["signed_local_web_payload", "immutable_target_tree",
                "persistent_overlay", "migration_dag"],
            "write_inactive_slot", "discard_uncommitted_slot", false),
        new("LOCAL_CODE_SWITCH_PRE_DB",
            ["durable_before_switch_intent", "apache_stop_proof",
                "previous_slot_snapshot", "target_build_http_health"],
            "apache_junction_change", "restore_previous_slot_only_before_db_boundary", false),
        new("DB_FORWARD_BOUNDARY",
            ["verified_backup_and_restore_drill", "exact_schema_fingerprint",
                "migration_dag", "boundary_journal"],
            "irreversible_schema_forward", "retain_target_updater_forward_recovery_only", false),
        new("VERIFY_AND_COMMIT",
            ["active_slot_tree", "apache_health", "mariadb_migration_head",
                "public_compatibility_ack", "durable_commit_receipt"],
            "commit_versioned_identity", "recovery_required_if_unproven", false)
    ];
}
