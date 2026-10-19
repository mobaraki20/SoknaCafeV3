using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalSlotHomeCycleRequest(
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_managed_files")] int ExpectedManagedFiles,
    [property: JsonPropertyName("expected_managed_tree_sha256")] string ExpectedManagedTreeSha256,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record LocalSlotOperationResult(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

public static class LocalManagedTreeAttestation
{
    public static string Compute(string webRoot, int expectedManagedFiles)
    {
        webRoot = Path.GetFullPath(webRoot);
        var inventoryPath = Path.Combine(webRoot, "resources", "local-managed-files-v1.json");
        if (!File.Exists(inventoryPath)) throw new FileNotFoundException("Current release managed inventory is missing.", inventoryPath);
        using var document = JsonDocument.Parse(File.ReadAllBytes(inventoryPath));
        var root = document.RootElement;
        if (!root.TryGetProperty("format", out var format) || format.GetString() != "sokna-local-managed-files-v1")
            throw new InvalidDataException("Managed inventory format is invalid.");
        if (!root.TryGetProperty("files", out var files) || files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("Managed inventory files array is invalid.");

        var normalized = new List<string>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var item in files.EnumerateArray())
        {
            var rel = (item.GetString() ?? string.Empty).Replace('\\', '/').TrimStart('/');
            if (string.IsNullOrWhiteSpace(rel) || Path.IsPathRooted(rel) || rel.Split('/').Any(static part => part is "" or "." or ".."))
                throw new InvalidDataException("Managed inventory contains an unsafe relative path.");
            if (!seen.Add(rel)) throw new InvalidDataException($"Managed inventory contains a duplicate path: {rel}");
            normalized.Add(rel);
        }
        if (normalized.Count != expectedManagedFiles)
            throw new InvalidDataException($"Managed inventory count mismatch. Expected {expectedManagedFiles}, found {normalized.Count}.");

        using var aggregate = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
        foreach (var rel in normalized.OrderBy(static value => value, StringComparer.Ordinal))
        {
            var fullPath = Path.Combine(webRoot, rel.Replace('/', Path.DirectorySeparatorChar));
            if (!File.Exists(fullPath)) throw new FileNotFoundException($"Managed Local file is missing: {rel}", fullPath);
            var fileHash = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(fullPath))).ToLowerInvariant();
            aggregate.AppendData(Encoding.UTF8.GetBytes(rel + "\0" + fileHash + "\n"));
        }
        return Convert.ToHexString(aggregate.GetHashAndReset()).ToLowerInvariant();
    }

    public static string RequireExact(string webRoot, int expectedManagedFiles, string expectedSha256)
    {
        expectedSha256 = NormalizeSha256(expectedSha256);
        var actual = Compute(webRoot, expectedManagedFiles);
        if (!FixedTimeHexEquals(actual, expectedSha256))
            throw new InvalidDataException($"Managed Local tree digest mismatch. Expected {expectedSha256}, found {actual}.");
        return actual;
    }

    public static string NormalizeSha256(string value)
    {
        value = (value ?? string.Empty).Trim().ToLowerInvariant();
        if (value.Length != 64 || value.Any(static c => !char.IsAsciiHexDigit(c)))
            throw new InvalidDataException("expected_managed_tree_sha256 is invalid.");
        return value;
    }

    private static bool FixedTimeHexEquals(string left, string right)
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
}

