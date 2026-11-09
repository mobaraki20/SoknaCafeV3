using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Sokna.Maintenance.Core;

/// <summary>Observation does not require permission to mutate or archive.
/// Only fixed safe fields are exported; configuration, SQL, paths and rows stay private.</summary>
public static class R125ReadOnlyAssessment
{
    public static Dictionary<string, object?> Inspect(MaintenancePaths paths)
    {
        var result = new Dictionary<string, object?> {
            ["live_database_migration_ledger"] = "NOT_VERIFIED",
            ["live_database_safe_code"] = "R125_DATABASE_INSPECTION_NOT_STARTED",
            ["r129_evidence"] = "NOT_VERIFIED",
            ["r129_safe_code"] = "R129_NOT_INSPECTED",
            ["routine_state_safe_code"] = "R125_ROUTINE_NOT_INSPECTED",
            ["history_preserved"] = true,
            ["archive_authorized_by_assessment"] = false,
            ["backup_restore_proven"] = false,
            ["ready_for_production_or_upgrade"] = false
        };
        RoutineLocalSlotState? state = null;
        try {
            var p = Path.Combine(paths.LocalSlotStateRoot, "routine-state.json");
            if ((File.GetAttributes(p) & FileAttributes.ReparsePoint) != 0 || new FileInfo(p).Length > 8_000_000)
                throw new InvalidDataException("R125_ROUTINE_UNSAFE");
            var json = JsonNode.Parse(File.ReadAllBytes(p)) as JsonObject ?? throw new InvalidDataException("R125_ROUTINE_INVALID");
            state = AtomicFile.ReadJson<RoutineLocalSlotState>(p);
            var phase = json["phase"]?.GetValue<string>();
            result["routine_phase"] = phase is "RECOVERED_TO_PREVIOUS_SLOT" or "RECOVERY_REQUIRED" or "COMMITTED" or "ROLLED_BACK" ? phase : "UNKNOWN";
            foreach (var key in new[] { "database_mutation_performed", "recovery_required", "public_mutation_performed", "windows_reboot_required", "maintenance_identity_commit_expected" })
                result[key] = json[key] is JsonValue value && value.TryGetValue<bool>(out var flag) ? flag : null;
            result["routine_state_safe_code"] = "R125_ROUTINE_READ";
        } catch (Exception e) when (SafeException(e)) { result["routine_state_safe_code"] = Code(e); }

        try {
            var layout = new LocalSlotManager(paths).InspectHealthyCurrent();
            result["local_build"] = layout.Build;
            if (state is null || state.CurrentBuild != layout.Build)
                throw new InvalidDataException("R125_ACTIVE_BUILD_OR_LAYOUT_MISMATCH");
            var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(paths.InstalledReleaseIdentityPath);
            if (identity.Local?.Version != layout.Build || identity.Local.ManagedTreeSha256 != state.CurrentManagedTreeSha256)
                throw new InvalidDataException("R125_INSTALLED_IDENTITY_NOT_QUALIFIED");
            LocalManagedTreeAttestation.RequireExact(layout.WebRoot, state.CurrentManagedFiles, state.CurrentManagedTreeSha256);
            var plan = RoutineLocalDatabase.Plan(layout.WebRoot, layout.WebRoot);
            // This is independent from the reconciliation permission gate. Even
            // an unresolved history can be observed after exact source attestation.
            RoutineLocalDatabase.RequireBaseline(layout.PackageRoot, layout.WebRoot, layout.WebRoot, plan);
            result["live_database_migration_ledger"] = "PASS_SELECT_ONLY";
            result["live_database_safe_code"] = "R125_DATABASE_BASELINE_VERIFIED";
            if (state.DatabaseMutationPerformed) {
                try {
                    R129ExternalRollbackEvidence.Require(paths, layout.Build, plan);
                    RoutineLocalDatabase.RequireExternalRollbackBaseline(layout.PackageRoot, layout.WebRoot, plan);
                    result["r129_evidence"] = "PASS_EVIDENCE_AND_LIVE_BASELINE";
                    result["r129_safe_code"] = "R129_EXTERNAL_ROLLBACK_VERIFIED_HISTORY_RETAINED";
                } catch (Exception e) when (SafeException(e)) { result["r129_safe_code"] = Code(e); }
            } else result["r129_evidence"] = "NOT_REQUIRED_NO_HISTORICAL_DB_FLAG";
        } catch (Exception e) when (SafeException(e)) { result["live_database_safe_code"] = Code(e); }
        return result;
    }

    public static bool SafeException(Exception e) => e is IOException or UnauthorizedAccessException or
        InvalidDataException or InvalidOperationException or TimeoutException or JsonException or
        FormatException or System.ComponentModel.Win32Exception or CryptographicException;

    public static string Code(Exception e)
    {
        if (e is TimeoutException) return "R125_READONLY_TIMEOUT";
        if (e is UnauthorizedAccessException) return "R125_READ_ACCESS_DENIED";
        if (e is InvalidDataException) {
            // Export only literal application codes. Never an arbitrary exception message.
            string[] allowed = [ "R125_RECOVERY_STATE_NOT_QUALIFIED", "R125_ROUTINE_UNSAFE", "R125_ROUTINE_INVALID",
                "R125_ACTIVE_BUILD_OR_LAYOUT_MISMATCH", "R125_INSTALLED_IDENTITY_NOT_QUALIFIED",
                "R125_MAINTENANCE_IDENTITY_UPGRADE_UNPROVEN", "LOCAL_LIFECYCLE_RECOVERED_BYTES_NOT_EXACT",
                "R129_EVIDENCE_MISSING_OR_REPARSE", "R129_EVIDENCE_ROOT_UNSAFE", "R129_EVIDENCE_SIZE_INVALID",
                "R129_EVIDENCE_JSON_INVALID", "R129_RECEIPT_MISSING_OR_AMBIGUOUS", "R129_RECEIPT_NAME_INVALID",
                "R129_RECEIPT_SCOPE_MISMATCH", "R129_ORIGINAL_HASH_MISMATCH", "R129_ORIGINAL_ROUTINE_UNQUALIFIED",
                "R129_ORIGINAL_CATALOG_MISMATCH", "R129_ORIGINAL_OPERATION_UNQUALIFIED", "R129_OPERATION_NOT_SETTLED",
                "R129_OPERATION_SCOPE_DRIFT", "R129_HISTORY_MISMATCH", "R129_RECOVERY_EVENT_UNQUALIFIED",
                "R129_ROUTINE_SCOPE_DRIFT", "R129_BACKUP_BINDING_MISMATCH", "R129_RECOVERY_INTENT_MISMATCH",
                "LOCAL_DATABASE_BACKUP_PROOF_INVALID", "LOCAL_MIGRATIONS_NOT_APPEND_ONLY",
                "LOCAL_MIGRATION_CATALOG_MISSING", "LOCAL_MIGRATION_CATALOG_INVALID" ];
            if (allowed.Contains(e.Message, StringComparer.Ordinal)) return e.Message;
            if (e.Message.StartsWith("LOCAL_DATABASE_INSPECT_FAILED_STATUS_", StringComparison.Ordinal))
                return "R125_LIVE_DATABASE_LEDGER_INSPECTION_FAILED";
            if (e.Message.StartsWith("LOCAL_DATABASE_INSPECT-EXTERNAL-ROLLBACK_FAILED_STATUS_", StringComparison.Ordinal))
                return "R129_LIVE_DATABASE_ROLLBACK_NOT_QUALIFIED";
        }
        return "R125_READONLY_EVIDENCE_UNAVAILABLE";
    }
}
