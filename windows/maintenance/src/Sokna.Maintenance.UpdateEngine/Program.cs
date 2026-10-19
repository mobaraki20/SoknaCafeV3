using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

const string EngineVersion = "0.3.6";

if (args.Length == 0 || args[0] is "--help" or "-h")
{
    Console.WriteLine($"SOKNA Maintenance Update Engine {EngineVersion} (R8 sibling-release routine activation; DB mutation disabled)");
    Console.WriteLine("verify-only --package <zip> --identity <json> [--data-root <path>] (read-only; no stage or sequence mutation)");
    Console.WriteLine("cutover-attest --transaction-id <id> --stage-operation-id <id> --data-root <path> --delegation-id <id> (Supervisor-delegated read-only evidence only)");
    Console.WriteLine("cutover-lab-switch --transaction-id <id> --stage-operation-id <id> --data-root <path> --lab-case <name> --lab-mode <mode> --delegation-id <id> (disposable CI fixture ONLY)");
    Console.WriteLine("verify-stage --package <zip> --identity <json> [--data-root <path>]");
    Console.WriteLine("local-slot-inspect --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-migrate --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-recover [--data-root <path>]");
    Console.WriteLine("local-slot-home-cycle --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-stage --source <path> --current-build <id> --current-managed-files <n> --current-managed-tree-sha256 <sha256> --target-build <id> --target-managed-files <n> --target-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-activate --slot <path> --current-build <id> --current-managed-files <n> --current-managed-tree-sha256 <sha256> --target-build <id> --target-managed-files <n> --target-managed-tree-sha256 <sha256> [--data-root <path>]");
    Console.WriteLine("local-slot-recover-routine [--data-root <path>]");
    return;
}

var command = args[0].ToLowerInvariant();
var options = ParseOptions(args.Skip(1).ToArray());
var dataRoot = options.TryGetValue("--data-root", out var dataRootText)
    ? Path.GetFullPath(dataRootText)
    : MaintenancePaths.DefaultDataRoot();
var paths = new MaintenancePaths(dataRoot);

// F1-S7D5B: physically switches ONLY an isolated NTFS junction in a
// disposable signed Windows CI fixture. No production execution path.
if (command == "cutover-lab-switch")
{
    if (args.Length != 13 || args[^2] != "--delegation-id")
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new {
            ok = false, code = CutoverLabSwitch.Required,
            activation_performed = false, database_mutation_performed = false }));
        Environment.ExitCode = 75;
        return;
    }
    try
    {
        RequireOption(options, "--transaction-id", out var tx);
        RequireOption(options, "--stage-operation-id", out var stageId);
        RequireOption(options, "--lab-case", out var labCase);
        RequireOption(options, "--lab-mode", out var mode);
        var result = CutoverLabSwitch.ConsumeAndSwitch(paths,
            args[^1], tx, stageId, labCase, mode, args[..^2]);
        Console.WriteLine(JsonSerializer.Serialize(new {
            ok = true, phase = "CUTOVER_LAB_PHYSICAL_SWITCH",
            observed_target = result.PhysicalPointer,
            physical_ntfs_lab_switch = true, production_authorized = false,
            activation_performed = false, database_mutation_performed = false,
            public_mutation_performed = false, panel_update_ready = false
        }));
        return;
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or
        UnauthorizedAccessException or CryptographicException or InvalidOperationException
        or ArgumentException or System.ComponentModel.Win32Exception)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new {
            ok = false, code = CutoverLabSwitch.Rejected,
            error_type = ex.GetType().Name, activation_performed = false,
            database_mutation_performed = false, panel_update_ready = false
        }));
        Environment.ExitCode = 75;
        return;
    }
}

