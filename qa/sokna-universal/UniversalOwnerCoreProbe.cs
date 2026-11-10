using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows" ||
    string.IsNullOrEmpty(Environment.GetEnvironmentVariable("RUNNER_TEMP")))
    throw new InvalidOperationException("DISPOSABLE_GITHUB_WINDOWS_ONLY");

var temp = Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP")!);
const string localSha = "968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4";
const string publicSha = "1131d89c98e05c9597f372ed1d480e5637c3ad1969bc8733d2a837b644e57584";
const string maintSha = "69b524abb727b2066574c17d716ceea6cc49326e7c4283b02df6871d9998b0b";
static void Need(bool condition, string label)
{
    if (!condition) throw new InvalidOperationException("FAIL_" + label);
    Console.WriteLine("PASS " + label);
}
static void Deny(Action call, string label)
{
    try { call(); }
    catch (InvalidDataException) { Console.WriteLine("PASS DENY_" + label); return; }
    catch (InvalidOperationException) { Console.WriteLine("PASS DENY_" + label); return; }
    throw new InvalidOperationException("UNSAFE_ALLOWED_" + label);
}
static string ShaFile(string path)
    => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
static string GetString(string path, string key)
{
    using var doc = JsonDocument.Parse(File.ReadAllText(path, Encoding.UTF8));
    return doc.RootElement.GetProperty(key).GetString()
        ?? throw new InvalidDataException("QA_PROPERTY_MISSING_" + key);
}
static bool GetBool(string path, string key)
{
    using var doc = JsonDocument.Parse(File.ReadAllText(path, Encoding.UTF8));
    return doc.RootElement.GetProperty(key).GetBoolean();
}
static void AssertSha(string value, string expected, string code)
    => Need(value == expected, code);

