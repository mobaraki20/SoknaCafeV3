using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record EngineDelegationReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("permit_id")] string PermitId,
    [property: JsonPropertyName("operation_id")] string OperationId,
    [property: JsonPropertyName("command_sha256")] string CommandSha256,
    [property: JsonPropertyName("installation_sha256")] string InstallationSha256,
    [property: JsonPropertyName("parent_pid")] int ParentPid,
    [property: JsonPropertyName("parent_start_ticks")] long ParentStartTicks,
    [property: JsonPropertyName("issued_utc_ticks")] long IssuedUtcTicks,
    [property: JsonPropertyName("expires_utc_ticks")] long ExpiresUtcTicks,
    [property: JsonPropertyName("signature")] string Signature);

/// <summary>
/// F1-S6B delegated execution foundation. A lease is NOT authorization.
/// Supervisor issues a short-lived exact-command HMAC receipt while holding
/// the machine lease; Engine consumes a one-use token before executing.
/// This is not the final Windows Job-object process-death qualification.
/// </summary>
public static class EngineDelegationPermit
{
    public const string Format = "sokna-engine-delegation-v1";
    public const string Owner = "supervisor-legacy-engine";
    public const string RequiredCode = "ENGINE_DELEGATION_REQUIRED";
    public const string RejectedCode = "ENGINE_DELEGATION_REJECTED";
    private static readonly TimeSpan Lifetime = TimeSpan.FromSeconds(45);

    public static string Issue(MaintenancePaths paths, MachineMutationLease lease,
        string operationId, IReadOnlyList<string> exactArguments)
    {
        ArgumentNullException.ThrowIfNull(lease);
        if (lease.IsDisposed || lease.ProcessId != Environment.ProcessId || lease.Owner != Owner)
            throw new InvalidOperationException("ENGINE_DELEGATION_ISSUER_LEASE_REQUIRED");
        if (!ValidId(operationId)) throw new InvalidDataException("Operation ID is invalid.");
        ValidateArguments(exactArguments, paths);
        var now = DateTimeOffset.UtcNow;
        using var current = Process.GetCurrentProcess();
        var receipt = new EngineDelegationReceipt(
            Format, 1, Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant(),
            operationId, HashArguments(exactArguments), HashInstallation(paths),
            Environment.ProcessId, current.StartTime.ToUniversalTime().Ticks,
            now.UtcTicks, now.Add(Lifetime).UtcTicks, "");
        var key = new SecretStore(paths).LoadKey("engine-delegation");
        var signed = receipt with { Signature = Sign(receipt, key) };
        var path = PermitPath(paths, signed.PermitId);
        AtomicFile.WriteJson(path, signed);
        return signed.PermitId;
    }

    public static int Consume(MaintenancePaths paths, string permitId, IReadOnlyList<string> actualArguments)
    {
        if (!ValidId(permitId))
            throw new InvalidDataException(RejectedCode);
        ValidateArguments(actualArguments, paths);
        var path = PermitPath(paths, permitId);
        if (!File.Exists(path) || IsReparse(path))
            throw new InvalidDataException(RejectedCode);
        var p = AtomicFile.ReadJson<EngineDelegationReceipt>(path);
        if (p is null || p.Format != Format || p.SchemaVersion != 1 ||
            p.PermitId != permitId || !ValidId(p.OperationId) ||
            !string.Equals(p.CommandSha256, HashArguments(actualArguments), StringComparison.Ordinal) ||
            !string.Equals(p.InstallationSha256, HashInstallation(paths), StringComparison.Ordinal) ||
            p.ParentPid <= 0 || p.ParentPid == Environment.ProcessId ||
            p.ParentStartTicks <= 0 || p.IssuedUtcTicks <= 0 ||
            p.ExpiresUtcTicks - p.IssuedUtcTicks != Lifetime.Ticks ||
            DateTimeOffset.UtcNow.UtcTicks > p.ExpiresUtcTicks ||
            DateTimeOffset.UtcNow.UtcTicks < p.IssuedUtcTicks - TimeSpan.FromSeconds(5).Ticks)
            throw new InvalidDataException(RejectedCode);

        var key = new SecretStore(paths).LoadKey("engine-delegation");
        var expected = Convert.FromHexString(Sign(p with { Signature = "" }, key));
        byte[] actual;
        try { actual = Convert.FromHexString(p.Signature); }
        catch (FormatException) { throw new InvalidDataException(RejectedCode); }
        if (actual.Length != expected.Length || !CryptographicOperations.FixedTimeEquals(actual, expected))
            throw new InvalidDataException(RejectedCode);

        RequireParentAndMachineLease(paths, p.ParentPid, p.ParentStartTicks);
        // Atomic CreateNew marker. Never remove after a failure or crash:
        // a stale permit must not gain a second execution opportunity.
        var consumedPath = Path.Combine(paths.EngineDelegationsRoot, permitId + ".consumed");
        if (File.Exists(consumedPath) || IsReparse(paths.EngineDelegationsRoot))
            throw new InvalidDataException(RejectedCode);
        try
        {
            using var marker = new FileStream(consumedPath, FileMode.CreateNew,
                FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough);
            var data = Encoding.ASCII.GetBytes(
                $"permit={permitId};pid={Environment.ProcessId};utc={DateTimeOffset.UtcNow:O}");
            marker.Write(data);
            marker.Flush(flushToDisk: true);
        }
        catch (IOException) { throw new InvalidDataException(RejectedCode); }
        RequireParentAndMachineLease(paths, p.ParentPid, p.ParentStartTicks);
        return p.ParentPid;
    }

