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

    internal static bool Qualifies(string version, string treeSha) =>
        string.Equals(version, BaselineVersion, StringComparison.Ordinal) &&
        string.Equals(treeSha, BaselineTree, StringComparison.OrdinalIgnoreCase);

    internal static bool TryMigrate(string packageRoot, string version, string treeSha,
        string[] files, byte[] trustedPayload) =>
        TryMigrateCore(packageRoot, version, treeSha, files, trustedPayload,
            LegacySha256, LegacyLength);

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
