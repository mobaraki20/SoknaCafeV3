using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record SupervisorState(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("protocol")] string Protocol,
    [property: JsonPropertyName("instance_id")] string InstanceId,
    [property: JsonPropertyName("install_generation")] long InstallGeneration,
    [property: JsonPropertyName("created_at_utc")] DateTimeOffset CreatedAtUtc,
    [property: JsonPropertyName("last_started_at_utc")] DateTimeOffset LastStartedAtUtc,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("local_release_binding_state")] string LocalReleaseBindingState);

public sealed record JournalEntry(
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("event_id")] string EventId,
    [property: JsonPropertyName("event_type")] string EventType,
    [property: JsonPropertyName("occurred_at_utc")] DateTimeOffset OccurredAtUtc,
    [property: JsonPropertyName("instance_id")] string InstanceId,
    [property: JsonPropertyName("install_generation")] long InstallGeneration,
    [property: JsonPropertyName("details")] IReadOnlyDictionary<string, object?> Details);

public sealed record ControlPlaneDescriptor(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("protocol")] string Protocol,
    [property: JsonPropertyName("base_url")] string BaseUrl,
    [property: JsonPropertyName("supervisor_version")] string SupervisorVersion,
    [property: JsonPropertyName("instance_id")] string InstanceId,
    [property: JsonPropertyName("install_generation")] long InstallGeneration,
    [property: JsonPropertyName("readiness")] string Readiness,
    [property: JsonPropertyName("auth_scheme")] string AuthScheme,
    [property: JsonPropertyName("supported_key_ids")] string[] SupportedKeyIds,
    [property: JsonPropertyName("read_only_p1")] bool ReadOnlyP1,
    [property: JsonPropertyName("written_at_utc")] DateTimeOffset WrittenAtUtc);

public sealed record ApiEnvelope(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("protocol")] string Protocol,
    [property: JsonPropertyName("operation_id")] string? OperationId,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("retryable")] bool Retryable,
    [property: JsonPropertyName("requires_recovery_console")] bool RequiresRecoveryConsole,
    [property: JsonPropertyName("data")] object Data);
