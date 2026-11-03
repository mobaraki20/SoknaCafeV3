using System.Security.Cryptography;
using System.Text.Json;

namespace Sokna.Maintenance.Core;

/// <summary>
/// One narrowly scoped, audited compatibility migration for the field-observed
/// Cafe 231bf overlay pair. It NEVER accepts arbitrary older PHP, an unmatched
/// tree, redirected files, or a half-known pair without preserved backups.
/// The trusted replacement comes only from the installed Maintenance assembly.
/// Caller already verified installed identity, slot junction, bindings, and
/// operation ledger, and owns the machine mutation lease.
/// </summary>
internal static class KnownCafeRecoveryOverlayMigration
{
    internal const string BaselineVersion = "3.0.0-g6.2-workspace.231bf-u01-iran-time-updater-rc1";
    internal const string BaselineTree = "80fd968a9e4f1642c3f1519f66d7ab6a2fb1bb64f1a8c0a26444d0d8da1712be";
    internal const string LegacySha256 = "e23949e5522c42fc8e6cf661b302a2fbb23f0c2d6138a873bef8644c2a5ee7a9";
    internal const int LegacyLength = 6691;
    private const string Invalid = "STABLE_RECOVERY_EXISTING_OVERLAY_NOT_QUALIFIED";

    // Additional *exact* Local baseline attested by the R94 231cs multi-baseline
    // release set. Never grant this migration based on version alone; the caller
    // must additionally verify the physical managed tree and installed identity.
    internal const string Cafe231BzVersion = "3.0.0-g6.2-workspace.231bz-r38-87-integrated-panel-uat";
    internal const string Cafe231BzTree = "827037f2efdf313560fbf24a430cec3eeeedfd274b1fc658d45dfcb3aa4d7fa1";

    internal static bool Qualifies(string version, string treeSha) =>
        (string.Equals(version, BaselineVersion, StringComparison.Ordinal) &&
         string.Equals(treeSha, BaselineTree, StringComparison.OrdinalIgnoreCase)) ||
        (string.Equals(version, Cafe231BzVersion, StringComparison.Ordinal) &&
         string.Equals(treeSha, Cafe231BzTree, StringComparison.OrdinalIgnoreCase));

    internal static bool TryMigrate(string packageRoot, string version, string treeSha,
        string[] files, byte[] trustedPayload) =>
        TryMigrateMissingPartnerCore(packageRoot, version, treeSha, files, trustedPayload,
            LegacySha256, LegacyLength) ||
        TryMigrateCore(packageRoot, version, treeSha, files, trustedPayload,
            LegacySha256, LegacyLength);

