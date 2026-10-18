using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record MaintenanceOperation(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("operation_id")] string OperationId,
    [property: JsonPropertyName("idempotency_key")] string IdempotencyKey,
    [property: JsonPropertyName("canonical_request_sha256")] string CanonicalRequestSha256,
    [property: JsonPropertyName("operation_type")] string OperationType,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("package_ref")] string PackageRef,
    [property: JsonPropertyName("created_at_utc")] DateTimeOffset CreatedAtUtc,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc,
    [property: JsonPropertyName("attempt_count")] int AttemptCount,
    [property: JsonPropertyName("result_code")] string? ResultCode,
    [property: JsonPropertyName("result_message")] string? ResultMessage,
    [property: JsonPropertyName("stage_receipt_path")] string? StageReceiptPath,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("preflight_receipt_path")] string? PreflightReceiptPath = null);

public sealed class MaintenanceOperationStore
{
    private readonly MaintenancePaths _paths;
    private readonly object _sync = new();

    public MaintenanceOperationStore(MaintenancePaths paths)
    {
        _paths = paths;
        Directory.CreateDirectory(_paths.OperationsRoot);
    }

    public static string CanonicalRequestHash(string operationType, string packageRef, string operatorId)
    {
        var canonical = $"{operationType.Trim().ToLowerInvariant()}\n{packageRef.Trim()}\n{operatorId.Trim()}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(canonical))).ToLowerInvariant();
    }

    public (MaintenanceOperation Operation, bool Created) GetOrCreate(string idempotencyKey, string requestHash, string operationType, string packageRef, string operatorId)
    {
        ValidateIdempotencyKey(idempotencyKey);
        ValidateOperatorId(operatorId);
        lock (_sync)
        {
            var indexPath = IndexPath(idempotencyKey);
            if (File.Exists(indexPath))
            {
                var operationId = File.ReadAllText(indexPath).Trim();
                var existing = Get(operationId) ?? throw new InvalidDataException("Idempotency index points to a missing maintenance operation.");
                if (!FixedHashEquals(existing.CanonicalRequestSha256, requestHash))
                    throw new InvalidDataException("Idempotency key was already used for a different maintenance request.");
                return (existing, false);
            }

            var now = DateTimeOffset.UtcNow;
            var operation = new MaintenanceOperation(
                "sokna-maintenance-operation-v1", 1, Guid.NewGuid().ToString("N"), idempotencyKey, requestHash,
                operationType, operatorId, "accepted", packageRef, now, now, 0, null, null, null, false, false);
            AtomicFile.WriteJson(OperationPath(operation.OperationId), operation);
            AtomicWriteText(indexPath, operation.OperationId + Environment.NewLine);
            return (operation, true);
        }
    }

    public MaintenanceOperation? Get(string operationId)
    {
        if (!IsSafeToken(operationId, 64)) return null;
        var path = OperationPath(operationId);
        if (!File.Exists(path)) return null;
        var operation = AtomicFile.ReadJson<MaintenanceOperation>(path);
        ValidateStored(operation, operationId);
        return operation;
    }

    public MaintenanceOperation? GetByIdempotencyKey(string idempotencyKey)
    {
        ValidateIdempotencyKey(idempotencyKey);
        var path = IndexPath(idempotencyKey);
        if (!File.Exists(path)) return null;
        var operationId = File.ReadAllText(path).Trim();
        if (!IsSafeToken(operationId, 64)) throw new InvalidDataException("Idempotency index contains an invalid operation id.");
        return Get(operationId) ?? throw new InvalidDataException("Idempotency index points to a missing maintenance operation.");
    }

    /// <summary>
    /// Do not interpret corrupt or missing operation evidence as an idle updater.
    /// ListActive scans the whole bounded journal, NOT just the latest page.
    /// </summary>
    private IReadOnlyList<MaintenanceOperation> LoadAllOperations()
    {
        lock (_sync)
        {
            var paths = Directory.EnumerateFiles(_paths.OperationsRoot, "op-*.json",
                SearchOption.TopDirectoryOnly).ToArray();
            if (paths.Length > 20_000)
                throw new InvalidDataException("Maintenance operation history exceeds the safe inspection limit.");
            var result = new List<MaintenanceOperation>(paths.Length);
            foreach (var path in paths)
            {
                var operation = AtomicFile.ReadJson<MaintenanceOperation>(path);
                ValidateStored(operation, Path.GetFileNameWithoutExtension(path)[3..]);
                result.Add(operation);
            }
            return result;
        }
    }

    public IReadOnlyList<MaintenanceOperation> ListRecent(int max = 30)
    {
        if (max is < 1 or > 100) throw new ArgumentOutOfRangeException(nameof(max));
        return LoadAllOperations().OrderByDescending(static o => o.UpdatedAtUtc)
            .ThenByDescending(static o => o.OperationId, StringComparer.Ordinal)
            .Take(max).ToArray();
    }