if (args.Length != 0)
{
    if (args.Length != 3 || args[0] != "--real-native-evidence" ||
        Environment.GetEnvironmentVariable("SOKNA_REAL_MAINTENANCE_COHOST_QA") != "YES")
        throw new InvalidOperationException("REAL_NATIVE_PROBE_MODE_UNQUALIFIED");
    var root = Path.GetFullPath(args[1]);
    var maintenanceProof = Path.GetFullPath(args[2]);
    if (!root.StartsWith(temp + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase) ||
        !maintenanceProof.StartsWith(temp + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase) ||
        !File.Exists(maintenanceProof))
        throw new InvalidDataException("REAL_NATIVE_EVIDENCE_OUTSIDE_DISPOSABLE_RUNNER");

    var owned = Path.Combine(temp, "sokna-native-maintenance-cohost", "qa-ownership.json");
    Need(File.Exists(owned) && GetString(owned, "run_id") ==
        Environment.GetEnvironmentVariable("GITHUB_RUN_ID"), "REAL_INSTALLER_OWNED_BY_THIS_GITHUB_RUN");
    Need(GetBool(maintenanceProof, "maintenance_real_exe_installed") &&
        GetBool(maintenanceProof, "local_public_test_executed") &&
        !GetBool(maintenanceProof, "production_authorized") &&
        !GetBool(maintenanceProof, "global_three_component_commit_rollback_verified"),
        "REAL_MAINTENANCE_EVIDENCE_NON_PRODUCTION");
    AssertSha(GetString(maintenanceProof, "installer_sha256"), maintSha, "REAL_INSTALLER_EXACT_SHA");
    for (var i = 0; i < 2; i++)
    {
        var profile = i == 0 ? "r75" : "r140";
        var report = Path.Combine(root, "native-colocated-" + profile + ".json");
        var fence = Path.Combine(root, "native-forward-" + profile, "forward-fence.json");
        Need(File.Exists(report) && File.Exists(fence), "REAL_NATIVE_PROOFS_EXIST_" + profile);
        AssertSha(GetString(report, "local_archive_sha256"), localSha, "LOCAL_TARGET_" + profile);
        AssertSha(GetString(report, "public_archive_sha256"), publicSha, "PUBLIC_TARGET_" + profile);
        AssertSha(GetString(report, "local_engine_sha256"),
            GetString(maintenanceProof, "maintenance_native_engine_sha256"), "INSTALLED_ENGINE_" + profile);
        Need(GetBool(report, "database_forward_fence_unresolved") &&
            !GetBool(report, "production_authorized") &&
            GetString(fence, "phase") == "PUBLIC_NATIVE_DONE_MAINTENANCE_PENDING" &&
            GetBool(fence, "recovery_required"), "DB_FORWARD_UNRESOLVED_" + profile);
    }

    // Exercise the actual store used by the live, installed Maintenance Supervisor.
    // This occurs after the Local/Public tests; it does NOT retroactively claim
    // to hold a shared lease throughout their execution.
    var data = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "SOKNA", "Maintenance");
    var paths = new MaintenancePaths(data);
    Need(File.Exists(paths.InstalledReleaseIdentityPath), "REAL_INSTALLED_IDENTITY_PRESENT");
    var operations = new MaintenanceOperationStore(paths);
    var gate = new SupervisorUniversalUpgradeFence(paths, operations);
    var start = gate.Inspect();
    Need(start.Blocked && !start.ProductionAuthorized && !start.DatabaseForwardReported,
        "REAL_SUPERVISOR_CORE_INITIAL_FAIL_CLOSED");
    var reserved = gate.Reserve("qa:github", "qa-universal-core-native-" +
        Environment.GetEnvironmentVariable("GITHUB_RUN_ID"),
        localSha, publicSha, maintSha);
    var reloaded = new SupervisorUniversalUpgradeFence(paths, new MaintenanceOperationStore(paths));
    Need(reloaded.Inspect().Status == "RESERVED_BLOCKING_ALL_UPDATES" &&
        operations.ListBlocking().Count == 1, "REAL_SUPERVISOR_OPERATION_LEDGER_BLOCKS_AFTER_RESERVE");
    var nativeForward = Path.Combine(root, "native-forward-r75", "forward-fence.json");
    reloaded.ReportDatabaseForward(reserved.OperationId, ShaFile(nativeForward));
    var publicReceipt = Path.Combine(root, "native-colocated-r140.json");
    reloaded.ReportPublicObserved(reserved.OperationId, ShaFile(publicReceipt));
    var status = reloaded.Inspect();
    Need(status.RecoveryRequired && status.DatabaseForwardReported &&
        status.Blocked && !status.ProductionAuthorized &&
        operations.ListBlocking().Single().State == "recovery_required",
        "REAL_SUPERVISOR_OPERATION_PERSISTS_DB_FORWARD_BLOCKER");
    Deny(() => gate.Reserve("qa:github", "qa-after-forward",
        localSha, publicSha, maintSha), "SECOND_REAL_SUPERVISOR_TRANSACTION");
    var summary = Path.Combine(temp, "sokna-native-maintenance-cohost", "universal-owner-core-qa.json");
    AtomicFile.WriteJson(summary, new
    {
        format = "sokna-universal-owner-core-native-qa-v1",
        operation_id = reserved.OperationId,
        maintenance_installer_sha256 = maintSha,
        native_sources = new[] { "r75", "r140" },
        native_local_sha256 = localSha,
        native_public_sha256 = publicSha,
        supervisor_operation_state = "recovery_required",
        all_component_code_present_on_disposable_host = true,
        coordinated_mutation_window_verified = false,
        actual_supervisor_binary_contains_new_owner = false,
        recovery_required = true,
        production_authorized = false,
        global_commit_rollback_verified = false
    });
    Console.WriteLine("PASS REAL_MAINTENANCE_OPERATIONS_STORE_COORDINATOR_FENCE_NOT_A_UNIVERSAL_INSTALLER");
    return;
}

