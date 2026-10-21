using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7A: advisory pre-activation qualification, NOT an activation permit.
/// An independent activation transaction and host evidence are intentionally
/// absent, so an assessment may never turn panel_update_ready on.
/// </summary>
public sealed record LocalActivationReadiness(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("release_type")] string ReleaseType,
    [property: JsonPropertyName("stage_evidence_verified")] bool StageEvidenceVerified,
    [property: JsonPropertyName("blockers")] string[] Blockers,
    [property: JsonPropertyName("pre_db_rollback_policy")] string PreDbRollbackPolicy,
    [property: JsonPropertyName("post_db_recovery_policy")] string PostDbRecoveryPolicy,
    [property: JsonPropertyName("database_forward_boundary")] string DatabaseForwardBoundary,
    [property: JsonPropertyName("pre_mutation_backup_required")] bool PreMutationBackupRequired,
    [property: JsonPropertyName("activation_allowed")] bool ActivationAllowed,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed);

public static class LocalActivationReadinessPolicy
{
    public static LocalActivationReadiness Assess(ReleaseSetManifest manifest,
        StagedReleaseReceipt receipt, bool fullyVerifiedStage)
    {
        ArgumentNullException.ThrowIfNull(manifest);
        ArgumentNullException.ThrowIfNull(receipt);
        var blockers = new SortedSet<string>(StringComparer.Ordinal);
        if (!fullyVerifiedStage || receipt.Format != "sokna-maintenance-staged-release-v1" ||
            receipt.SchemaVersion != 1 || receipt.ActivationPerformed ||
            receipt.DatabaseMutationPerformed ||
            !receipt.PreflightIdentityMatch ||
            !OperatorUploadedLocalPackage.HasTrustedPackageEvidence(
                receipt.SignatureVerified, receipt.OperatorAuthorized) ||
            !receipt.ArtifactIntegrityVerified ||
            manifest.ReleaseSetId != receipt.ReleaseSetId ||
            manifest.Sequence != receipt.Sequence)
            blockers.Add("STAGE_EVIDENCE_NOT_VERIFIED");

        if (manifest.ReleaseType != "local_only")
            blockers.Add("LOCAL_ONLY_ACTIVATION_CONTRACT_REQUIRED");
        if (!receipt.OperatorAuthorized && (!manifest.ForInstall || !manifest.ProductionPass ||
            string.IsNullOrWhiteSpace(manifest.Status) ||
            manifest.Status.Contains("NOT_FOR_INSTALL", StringComparison.OrdinalIgnoreCase)))
            blockers.Add("RELEASE_NOT_QUALIFIED_FOR_INSTALL");

        var qualification = manifest.Qualification;
        if (!receipt.OperatorAuthorized && (qualification is null ||
            !qualification.RealWindows || !qualification.RealMariaDb ||
            !qualification.RealPublicHost || !qualification.Canary ||
            !qualification.RecoveryConsoleWebDown ||
            !qualification.BusinessBackupConsistencyRealMariaDb ||
            !qualification.RemoteVaultRealHost ||
            !qualification.CleanMachineRemoteRestore ||
            qualification.FailureMatrixCasesRequired < 80 ||
            string.IsNullOrWhiteSpace(qualification.FailureMatrixId)))
            blockers.Add("RELEASE_FAILURE_MATRIX_NOT_QUALIFIED");

        var backup = manifest.PreUpdateBackupPolicy;
        if (backup is null || !backup.Required ||
            backup.RecentLocalVerifiedMaxAgeSeconds is null or <= 0 ||
            !backup.RequireRemoteVerifiedBeforeDbBoundary ||
            !backup.SystemRecoverySnapshotRequired ||
            backup.OnFailure != "reject_pre_mutation")
            blockers.Add("BACKUP_POLICY_NOT_INSTALL_QUALIFIED");

        var database = manifest.Database;
        if (database?.Local is null || database.Local.ForwardBoundaryPhase != "db_forward_boundary" ||
            database.Local.RollbackAfterBoundary != "forbidden")
            blockers.Add("DATABASE_FORWARD_BOUNDARY_UNDEFINED");

        var recovery = manifest.Recovery;
        if (recovery is null ||
            recovery.PreDb != "rollback_or_discard_allowed" ||
            recovery.PostDb != "retain_target_updater_forward_recovery_only" ||
            recovery.BusinessBackupRestore != "clean_target_only")
            blockers.Add("RECOVERY_BOUNDARIES_UNQUALIFIED");

        // This assessment never accepts user-supplied health/backup flags.
        // Supervisor must provide independently observed and transaction-bound
        // evidence in future phases before these blocks can be lifted.
        blockers.Add("APACHE_RUNTIME_HEALTH_NOT_ATTESTED");
        blockers.Add("MARIADB_SCHEMA_AND_CONSISTENCY_NOT_ATTESTED");
        blockers.Add("VERIFIED_LOCAL_AND_REMOTE_BACKUP_NOT_ATTESTED");
        blockers.Add("ATOMIC_SWITCH_ROLLBACK_JOURNAL_NOT_QUALIFIED");
        blockers.Add("FOUNDATION_UPDATE_REQUIRED");
        return new LocalActivationReadiness(
            "sokna-local-activation-readiness-v1",
            manifest.ReleaseSetId ?? "",
            manifest.Sequence,
            manifest.ReleaseType ?? "",
            fullyVerifiedStage &&
                !blockers.Contains("STAGE_EVIDENCE_NOT_VERIFIED"),
            blockers.ToArray(),
            recovery?.PreDb ?? "unavailable",
            recovery?.PostDb ?? "unavailable",
            database?.Local?.ForwardBoundaryPhase ?? "unavailable",
            backup?.Required == true,
            ActivationAllowed: false,
            PanelUpdateReady: false,
            ActivationPerformed: false,
            DatabaseMutationPerformed: false);
    }
}
