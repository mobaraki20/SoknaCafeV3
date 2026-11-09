using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

// Deliberately no MachineMutationLease, MaintenanceOperationStore, journal,
// backup/restore/migration apply, service control, File.Move or File.Delete.
// Read the existing cafe's live migration ledger through the SAME selector
// as formal R125 reconciliation, and export only bounded safe status codes.
var result = new Dictionary<string, object?>
{
    ["format"] = "sokna-r125-live-db-readonly-preflight-v1",
    ["collected_at_utc"] = DateTimeOffset.UtcNow,
    ["verification_scope"] = "R125_RECOVERED_BYTE_EXACT_AND_LIVE_DB_SELECT_ONLY",
    ["live_database_migration_ledger"] = "NOT_VERIFIED",
    ["recovered_r125_proof"] = "NOT_VERIFIED",
    ["maintenance_backup_state"] = "NOT_CHECKED",
    ["backup_restore_proven"] = false,
    ["ready_for_production_or_upgrade"] = false,
    ["actual_reconciliation_performed"] = false,
    ["local_public_windows_service_modified"] = false,
    ["database_schema_data_mutated"] = false,
    ["status"] = "BLOCKED",
    ["safe_code"] = "R125_PREFLIGHT_NOT_STARTED"
};
var desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
var timestamp = DateTimeOffset.UtcNow.ToString("yyyyMMdd-HHmmss");
var output = Path.Combine(desktop, $"SOKNA-R125-READONLY-DB-PREFLIGHT-{timestamp}-{Guid.NewGuid():N}.json");
var root = MaintenancePaths.DefaultDataRoot();
try
{
    if (args.Length != 0)
    {
        // Test override is available *only* on disposable GitHub Windows CI.
        if (args.Length != 4 || args[0] != "--qa-data-root" || args[2] != "--qa-output" ||
            Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
            Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
            throw new InvalidDataException("R125_PREFLIGHT_UNRECOGNIZED_ARGUMENTS");
        var tmp = Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP") ??
            throw new InvalidDataException("R125_CI_TEMP_UNAVAILABLE"));
        root = Path.GetFullPath(args[1]);
        output = Path.GetFullPath(args[3]);
        var prefix = tmp.TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (!root.StartsWith(prefix, StringComparison.OrdinalIgnoreCase) ||
            !output.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("R125_CI_ROOT_OUTSIDE_DISPOSABLE_WORKSPACE");
    }
    var paths = new MaintenancePaths(root);
    var proof = Path.Combine(paths.LocalSlotStateRoot, "routine-local-lifecycle-before.json");
    var schedule = Path.Combine(paths.DataRoot, "backups", "schedule-state.json");
    result["maintenance_backup_state"] = File.Exists(schedule)
        ? "SCHEDULE_PRESENT_NOT_RESTORE_VERIFIED"
        : "NO_SCHEDULE_RECORD_NOT_A_PROOF_OF_NO_EXTERNAL_BACKUP";
    if (!File.Exists(proof))
        throw new InvalidDataException("R125_ORIGINAL_PROOF_NOT_FOUND");
    if ((File.GetAttributes(proof) & FileAttributes.ReparsePoint) != 0 ||
        new FileInfo(proof).Length is <= 0 or > 8_000_000)
        throw new InvalidDataException("R125_ORIGINAL_PROOF_UNSAFE");
    using var stream = File.OpenRead(proof);
    var sha = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    result["proof_sha256"] = sha;
    result["proof_bytes"] = stream.Length;
    result["recovered_r125_proof"] = "PRESENT_WITH_SHA256";
    // Only active build identity and exact migration ledger status; no records.
    var layout = new LocalSlotManager(paths).InspectHealthyCurrent();
    result["local_build"] = layout.Build;
    SupervisorR125LifecycleEvidenceReconciler.RequireReadOnlyQualification(
        paths, proof, layout.Build, sha);
    result["live_database_migration_ledger"] = "PASS_SELECT_ONLY";
    result["recovered_r125_proof"] = "PASS_BYTE_EXACT";
    result["status"] = "READ_ONLY_CHECKS_PASSED__BACKUP_RESTORE_STILL_REQUIRED";
    result["safe_code"] = "R125_READONLY_ELIGIBLE_NOT_PRODUCTION_APPROVED";
}
catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or
    InvalidDataException or FileNotFoundException or DirectoryNotFoundException or
    InvalidOperationException or TimeoutException or System.ComponentModel.Win32Exception or
    System.Security.Cryptography.CryptographicException)
{
    // Never disclose exception message: it may expose local paths, connection
    // details, passwords, DDL statements, or other private evidence.
    result["status"] = "BLOCKED";
    result["safe_code"] = SafeCode(ex);
}
try
{
    if (string.IsNullOrWhiteSpace(Path.GetDirectoryName(output)))
        throw new InvalidOperationException("R125_OUTPUT_PATH_UNSAFE");
    var json = JsonSerializer.Serialize(result, new JsonSerializerOptions { WriteIndented = true });
    using var file = new FileStream(output, FileMode.CreateNew, FileAccess.Write,
        FileShare.None, 4096, FileOptions.WriteThrough);
    var bytes = new UTF8Encoding(false).GetBytes(json + Environment.NewLine);
    file.Write(bytes);
    file.Flush(true);
    Console.WriteLine("SOKNA_R125_READONLY_REPORT=" + output);
    // No Browser/Explorer launch in CI; open the report folder for the operator.
    if (args.Length == 0)
    {
        try
        {
            System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Windows),
                    "explorer.exe"))
            {
                UseShellExecute = true,
                ArgumentList = { "/select," + output }
            });
        }
        catch { /* written report remains authoritative */ }
    }
}
catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidOperationException)
{
    Console.WriteLine("SOKNA_R125_READONLY_REPORT_WRITE_FAILED");
    Environment.ExitCode = 3;
    return;
}
Environment.ExitCode = result["status"]?.ToString()?.StartsWith("READ_ONLY_CHECKS_PASSED",
    StringComparison.Ordinal) == true ? 0 : 2;