// F1-S7D5A: separate non-mutating command using an exact signed,
// one-use Supervisor->Engine permit. It does NOT authorize cutover.
if (command == "cutover-attest")
{
    if (args.Length != 9 || args[^2] != "--delegation-id")
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new {
            ok = false, code = CutoverEngineAttestationPermit.Required,
            activation_performed = false, database_mutation_performed = false }));
        Environment.ExitCode = 75;
        return;
    }
    try
    {
        RequireOption(options, "--transaction-id", out var transactionId);
        RequireOption(options, "--stage-operation-id", out var stageId);
        var permit = CutoverEngineAttestationPermit.Consume(paths, args[^1],
            transactionId, stageId, args[..^2]);
        Console.WriteLine(JsonSerializer.Serialize(new {
            ok = true,
            phase = "DELEGATED_SIGNED_STAGE_ATTESTED_READ_ONLY",
            transaction_id = permit.TransactionId,
            stage_operation_id = permit.StageOperationId,
            release_set_id = permit.ReleaseSetId,
            sequence = permit.Sequence,
            evidence_only = true,
            execution_authorized = false,
            activation_performed = false, database_mutation_performed = false,
            public_mutation_performed = false, panel_update_ready = false
        }));
        return;
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or
        UnauthorizedAccessException or CryptographicException or InvalidOperationException
        or ArgumentException or System.ComponentModel.Win32Exception)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new {
            ok = false, code = CutoverEngineAttestationPermit.Rejected,
            error_type = ex.GetType().Name, activation_performed = false,
            database_mutation_performed = false, panel_update_ready = false
        }));
        Environment.ExitCode = 75;
        return;
    }
}

// Mutating Engine execution is delegated by a live Supervisor that retains
// the machine lease. Engine cannot acquire the lease or self-authorize a CLI
// command. The one-use permit must match every argument byte-for-byte.
if (command is "verify-stage" or "local-slot-migrate" or "local-slot-recover"
    or "local-slot-home-cycle" or "local-slot-stage" or "local-slot-activate"
    or "local-slot-activate-qualified" or "local-slot-recover-routine" or "local-slot-activate-qualified-legacy")
{
    if (args.Length < 4 || args[^2] != "--delegation-id")
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new
        {
            ok = false, phase = "BLOCKED_PRE_MUTATION",
            code = EngineDelegationPermit.RequiredCode,
            activation_performed = false, database_mutation_performed = false
        }, AtomicFile.JsonOptions));
        Environment.ExitCode = 75;
        return;
    }
    try
    {
        var originalArguments = args[..^2];
        var parentPid = EngineDelegationPermit.Consume(paths, args[^1], originalArguments);
        // The parent is responsible for keeping the OS lease alive throughout
        // this Engine execution. Unexpected Supervisor exit aborts the child.
        _ = Task.Run(() =>
        {
            try
            {
                using var parent = System.Diagnostics.Process.GetProcessById(parentPid);
                parent.WaitForExit();
                Environment.FailFast("Supervisor delegation owner exited while Engine was executing.");
            }
            catch (ArgumentException)
            {
                Environment.FailFast("Supervisor delegation owner disappeared.");
            }
        });
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or
        UnauthorizedAccessException or CryptographicException or InvalidOperationException or
        ArgumentException or System.ComponentModel.Win32Exception)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new
        {
            ok = false, phase = "BLOCKED_PRE_MUTATION",
            code = EngineDelegationPermit.RejectedCode,
            error_type = ex.GetType().Name,
            activation_performed = false, database_mutation_performed = false
        }, AtomicFile.JsonOptions));
        Environment.ExitCode = 75;
        return;
    }
}
var bridge = new ProductionLocalSlotMigrationBridge(paths);

if (command == "verify-only")
{
    RequireOption(options, "--package", out var package);
    RequireOption(options, "--identity", out var identity);
    RunVerifyOnly(package, identity, paths);
    return;
}

if (command == "verify-stage")
{
    RequireOption(options, "--package", out var package);
    RequireOption(options, "--identity", out var identity);
    RunVerifyStage(package, identity, paths);
    return;
}

if (command == "local-slot-recover")
{
    RunRecovery(() => bridge.Recover(), "LOCAL_SLOT_RECOVERY_FAILED", Path.Combine(paths.LocalSlotStateRoot, "migration-state.json"));
    return;
}

if (command == "local-slot-recover-routine")
{
    RunRoutineRecovery(() => bridge.RecoverRoutine(), "LOCAL_SLOT_ROUTINE_RECOVERY_FAILED", bridge.RoutineStatePath);
    return;
}