    public static void RequireParentAndMachineLease(MaintenancePaths paths, int parentPid, long parentStartTicks)
    {
        using var parent = Process.GetProcessById(parentPid);
        if (parent.HasExited || parent.StartTime.ToUniversalTime().Ticks != parentStartTicks)
            throw new InvalidDataException(RejectedCode);
        var lockPath = paths.MachineMutationLockPath;
        if (!File.Exists(lockPath) || IsReparse(lockPath))
            throw new InvalidDataException(RejectedCode);
        var evidence = File.ReadAllText(lockPath);
        if (!evidence.Contains($"owner={Owner};pid={parentPid};", StringComparison.Ordinal))
            throw new InvalidDataException(RejectedCode);
        // A stale lease file is never enough: opening a competing writer must
        // be denied by Windows while the Supervisor keeps its live handle.
        try
        {
            using var unexpected = new FileStream(lockPath, FileMode.Open,
                FileAccess.ReadWrite, FileShare.Read, 1, FileOptions.None);
            throw new InvalidDataException(RejectedCode);
        }
        catch (IOException)
        {
            // Sharing violation: owner still holds its OS handle.
        }
    }

    public static void ValidateArguments(IReadOnlyList<string> arguments, MaintenancePaths paths)
    {
        if (arguments.Count is < 1 or > 32 ||
            arguments.Any(a => string.IsNullOrWhiteSpace(a) || a.Length > 1024 || a.Any(char.IsControl)) ||
            !arguments.Contains("--data-root", StringComparer.Ordinal) ||
            arguments.Count(a => a == "--data-root") != 1)
            throw new InvalidDataException(RejectedCode);
        var index = arguments.ToList().IndexOf("--data-root");
        if (index + 1 >= arguments.Count ||
            !string.Equals(Path.GetFullPath(arguments[index + 1]).TrimEnd('\\', '/'),
                Path.GetFullPath(paths.DataRoot).TrimEnd('\\', '/'), StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException(RejectedCode);
        if (arguments[0] is not ("verify-stage" or "local-slot-migrate" or "local-slot-recover"
            or "local-slot-home-cycle" or "local-slot-stage" or "local-slot-activate"
            or "local-slot-recover-routine"))
            throw new InvalidDataException(RejectedCode);
    }

    private static string PermitPath(MaintenancePaths paths, string id)
    {
        if (!ValidId(id)) throw new InvalidDataException(RejectedCode);
        Directory.CreateDirectory(paths.EngineDelegationsRoot);
        if (IsReparse(paths.EngineDelegationsRoot)) throw new InvalidDataException(RejectedCode);
        return Path.Combine(paths.EngineDelegationsRoot, id + ".json");
    }
    private static bool IsReparse(string path) => (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0;
    private static bool ValidId(string id) => id is { Length: 32 } && id.All(char.IsAsciiHexDigit);
    private static string HashInstallation(MaintenancePaths p) =>
        Sha256(Path.GetFullPath(p.DataRoot).TrimEnd('\\', '/').ToUpperInvariant());
    private static string HashArguments(IReadOnlyList<string> args) =>
        Sha256(JsonSerializer.Serialize(args));
    private static string Sha256(string text) =>
        Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(text))).ToLowerInvariant();
    private static string Sign(EngineDelegationReceipt r, byte[] key)
    {
        var canonical = string.Join("\n", r.Format, r.SchemaVersion, r.PermitId,
            r.OperationId, r.CommandSha256, r.InstallationSha256,
            r.ParentPid, r.ParentStartTicks, r.IssuedUtcTicks, r.ExpiresUtcTicks);
        return Convert.ToHexString(HMACSHA256.HashData(key, Encoding.UTF8.GetBytes(canonical))).ToLowerInvariant();
    }
}
