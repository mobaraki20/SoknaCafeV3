using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalSlotAdoptionRequest(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("request_id")] string RequestId,
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_managed_files")] int ExpectedManagedFiles,
    [property: JsonPropertyName("expected_managed_tree_sha256")] string ExpectedManagedTreeSha256,
    [property: JsonPropertyName("source_package_version")] string SourcePackageVersion,
    [property: JsonPropertyName("created_at_utc")] DateTimeOffset CreatedAtUtc);

public sealed record LocalSlotAdoptionStatus(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("request_id")] string RequestId,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("attempt_count")] int AttemptCount,
    [property: JsonPropertyName("last_operation_id")] string? LastOperationId,
    [property: JsonPropertyName("last_code")] string? LastCode,
    [property: JsonPropertyName("last_message")] string? LastMessage,
    [property: JsonPropertyName("next_attempt_at_utc")] DateTimeOffset? NextAttemptAtUtc,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc);

public sealed class LocalSlotAdoptionQueue
{
    public const string RequestFormat = "sokna-local-slot-adoption-request-v1";
    public const string StatusFormat = "sokna-local-slot-adoption-status-v1";
    public const int SchemaVersion = 1;

    private readonly MaintenancePaths _paths;
    private readonly object _sync = new();

    public LocalSlotAdoptionQueue(MaintenancePaths paths)
    {
        _paths = paths;
        Directory.CreateDirectory(_paths.BootstrapRoot);
    }

    public LocalSlotAdoptionRequest? LoadRequest()
    {
        lock (_sync)
        {
            if (!File.Exists(_paths.LocalSlotAdoptionRequestPath)) return null;
            var request = AtomicFile.ReadJson<LocalSlotAdoptionRequest>(_paths.LocalSlotAdoptionRequestPath);
            Validate(request);
            return request;
        }
    }

    public LocalSlotAdoptionStatus? LoadStatus()
    {
        lock (_sync)
        {
            if (!File.Exists(_paths.LocalSlotAdoptionStatusPath)) return null;
            var status = AtomicFile.ReadJson<LocalSlotAdoptionStatus>(_paths.LocalSlotAdoptionStatusPath);
            Validate(status);
            return status;
        }
    }

    public LocalSlotAdoptionStatus EnsurePending(LocalSlotAdoptionRequest request)
    {
        Validate(request);
        lock (_sync)
        {
            var current = File.Exists(_paths.LocalSlotAdoptionStatusPath)
                ? AtomicFile.ReadJson<LocalSlotAdoptionStatus>(_paths.LocalSlotAdoptionStatusPath)
                : null;
            if (current is not null)
            {
                Validate(current);
                if (!string.Equals(current.RequestId, request.RequestId, StringComparison.Ordinal))
                    throw new InvalidDataException("Local slot adoption status belongs to a different request.");
                return current;
            }

            var status = NewStatus(request.RequestId, "pending", 0, null, null, null, null, false);
            AtomicFile.WriteJson(_paths.LocalSlotAdoptionStatusPath, status);
            return status;
        }
    }

    public LocalSlotAdoptionStatus MarkRunning(LocalSlotAdoptionRequest request, int attemptCount)
        => Write(request, "running", attemptCount, null, null, null, null, false);

    public LocalSlotAdoptionStatus MarkRetry(
        LocalSlotAdoptionRequest request,
        int attemptCount,
        string? operationId,
        string? code,
        string? message,
        DateTimeOffset nextAttemptAtUtc)
        => Write(request, "retry_wait", attemptCount, operationId, code, message, nextAttemptAtUtc, false);

    public LocalSlotAdoptionStatus MarkCommitted(
        LocalSlotAdoptionRequest request,
        int attemptCount,
        string? operationId,
        string? code,
        string? message,
        bool activationPerformed)
        => Write(request, "committed", attemptCount, operationId, code, message, null, activationPerformed);

    public LocalSlotAdoptionStatus MarkRecoveryRequired(
        LocalSlotAdoptionRequest request,
        int attemptCount,
        string? operationId,
        string? code,
        string? message,
        bool activationPerformed)
        => Write(request, "recovery_required", attemptCount, operationId, code, message, null, activationPerformed);

