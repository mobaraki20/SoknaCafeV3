using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record StageUpdateRequest(
    [property: JsonPropertyName("package_ref")] string PackageRef,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record StageUpdateResult(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

public sealed class SupervisorUpdateStagingCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisorState;
    private readonly string _engineExecutable;

    public SupervisorUpdateStagingCoordinator(MaintenancePaths paths, MaintenanceOperationStore operations, DurableJournal journal, SupervisorState supervisorState, string engineExecutable)
    {
        _paths = paths;
        _operations = operations;
        _journal = journal;
        _supervisorState = supervisorState;
        _engineExecutable = Path.GetFullPath(engineExecutable);
    }

    public StageUpdateResult Stage(string packageRef, string operatorId, string idempotencyKey)
    {
        packageRef = NormalizePackageRef(packageRef);
        operatorId = NormalizeOperatorId(operatorId);
        var requestHash = MaintenanceOperationStore.CanonicalRequestHash("update.stage", packageRef, operatorId);
        var (operation, created) = _operations.GetOrCreate(idempotencyKey, requestHash, "update.stage", packageRef, operatorId);
        if (operation.State == "staged") return new(operation, created, Reconciled: !created);
        if (operation.State is "failed_pre_mutation" or "rejected_pre_mutation")
            return new(operation, created, Reconciled: !created);

        var packagePath = Path.Combine(_paths.InboxRoot, packageRef);
        if (!File.Exists(packagePath))
        {
            operation = _operations.Update(operation, "rejected_pre_mutation", "PACKAGE_NOT_FOUND", "Package reference does not exist in the Supervisor-owned inbox.");
            Journal("update.stage.rejected", operation);
            return new(operation, created, Reconciled: !created);
        }
        if (!File.Exists(_paths.InstalledReleaseIdentityPath))
        {
            operation = _operations.Update(operation, "rejected_pre_mutation", "LOCAL_IDENTITY_UNBOUND", "Authoritative installed release identity is not bound yet.");
            Journal("update.stage.rejected", operation);
            return new(operation, created, Reconciled: !created);
        }
        if (!File.Exists(_engineExecutable))
        {
            operation = _operations.Update(operation, "failed_pre_mutation", "UPDATE_ENGINE_UNAVAILABLE", "Versioned Update Engine executable is unavailable.");
            Journal("update.stage.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        operation = _operations.Update(operation, "running", incrementAttempt: true);
        Journal("update.stage.running", operation);
        var start = new ProcessStartInfo
        {
            FileName = _engineExecutable,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = AppContext.BaseDirectory
        };
        start.ArgumentList.Add("verify-stage");
        start.ArgumentList.Add("--package");
        start.ArgumentList.Add(packagePath);
        start.ArgumentList.Add("--identity");
        start.ArgumentList.Add(_paths.InstalledReleaseIdentityPath);
        start.ArgumentList.Add("--data-root");
        start.ArgumentList.Add(_paths.DataRoot);

        string stdout;
        string stderr;
        int exitCode;
        try
        {
            using var process = Process.Start(start) ?? throw new InvalidOperationException("Unable to start Update Engine.");
            var stdoutTask = process.StandardOutput.ReadToEndAsync();
            var stderrTask = process.StandardError.ReadToEndAsync();
            if (!process.WaitForExit(120_000))
            {
                try { process.Kill(entireProcessTree: true); } catch { }
                operation = _operations.Update(operation, "failed_pre_mutation", "UPDATE_ENGINE_TIMEOUT", "Update Engine exceeded the P2B staging timeout.");
                Journal("update.stage.failed", operation);
                return new(operation, created, Reconciled: !created);
            }
            stdout = stdoutTask.GetAwaiter().GetResult();
            stderr = stderrTask.GetAwaiter().GetResult();
            exitCode = process.ExitCode;
        }
        catch (Exception ex) when (ex is InvalidOperationException or System.ComponentModel.Win32Exception or IOException)
        {
            operation = _operations.Update(operation, "failed_pre_mutation", "UPDATE_ENGINE_START_FAILED", ex.Message);
            Journal("update.stage.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        if (exitCode != 0)
        {
            var detail = string.IsNullOrWhiteSpace(stderr) ? stdout : stderr;
            if (detail.Length > 2000) detail = detail[..2000];
            operation = _operations.Update(operation, "rejected_pre_mutation", "RELEASE_SET_REJECTED", detail.Trim());
            Journal("update.stage.rejected", operation);
            return new(operation, created, Reconciled: !created);
        }

        try
        {
            using var doc = JsonDocument.Parse(stdout);
            var root = doc.RootElement;
            if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() ||
                !root.TryGetProperty("phase", out var phase) || phase.GetString() != "STAGED" ||
                !root.TryGetProperty("receipt", out var receipt) ||
                !receipt.TryGetProperty("stage_root", out var stageRootElement))
                throw new InvalidDataException("Update Engine returned an invalid success envelope.");
            var stageRoot = stageRootElement.GetString();
            if (string.IsNullOrWhiteSpace(stageRoot)) throw new InvalidDataException("Update Engine success envelope omitted stage_root.");
            var receiptPath = Path.Combine(Path.GetFullPath(stageRoot), "stage-receipt.json");
            if (!File.Exists(receiptPath)) throw new InvalidDataException("Update Engine reported a stage without its durable receipt.");
            var receiptRecord = AtomicFile.ReadJson<StagedReleaseReceipt>(receiptPath);
            if (receiptRecord.ActivationPerformed || receiptRecord.DatabaseMutationPerformed || !receiptRecord.SignatureVerified || !receiptRecord.ArtifactIntegrityVerified || !receiptRecord.PreflightIdentityMatch)
                throw new InvalidDataException("Staged Release receipt violates P2B pre-mutation evidence requirements.");
            operation = _operations.Update(operation, "staged", "STAGED", "Release Set verified and staged without activation or DB mutation.", receiptPath);
            Journal("update.stage.completed", operation);
            return new(operation, created, Reconciled: !created);
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException)
        {
            operation = _operations.Update(operation, "failed_pre_mutation", "UPDATE_ENGINE_RESULT_INVALID", ex.Message);
            Journal("update.stage.failed", operation);
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
            ["package_ref"] = operation.PackageRef,
            ["attempt_count"] = operation.AttemptCount,
            ["result_code"] = operation.ResultCode,
            ["activation_performed"] = false,
            ["database_mutation_performed"] = false
        });

    private static string NormalizePackageRef(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 160) throw new InvalidDataException("package_ref is invalid.");
        var fileName = Path.GetFileName(value);
        if (!string.Equals(fileName, value, StringComparison.Ordinal) || !value.EndsWith(".zip", StringComparison.OrdinalIgnoreCase) ||
            value.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.')))
            throw new InvalidDataException("package_ref must be a simple ZIP filename in the Supervisor inbox.");
        return value;
    }

    private static string NormalizeOperatorId(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 128 || value.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("operator_id is invalid.");
        return value;
    }
}
