using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record ReleaseSetSignature(
    [property: JsonPropertyName("algorithm")] string Algorithm,
    [property: JsonPropertyName("key_id")] string KeyId,
    [property: JsonPropertyName("value")] string Value);

public sealed record ReleaseSetRequirements(
    [property: JsonPropertyName("maintenance_supervisor_min")] string? MaintenanceSupervisorMin,
    [property: JsonPropertyName("update_engine_min")] string? UpdateEngineMin,
    [property: JsonPropertyName("backup_recovery_engine_min")] string? BackupRecoveryEngineMin,
    [property: JsonPropertyName("public_maintenance_kernel_min")] string? PublicMaintenanceKernelMin);

public sealed record ReleaseComponentIdentity(
    [property: JsonPropertyName("release_id")] string ReleaseId,
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("managed_tree_sha256")] string ManagedTreeSha256,
    [property: JsonPropertyName("schema_fingerprint")] string SchemaFingerprint,
    [property: JsonPropertyName("migration_head")] string MigrationHead,
    [property: JsonPropertyName("authority_epoch_policy")] string? AuthorityEpochPolicy = null);

public sealed record ReleaseBaseline(
    [property: JsonPropertyName("local")] ReleaseComponentIdentity? Local,
    [property: JsonPropertyName("public")] ReleaseComponentIdentity? Public);

public sealed record ReleaseArtifact(
    [property: JsonPropertyName("path")] string Path,
    [property: JsonPropertyName("sha256")] string Sha256,
    [property: JsonPropertyName("size_bytes")] long SizeBytes,
    [property: JsonPropertyName("component")] string Component,
    [property: JsonPropertyName("kind")] string Kind);

public sealed record InstallPlanStep(
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("component")] string Component,
    [property: JsonPropertyName("mutation")] bool Mutation);

public sealed record ReleaseCompatibility(
    [property: JsonPropertyName("allowed_intermediate_pairs")] JsonElement AllowedIntermediatePairs,
    [property: JsonPropertyName("max_stagger_seconds")] int MaxStaggerSeconds,
    [property: JsonPropertyName("client_build_window")] JsonElement ClientBuildWindow,
    [property: JsonPropertyName("protocol_window")] JsonElement ProtocolWindow);

public sealed record ReleaseMutationPolicy(
    [property: JsonPropertyName("guest")] string Guest,
    [property: JsonPropertyName("staff_public")] string StaffPublic,
    [property: JsonPropertyName("local")] string Local);

public sealed record ReleaseDatabaseSide(
    [property: JsonPropertyName("migration_dag")] JsonElement MigrationDag,
    [property: JsonPropertyName("forward_boundary_phase")] string ForwardBoundaryPhase,
    [property: JsonPropertyName("rollback_after_boundary")] string RollbackAfterBoundary);

public sealed record ReleaseDatabase(
    [property: JsonPropertyName("local")] ReleaseDatabaseSide Local,
    [property: JsonPropertyName("public")] ReleaseDatabaseSide Public);

public sealed record ReleaseRecovery(
    [property: JsonPropertyName("pre_db")] string PreDb,
    [property: JsonPropertyName("post_db")] string PostDb,
    [property: JsonPropertyName("disaster_restore")] string DisasterRestore,
    [property: JsonPropertyName("business_backup_restore")] string BusinessBackupRestore,
    [property: JsonPropertyName("machine_identity")] string MachineIdentity,
    [property: JsonPropertyName("public_reenrollment")] string PublicReenrollment);

public sealed record ReleaseQualification(
    [property: JsonPropertyName("failure_matrix_id")] string FailureMatrixId,
    [property: JsonPropertyName("real_windows")] bool RealWindows,
    [property: JsonPropertyName("real_mariadb")] bool RealMariaDb,
    [property: JsonPropertyName("real_public_host")] bool RealPublicHost,
    [property: JsonPropertyName("canary")] bool Canary,
    [property: JsonPropertyName("recovery_console_web_down")] bool RecoveryConsoleWebDown,
    [property: JsonPropertyName("business_backup_consistency_real_mariadb")] bool BusinessBackupConsistencyRealMariaDb,
    [property: JsonPropertyName("remote_vault_real_host")] bool RemoteVaultRealHost,
    [property: JsonPropertyName("clean_machine_remote_restore")] bool CleanMachineRemoteRestore,
    [property: JsonPropertyName("failure_matrix_cases_required")] int FailureMatrixCasesRequired);