var dir = Path.Combine(temp, "sokna-native-universal-core-negative-tests");
Need(!Directory.Exists(dir), "FRESH_DISPOSABLE_NEGATIVE_TEST_ROOT");
Directory.CreateDirectory(dir);
try
{
    var paths = new MaintenancePaths(Path.Combine(dir, "owned-Maintenance"));
    AtomicFile.WriteJson(paths.InstalledReleaseIdentityPath, new { maintenance_supervisor_version = "0.3.22",
        update_engine_version = "0.3.22", local = new { version = "qa-r112" }, @public = new { version = "qa-r112" } });
    var ops = new MaintenanceOperationStore(paths);
    var gate = new SupervisorUniversalUpgradeFence(paths, ops);
    Need(gate.Inspect().Blocked && !gate.Inspect().ProductionAuthorized,
        "UNARMED_OWNER_NEVER_AUTHORIZES_RELEASE");
    Deny(() => gate.Reserve("qa:operator", "invalid-sha", "AAAA", publicSha, maintSha),
        "INVALID_COMPONENT_HASH");
    var first = gate.Reserve("qa:operator", "qa-universal-first",
        localSha, publicSha, maintSha);
    Need(first.Phase == "RESERVED_NO_ACTIVATION" &&
        ops.ListBlocking().Count == 1 &&
        ops.ListBlocking()[0].State == "accepted", "DURABLE_OPERATION_RESERVED_BEFORE_ACTIVATION");
    var restarted = new SupervisorUniversalUpgradeFence(paths, new MaintenanceOperationStore(paths));
    Need(restarted.Inspect().Status == "RESERVED_BLOCKING_ALL_UPDATES",
        "RESTART_RECOVERS_ACCEPTED_RESERVATION");
    Deny(() => restarted.Reserve("qa:operator", "second-op", localSha, publicSha, maintSha),
        "CONCURRENT_RESERVATION");
    Deny(() => restarted.Reserve("qa:operator", "qa-universal-first", localSha, publicSha, maintSha),
        "REPLAY_CANNOT_CLEAR_PENDING_RESERVATION");
    Deny(() => restarted.ReportPublicObserved(first.OperationId, publicSha),
        "PUBLIC_BEFORE_LOCAL_FORWARD");
    using (MachineMutationLease.Acquire(paths, "qa-held-external-writer"))
    {
        Deny(() => restarted.ReportDatabaseForward(first.OperationId, localSha),
            "CROSS_PROCESS_MACHINE_LEASE_CONFLICT");
    }
    Deny(() => restarted.ReportDatabaseForward(first.OperationId, "bad"),
        "INVALID_DB_FORWARD_HASH");
    var forward = restarted.ReportDatabaseForward(first.OperationId, localSha);
    Need(forward.RecoveryRequired && forward.DatabaseForwardReported &&
        ops.ListBlocking().Single().State == "recovery_required" &&
        ops.ListBlocking().Single().DatabaseMutationPerformed,
        "DB_FORWARD_HAZARD_LATCHED_IN_DURABLE_OPERATION");
    Need(new SupervisorUniversalUpgradeFence(paths, new MaintenanceOperationStore(paths))
        .Inspect().RecoveryRequired, "RESTART_NEVER_CLEARS_DB_FORWARD_HAZARD");
    Deny(() => gate.ReportDatabaseForward(first.OperationId, localSha),
        "FORWARD_REPLAY_NOT_SILENTLY_ACCEPTED");
    var observed = restarted.ReportPublicObserved(first.OperationId, publicSha);
    Need(observed.Phase == "PUBLIC_OBSERVED_RECOVERY_UNQUALIFIED" &&
        !observed.GlobalCommitVerified && !observed.ProductionAuthorized &&
        restarted.Inspect().RecoveryRequired, "PUBLIC_OBSERVED_NEVER_COMMITS_THREE_COMPONENTS");
    Deny(() => gate.ReportPublicObserved(first.OperationId, publicSha),
        "PUBLIC_REPLAY_NOT_UNSAFE_SUCCESS");
    Deny(() => gate.Reserve("qa:operator", "third-op", localSha, publicSha, maintSha),
        "POST_DB_FORWARD_NEW_OPERATION");
    var fencePath = Path.Combine(paths.UpdateRoot, "universal-three-component", "active.json");
    var original = File.ReadAllBytes(fencePath);
    try
    {
        File.WriteAllText(fencePath, "{broken-json");
        Deny(() => restarted.Inspect(), "CORRUPT_DURABLE_RECEIPT_BLOCKS");
    }
    finally { File.WriteAllBytes(fencePath, original); }
    var op = ops.Get(first.OperationId)!;
    try
    {
        ops.Update(op, "committed");
        Deny(() => restarted.Inspect(), "FORGED_COMMITTED_OPERATION_REJECTED");
    }
    finally { ops.Update(ops.Get(first.OperationId)!, "recovery_required",
        databaseMutationPerformed: true, activationPerformed: true); }
    Need(restarted.Inspect().RecoveryRequired, "CORRUPTION_RECOVERY_REMAINS_BLOCKED");
    var missing = Path.Combine(dir, "missing-history");
    var p2 = new MaintenancePaths(missing);
    AtomicFile.WriteJson(p2.InstalledReleaseIdentityPath, new { version = "qa" });
    var o2 = new MaintenanceOperationStore(p2);
    var (r125, _) = o2.GetOrCreate("qa-r125-recovery", "a" + new string('1', 63),
        "update.local-slot-migrate", "legacy-r125", "qa:operator");
    o2.Update(r125, "recovery_required", databaseMutationPerformed: true);
    var g2 = new SupervisorUniversalUpgradeFence(p2, o2);
    Deny(() => g2.Reserve("qa:operator", "must-not-bypass-r125",
        localSha, publicSha, maintSha), "HISTORICAL_R125_RECOVERY_BLOCKER");
    Need(g2.Inspect().Blocked && !g2.Inspect().ProductionAuthorized,
        "HISTORICAL_R125_CANNOT_CLEAR_WITH_UNIVERSAL");
    var ambiguous = new MaintenancePaths(Path.Combine(dir, "historical-unqualified"));
    AtomicFile.WriteJson(ambiguous.InstalledReleaseIdentityPath, new { version = "qa" });
    var ambiguousOps = new MaintenanceOperationStore(ambiguous);
    var (stageRecord, _) = ambiguousOps.GetOrCreate("historical-stage",
        "b" + new string('0', 63), "update.local-stage", "old-staged-r112", "qa:operator");
    ambiguousOps.Update(stageRecord, "staged", "STAGED");
    Deny(() => new SupervisorUniversalUpgradeFence(ambiguous, ambiguousOps)
        .Reserve("qa:operator", "unqualified-history", localSha, publicSha, maintSha),
        "OLD_STAGE_WITHOUT_DURABLE_RECEIPTS_NOT_UNIVERSAL_SAFE");
    var unknown = new MaintenancePaths(Path.Combine(dir, "historical-unknown"));
    AtomicFile.WriteJson(unknown.InstalledReleaseIdentityPath, new { version = "qa" });
    var unknownOps = new MaintenanceOperationStore(unknown);
    var (unknownRecord, _) = unknownOps.GetOrCreate("historical-unknown",
        "c" + new string('0', 63), "update.local-stage", "old-unknown", "qa:operator");
    unknownOps.Update(unknownRecord, "unexpected_legacy_state");
    Deny(() => new SupervisorUniversalUpgradeFence(unknown, unknownOps)
        .Reserve("qa:operator", "unknown-history", localSha, publicSha, maintSha),
        "UNKNOWN_HISTORICAL_STATE_NOT_IDLE");
    Console.WriteLine("PASS REAL_CORE_DURABLE_UNIVERSAL_FENCE_NEGATIVE_MATRIX_RELEASE_BLOCKED");
}
finally
{
    if (Directory.Exists(dir)) Directory.Delete(dir, recursive: true);
}
