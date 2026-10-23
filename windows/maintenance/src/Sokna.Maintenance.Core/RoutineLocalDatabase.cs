using System.Diagnostics;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed record RoutineMigrationPlan(string SourceFingerprint, string TargetFingerprint,
    string SourceHead, string TargetHead, int SourceCount, int TargetCount)
{
    public bool RequiresMigration => SourceFingerprint != TargetFingerprint;
}

public sealed record RoutineDatabaseBackup(string Path, string Sha256, long Bytes);

/// <summary>Append-only catalog proof and fixed, private PHP/MariaDB execution.
/// Never restores a database across the forward boundary.</summary>
public static class RoutineLocalDatabase
{
    public static RoutineMigrationPlan Plan(string sourceRoot, string targetRoot)
    {
        var source = Catalog(sourceRoot); var target = Catalog(targetRoot);
        if (source.Count == 0 || source.Any(p => !target.TryGetValue(p.Key, out var hash) || hash != p.Value) ||
            target.Keys.Except(source.Keys, StringComparer.Ordinal).Any(v => string.CompareOrdinal(v, source.Keys.Last()) <= 0))
            throw new InvalidDataException("LOCAL_MIGRATIONS_NOT_APPEND_ONLY");
        return new(Fingerprint(source), Fingerprint(target), source.Keys.Last(), target.Keys.Last(), source.Count, target.Count);
    }

    private static SortedDictionary<string, string> Catalog(string root)
    {
        var dir = Path.Combine(root, "database", "migrations");
        if (!Directory.Exists(dir)) throw new InvalidDataException("LOCAL_MIGRATION_CATALOG_MISSING");
        var result = new SortedDictionary<string, string>(StringComparer.Ordinal);
        foreach (var file in Directory.EnumerateFiles(dir, "*.sql"))
        {
            var name = Path.GetFileNameWithoutExtension(file);
            if (!Regex.IsMatch(name, @"^\d{4}_[a-z0-9_]+$", RegexOptions.CultureInvariant) || name.Length > 160 ||
                (File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("LOCAL_MIGRATION_CATALOG_INVALID");
            result.Add(name, Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(file))).ToLowerInvariant());
        }
        return result;
    }