try
{
    switch (command)
    {
        case "local-slot-inspect":
        {
            ReadExpected(options, out var expectedBuild, out var expectedManagedFiles, out var expectedManagedTreeSha256);
            var slots = new LocalSlotManager(paths);
            var layout = slots.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            var managedTree = LocalManagedTreeAttestation.RequireExact(layout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = true,
                engine_version = EngineVersion,
                phase = "INSPECTED",
                layout,
                managed_tree_sha256 = managedTree,
                activation_performed = false,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false
            }, AtomicFile.JsonOptions));
            break;
        }
        case "local-slot-migrate":
        {
            ReadExpected(options, out var expectedBuild, out var expectedManagedFiles, out var expectedManagedTreeSha256);
            var slots = new LocalSlotManager(paths);
            var layout = slots.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            var managedTree = LocalManagedTreeAttestation.RequireExact(layout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            var result = bridge.Migrate(expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = result.Ok,
                engine_version = EngineVersion,
                phase = result.Phase,
                result,
                managed_tree_sha256 = result.ManagedTreeSha256,
                pre_activation_managed_tree_sha256 = managedTree,
                activation_performed = result.ActivationPerformed,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false,
                recovery_required = result.RecoveryRequired
            }, AtomicFile.JsonOptions));
            break;
        }
        case "local-slot-home-cycle":
        {
            ReadExpected(options, out var expectedBuild, out var expectedManagedFiles, out var expectedManagedTreeSha256);
            var result = bridge.CycleRoutineSameBuild(expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = result.Ok,
                engine_version = EngineVersion,
                phase = result.Phase,
                result,
                managed_tree_sha256 = result.ManagedTreeSha256,
                activation_performed = result.ActivationPerformed,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false,
                recovery_required = result.RecoveryRequired
            }, AtomicFile.JsonOptions));
            break;
        }
        case "local-slot-stage":
        {
            RequireOption(options, "--source", out var source);
            ReadRoutineIdentity(options, out var currentBuild, out var currentCount, out var currentTree, out var targetBuild, out var targetCount, out var targetTree);
            var targetSlot = bridge.StageRoutineSiblingRelease(source, currentBuild, currentCount, currentTree, targetBuild, targetCount, targetTree);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = true,
                engine_version = EngineVersion,
                phase = "STAGED",
                target_slot = targetSlot,
                target_build = targetBuild,
                target_managed_tree_sha256 = targetTree,
                activation_performed = false,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false
            }, AtomicFile.JsonOptions));
            break;
        }
        // The first physical-Web -> R8 transition uses the SAME delegated,
        // signed Supervisor/UpdateEngine transaction. Direct CLI calls without
        // the one-use Supervisor delegation token are rejected above.
        case "local-slot-activate-qualified-legacy":
        {
            RequireOption(options, "--source", out var source);
            RequireOption(options, "--current-build", out var currentBuild);
            RequireOption(options, "--target-build", out var targetBuild);
            RequireOption(options, "--target-managed-files", out var targetCountText);
            RequireOption(options, "--target-managed-tree-sha256", out var targetTree);
            RequireOption(options, "--release-set-id", out var releaseId);
            if (!int.TryParse(targetCountText, out var targetCount) ||
                targetCount is < 1 or > 20000)
                throw new InvalidDataException("LEGACY_TARGET_MANAGED_COUNT_INVALID");
            var result = bridge.MigrateSignedLegacy(
                currentBuild, targetBuild, source, targetCount, targetTree, releaseId);
            Console.WriteLine(JsonSerializer.Serialize(new {
                ok = result.Ok,
                engine_version = EngineVersion,
                phase = result.Phase,
                result,
                managed_tree_sha256 = result.ManagedTreeSha256,
                activation_performed = result.ActivationPerformed,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false,
                recovery_required = result.RecoveryRequired
            }, AtomicFile.JsonOptions));
            break;
        }
        case "local-slot-activate":
        case "local-slot-activate-qualified":
        {
            RequireOption(options, "--slot", out var targetSlot);
            ReadRoutineIdentity(options, out var currentBuild, out var currentCount, out var currentTree, out var targetBuild, out var targetCount, out var targetTree);
            string? signedReleaseSetId = null;
            if (command == "local-slot-activate-qualified")
                RequireOption(options, "--release-set-id", out signedReleaseSetId);
            var result = bridge.ActivateRoutineSiblingRelease(targetSlot, currentBuild, currentCount, currentTree,
                targetBuild, targetCount, targetTree, signedReleaseSetId);
            Console.WriteLine(JsonSerializer.Serialize(new
            {
                ok = result.Ok,
                engine_version = EngineVersion,
                phase = result.Phase,
                result,
                managed_tree_sha256 = result.ManagedTreeSha256,
                activation_performed = result.ActivationPerformed,
                database_mutation_performed = false,
                public_mutation_performed = false,
                windows_reboot_required = false,
                recovery_required = result.RecoveryRequired
            }, AtomicFile.JsonOptions));
            break;
        }
        default:
            throw new InvalidDataException("Unknown Update Engine command.");
    }
}
catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or CryptographicException or TimeoutException or AggregateException or System.ComponentModel.Win32Exception or InvalidOperationException)
{
    Console.Error.WriteLine(JsonSerializer.Serialize(new
    {
        ok = false,
        engine_version = EngineVersion,
        phase = "FAILED",
        code = command switch
        {
            "local-slot-migrate" => "LOCAL_SLOT_MIGRATION_FAILED",
            "local-slot-stage" => "LOCAL_SLOT_ROUTINE_STAGE_FAILED",
            "local-slot-activate" or "local-slot-activate-qualified" or "local-slot-activate-qualified-legacy" or "local-slot-home-cycle" => "LOCAL_SLOT_ROUTINE_ACTIVATION_FAILED",
            _ => "LOCAL_SLOT_OPERATION_FAILED"
        },
        error = ex.Message,
        database_mutation_performed = false,
        public_mutation_performed = false,
        windows_reboot_required = false,
        migration_state_path = Path.Combine(paths.LocalSlotStateRoot, "migration-state.json"),
        routine_state_path = bridge.RoutineStatePath
    }, AtomicFile.JsonOptions));
    Environment.ExitCode = 3;
}