    // Cafe host, 2026-11-03: persistent overlay is the exact historical 6691-byte
    // approved PHP while Web's active overlay is absent. The old pair-only
    // migration cannot handle this combination. Both the old bytes AND absence
    // are recorded off-webroot before any mutation; foreign content never passes.
    // This does not broaden adoption: physical tree, slot, binding, idle ledger,
    // machine lease and installed identity are verified by the caller.
    internal static bool TryMigrateMissingPartnerCore(string packageRoot, string version, string treeSha,
        string[] files, byte[] trustedPayload, string approvedLegacySha256, int approvedLegacyLength)
    {
        if (!Qualifies(version, treeSha)) return false;
        if (files.Length != 2 || trustedPayload.Length is < 100 or > 65536 ||
            approvedLegacySha256.Length != 64 || approvedLegacyLength < 100) 
            throw new InvalidDataException(Invalid);

        var expected = Hash(trustedPayload);
        var legacy = Convert.FromHexString(approvedLegacySha256);
        var oldIndex = -1;
        var absentIndex = -1;
        byte[]? original = null;

        // Inspect both before creating evidence or writing either target.
        for (var i = 0; i < 2; i++)
        {
            var file = files[i];
            var dir = Path.GetDirectoryName(file)!;
            if (!Directory.Exists(dir) ||
                (File.GetAttributes(dir) & FileAttributes.ReparsePoint) != 0 ||
                Directory.Exists(file)) return false;
            if (!File.Exists(file))
            {
                if (absentIndex != -1) return false;
                absentIndex = i;
                continue;
            }
            if ((File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0) return false;
            var content = File.ReadAllBytes(file);
            if (content.Length != approvedLegacyLength || !Equal(Hash(content), legacy))
                return false;
            if (oldIndex != -1) return false;
            oldIndex = i;
            original = content;
        }
        if (oldIndex < 0 || absentIndex < 0 || original is null) return false;

        var localWeb = Path.Combine(packageRoot, "LocalWeb");
        if (!Directory.Exists(localWeb) ||
            (File.GetAttributes(localWeb) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_ROOT_UNSAFE");
        // Separate from the original pair's evidence, so an old pair receipt can
        // never be mistaken for a one-missing historical state.
        var backups = Path.Combine(localWeb, "recovery-overlay-migration-missing-" +
            approvedLegacySha256[..16] + "-" + treeSha[..12]);
        if (File.Exists(backups)) throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_ROOT_UNSAFE");
        Directory.CreateDirectory(backups);
        if ((File.GetAttributes(backups) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_ROOT_UNSAFE");

        var labels = new[] { "persistent", "active" };
        var previous = Path.Combine(backups, labels[oldIndex] + ".before.bin");
        var absent = Path.Combine(backups, labels[absentIndex] + ".before.absent");
        var receipt = Path.Combine(backups, "missing-partner-migration-receipt.json");
        var marker = System.Text.Encoding.ASCII.GetBytes("SOKNA_STABLE_RECOVERY_ABSENT_BEFORE_V1\n");
        if (File.Exists(receipt)) throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_RECEIPT_CONFLICT");
        if (File.Exists(previous))
        {
            if ((File.GetAttributes(previous) & FileAttributes.ReparsePoint) != 0 ||
                new FileInfo(previous).Length != approvedLegacyLength ||
                !Equal(Hash(File.ReadAllBytes(previous)), legacy))
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_CONFLICT");
        }
        else CreateAtomically(previous, original);
        if (!Equal(Hash(File.ReadAllBytes(previous)), legacy))
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_READBACK_FAILED");

        if (File.Exists(absent))
        {
            if ((File.GetAttributes(absent) & FileAttributes.ReparsePoint) != 0 ||
                !File.ReadAllBytes(absent).SequenceEqual(marker))
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_ABSENCE_PROOF_CONFLICT");
        }
        else CreateAtomically(absent, marker);
        if (!File.ReadAllBytes(absent).SequenceEqual(marker))
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_ABSENCE_PROOF_FAILED");

        // Re-check the two live targets immediately before mutation, under the
        // caller's exclusive machine lease.
        if (!File.Exists(files[oldIndex]) ||
            (File.GetAttributes(files[oldIndex]) & FileAttributes.ReparsePoint) != 0 ||
            !Equal(Hash(File.ReadAllBytes(files[oldIndex])), legacy) ||
            File.Exists(files[absentIndex]) || Directory.Exists(files[absentIndex]))
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_TARGET_CHANGED");

        var changedOld = false;
        var createdNew = false;
        try
        {
            ReplaceAtomically(files[oldIndex], trustedPayload);
            changedOld = true;
            if (!Equal(Hash(File.ReadAllBytes(files[oldIndex])), expected))
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_READBACK_FAILED");
            CreateAtomically(files[absentIndex], trustedPayload); // Verified temp + no-overwrite rename.
            createdNew = true;
            if (files.Any(file => !File.Exists(file) || !Equal(Hash(File.ReadAllBytes(file)), expected)))
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_PAIR_INCOMPLETE");

            var json = JsonSerializer.Serialize(new {
                format = "sokna-stable-recovery-known-cafe-missing-partner-v1",
                baseline_version = version, baseline_tree_sha256 = treeSha,
                old_sha256 = approvedLegacySha256,
                new_sha256 = Convert.ToHexString(expected).ToLowerInvariant(),
                old_path_kind = labels[oldIndex], missing_path_kind = labels[absentIndex],
                previous_backup = Path.GetFileName(previous),
                absence_marker = Path.GetFileName(absent),
                activation_performed = false, database_mutation_performed = false,
                created_at_utc = DateTimeOffset.UtcNow.ToString("O")
            });
            CreateAtomically(receipt, System.Text.Encoding.UTF8.GetBytes(json));
            return true;
        }
        catch
        {
            // Restore only files changed in this invocation, never delete a
            // different writer's content. Preserve off-webroot evidence.
            var restored = true;
            if (createdNew)
            {
                try
                {
                    if (!Equal(Hash(File.ReadAllBytes(files[absentIndex])), expected))
                        restored = false;
                    else File.Delete(files[absentIndex]);
                }
                catch { restored = false; }
            }
            if (changedOld)
            {
                try
                {
                    ReplaceAtomically(files[oldIndex], File.ReadAllBytes(previous));
                    if (!Equal(Hash(File.ReadAllBytes(files[oldIndex])), legacy)) restored = false;
                }
                catch { restored = false; }
            }
            if (!restored)
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_ROLLBACK_UNPROVEN");
            throw;
        }
    }

    // CI injects a synthetic legacy hash to exercise atomic migration and
    // rollback without distributing the field-observed recovery PHP bytes.
    internal static bool TryMigrateCore(string packageRoot, string version, string treeSha,
        string[] files, byte[] trustedPayload, string approvedLegacySha256, int approvedLegacyLength)
    {
        if (!Qualifies(version, treeSha)) return false;
        if (files.Length != 2 || trustedPayload.Length < 100 || trustedPayload.Length > 65536)
            throw new InvalidDataException(Invalid);
        var expected = Hash(trustedPayload);
        var legacy = Convert.FromHexString(approvedLegacySha256);
        var current = new byte[2][];
        var old = new bool[2];

        // Inspect both before any write. A foreign or redirected file is NEVER
        // replaced. Both must already exist; missing-file provisioning remains
        // the responsibility of StableLocalRecoveryOverlayRepair.
        for (var i = 0; i < 2; i++)
        {
            var file = files[i];
            if (Directory.Exists(file) || !File.Exists(file) ||
                (File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0)
                return false;
            var dir = Path.GetDirectoryName(file)!;
            if (!Directory.Exists(dir) ||
                (File.GetAttributes(dir) & FileAttributes.ReparsePoint) != 0)
                return false;
            current[i] = File.ReadAllBytes(file);
            var hash = Hash(current[i]);
            old[i] = current[i].Length == approvedLegacyLength && Equal(hash, legacy);
            if (!old[i] && !Equal(hash, expected)) return false;
        }
        if (!old[0] && !old[1]) return false;

        var localWeb = Path.Combine(packageRoot, "LocalWeb");
        if (!Directory.Exists(localWeb) ||
            (File.GetAttributes(localWeb) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_ROOT_UNSAFE");
        var backups = Path.Combine(localWeb, "recovery-overlay-migration-" + approvedLegacySha256[..16]);
        Directory.CreateDirectory(backups);
        if ((File.GetAttributes(backups) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_ROOT_UNSAFE");

        // Backup files live outside the HTTP document root and on the same
        // volume as the active/persistent overlay. The exact old bytes must be
        // preserved before either target changes. Existing backups must attest.
        var names = new[] { "persistent.before.bin", "active.before.bin" };
        var backupsPath = names.Select(x => Path.Combine(backups, x)).ToArray();
        for (var i = 0; i < 2; i++)
        {
            if (File.Exists(backupsPath[i]))
            {
                if ((File.GetAttributes(backupsPath[i]) & FileAttributes.ReparsePoint) != 0 ||
                    new FileInfo(backupsPath[i]).Length != approvedLegacyLength ||
                    !Equal(Hash(File.ReadAllBytes(backupsPath[i])), legacy))
                    throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_CONFLICT");
            }
            else
            {
                if (!old[i]) throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_MISSING");
                WriteNew(backupsPath[i], current[i]);
                if (!Equal(Hash(File.ReadAllBytes(backupsPath[i])), legacy))
                    throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_BACKUP_READBACK_FAILED");
            }
        }

        var replaced = new List<int>();
        try
        {
            for (var i = 0; i < 2; i++)
            {
                if (!old[i]) continue;
                ReplaceAtomically(files[i], trustedPayload);
                replaced.Add(i);
                if (!Equal(Hash(File.ReadAllBytes(files[i])), expected))
                    throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_READBACK_FAILED");
            }
            for (var i = 0; i < 2; i++)
                if (!Equal(Hash(File.ReadAllBytes(files[i])), expected))
                    throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_PAIR_INCOMPLETE");
        }
        catch
        {
            // Restore only targets touched in THIS invocation; retain verified
            // backups for incident review even after a successful rollback.
            var restored = true;
            foreach (var i in replaced.AsEnumerable().Reverse())
            {
                try
                {
                    ReplaceAtomically(files[i], File.ReadAllBytes(backupsPath[i]));
                    if (!Equal(Hash(File.ReadAllBytes(files[i])), legacy)) restored = false;
                }
                catch { restored = false; }
            }
            if (!restored)
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_ROLLBACK_UNPROVEN");
            throw;
        }

        var receipt = Path.Combine(backups, "migration-receipt.json");
        if (!File.Exists(receipt))
        {
            var json = JsonSerializer.Serialize(new {
                format = "sokna-stable-recovery-known-cafe-migration-v1",
                baseline_version = version, baseline_tree_sha256 = treeSha,
                old_sha256 = approvedLegacySha256,
                new_sha256 = Convert.ToHexString(expected).ToLowerInvariant(),
                backup_paths = names,
                activation_performed = false, database_mutation_performed = false,
                created_at_utc = DateTimeOffset.UtcNow.ToString("O")
            });
            WriteNew(receipt, System.Text.Encoding.UTF8.GetBytes(json));
        }
        return true;
    }

    // Never expose a half-written PHP endpoint or an incomplete evidence file:
    // flush a new sibling, verify its bytes, then rename without overwrite.
    private static void CreateAtomically(string target, byte[] content)
    {
        var incoming = target + ".incoming." + Guid.NewGuid().ToString("N");
        try
        {
            WriteNew(incoming, content);
            if (!Equal(Hash(File.ReadAllBytes(incoming)), Hash(content)))
                throw new InvalidDataException("STABLE_RECOVERY_MIGRATION_INCOMING_READBACK_FAILED");
            File.Move(incoming, target, overwrite: false);
        }
        finally { if (File.Exists(incoming)) File.Delete(incoming); }
    }

    private static void ReplaceAtomically(string target, byte[] content)
    {
        var incoming = target + ".incoming." + Guid.NewGuid().ToString("N");
        try
        {
            WriteNew(incoming, content);
            File.Replace(incoming, target, null);
        }
        finally { if (File.Exists(incoming)) File.Delete(incoming); }
    }

    private static void WriteNew(string path, byte[] bytes)
    {
        using var stream = new FileStream(path, FileMode.CreateNew, FileAccess.Write,
            FileShare.None, 4096, FileOptions.WriteThrough);
        stream.Write(bytes);
        stream.Flush(flushToDisk: true);
    }

    private static byte[] Hash(byte[] bytes) => SHA256.HashData(bytes);
    private static bool Equal(byte[] a, byte[] b) =>
        CryptographicOperations.FixedTimeEquals(a, b);
}