public sealed class SupervisorLocalSlotCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisorState;
    private readonly string _engineExecutable;

    public SupervisorLocalSlotCoordinator(
        MaintenancePaths paths,
        MaintenanceOperationStore operations,
        DurableJournal journal,
        SupervisorState supervisorState,
        string engineExecutable)
    {
        _paths = paths;
        _operations = operations;
        _journal = journal;
        _supervisorState = supervisorState;
        _engineExecutable = Path.GetFullPath(engineExecutable);
    }

    public LocalSlotOperationResult RunHomeCycle(string expectedBuild, int expectedManagedFiles, string expectedManagedTreeSha256, string operatorId, string idempotencyKey)
    {
        lock (LocalUpdateOperationGate.Sync)
        {
            LocalUpdateOperationGate.RequireNoPendingMutation(_operations);
            using var machineLease = MachineMutationLease.Acquire(_paths, EngineDelegationPermit.Owner);
            return RunHomeCycleCore(expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, operatorId, idempotencyKey, machineLease);
        }
    }

    private LocalSlotOperationResult RunHomeCycleCore(string expectedBuild, int expectedManagedFiles, string expectedManagedTreeSha256, string operatorId, string idempotencyKey, MachineMutationLease machineLease)
    {
        expectedBuild = NormalizeBuild(expectedBuild);
        expectedManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(expectedManagedTreeSha256);
        operatorId = NormalizeOperatorId(operatorId);
        if (expectedManagedFiles < 1 || expectedManagedFiles > 20_000) throw new InvalidDataException("expected_managed_files is invalid.");

        var requestRef = $"phase3-local-slots:{expectedBuild}:{expectedManagedFiles}:{expectedManagedTreeSha256}";
        var requestHash = MaintenanceOperationStore.CanonicalRequestHash("update.local-slot-home-cycle", requestRef, operatorId);
        var (operation, created) = _operations.GetOrCreate(idempotencyKey, requestHash, "update.local-slot-home-cycle", requestRef, operatorId);
        if (operation.State == "committed") return new(operation, created, Reconciled: !created);
        if (operation.State is "failed" or "recovery_required") return new(operation, created, Reconciled: !created);
        if (!File.Exists(_engineExecutable))
        {
            operation = _operations.Update(operation, "failed", "UPDATE_ENGINE_UNAVAILABLE", "Versioned Update Engine executable is unavailable.");
            Journal("update.local_slot.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        operation = _operations.Update(operation, "running", incrementAttempt: true);
        Journal("update.local_slot.running", operation);
        var engineArguments = new[]
        {
            "local-slot-home-cycle",
            "--expected-build", expectedBuild,
            "--expected-managed-files", expectedManagedFiles.ToString(System.Globalization.CultureInfo.InvariantCulture),
            "--expected-managed-tree-sha256", expectedManagedTreeSha256,
            "--data-root", _paths.DataRoot
        };
        string stdout;
        string stderr;
        int exitCode;
        try
        {
            var permitId = EngineDelegationPermit.Issue(_paths, machineLease,
                operation.OperationId, engineArguments);
            var exactArguments = engineArguments.Concat(new[] { "--delegation-id", permitId }).ToArray();
            using var child = WindowsJobChildProcess.Start(_engineExecutable,
                exactArguments, AppContext.BaseDirectory);
            var result = child.WaitAndCapture(240_000);
            if (!result.Finished)
            {
                // A timed-out Engine may have reached pointer activation.
                // Never claim a clean retry without inspecting durable state.
                operation = _operations.Update(operation, "recovery_required",
                    "UPDATE_ENGINE_TIMEOUT_STATE_UNPROVEN",
                    "Phase 3 Local-slot Job expired; child and descendants were terminated. Slot activation status unproven.",
                    File.Exists(_paths.LocalSlotStatePath) ? _paths.LocalSlotStatePath : null,
                    activationPerformed: File.Exists(_paths.LocalSlotStatePath),
                    databaseMutationPerformed: false);
                Journal("update.local_slot.recovery_required", operation);
                return new(operation, created, Reconciled: !created);
            }
            stdout = result.Stdout;
            stderr = result.Stderr;
            exitCode = result.ExitCode;
        }
        catch (Exception ex) when (ex is InvalidOperationException or
            System.ComponentModel.Win32Exception or IOException or UnauthorizedAccessException)
        {
            operation = _operations.Update(operation, "recovery_required",
                "UPDATE_ENGINE_JOB_START_UNPROVEN", ex.Message,
                File.Exists(_paths.LocalSlotStatePath) ? _paths.LocalSlotStatePath : null,
                activationPerformed: File.Exists(_paths.LocalSlotStatePath),
                databaseMutationPerformed: false);
            Journal("update.local_slot.recovery_required", operation);
            return new(operation, created, Reconciled: !created);
        }

        if (exitCode != 0)
        {
            var detail = string.IsNullOrWhiteSpace(stderr) ? stdout : stderr;
            if (detail.Length > 4000) detail = detail[..4000];
            var recoveryRequired = false;
            var activationAttempted = false;
            try
            {
                using var doc = JsonDocument.Parse(detail);
                activationAttempted = doc.RootElement.TryGetProperty("activation_performed", out var activation) && activation.GetBoolean();
                if (File.Exists(_paths.LocalSlotStatePath))
                    recoveryRequired = AtomicFile.ReadJson<LocalSlotPhase3State>(_paths.LocalSlotStatePath).RecoveryRequired;
            }
            catch
            {
            }
            operation = _operations.Update(
                operation,
                recoveryRequired ? "recovery_required" : "failed",
                recoveryRequired ? "LOCAL_SLOT_RECOVERY_REQUIRED" : "LOCAL_SLOT_PHASE3_FAILED",
                detail.Trim(),
                File.Exists(_paths.LocalSlotStatePath) ? _paths.LocalSlotStatePath : null,
                activationPerformed: activationAttempted,
                databaseMutationPerformed: false);
            Journal(recoveryRequired ? "update.local_slot.recovery_required" : "update.local_slot.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        try
        {
            using var doc = JsonDocument.Parse(stdout);
            var root = doc.RootElement;
            if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() ||
                !root.TryGetProperty("phase", out var phase) || phase.GetString() != "COMMITTED" ||
                !root.TryGetProperty("activation_performed", out var activation) || !activation.GetBoolean() ||
                !root.TryGetProperty("database_mutation_performed", out var db) || db.GetBoolean() ||
                !root.TryGetProperty("managed_tree_sha256", out var tree) ||
                !string.Equals(tree.GetString(), expectedManagedTreeSha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("Update Engine returned an invalid Phase 3 success envelope.");
            if (!File.Exists(_paths.LocalSlotStatePath)) throw new InvalidDataException("Phase 3 committed without durable Local-slot state.");
            var state = AtomicFile.ReadJson<LocalSlotPhase3State>(_paths.LocalSlotStatePath);
            if (state.RecoveryRequired || state.DatabaseMutationPerformed || state.PublicMutationPerformed || state.WindowsRebootRequired || state.Phase != "COMMITTED")
                throw new InvalidDataException("Durable Local-slot state violates Phase 3 commit invariants.");

            operation = _operations.Update(
                operation,
                "committed",
                "LOCAL_SLOT_PHASE3_COMMITTED",
                $"Local Web converted to release slots and same-build A/B/A activation passed; managed_tree={expectedManagedTreeSha256}.",
                _paths.LocalSlotStatePath,
                activationPerformed: true,
                databaseMutationPerformed: false);
            Journal("update.local_slot.committed", operation);
            return new(operation, created, Reconciled: !created);
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException)
        {
            operation = _operations.Update(operation, "recovery_required", "UPDATE_ENGINE_RESULT_INVALID", ex.Message,
                File.Exists(_paths.LocalSlotStatePath) ? _paths.LocalSlotStatePath : null,
                activationPerformed: true, databaseMutationPerformed: false);
            Journal("update.local_slot.recovery_required", operation);
            return new(operation, created, Reconciled: !created);
        }
    }

    private void Journal(string eventType, MaintenanceOperation operation)
        => _journal.Append(eventType, _supervisorState.InstanceId, _supervisorState.InstallGeneration, new Dictionary<string, object?>
        {
            ["operation_id"] = operation.OperationId,
            ["operation_type"] = operation.OperationType,
            ["operator_id"] = operation.OperatorId,
            ["state"] = operation.State,
            ["attempt_count"] = operation.AttemptCount,
            ["result_code"] = operation.ResultCode,
            ["activation_performed"] = operation.ActivationPerformed,
            ["database_mutation_performed"] = operation.DatabaseMutationPerformed,
            ["local_slot_state_path"] = File.Exists(_paths.LocalSlotStatePath) ? _paths.LocalSlotStatePath : null
        });

    private static string NormalizeBuild(string value)
    {
        value = value.Trim();
        if (string.IsNullOrWhiteSpace(value) || value.Length > 180 || value.Any(static c => char.IsControl(c)))
            throw new InvalidDataException("expected_build is invalid.");
        return value;
    }

    private static string NormalizeOperatorId(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 128 || value.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("operator_id is invalid.");
        return value;
    }
}
