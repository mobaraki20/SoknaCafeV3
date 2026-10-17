using System.Diagnostics;
using System.ServiceProcess;
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

        var migrationStatePath = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");
        var priorAssessment = AssessDurableMigrationState(migrationStatePath, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
        if (priorAssessment.Error is not null)
        {
            operation = _operations.Update(
                operation,
                "recovery_required",
                "LOCAL_SLOT_DURABLE_STATE_UNSAFE",
                priorAssessment.Error,
                File.Exists(migrationStatePath) ? migrationStatePath : null,
                activationPerformed: priorAssessment.ActivationMayHaveOccurred,
                databaseMutationPerformed: false);
            Journal("update.local_slot_migration.recovery_required", operation);
            return new(operation, created, Reconciled: !created);
        }

        if (priorAssessment.State is { } prior)
        {
            if (prior.RecoveryRequired)
            {
                operation = _operations.Update(
                    operation,
                    "recovery_required",
                    "LOCAL_SLOT_PRIOR_RECOVERY_REQUIRED",
                    $"Prior Local migration state requires recovery before retry; phase={prior.Phase}.",
                    migrationStatePath,
                    activationPerformed: prior.ActivationPerformed || StableWebIsJunction(prior.StableWebRoot),
                    databaseMutationPerformed: false);
                Journal("update.local_slot_migration.recovery_required", operation);
                return new(operation, created, Reconciled: !created);
            }

            if (IsLayoutReadyPhase(prior.Phase) && TryVerifyReadyLayout(prior, expectedManagedFiles, expectedManagedTreeSha256, out var readyDetail))
            {
                operation = _operations.Update(
                    operation,
                    "committed",
                    "LOCAL_SLOT_LAYOUT_RECONCILED_FROM_DURABLE_STATE",
                    readyDetail,
                    migrationStatePath,
                    activationPerformed: true,
                    databaseMutationPerformed: false);
                Journal("update.local_slot_migration.committed", operation);
                return new(operation, created, Reconciled: true);
            }

            if (prior.ActivationPerformed || StableWebIsJunction(prior.StableWebRoot))
            {
                operation = _operations.Update(
                    operation,
                    "recovery_required",
                    "LOCAL_SLOT_PRIOR_ACTIVATION_UNCERTAIN",
                    $"A prior interrupted Local migration reached or may have reached pointer activation; unattended retry is forbidden. phase={prior.Phase}.",
                    migrationStatePath,
                    activationPerformed: true,
                    databaseMutationPerformed: false);
                Journal("update.local_slot_migration.recovery_required", operation);
                return new(operation, created, Reconciled: !created);
            }

            if (!IsRecoveredPhysicalPhase(prior.Phase))
            {
                var reconciled = TryRecoverPreActivationInterruption(prior, out var reconciliationDetail);
                if (!reconciled)
                {
                    operation = _operations.Update(
                        operation,
                        "recovery_required",
                        "LOCAL_SLOT_PREVIOUS_ATTEMPT_RECOVERY_FAILED",
                        reconciliationDetail,
                        migrationStatePath,
                        activationPerformed: false,
                        databaseMutationPerformed: false);
                    Journal("update.local_slot_migration.recovery_required", operation);
                    return new(operation, created, Reconciled: !created);
                }

                Journal("update.local_slot_migration.pre_activation_reconciled", operation);
            }
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

        if (execution.ExitCode != 0)
        {
            var assessment = AssessDurableMigrationState(migrationStatePath, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
            if (assessment.Error is not null)
            {
                operation = _operations.Update(
                    operation,
                    "recovery_required",
                    "LOCAL_SLOT_DURABLE_STATE_UNSAFE",
                    $"{execution.Code}: {execution.Detail} Durable state: {assessment.Error}",
                    File.Exists(migrationStatePath) ? migrationStatePath : null,
                    activationPerformed: assessment.ActivationMayHaveOccurred,
                    databaseMutationPerformed: false);
                Journal("update.local_slot_migration.recovery_required", operation);
                return new(operation, created, Reconciled: !created);
            }

            if (assessment.State is { } failedState)
            {
                if (failedState.RecoveryRequired)
                {
                    operation = _operations.Update(
                        operation,
                        "recovery_required",
                        "LOCAL_SLOT_MIGRATION_RECOVERY_REQUIRED",
                        execution.Detail,
                        migrationStatePath,
                        activationPerformed: failedState.ActivationPerformed || StableWebIsJunction(failedState.StableWebRoot),
                        databaseMutationPerformed: false);
                    Journal("update.local_slot_migration.recovery_required", operation);
                    return new(operation, created, Reconciled: !created);
                }

                if (IsLayoutReadyPhase(failedState.Phase) && TryVerifyReadyLayout(failedState, expectedManagedFiles, expectedManagedTreeSha256, out var committedDetail))
                {
                    operation = _operations.Update(
                        operation,
                        "committed",
                        "LOCAL_SLOT_COMMIT_RECONCILED_AFTER_ENGINE_EXIT",
                        committedDetail,
                        migrationStatePath,
                        activationPerformed: true,
                        databaseMutationPerformed: false);
                    Journal("update.local_slot_migration.committed", operation);
                    return new(operation, created, Reconciled: true);
                }

                if (failedState.ActivationPerformed || StableWebIsJunction(failedState.StableWebRoot))
                {
                    operation = _operations.Update(
                        operation,
                        "recovery_required",
                        "LOCAL_SLOT_MIGRATION_FAILED_AFTER_ACTIVATION",
                        execution.Detail,
                        migrationStatePath,
                        activationPerformed: true,
                        databaseMutationPerformed: false);
                    Journal("update.local_slot_migration.recovery_required", operation);
                    return new(operation, created, Reconciled: !created);
                }

                var recovered = TryRecoverPreActivationInterruption(failedState, out var recoveryDetail);
                if (!recovered)
                {
                    operation = _operations.Update(
                        operation,
                        "recovery_required",
                        "LOCAL_SLOT_INTERRUPTED_RECOVERY_FAILED",
                        $"{execution.Detail} Recovery: {recoveryDetail}",
                        migrationStatePath,
                        activationPerformed: false,
                        databaseMutationPerformed: false);
                    Journal("update.local_slot_migration.recovery_required", operation);
                    return new(operation, created, Reconciled: !created);
                }

                operation = _operations.Update(
                    operation,
                    "failed",
                    "LOCAL_SLOT_MIGRATION_INTERRUPTED_RECOVERED",
                    $"Migration failed before activation and durable recovery restored a safe Local layout. Engine: {execution.Detail}",
                    migrationStatePath,
                    activationPerformed: false,
                    databaseMutationPerformed: false);
                Journal("update.local_slot_migration.failed", operation);
                return new(operation, created, Reconciled: !created);
            }

            if (execution.Code == "UPDATE_ENGINE_TIMEOUT")
            {
                operation = _operations.Update(
                    operation,
                    "recovery_required",
                    "UPDATE_ENGINE_TIMEOUT_STATE_UNPROVEN",
                    execution.Detail,
                    File.Exists(migrationStatePath) ? migrationStatePath : null,
                    activationPerformed: false,
                    databaseMutationPerformed: false);
                Journal("update.local_slot_migration.recovery_required", operation);
                return new(operation, created, Reconciled: !created);
            }

            operation = _operations.Update(
                operation,
                "failed",
                execution.Code == "OK" ? "LOCAL_SLOT_MIGRATION_FAILED_PRE_ACTIVATION" : execution.Code,
                execution.Detail,
                null,
                activationPerformed: false,
                databaseMutationPerformed: false);
            Journal("update.local_slot_migration.failed", operation);
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
            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            if (!StableWebIsJunction(state.StableWebRoot)) throw new InvalidDataException("Committed Local migration did not leave the stable Web root as a junction.");
            var committedTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;
            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(committedTarget, state.PackageRoot);
            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||
                !string.Equals(Path.GetFullPath(committedTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("Committed Local migration active-slot state does not match the stable Web junction target.");

            operation = _operations.Update(
                operation,
                "committed",
                phase == "ALREADY_MIGRATED" ? "LOCAL_SLOT_LAYOUT_ALREADY_READY" : "LOCAL_SLOT_MIGRATION_COMMITTED",
                $"Production Local slot migration committed; managed_tree={expectedManagedTreeSha256}.",
                migrationStatePath,
                activationPerformed: true,
                databaseMutationPerformed: false);
            Journal("update.local_slot_migration.committed", operation);
            return new(operation, created, Reconciled: !created);
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException or UnauthorizedAccessException)
        {
            operation = _operations.Update(
                operation,
                "recovery_required",
                "UPDATE_ENGINE_RESULT_INVALID",
                ex.Message,
                File.Exists(migrationStatePath) ? migrationStatePath : null,
                activationPerformed: File.Exists(migrationStatePath),
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
        try
        {
            PrimeApacheForRecovery(state);
        }
        catch (Exception ex) when (ex is InvalidOperationException or TimeoutException or System.ComponentModel.Win32Exception)
        {
            operation = _operations.Update(
                operation,
                "failed",
                "LOCAL_SLOT_RECOVERY_SERVICE_PRIME_FAILED",
                ex.Message,
                migrationStatePath,
                activationPerformed: state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot),
                databaseMutationPerformed: false);
            Journal("update.local_slot_recovery.failed", operation);
            return new(operation, created, Reconciled: !created);
        }

        var execution = ExecuteEngine(new[] { "local-slot-recover", "--data-root", _paths.DataRoot }, timeoutMs: 180_000);
        if (!execution.Started || execution.ExitCode != 0)
        {
            operation = _operations.Update(
                operation,
                execution.Code == "UPDATE_ENGINE_TIMEOUT" ? "recovery_required" : "failed",
                execution.Started ? "LOCAL_SLOT_RECOVERY_FAILED" : execution.Code,
                execution.Detail,
                migrationStatePath,
                activationPerformed: state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot),
                databaseMutationPerformed: false);
            Journal(execution.Code == "UPDATE_ENGINE_TIMEOUT" ? "update.local_slot_recovery.recovery_required" : "update.local_slot_recovery.failed", operation);
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
                activationPerformed: state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot),
                databaseMutationPerformed: false);
            Journal("update.local_slot_recovery.committed", operation);
            return new(operation, created, Reconciled: !created);
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException or IOException)
        {
            operation = _operations.Update(
                operation,
                "failed",
                "UPDATE_ENGINE_RESULT_INVALID",
                ex.Message,
                migrationStatePath,
                activationPerformed: state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot),
                databaseMutationPerformed: false);
            Journal("update.local_slot_recovery.failed", operation);
            return new(operation, created, Reconciled: !created);
        }
    }

    private bool TryRecoverPreActivationInterruption(ProductionLocalSlotMigrationState state, out string detail)
    {
        detail = string.Empty;
        if (state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot))
        {
            detail = "Durable or filesystem evidence indicates pointer activation; automatic recovery-for-retry is forbidden.";
            return false;
        }

        try
        {
            PrimeApacheForRecovery(state);
        }
        catch (Exception ex) when (ex is InvalidOperationException or TimeoutException or System.ComponentModel.Win32Exception)
        {
            detail = "Unable to prime SoknaApache for pre-activation recovery: " + ex.Message;
            return false;
        }

        var recovery = ExecuteEngine(new[] { "local-slot-recover", "--data-root", _paths.DataRoot }, timeoutMs: 180_000);
        if (!recovery.Started || recovery.ExitCode != 0)
        {
            detail = recovery.Detail;
            return false;
        }

        try
        {
            using var document = JsonDocument.Parse(recovery.Stdout);
            var root = document.RootElement;
            if (!root.TryGetProperty("ok", out var ok) || !ok.GetBoolean() ||
                !root.TryGetProperty("recovery_required", out var recoveryRequired) || recoveryRequired.GetBoolean() ||
                !root.TryGetProperty("database_mutation_performed", out var db) || db.GetBoolean() ||
                !root.TryGetProperty("public_mutation_performed", out var pub) || pub.GetBoolean() ||
                !root.TryGetProperty("windows_reboot_required", out var reboot) || reboot.GetBoolean())
            {
                detail = "Update Engine returned an invalid pre-activation recovery envelope.";
                return false;
            }

            detail = "Durable pre-activation interruption reconciled successfully.";
            return true;
        }
        catch (Exception ex) when (ex is JsonException or InvalidDataException)
        {
            detail = ex.Message;
            return false;
        }
    }

    private static DurableMigrationAssessment AssessDurableMigrationState(
        string migrationStatePath,
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256)
    {
        if (!File.Exists(migrationStatePath)) return new(null, null, false);
        try
        {
            var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(migrationStatePath);
            var identityMatches = string.Equals(state.ExpectedBuild, expectedBuild, StringComparison.Ordinal) &&
                                  state.ExpectedManagedFiles == expectedManagedFiles &&
                                  string.Equals(state.ExpectedManagedTreeSha256, expectedManagedTreeSha256, StringComparison.OrdinalIgnoreCase);
            if (!identityMatches)
            {
                if (IsLayoutReadyPhase(state.Phase) || IsRecoveredPhysicalPhase(state.Phase)) return new(null, null, false);
                return new(null, "A non-terminal durable Local migration state belongs to a different request.", state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot));
            }

            if (state.DatabaseMutationPerformed || state.PublicMutationPerformed || state.WindowsRebootRequired)
                return new(state, "Durable Local migration state violates the no-DB/no-Public/no-reboot bridge contract.", state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot));

            return new(state, null, state.ActivationPerformed || StableWebIsJunction(state.StableWebRoot));
        }
        catch (Exception ex) when (ex is IOException or InvalidDataException or JsonException or UnauthorizedAccessException)
        {
            return new(null, "Durable Local migration state could not be trusted: " + ex.Message, true);
        }
    }

    private static bool TryVerifyReadyLayout(
        ProductionLocalSlotMigrationState state,
        int expectedManagedFiles,
        string expectedManagedTreeSha256,
        out string detail)
    {
        detail = string.Empty;
        try
        {
            if (!Directory.Exists(state.StableWebRoot)) return false;
            if (!StableWebIsJunction(state.StableWebRoot)) return false;
            var activeTarget = Directory.ResolveLinkTarget(state.StableWebRoot, returnFinalTarget: true)?.FullName;
            ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(activeTarget, state.PackageRoot);
            if (string.IsNullOrWhiteSpace(state.ActiveSlot) ||
                !string.Equals(Path.GetFullPath(activeTarget!), Path.GetFullPath(state.ActiveSlot), StringComparison.OrdinalIgnoreCase))
            {
                detail = "Durable Local migration active-slot state does not match the stable Web junction target.";
                return false;
            }
            LocalManagedTreeAttestation.RequireExact(state.StableWebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            detail = $"Durable Local migration state and active sibling-release junction were reconciled successfully; phase={state.Phase}.";
            return true;
        }
        catch (Exception ex) when (ex is IOException or InvalidDataException or UnauthorizedAccessException)
        {
            detail = ex.Message;
            return false;
        }
    }

    private static bool IsLayoutReadyPhase(string phase)
        => phase is "COMMITTED" or "ALREADY_MIGRATED" or "RECOVERED_TO_VERIFIED_SLOT";

    private static bool IsRecoveredPhysicalPhase(string phase)
        => phase == "RECOVERED_TO_PHYSICAL_WEB";

    private static bool StableWebIsJunction(string stableWebRoot)
    {
        try
        {
            if (!Directory.Exists(stableWebRoot)) return false;
            return (File.GetAttributes(stableWebRoot) & FileAttributes.ReparsePoint) != 0;
        }
        catch
        {
            return false;
        }
    }

    private static void PrimeApacheForRecovery(ProductionLocalSlotMigrationState state)
    {
        if (!Directory.Exists(state.StableWebRoot)) return;
        using var service = new ServiceController(LocalSlotManager.ApacheServiceName);
        service.Refresh();
        if (service.Status == ServiceControllerStatus.Running) return;
        if (service.Status != ServiceControllerStatus.StartPending) service.Start();
        service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(35));
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
                try { process.WaitForExit(5_000); } catch { }
                var timeoutStdout = stdoutTask.IsCompleted ? stdoutTask.GetAwaiter().GetResult() : string.Empty;
                var timeoutStderr = stderrTask.IsCompleted ? stderrTask.GetAwaiter().GetResult() : string.Empty;
                var evidence = string.IsNullOrWhiteSpace(timeoutStderr) ? timeoutStdout : timeoutStderr;
                if (evidence.Length > 5000) evidence = evidence[..5000];
                var timeoutDetail = "Update Engine exceeded the production migration timeout." + (string.IsNullOrWhiteSpace(evidence) ? string.Empty : " Evidence: " + evidence.Trim());
                return new(true, -1, timeoutStdout, timeoutDetail, "UPDATE_ENGINE_TIMEOUT");
            }

            var stdout = stdoutTask.GetAwaiter().GetResult();
            var stderr = stderrTask.GetAwaiter().GetResult();
            var resultDetail = string.IsNullOrWhiteSpace(stderr) ? stdout : stderr;
            if (resultDetail.Length > 6000) resultDetail = resultDetail[..6000];
            return new(true, process.ExitCode, stdout, resultDetail.Trim(), "OK");
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

    private sealed record DurableMigrationAssessment(ProductionLocalSlotMigrationState? State, string? Error, bool ActivationMayHaveOccurred);
    private sealed record EngineExecution(bool Started, int ExitCode, string Stdout, string Detail, string Code);
}