    public LocalSlotAdoptionStatus MarkFailedTerminal(
        LocalSlotAdoptionRequest request,
        int attemptCount,
        string? operationId,
        string? code,
        string? message,
        bool activationPerformed)
        => Write(request, "failed_terminal", attemptCount, operationId, code, message, null, activationPerformed);

    public static bool IsTerminal(string state)
        => state is "committed" or "recovery_required" or "failed_terminal";

    private LocalSlotAdoptionStatus Write(
        LocalSlotAdoptionRequest request,
        string state,
        int attemptCount,
        string? operationId,
        string? code,
        string? message,
        DateTimeOffset? nextAttemptAtUtc,
        bool activationPerformed)
    {
        Validate(request);
        if (attemptCount < 0 || attemptCount > 10_000) throw new InvalidDataException("Local slot adoption attempt count is invalid.");
        if (!AllowedState(state)) throw new InvalidDataException("Local slot adoption state is invalid.");
        lock (_sync)
        {
            var status = NewStatus(
                request.RequestId,
                state,
                attemptCount,
                Safe(operationId, 128),
                Safe(code, 160),
                Safe(message, 2000),
                nextAttemptAtUtc,
                activationPerformed);
            AtomicFile.WriteJson(_paths.LocalSlotAdoptionStatusPath, status);
            return status;
        }
    }

    private static LocalSlotAdoptionStatus NewStatus(
        string requestId,
        string state,
        int attemptCount,
        string? operationId,
        string? code,
        string? message,
        DateTimeOffset? nextAttemptAtUtc,
        bool activationPerformed)
        => new(
            StatusFormat,
            SchemaVersion,
            requestId,
            state,
            attemptCount,
            operationId,
            code,
            message,
            nextAttemptAtUtc,
            activationPerformed,
            false,
            false,
            false,
            DateTimeOffset.UtcNow);

    private static void Validate(LocalSlotAdoptionRequest request)
    {
        if (request.Format != RequestFormat || request.SchemaVersion != SchemaVersion)
            throw new InvalidDataException("Local slot adoption request format is invalid.");
        if (!SafeToken(request.RequestId, 128)) throw new InvalidDataException("Local slot adoption request_id is invalid.");
        if (string.IsNullOrWhiteSpace(request.ExpectedBuild) || request.ExpectedBuild.Length > 180 || request.ExpectedBuild.Any(char.IsControl))
            throw new InvalidDataException("Local slot adoption expected_build is invalid.");
        if (request.ExpectedManagedFiles is < 1 or > 20_000)
            throw new InvalidDataException("Local slot adoption expected_managed_files is invalid.");
        _ = LocalManagedTreeAttestation.NormalizeSha256(request.ExpectedManagedTreeSha256);
        if (string.IsNullOrWhiteSpace(request.SourcePackageVersion) || request.SourcePackageVersion.Length > 64 || request.SourcePackageVersion.Any(char.IsControl))
            throw new InvalidDataException("Local slot adoption source_package_version is invalid.");
        if (request.CreatedAtUtc > DateTimeOffset.UtcNow.AddMinutes(10))
            throw new InvalidDataException("Local slot adoption created_at_utc is invalid.");
    }

    private static void Validate(LocalSlotAdoptionStatus status)
    {
        if (status.Format != StatusFormat || status.SchemaVersion != SchemaVersion || !SafeToken(status.RequestId, 128) ||
            !AllowedState(status.State) || status.AttemptCount < 0 || status.AttemptCount > 10_000 ||
            status.DatabaseMutationPerformed || status.PublicMutationPerformed || status.WindowsRebootRequired)
            throw new InvalidDataException("Local slot adoption status is invalid.");
    }

    private static bool AllowedState(string state)
        => state is "pending" or "running" or "retry_wait" or "committed" or "recovery_required" or "failed_terminal";

    private static bool SafeToken(string value, int max)
        => !string.IsNullOrWhiteSpace(value) && value.Length <= max && value.All(static c => char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':');

    private static string? Safe(string? value, int max)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var text = value.Replace('\r', ' ').Replace('\n', ' ').Replace('\0', ' ').Trim();
        return text.Length <= max ? text : text[..max];
    }
}