void RunRecovery(Func<ProductionLocalSlotMigrationResult> action, string code, string statePath)
{
    try
    {
        var result = action();
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            ok = result.Ok,
            engine_version = EngineVersion,
            phase = result.Phase,
            result,
            managed_tree_sha256 = result.ManagedTreeSha256,
            activation_performed = result.ActivationPerformed,
            database_mutation_performed = false,
            public_mutation_performed = false,
            windows_reboot_required = false,
            recovery_required = result.RecoveryRequired
        }, AtomicFile.JsonOptions));
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or TimeoutException or AggregateException or System.ComponentModel.Win32Exception or InvalidOperationException)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new { ok = false, engine_version = EngineVersion, phase = "RECOVERY_FAILED", code, error = ex.Message, database_mutation_performed = false, public_mutation_performed = false, windows_reboot_required = false, recovery_required = true, state_path = statePath }, AtomicFile.JsonOptions));
        Environment.ExitCode = 4;
    }
}

void RunRoutineRecovery(Func<RoutineLocalSlotResult> action, string code, string statePath)
{
    try
    {
        var result = action();
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            ok = result.Ok,
            engine_version = EngineVersion,
            phase = result.Phase,
            result,
            managed_tree_sha256 = result.ManagedTreeSha256,
            activation_performed = result.ActivationPerformed,
            database_mutation_performed = false,
            public_mutation_performed = false,
            windows_reboot_required = false,
            recovery_required = result.RecoveryRequired
        }, AtomicFile.JsonOptions));
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or TimeoutException or AggregateException or System.ComponentModel.Win32Exception or InvalidOperationException)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new { ok = false, engine_version = EngineVersion, phase = "RECOVERY_FAILED", code, error = ex.Message, database_mutation_performed = false, public_mutation_performed = false, windows_reboot_required = false, recovery_required = true, state_path = statePath }, AtomicFile.JsonOptions));
        Environment.ExitCode = 4;
    }
}

static void ReadExpected(IReadOnlyDictionary<string, string> options, out string build, out int count, out string tree)
{
    RequireOption(options, "--expected-build", out build);
    count = ReadPositiveInt(options, "--expected-managed-files");
    RequireOption(options, "--expected-managed-tree-sha256", out tree);
    tree = LocalManagedTreeAttestation.NormalizeSha256(tree);
}

