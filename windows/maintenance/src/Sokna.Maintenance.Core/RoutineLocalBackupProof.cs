using System.Text.Json;
using System.Text.Json.Nodes;

namespace Sokna.Maintenance.Core;

// Validates ALL rollback bytes before Recovery mutates the active Web pointer
// or rewrites either Local lifecycle file. Not an independent update engine.
public static class RoutineLocalBackupProof
{
    public static void ValidateSnapshot(string backupPath, string previousBuild,
        string expectedStatePath, string expectedManagedPath)
    {
        if (!File.Exists(backupPath) ||
            (File.GetAttributes(backupPath) & FileAttributes.ReparsePoint) != 0 ||
            new FileInfo(backupPath).Length is <= 0 or > 8_000_000)
            throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_UNAVAILABLE");
        JsonObject proof;
        try { proof = JsonNode.Parse(File.ReadAllBytes(backupPath)) as JsonObject
            ?? throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT"); }
        catch (JsonException ex) { throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT", ex); }
        if ((string?)proof["format"] != "sokna-routine-local-lifecycle-backup-v1" ||
            (string?)proof["active_build"] != previousBuild ||
            (string?)proof["state_path"] != expectedStatePath ||
            (string?)proof["managed_path"] != expectedManagedPath)
            throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_IDENTITY_MISMATCH");

        byte[] rawState;
        try { rawState = Convert.FromBase64String((string?)proof["state_base64"] ?? ""); }
        catch (FormatException ex) { throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT", ex); }
        if (rawState.Length is <= 0 or > 4_000_000)
            throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT");
        try
        {
            var state = JsonNode.Parse(rawState) as JsonObject;
            if (state is null || (string?)state["format"] != "sokna-local-update-state-v1" ||
                (string?)state["active_version"] != previousBuild)
                throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_STATE_MISMATCH");
            if ((string?)proof["managed_base64"] is string encodedManaged)
            {
                byte[] rawManaged;
                try { rawManaged = Convert.FromBase64String(encodedManaged); }
                catch (FormatException ex) { throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT", ex); }
                if (rawManaged.Length is <= 0 or > 4_000_000)
                    throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT");
                // Rollback restores the PREVIOUS Local bytes, which may
                // use a legacy managed-files shape. Require valid JSON object
                // and preserve its exact bytes; don't impose a target-version
                // schema on historical recovery evidence.
                var managed = JsonNode.Parse(rawManaged) as JsonObject;
                if (managed is null)
                    throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_MANAGED_MISMATCH");
            }
        }
        catch (JsonException ex) { throw new InvalidDataException("ROUTINE_ROLLBACK_EVIDENCE_CORRUPT", ex); }
    }
}