public sealed record PreUpdateBackupPolicy(
    [property: JsonPropertyName("required")] bool Required,
    [property: JsonPropertyName("recent_local_verified_max_age_seconds")] long? RecentLocalVerifiedMaxAgeSeconds,
    [property: JsonPropertyName("require_remote_verified_before_db_boundary")] bool RequireRemoteVerifiedBeforeDbBoundary,
    [property: JsonPropertyName("system_recovery_snapshot_required")] bool SystemRecoverySnapshotRequired,
    [property: JsonPropertyName("on_failure")] string OnFailure);

public sealed record ReleaseSetManifest(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] string SchemaVersion,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("release_type")] string ReleaseType,
    [property: JsonPropertyName("created_at")] DateTimeOffset CreatedAt,
    [property: JsonPropertyName("expires_at")] DateTimeOffset ExpiresAt,
    [property: JsonPropertyName("for_install")] bool ForInstall,
    [property: JsonPropertyName("production_pass")] bool ProductionPass,
    [property: JsonPropertyName("signature")] ReleaseSetSignature Signature,
    [property: JsonPropertyName("requirements")] ReleaseSetRequirements Requirements,
    [property: JsonPropertyName("supported_baselines")] ReleaseBaseline[] SupportedBaselines,
    [property: JsonPropertyName("targets")] JsonElement Targets,
    [property: JsonPropertyName("artifacts")] Dictionary<string, ReleaseArtifact> Artifacts,
    [property: JsonPropertyName("dependency_graph")] JsonElement DependencyGraph,
    [property: JsonPropertyName("install_plan")] InstallPlanStep[] InstallPlan,
    [property: JsonPropertyName("compatibility")] ReleaseCompatibility Compatibility,
    [property: JsonPropertyName("mutation_policy")] ReleaseMutationPolicy MutationPolicy,
    [property: JsonPropertyName("database")] ReleaseDatabase Database,
    [property: JsonPropertyName("recovery")] ReleaseRecovery Recovery,
    [property: JsonPropertyName("qualification")] ReleaseQualification Qualification,
    [property: JsonPropertyName("pre_update_backup_policy")] PreUpdateBackupPolicy PreUpdateBackupPolicy);

public sealed record InstalledReleaseIdentity(
    [property: JsonPropertyName("local")] ReleaseComponentIdentity? Local,
    [property: JsonPropertyName("public")] ReleaseComponentIdentity? Public,
    [property: JsonPropertyName("maintenance_supervisor_version")] string MaintenanceSupervisorVersion,
    [property: JsonPropertyName("update_engine_version")] string UpdateEngineVersion,
    [property: JsonPropertyName("backup_recovery_engine_version")] string? BackupRecoveryEngineVersion,
    [property: JsonPropertyName("public_maintenance_kernel_version")] string? PublicMaintenanceKernelVersion);

public sealed record ReleaseSequenceState(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("highest_sequence")] long HighestSequence,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("manifest_sha256")] string ManifestSha256,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc);

public sealed record StagedReleaseReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("release_set_id")] string ReleaseSetId,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("manifest_sha256")] string ManifestSha256,
    [property: JsonPropertyName("package_sha256")] string PackageSha256,
    [property: JsonPropertyName("stage_root")] string StageRoot,
    [property: JsonPropertyName("staged_at_utc")] DateTimeOffset StagedAtUtc,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("preflight_identity_match")] bool PreflightIdentityMatch,
    [property: JsonPropertyName("signature_verified")] bool SignatureVerified,
    [property: JsonPropertyName("artifact_integrity_verified")] bool ArtifactIntegrityVerified,
    [property: JsonPropertyName("operator_authorized")] bool OperatorAuthorized = false);