    public IReadOnlyList<MaintenanceOperation> ListActive()
        => LoadAllOperations()
            .Where(static o => o.State is "accepted" or "running" or "activating" or "recovering")
            .OrderBy(static o => o.CreatedAtUtc)
            .ToArray();

    public MaintenanceOperation Update(
        MaintenanceOperation operation,
        string state,
        string? resultCode = null,
        string? resultMessage = null,
        string? stageReceiptPath = null,
        bool incrementAttempt = false,
        bool? activationPerformed = null,
        bool? databaseMutationPerformed = null,
        string? preflightReceiptPath = null)
    {
        lock (_sync)
        {
            var current = Get(operation.OperationId) ?? throw new InvalidDataException("Maintenance operation disappeared during execution.");
            if (!FixedHashEquals(current.CanonicalRequestSha256, operation.CanonicalRequestSha256) || current.IdempotencyKey != operation.IdempotencyKey)
                throw new InvalidDataException("Maintenance operation identity changed unexpectedly.");
            var updated = current with
            {
                State = state,
                UpdatedAtUtc = DateTimeOffset.UtcNow,
                AttemptCount = incrementAttempt ? checked(current.AttemptCount + 1) : current.AttemptCount,
                ResultCode = resultCode,
                ResultMessage = resultMessage,
                StageReceiptPath = stageReceiptPath,
                ActivationPerformed = activationPerformed ?? current.ActivationPerformed,
                DatabaseMutationPerformed = databaseMutationPerformed ?? current.DatabaseMutationPerformed,
                PreflightReceiptPath = preflightReceiptPath ?? current.PreflightReceiptPath
            };
            AtomicFile.WriteJson(OperationPath(updated.OperationId), updated);
            return updated;
        }
    }

    private string OperationPath(string id) => Path.Combine(_paths.OperationsRoot, "op-" + id + ".json");
    private string IndexPath(string key)
    {
        var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(key))).ToLowerInvariant();
        return Path.Combine(_paths.OperationsRoot, "idem-" + hash + ".txt");
    }

    public static bool IsValidIdempotencyKey(string? key) => IsSafeToken(key, 128);

    private static void ValidateIdempotencyKey(string key)
    {
        if (!IsValidIdempotencyKey(key)) throw new InvalidDataException("Idempotency key is invalid.");
    }

    private static void ValidateOperatorId(string value)
    {
        if (!IsSafeToken(value, 128)) throw new InvalidDataException("operator_id is invalid.");
    }

    private static bool IsSafeToken(string? value, int maxLength)
        => !string.IsNullOrWhiteSpace(value) && value.Length <= maxLength && value.All(static c => char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':');

    private static bool FixedHashEquals(string left, string right)
    {
        try
        {
            var a = Convert.FromHexString(left);
            var b = Convert.FromHexString(right);
            return a.Length == b.Length && CryptographicOperations.FixedTimeEquals(a, b);
        }
        catch (FormatException)
        {
            return false;
        }
    }

    private static void ValidateStored(MaintenanceOperation? operation, string expectedOperationId)
    {
        // A well-formed JSON document can still be truncated, contain null required
        // properties, or describe a different operation from its durable filename.
        // All of these are corrupt evidence; never interpret them as updater idle.
        if (operation is null || operation.Format != "sokna-maintenance-operation-v1" || operation.SchemaVersion != 1 ||
            !IsSafeToken(operation.OperationId, 64) ||
            !string.Equals(operation.OperationId, expectedOperationId, StringComparison.Ordinal) ||
            !IsSafeToken(operation.IdempotencyKey, 128) || !IsSafeToken(operation.OperatorId, 128) ||
            !IsSafeToken(operation.OperationType, 128) || !IsSafeToken(operation.State, 64) ||
            string.IsNullOrWhiteSpace(operation.PackageRef) || operation.PackageRef.Length > 4096 ||
            !IsSha256(operation.CanonicalRequestSha256) ||
            operation.AttemptCount < 0 || operation.UpdatedAtUtc < operation.CreatedAtUtc)
            throw new InvalidDataException("Maintenance operation record is invalid or does not match its durable identity.");
    }

    private static bool IsSha256(string? value)
        => value is { Length: 64 } &&
           value.All(static c => c is >= '0' and <= '9' or >= 'a' and <= 'f' or >= 'A' and <= 'F');

    private static void AtomicWriteText(string path, string text)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        var temp = path + ".tmp." + Guid.NewGuid().ToString("N");
        File.WriteAllText(temp, text, new UTF8Encoding(false));
        using (var stream = new FileStream(temp, FileMode.Open, FileAccess.ReadWrite, FileShare.None)) stream.Flush(flushToDisk: true);
        File.Move(temp, path, overwrite: false);
    }
}