static string SafeCode(Exception ex)
{
    if (ex is UnauthorizedAccessException) return "R125_READ_ACCESS_DENIED";
    if (ex is FileNotFoundException or DirectoryNotFoundException) return "R125_REQUIRED_FILE_OR_DIRECTORY_UNAVAILABLE";
    if (ex is TimeoutException) return "R125_SELECT_ONLY_PREFLIGHT_TIMEOUT";
    if (ex is System.ComponentModel.Win32Exception) return "R125_SYSTEM_INSPECTION_UNAVAILABLE";
    if (ex is System.Security.Cryptography.CryptographicException) return "R125_SHA_VERIFICATION_FAILED";
    if (ex is InvalidDataException ide)
    {
        var value = ide.Message;
        // Stable, explicitly allowlisted code; never propagate unknown message.
        string[] allow = [
            "R125_RECOVERY_STATE_NOT_QUALIFIED",
            "R125_ORIGINAL_PROOF_NOT_FOUND",
            "R125_ORIGINAL_PROOF_UNSAFE",
            "R125_ACTIVE_BUILD_OR_LAYOUT_MISMATCH",
            "R125_RECOVERY_STATE_MISSING",
            "R125_INSTALLED_IDENTITY_NOT_QUALIFIED",
            "R125_PROOF_SHA256_MISMATCH",
            "R125_PROOF_UNAVAILABLE_OR_UNSAFE",
            "R125_UNEXPECTED_MIGRATION_PLAN",
            "LOCAL_MIGRATIONS_NOT_APPEND_ONLY",
            "LOCAL_MIGRATION_CATALOG_MISSING",
            "LOCAL_MIGRATION_CATALOG_INVALID",
            "LOCAL_LIFECYCLE_RECOVERED_BYTES_NOT_EXACT",
            "R125_PREFLIGHT_UNRECOGNIZED_ARGUMENTS",
            "R125_CI_TEMP_UNAVAILABLE",
            "R125_CI_ROOT_OUTSIDE_DISPOSABLE_WORKSPACE",
            "LOCAL_DATABASE_HELPER_MISSING"
        ];
        if (allow.Contains(value, StringComparer.Ordinal)) return value;
        if (value.StartsWith("LOCAL_DATABASE_INSPECT_FAILED_STATUS_", StringComparison.Ordinal))
            return "R125_LIVE_DATABASE_LEDGER_INSPECTION_FAILED";
        return "R125_READONLY_EVIDENCE_UNQUALIFIED";
    }
    return "R125_READONLY_EVIDENCE_UNAVAILABLE";
}