    private static string Fingerprint(SortedDictionary<string, string> catalog) =>
        "migration-ledger-sha256-v1:" + Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(
            string.Concat(catalog.Select(p => p.Key + "\0" + p.Value + "\n"))))).ToLowerInvariant();

    internal static void RequireBaseline(string packageRoot, string sourceRoot, string targetRoot, RoutineMigrationPlan plan)
        => Run(packageRoot, sourceRoot, targetRoot, "inspect", plan, "", "");

    internal static RoutineDatabaseBackup Backup(string packageRoot, string sourceRoot, string targetRoot,
        RoutineMigrationPlan plan, string evidenceRoot)
    {
        var backupRoot = Path.Combine(evidenceRoot, "private-db-backups");
        Directory.CreateDirectory(backupRoot);
        if ((File.GetAttributes(backupRoot) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("LOCAL_DATABASE_BACKUP_ROOT_UNSAFE");
        var acl = new ProcessStartInfo("icacls.exe") { UseShellExecute = false, CreateNoWindow = true,
            RedirectStandardOutput = true, RedirectStandardError = true };
        foreach (var arg in new[] { backupRoot, "/inheritance:r", "/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F" }) acl.ArgumentList.Add(arg);
        using (var process = Process.Start(acl) ?? throw new InvalidOperationException("LOCAL_DATABASE_BACKUP_ACL_FAILED"))
        {
            var stdout = process.StandardOutput.ReadToEndAsync(); var stderr = process.StandardError.ReadToEndAsync();
            if (!process.WaitForExit(15_000)) { process.Kill(entireProcessTree: true); throw new TimeoutException("LOCAL_DATABASE_BACKUP_ACL_TIMEOUT"); }
            Task.WaitAll(stdout, stderr);
            if (process.ExitCode != 0) throw new InvalidDataException("LOCAL_DATABASE_BACKUP_ACL_FAILED");
        }
        var path = Path.Combine(backupRoot, "local-before-" + Guid.NewGuid().ToString("N") + ".sql");
        var dump = FindBinary(Path.Combine(packageRoot, "Infrastructure"), "mariadb-dump.exe", "mysqldump.exe");
        Run(packageRoot, sourceRoot, targetRoot, "backup", plan, path, dump);
        using var stream = File.OpenRead(path);
        if (stream.Length < 200) throw new InvalidDataException("LOCAL_DATABASE_BACKUP_EMPTY");
        var hash = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
        return new(path, hash, stream.Length);
    }

    internal static void RequireBackup(RoutineDatabaseBackup backup, string evidenceRoot)
    {
        var path = Path.GetFullPath(backup.Path);
        var backupRoot = Path.Combine(Path.GetFullPath(evidenceRoot), "private-db-backups");
        if (!string.Equals(Path.GetDirectoryName(path), backupRoot, StringComparison.OrdinalIgnoreCase) ||
            (File.GetAttributes(backupRoot) & FileAttributes.ReparsePoint) != 0 ||
            (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0 || new FileInfo(path).Length != backup.Bytes)
            throw new InvalidDataException("LOCAL_DATABASE_BACKUP_PROOF_INVALID");
        using var stream = File.OpenRead(path);
        if (!string.Equals(Convert.ToHexString(SHA256.HashData(stream)), backup.Sha256, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("LOCAL_DATABASE_BACKUP_PROOF_INVALID");
    }

    internal static void Apply(string packageRoot, string sourceRoot, string targetRoot, RoutineMigrationPlan plan)
        => Run(packageRoot, sourceRoot, targetRoot, "apply", plan, "", "");

    private static void Run(string packageRoot, string sourceRoot, string targetRoot, string mode,
        RoutineMigrationPlan plan, string backup, string dump)
    {
        using var resource = Assembly.GetExecutingAssembly().GetManifestResourceStream("Sokna.Maintenance.Core.RoutineLocalDatabase.php")
            ?? throw new InvalidDataException("LOCAL_DATABASE_HELPER_MISSING");
        using var reader = new StreamReader(resource);
        var script = reader.ReadToEnd().Replace("<?php", "", StringComparison.Ordinal);
        var psi = new ProcessStartInfo(FindBinary(Path.Combine(packageRoot, "Infrastructure"), "php.exe"))
        { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
        // PHP auto-discovery may miss php.ini when its executable directory is Unicode.
        // Use the existing infrastructure-owned adjacent INI explicitly; never copy config.
        var ini = Path.Combine(Path.GetDirectoryName(psi.FileName)!, "php.ini");
        if (File.Exists(ini)) { psi.ArgumentList.Add("-c"); psi.ArgumentList.Add(ini); }
        foreach (var arg in new[] { "-r", script, packageRoot, sourceRoot, targetRoot, mode,
            JsonSerializer.Serialize(plan), backup, dump }) psi.ArgumentList.Add(arg);
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("LOCAL_DATABASE_HELPER_START_FAILED");
        // Drain both pipes while running, avoiding deadlocks with large diagnostics.
        var output = process.StandardOutput.ReadToEndAsync(); var errors = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(600_000)) { process.Kill(entireProcessTree: true); process.WaitForExit(); throw new TimeoutException("LOCAL_DATABASE_HELPER_TIMEOUT"); }
        Task.WaitAll(output, errors);
        if (process.ExitCode != 0 || output.Result.Trim() != "SOKNA_DATABASE_OK")
            throw new InvalidDataException("LOCAL_DATABASE_" + mode.ToUpperInvariant() + "_FAILED_STATUS_" + process.ExitCode);
    }

    internal static string FindBinary(string root, params string[] names)
    {
        var queue = new Queue<(string Root, int Depth)>(); queue.Enqueue((root, 0));
        while (queue.Count > 0)
        {
            var (dir, depth) = queue.Dequeue();
            if (!Directory.Exists(dir) || (File.GetAttributes(dir) & FileAttributes.ReparsePoint) != 0) continue;
            foreach (var name in names)
            {
                var file = Path.Combine(dir, name);
                if (File.Exists(file) && (File.GetAttributes(file) & FileAttributes.ReparsePoint) == 0) return file;
            }
            if (depth < 7) foreach (var child in Directory.EnumerateDirectories(dir)) queue.Enqueue((child, depth + 1));
        }
        throw new FileNotFoundException("LOCAL_INFRASTRUCTURE_BINARY_MISSING");
    }
}
