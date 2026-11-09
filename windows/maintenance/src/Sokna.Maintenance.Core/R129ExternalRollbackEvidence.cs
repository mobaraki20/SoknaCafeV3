using System.Security.Cryptography;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

/// <summary>Read-only qualification of the existing R129 evidence contract.
/// A historical DB mutation stays true. No receipt alone authorizes recovery:
/// the caller must also attest the live slot, lifecycle bytes, identity and DB.
/// No machine name, fixed UUID, operator log or uploaded receipt is an exception.</summary>
internal static class R129ExternalRollbackEvidence
{
    private static void Need(bool ok, string code)
    { if (!ok) throw new InvalidDataException("R129_" + code); }

    private static byte[] Bytes(string path, long limit = 8_000_000)
    {
        Need(File.Exists(path) && (File.GetAttributes(path) & FileAttributes.ReparsePoint) == 0,
            "EVIDENCE_MISSING_OR_REPARSE");
        Need(new FileInfo(path).Length is > 0 && new FileInfo(path).Length <= limit,
            "EVIDENCE_SIZE_INVALID");
        for (var dir = Path.GetDirectoryName(Path.GetFullPath(path)); dir is not null; dir = Path.GetDirectoryName(dir))
            Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0, "EVIDENCE_MISSING_OR_REPARSE");
        return File.ReadAllBytes(path);
    }
    private static JsonObject Object(string path) =>
        JsonNode.Parse(Bytes(path)) as JsonObject ?? throw new InvalidDataException("R129_EVIDENCE_JSON_INVALID");
    private static string? S(JsonObject o, string key) => o[key]?.GetValue<string>();
    private static bool? B(JsonObject o, string key) => o[key]?.GetValue<bool>();
    private static string Hash(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();

    internal static void Require(MaintenancePaths paths, string build, RoutineMigrationPlan plan)
    {
        var root = paths.LocalSlotStateRoot;
        Need(Directory.Exists(root) && (File.GetAttributes(root) & FileAttributes.ReparsePoint) == 0,
            "EVIDENCE_ROOT_UNSAFE");
        var files = Directory.GetFiles(root, "r125-reconcile-intent-*.json");
        Need(files.Length == 1, "RECEIPT_MISSING_OR_AMBIGUOUS");
        var name = Path.GetFileName(files[0]);
        var suffix = name["r125-reconcile-intent-".Length..^5];
        Need(Regex.IsMatch(suffix, "^[A-Za-z0-9_-]{1,100}$"), "RECEIPT_NAME_INVALID");
        var receipt = Object(files[0]);
        Need(S(receipt, "format") == "sokna-r129-external-recovery-reconciliation-v1" &&
            S(receipt, "recovered_build") == build &&
            S(receipt, "db_schema_head") == plan.SourceHead && !plan.RequiresMigration &&
            plan.SourceHead == "0076_late_served_accounting", "RECEIPT_SCOPE_MISMATCH");
        var originalPath = Path.Combine(root, "r125-reconcile-original-" + suffix + ".json");
        var operationPath = Path.Combine(root, "r125-reconcile-original-operation-" + suffix + ".json");
        var originalBytes = Bytes(originalPath); var operationBytes = Bytes(operationPath);
        Need(Hash(originalBytes) == S(receipt, "routine_state_original_sha256") &&
            Hash(operationBytes) == S(receipt, "operation_original_sha256"), "ORIGINAL_HASH_MISMATCH");
        var original = Object(originalPath); var operation = Object(operationPath);
        Need(S(original, "format") == "sokna-local-slot-routine-state-v1" &&
            original["schema_version"]?.GetValue<int>() == 1 &&
            S(original, "current_build") == build && S(original, "phase") == "RECOVERY_REQUIRED" &&
            B(original, "recovery_required") == true && B(original, "database_mutation_performed") == true &&
            B(original, "public_mutation_performed") == false && B(original, "windows_reboot_required") == false &&
            B(original, "activation_performed") == false && B(original, "maintenance_identity_commit_expected") == true,
            "ORIGINAL_ROUTINE_UNQUALIFIED");
        var migration = original["migrationPlan"] as JsonObject;
        Need(migration is not null && S(migration, "sourceFingerprint") == plan.SourceFingerprint &&
            S(migration, "sourceHead") == plan.SourceHead &&
            migration["sourceCount"]?.GetValue<int>() == plan.SourceCount, "ORIGINAL_CATALOG_MISMATCH");
        var id = S(receipt, "blocked_operation_id");
        Need(id is not null && Regex.IsMatch(id, "^[a-f0-9]{32}$") && S(operation, "operation_id") == id &&
            S(operation, "format") == "sokna-maintenance-operation-v1" &&
            S(operation, "operation_type") == "update.local-code-only-activate" &&
            S(operation, "state") == "recovery_required" &&
            S(operation, "result_code") == "CODE_ONLY_EXECUTION_OUTCOME_UNPROVEN" &&
            B(operation, "activation_performed") == true && B(operation, "database_mutation_performed") == true,
            "ORIGINAL_OPERATION_UNQUALIFIED");
        var liveOp = Object(Path.Combine(paths.DataRoot, "operations", "op-" + id + ".json"));
        Need(S(liveOp, "state") == "failed" &&
            S(liveOp, "result_code") == "CODE_ONLY_ENGINE_ROLLED_BACK_VERIFIED" &&
            B(liveOp, "database_mutation_performed") == false, "OPERATION_NOT_SETTLED");
        foreach (var key in new[] { "state", "result_code", "result_message", "updated_at_utc", "database_mutation_performed" })
        { operation.Remove(key); liveOp.Remove(key); }
        Need(JsonNode.DeepEquals(operation, liveOp), "OPERATION_SCOPE_DRIFT");

        var current = Object(Path.Combine(root, "routine-state.json"));
        var events = original["events"] as JsonArray;
        var currentEvents = current["events"] as JsonArray;
        Need(events is not null && events.Count > 0 && events[^1]?["state"]?.GetValue<string>() == "RECOVERY_REQUIRED" &&
            currentEvents is not null && currentEvents.Count == events.Count + 1, "HISTORY_MISMATCH");
        var last = currentEvents![^1] as JsonObject;
        Need(last is not null && S(last, "state") == "RECOVERED_TO_PREVIOUS_SLOT" &&
            S(last, "detail") == "R125_EXTERNAL_EXACT_ROLLBACK_R112_PROVEN_BY_R129" &&
            DateTimeOffset.TryParse(S(last, "at_utc"), out var at) &&
            DateTimeOffset.TryParse(S(receipt, "at_utc"), out var receiptAt) &&
            at >= receiptAt && at - receiptAt <= TimeSpan.FromMinutes(5), "RECOVERY_EVENT_UNQUALIFIED");
        original["phase"] = "RECOVERED_TO_PREVIOUS_SLOT"; original["recovery_required"] = false;
        original["updated_at_utc"] = current["updated_at_utc"]?.DeepClone();
        events!.Add(last!.DeepClone());
        Need(JsonNode.DeepEquals(original, current), "ROUTINE_SCOPE_DRIFT");
        var backup = current["databaseBackup"] as JsonObject;
        Need(backup is not null && S(backup, "sha256") == S(receipt, "backup_sha256"), "BACKUP_BINDING_MISMATCH");
        RoutineLocalDatabase.RequireBackup(new RoutineDatabaseBackup(
            S(backup!, "path") ?? "", S(backup!, "sha256") ?? "", backup!["bytes"]?.GetValue<long>() ?? -1), root);
        var intent = Object(Path.Combine(root, "r125-emergency-recovery-intent.json"));
        Need(S(intent, "format") == "sokna-r125-emergency-exact-revert-v1" &&
            S(intent, "to_version") == build && S(intent, "from_version") == S(current, "target_build") &&
            S(intent, "backup_sha256") == S(receipt, "backup_sha256") &&
            S(intent, "reason") == "0078_FIRST_GUARD_AND_ONLY_0077_EMPTY_TABLE", "RECOVERY_INTENT_MISMATCH");
    }
}
