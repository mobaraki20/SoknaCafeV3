using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record LocalSlotHomeCycleRequest(
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_managed_files")] int ExpectedManagedFiles,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record LocalSlotOperationResult(
    [property: JsonPropertyName("operation")] MaintenanceOperation Operation,
    [property: JsonPropertyName("created")] bool Created,
    [property: JsonPropertyName("reconciled")] bool Reconciled);

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

    public LocalSlotOperationResult RunHomeCycle(string expectedBuild, int expectedManagedFiles, string operatorId, string idempotencyKey)
    {
        expectedBuild = NormalizeBuild(expectedBuild);
        operatorId = NormalizeOperatorId(operatorId);
        if (expectedManagedFiles < 1 || expectedManagedFiles > 20_000) throw new InvalidDataException("expected_managed_files is invalid.");

        var requestRef = $"phase3-local-slots:{expectedBuild}:{expectedManagedFiles}";
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
        var start = new ProcessStartInfo
        {
            FileName = _engineExecutable,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = AppContext.BaseDirectory
        };
        start.ArgumentList.Add("local-slot-home-cycle");
        start.ArgumentList.Add("--expected-build");
        start.ArgumentList.Add(expectedBuild);
        start.ArgumentList.Add("--expected-managed-files");
        start.ArgumentList.Add(expectedManagedFiles.ToString(System.Globalization.CultureInfo.InvariantCulture));
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
            if (!process.WaitForExit(240_000))
            {
                try { process.Kill(entireProcessTree: true); } catch { }
                operation = _operations.Update(operation, "failed", "UPDATE_ENGINE_TIMEOUT", "Phase 3 Local-slot operation exceeded the execution timeout.");
                Journal("update.local_slot.failed", operation);
                return new(operation, created, Reconciled: !created);
            }
            stdout = stdoutTask.GetAwaiter().GetResult();
            stderr = stderrTask.GetAwaiter().GetResult();
            exitCode = process.ExitCode;
        }
        catch (Exception ex) when (ex is InvalidOperationException or System.ComponentModel.Win32Exception or IOException)
        {
            operation = _operations.Update(operation, "failed", "UPDATE_ENGINE_START_FAILED", ex.Message);
            Journal("update.local_slot.failed", operation);
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
                !root.TryGetProperty("database_mutation_performed", out var db) || db.GetBoolean())
                throw new InvalidDataException("Update Engine returned an invalid Phase 3 success envelope.");
            if (!File.Exists(_paths.LocalSlotStatePath)) throw new InvalidDataException("Phase 3 committed without durable Local-slot state.");
            var state = AtomicFile.ReadJson<LocalSlotPhase3State>(_paths.LocalSlotStatePath);
            if (state.RecoveryRequired || state.DatabaseMutationPerformed || state.PublicMutationPerformed || state.WindowsRebootRequired || state.Phase != "COMMITTED")
                throw new InvalidDataException("Durable Local-slot state violates Phase 3 commit invariants.");

            operation = _operations.Update(
                operation,
                "committed",
                "LOCAL_SLOT_PHASE3_COMMITTED",
                "Local Web converted to release slots and same-build A/B/A activation passed.",
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
