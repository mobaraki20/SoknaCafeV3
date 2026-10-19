using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalPackagePreflightRequest(
    [property: JsonPropertyName("package_ref")] string PackageRef,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record LocalPackagePreflightReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("operation_id")] string OperationId,
    [property: JsonPropertyName("package_ref")] string PackageRef,
    [property: JsonPropertyName("installed_identity_sha256")] string InstalledIdentitySha256,
    [property: JsonPropertyName("verification")] ReleaseSetPreflightResult Verification,
    [property: JsonPropertyName("verified_at_utc")] DateTimeOffset VerifiedAtUtc);

public sealed record LocalPackagePreflightOutcome(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled,
    [property: JsonPropertyName("receipt")] LocalPackagePreflightReceipt? Receipt);

/// <summary>
/// Signed Supervisor inspection boundary. This endpoint is intentionally NOT a
/// panel-ready update lifecycle. Its only durable writes are an operation receipt
/// and journal entry; package, sequence, slots, DB and services are not changed.
/// </summary>
public sealed class SupervisorLocalPackagePreflightCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _state;

    public SupervisorLocalPackagePreflightCoordinator(
        MaintenancePaths paths, MaintenanceOperationStore operations,
        DurableJournal journal, SupervisorState state)
    {
        _paths = paths;
        _operations = operations;
        _journal = journal;
        _state = state;
    }

    public LocalPackagePreflightOutcome Verify(
        string packageRef, string operatorId, string idempotencyKey)
    {
        packageRef = RequirePackageRef(packageRef);
        operatorId = RequireOperatorId(operatorId);
        lock (LocalUpdateOperationGate.Sync)
        {
            // Existing operations must not be overlooked because they are older
            // than the last 100 receipts. Damaged journal evidence fails closed.
            var prior = _operations.GetByIdempotencyKey(idempotencyKey);
            if (_operations.ListBlocking().Any(op => prior is null || op.OperationId != prior.OperationId || op.State == "recovery_required"))
                throw new InvalidOperationException("UPDATER_OPERATION_BUSY");

            var hash = MaintenanceOperationStore.CanonicalRequestHash(
                "update.local-package-preflight", packageRef, operatorId);
            var (operation, created) = _operations.GetOrCreate(
                idempotencyKey, hash, "update.local-package-preflight",
                packageRef, operatorId);

            if (operation.State == "rejected_pre_mutation")
                return new(operation, created, !created, null);
            if (operation.State != "accepted" && operation.State != "running" &&
                operation.State != "verified")
                throw new InvalidDataException("Preflight durable operation is in an unsupported state.");

            var package = Path.Combine(_paths.InboxRoot, packageRef);
            try
            {
                RequireOwnedInboxPath(_paths.InboxRoot, package);
                if (!File.Exists(_paths.InstalledReleaseIdentityPath))
                    throw new InvalidDataException("Installed release identity has not been bound.");
                var identityBytes = File.ReadAllBytes(_paths.InstalledReleaseIdentityPath);
                var identityHash = Sha256(identityBytes);
                InstalledReleaseIdentity installed;
                try
                {
                    installed = JsonSerializer.Deserialize<InstalledReleaseIdentity>(
                        identityBytes, AtomicFile.JsonOptions)
                        ?? throw new InvalidDataException("Installed identity is empty.");
                }
                catch (JsonException ex)
                {
                    throw new InvalidDataException("Installed identity JSON is invalid.", ex);
                }

                // Validates the signed manifest, artifact hashes, exact baseline
                // and anti-rollback state WITHOUT persisting a stage or sequence.
                var verified = new ReleaseSetVerifierStager(_paths)
                    .VerifyOnly(package, installed);
                using var opened = File.OpenRead(package);
                var packageAfter = Sha256(opened);
                var identityAfter = Sha256(File.ReadAllBytes(_paths.InstalledReleaseIdentityPath));
                if (!string.Equals(packageAfter, verified.PackageSha256, StringComparison.Ordinal) ||
                    !string.Equals(identityAfter, identityHash, StringComparison.Ordinal))
                    throw new InvalidDataException("Package or installed identity changed during preflight.");

                var receiptPath = ReceiptPath(operation.OperationId);
                if (operation.State == "verified")
                {
                    if (!File.Exists(receiptPath))
                        throw new InvalidDataException("Verified preflight operation has no durable receipt.");
                    var saved = AtomicFile.ReadJson<LocalPackagePreflightReceipt>(receiptPath);
                    if (saved is null || saved.Format != "sokna-local-package-preflight-v1" ||
                        saved.SchemaVersion != 1 || saved.OperationId != operation.OperationId ||
                        saved.PackageRef != packageRef || saved.InstalledIdentitySha256 != identityHash ||
                        saved.Verification.PackageSha256 != verified.PackageSha256 ||
                        saved.Verification.ManifestSha256 != verified.ManifestSha256)
                        throw new InvalidDataException("Preflight receipt identity is stale or corrupt.");
                    return new(operation, false, true, saved);
                }

                operation = _operations.Update(operation, "running", incrementAttempt: true);
                var receipt = new LocalPackagePreflightReceipt(
                    "sokna-local-package-preflight-v1", 1, operation.OperationId,
                    packageRef, identityHash, verified, DateTimeOffset.UtcNow);
                AtomicFile.WriteJson(receiptPath, receipt);
                operation = _operations.Update(operation, "verified",
                    "PACKAGE_INTEGRITY_VERIFIED",
                    "Signed package and installed baseline verified; activation NOT authorized.",
                    preflightReceiptPath: receiptPath);
                Journal("update.local_preflight.verified", operation);
                return new(operation, created, !created, receipt);
            }
            catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException
                or CryptographicException or JsonException)
            {
                // Even an error from an old verified result revokes the previous
                // acceptance: never return a stale green preflight after drift.
                operation = _operations.Update(operation, "rejected_pre_mutation",
                    "PACKAGE_PREFLIGHT_REJECTED", ex.Message.Length > 500 ? ex.Message[..500] : ex.Message);
                Journal("update.local_preflight.rejected", operation);
                return new(operation, created, !created, null);
            }
        }
    }

    private string ReceiptPath(string id)
        => Path.Combine(_paths.OperationsRoot, "preflight-" + id + ".json");

    private void Journal(string eventType, MaintenanceOperation operation)
        => _journal.Append(eventType, _state.InstanceId, _state.InstallGeneration,
            new Dictionary<string, object?>
            {
                ["operation_id"] = operation.OperationId,
                ["operator_id"] = operation.OperatorId,
                ["package_ref"] = operation.PackageRef,
                ["result_code"] = operation.ResultCode,
                ["activation_performed"] = false,
                ["database_mutation_performed"] = false
            });

    private static void RequireOwnedInboxPath(string inbox, string packagePath)
    {
        if (!Directory.Exists(inbox) || !File.Exists(packagePath))
            throw new InvalidDataException("Release package is missing from the Supervisor inbox.");
        if ((File.GetAttributes(inbox) & FileAttributes.ReparsePoint) != 0 ||
            (File.GetAttributes(packagePath) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("Reparse-point package/inbox paths are forbidden.");
    }

    private static string RequirePackageRef(string? value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 160 ||
            value is "." or ".." || !value.EndsWith(".zip", StringComparison.OrdinalIgnoreCase) ||
            value.Any(static c => !(c is >= 'a' and <= 'z' or >= 'A' and <= 'Z' or >= '0' and <= '9' or '-' or '_' or '.')))
            throw new InvalidDataException("package_ref must be a simple ASCII ZIP filename.");
        return value;
    }

    private static string RequireOperatorId(string? value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 128 ||
            value.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("operator_id is invalid.");
        return value;
    }

    private static string Sha256(byte[] bytes)
        => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    private static string Sha256(Stream input)
        => Convert.ToHexString(SHA256.HashData(input)).ToLowerInvariant();
}
