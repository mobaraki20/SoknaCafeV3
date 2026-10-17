using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record ProductionLocalSlotMigrationRequest(
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_managed_files")] int ExpectedManagedFiles,
    [property: JsonPropertyName("expected_managed_tree_sha256")] string ExpectedManagedTreeSha256,
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed record ProductionLocalSlotRecoveryRequest(
    [property: JsonPropertyName("operator_id")] string OperatorId);

public sealed class SupervisorProductionMigrationCoordinator : IProductionLocalSlotMigrationCoordinator
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisorState;
    private readonly string _engineExecutable;
    private readonly object _executionSync = new();

    public SupervisorProductionMigrationCoordinator(
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

    public LocalSlotOperationResult Migrate(
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256,
        string operatorId,
        string idempotencyKey)
    {
        lock (_executionSync)
        {
            return MigrateCore(expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, operatorId, idempotencyKey);
        }
    }

    private LocalSlotOperationResult MigrateCore(
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256,
        string operatorId,
        string idempotencyKey)
    {
        expectedBuild = NormalizeBuild(expectedBuild);
        if (expectedManagedFiles is < 1 or > 20_000) throw new InvalidDataException("expected_managed_files is invalid.");
        expectedManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(expectedManagedTreeSha256);
        operatorId = NormalizeOperatorId(operatorId);

        var requestRef = $"production-local-slot-migration:{expectedBuild}:{expectedManagedFiles}:{expectedManagedTreeSha256}";
        var requestHash = MaintenanceOperationStore.CanonicalRequestHash("update.local-slot-migrate", requestRef, operatorId);
        var (operation, created) = _operations.GetOrCreate(idempotencyKey, requestHash, "update.local-slot-migrate", requestRef, operatorId);
        if (operation.State == "committed") return new(operation, created, Reconciled: !created);
        if (operation.State is "failed" or "recovery_required") return new(operation, created, Reconciled: !created);

        if (!File.Exists(_engineExecutable))
        {
            operation = _operations.Update(operation, "failed", "UPDATE_ENGINE_UNAVAILABLE", "Versioned Update Engine executable is unavailable.");
            Journal("update.local_slot_migration.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        operation = _operations.Update(operation, "running", incrementAttempt: true);
        Journal("update.local_slot_migration.running", operation);
        var execution = ExecuteEngine(new[]
        {
            "local-slot-migrate",
            "--expected-build", expectedBuild,
            "--expected-managed-files", expectedManagedFiles.ToString(System.Globalization.CultureInfo.InvariantCulture),
            "--expected-managed-tree-sha256", expectedManagedTreeSha256,
            "--data-root", _paths.DataRoot
        }, timeoutMs: 180_000);

        if (!execution.Started)
        {
            operation = _operations.Update(operation, "failed", execution.Code, execution.Detail);
            Journal("update.local_slot_migration.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        var migrationStatePath = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");
        if (execution.ExitCode != 0)
        {
            var activationPerformed = TryReadBool(execution.Detail, "activation_performed");
            var recoveryRequired = false;
            try
            {
                if (File.Exists(migrationStatePath))
                    recoveryRequired = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(migrationStatePath).RecoveryRequired;
            }
            catch
            {
                recoveryRequired = true;
            }

            operation = _operations.Update(
                operation,
                recoveryRequired ? "recovery_required" : "failed",
                recoveryRequired ? "LOCAL_SLOT_MIGRATION_RECOVERY_REQUIRED" : "LOCAL_SLOT_MIGRATION_FAILED",
                execution.Detail,
                File.Exists(migrationStatePath) ? migrationStatePath : null,
                activationPerformed: activationPerformed,
                databaseMutationPerformed: false);
            Journal(recoveryRequired ? "update.local_slot_migration.recovery_required" : "update.local_slot_migration.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        try
        {
            using var document = JsonDocument.Parse(execution.Stdout);
            var root = document.RootElement;
            var phase = root.TryGetProperty("phase", out var phaseElement) ? phaseElement.GetString() : null;
            if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() || phase is not ("COMMITTED" or "ALREADY_MIGRATED") ||
                !root.TryGetProperty("database_mutation_performed", out var db) || db.GetBoolean() ||
                !root.TryGetProperty("public_mutation_performed", out var pub) || pub.GetBoolean() ||
                !root.TryGetProperty("windows_reboot_required", out var reboot) || reboot.GetBoolean() ||
                !root.TryGetProperty("managed_tree_sha256", out var tree) ||
                !string.Equals(tree.GetString(), expectedManagedTreeSha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("Update Engine returned an invalid production migration success envelope.");

            if (!File.Exists(migrationStatePath)) throw new InvalidDataException("Production migration committed without durable state.");
            var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(migrationStatePath);
            if (state.RecoveryRequired || state.DatabaseMutationPerformed || state.PublicMutationPerformed || state.WindowsRebootRequired ||
                state.Phase is not ("COMMITTED" or "ALREADY_MIGRATED"))
                throw new InvalidDataException("Durable production migration state violates commit invariants.");

            var activationPerformed = root.TryGetProperty("activation_performed", out var activation) && activation.GetBoolean();
            operation = _operations.Update(
                operation,
                "committed",
                phase == "ALREADY_MIGRATED" ? "LOCAL_SLOT_LAYOUT_ALREADY_READY" : "LOCAL_SLOT_MIGRATION_COMMITTED",
                $"Production Local slot migration committed; managed_tree={expectedManagedTreeSha256}.",
                migrationStatePath,
                activationPerformed: activationPerformed,
                databaseMutationPerformed: false);
            Journal("update.local_slot_migration.committed", operation);
            return new(operation, created, Reconciled: !created);
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException)
        {
            operation = _operations.Update(
                operation,
                "recovery_required",
                "UPDATE_ENGINE_RESULT_INVALID",
                ex.Message,
                File.Exists(migrationStatePath) ? migrationStatePath : null,
                activationPerformed: true,
                databaseMutationPerformed: false);
            Journal("update.local_slot_migration.recovery_required", operation);
            return new(operation, created, Reconciled: !created);
        }
    }

    public LocalSlotOperationResult Recover(string operatorId, string idempotencyKey)
    {
        lock (_executionSync)
        {
            return RecoverCore(operatorId, idempotencyKey);
        }
    }

    private LocalSlotOperationResult RecoverCore(string operatorId, string idempotencyKey)
    {
        operatorId = NormalizeOperatorId(operatorId);
        var migrationStatePath = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");
        if (!File.Exists(migrationStatePath)) throw new InvalidDataException("Production Local migration state is unavailable.");
        var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(migrationStatePath);
        var requestRef = $"production-local-slot-recovery:{state.ExpectedBuild}:{state.UpdatedAtUtc:O}";
        var requestHash = MaintenanceOperationStore.CanonicalRequestHash("update.local-slot-recover", requestRef, operatorId);
        var (operation, created) = _operations.GetOrCreate(idempotencyKey, requestHash, "update.local-slot-recover", requestRef, operatorId);
        if (operation.State == "committed") return new(operation, created, Reconciled: !created);
        if (operation.State == "failed") return new(operation, created, Reconciled: !created);

        if (!File.Exists(_engineExecutable))
        {
            operation = _operations.Update(operation, "failed", "UPDATE_ENGINE_UNAVAILABLE", "Versioned Update Engine executable is unavailable.");
            Journal("update.local_slot_recovery.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        operation = _operations.Update(operation, "running", incrementAttempt: true);
        Journal("update.local_slot_recovery.running", operation);
        var execution = ExecuteEngine(new[] { "local-slot-recover", "--data-root", _paths.DataRoot }, timeoutMs: 180_000);
        if (!execution.Started || execution.ExitCode != 0)
        {
            operation = _operations.Update(
                operation,
                "failed",
                execution.Started ? "LOCAL_SLOT_RECOVERY_FAILED" : execution.Code,
                execution.Detail,
                migrationStatePath,
                activationPerformed: true,
                databaseMutationPerformed: false);
            Journal("update.local_slot_recovery.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        try
        {
            using var document = JsonDocument.Parse(execution.Stdout);
            var root = document.RootElement;
            if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() ||
                !root.TryGetProperty("recovery_required", out var recovery) || recovery.GetBoolean() ||
                !root.TryGetProperty("database_mutation_performed", out var db) || db.GetBoolean() ||
                !root.TryGetProperty("public_mutation_performed", out var pub) || pub.GetBoolean() ||
                !root.TryGetProperty("windows_reboot_required", out var reboot) || reboot.GetBoolean())
                throw new InvalidDataException("Update Engine returned an invalid production recovery success envelope.");

            operation = _operations.Update(
                operation,
                "committed",
                "LOCAL_SLOT_RECOVERY_COMMITTED",
                "Production Local slot recovery/reconciliation completed without DB/Public mutation.",
                migrationStatePath,
                activationPerformed: true,
                databaseMutationPerformed: false);
            Journal("update.local_slot_recovery.committed", operation);
            return new(operation, created, Reconciled: !created);
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException)
        {
            operation = _operations.Update(operation, "failed", "UPDATE_ENGINE_RESULT_INVALID", ex.Message, migrationStatePath, activationPerformed: true, databaseMutationPerformed: false);
            Journal("update.local_slot_recovery.failed", operation);
            return new(operation, created, Reconciled: !created);
        }
    }

    private EngineExecution ExecuteEngine(IReadOnlyList<string> arguments, int timeoutMs)
    {
        var start = new ProcessStartInfo
        {
            FileName = _engineExecutable,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = AppContext.BaseDirectory
        };
        foreach (var argument in arguments) start.ArgumentList.Add(argument);

        try
        {
            using var process = Process.Start(start) ?? throw new InvalidOperationException("Unable to start Update Engine.");
            var stdoutTask = process.StandardOutput.ReadToEndAsync();
            var stderrTask = process.StandardError.ReadToEndAsync();
            if (!process.WaitForExit(timeoutMs))
            {
                try { process.Kill(entireProcessTree: true); } catch { }
                return new(false, -1, string.Empty, "Update Engine exceeded the production migration timeout.", "UPDATE_ENGINE_TIMEOUT");
            }
            var stdout = stdoutTask.GetAwaiter().GetResult();
            var stderr = stderrTask.GetAwaiter().GetResult();
            var detail = string.IsNullOrWhiteSpace(stderr) ? stdout : stderr;
            if (detail.Length > 6000) detail = detail[..6000];
            return new(true, process.ExitCode, stdout, detail.Trim(), "OK");
        }
        catch (Exception ex) when (ex is InvalidOperationException or System.ComponentModel.Win32Exception or IOException)
        {
            return new(false, -1, string.Empty, ex.Message, "UPDATE_ENGINE_START_FAILED");
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
            ["migration_state_path"] = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json")
        });

    private static bool TryReadBool(string json, string name)
    {
        try
        {
            using var document = JsonDocument.Parse(json);
            return document.RootElement.TryGetProperty(name, out var value) && value.GetBoolean();
        }
        catch
        {
            return false;
        }
    }

    private static string NormalizeBuild(string value)
    {
        value = (value ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(value) || value.Length > 180 || value.Any(char.IsControl))
            throw new InvalidDataException("expected_build is invalid.");
        return value;
    }

    private static string NormalizeOperatorId(string value)
    {
        if (string.IsNullOrWhiteSpace(value) || value.Length > 128 || value.Any(static c => !(char.IsLetterOrDigit(c) || c is '-' or '_' or '.' or ':')))
            throw new InvalidDataException("operator_id is invalid.");
        return value;
    }

    private sealed record EngineExecution(bool Started, int ExitCode, string Stdout, string Detail, string Code);
}
