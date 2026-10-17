using System.Diagnostics;
using System.Security.Cryptography;
using System.ServiceProcess;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed record ProductionLocalSlotMigrationEvent(
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("at_utc")] DateTimeOffset AtUtc,
    [property: JsonPropertyName("detail")] string? Detail = null);

public sealed record ProductionLocalSlotMigrationState(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_managed_files")] int ExpectedManagedFiles,
    [property: JsonPropertyName("expected_managed_tree_sha256")] string ExpectedManagedTreeSha256,
    [property: JsonPropertyName("package_root")] string PackageRoot,
    [property: JsonPropertyName("stable_web_root")] string StableWebRoot,
    [property: JsonPropertyName("apache_executable")] string ApacheExecutable,
    [property: JsonPropertyName("health_url")] string HealthUrl,
    [property: JsonPropertyName("release_root")] string ReleaseRoot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("physical_web_backup")] string? PhysicalWebBackup,
    [property: JsonPropertyName("active_slot")] string? ActiveSlot,
    [property: JsonPropertyName("previous_slot")] string? PreviousSlot,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc,
    [property: JsonPropertyName("events")] IReadOnlyList<ProductionLocalSlotMigrationEvent> Events);

public sealed record ProductionLocalSlotMigrationResult(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("active_slot")] string? ActiveSlot,
    [property: JsonPropertyName("physical_web_backup")] string? PhysicalWebBackup,
    [property: JsonPropertyName("managed_tree_sha256")] string ManagedTreeSha256,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("state_path")] string StatePath);