static void ReadRoutineIdentity(IReadOnlyDictionary<string, string> options, out string currentBuild, out int currentCount, out string currentTree, out string targetBuild, out int targetCount, out string targetTree)
{
    RequireOption(options, "--current-build", out currentBuild);
    currentCount = ReadPositiveInt(options, "--current-managed-files");
    RequireOption(options, "--current-managed-tree-sha256", out currentTree);
    currentTree = LocalManagedTreeAttestation.NormalizeSha256(currentTree);
    RequireOption(options, "--target-build", out targetBuild);
    targetCount = ReadPositiveInt(options, "--target-managed-files");
    RequireOption(options, "--target-managed-tree-sha256", out targetTree);
    targetTree = LocalManagedTreeAttestation.NormalizeSha256(targetTree);
}

static int ReadPositiveInt(IReadOnlyDictionary<string, string> options, string key)
{
    if (!options.TryGetValue(key, out var text) || !int.TryParse(text, out var value) || value < 1)
        throw new InvalidDataException($"{key} must be a positive integer.");
    return value;
}

void RunVerifyOnly(string package, string identity, MaintenancePaths paths)
{
    try
    {
        // No directory creation, lock acquisition, anti-rollback commit or staging.
        // This validates the current bytes only; a future stage must independently
        // authenticate the package and installed identity again.
        var installed = ReadInstalledIdentity(identity);
        var receipt = new ReleaseSetVerifierStager(paths).VerifyOnly(package, installed);
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            ok = true,
            engine_version = EngineVersion,
            phase = "VERIFIED_READ_ONLY",
            code = "PACKAGE_INTEGRITY_VERIFIED",
            receipt,
            installation_allowed = false,
            stage_performed = false,
            activation_performed = false,
            database_mutation_performed = false
        }, AtomicFile.JsonOptions));
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or CryptographicException or JsonException)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new
        {
            ok = false,
            engine_version = EngineVersion,
            phase = "REJECTED_READ_ONLY",
            code = "PACKAGE_PREFLIGHT_REJECTED",
            error = ex.Message,
            installation_allowed = false,
            stage_performed = false,
            activation_performed = false,
            database_mutation_performed = false
        }, AtomicFile.JsonOptions));
        Environment.ExitCode = 2;
    }
}

InstalledReleaseIdentity ReadInstalledIdentity(string identity)
{
    var path = Path.GetFullPath(identity);
    if (!File.Exists(path)) throw new FileNotFoundException("Installed identity file was not found.", path);
    try
    {
        return JsonSerializer.Deserialize<InstalledReleaseIdentity>(File.ReadAllBytes(path), AtomicFile.JsonOptions)
               ?? throw new InvalidDataException("Installed identity document is empty.");
    }
    catch (JsonException ex)
    {
        throw new InvalidDataException("Installed identity JSON is invalid.", ex);
    }
}

void RunVerifyStage(string package, string identity, MaintenancePaths paths)
{
    var installed = ReadInstalledIdentity(identity);
    var engine = new ReleaseSetVerifierStager(paths);
    try
    {
        var receipt = engine.VerifyAndStage(package, installed);
        Console.WriteLine(JsonSerializer.Serialize(new { ok = true, engine_version = EngineVersion, phase = "STAGED", release_set_id = receipt.ReleaseSetId, sequence = receipt.Sequence, receipt, activation_performed = false, database_mutation_performed = false }, AtomicFile.JsonOptions));
    }
    catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException or CryptographicException)
    {
        Console.Error.WriteLine(JsonSerializer.Serialize(new { ok = false, engine_version = EngineVersion, phase = "REJECTED_PRE_MUTATION", code = "RELEASE_SET_REJECTED", error = ex.Message, activation_performed = false, database_mutation_performed = false }, AtomicFile.JsonOptions));
        Environment.ExitCode = 2;
    }
}

static Dictionary<string, string> ParseOptions(string[] values)
{
    var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
    for (var i = 0; i < values.Length; i++)
    {
        var key = values[i];
        if (!key.StartsWith("--", StringComparison.Ordinal) || i + 1 >= values.Length)
            throw new InvalidDataException($"Unknown or incomplete argument: {key}.");
        result[key] = values[++i];
    }
    return result;
}

static void RequireOption(IReadOnlyDictionary<string, string> options, string key, out string value)
{
    if (!options.TryGetValue(key, out value!) || string.IsNullOrWhiteSpace(value))
        throw new InvalidDataException($"{key} is required.");
}
