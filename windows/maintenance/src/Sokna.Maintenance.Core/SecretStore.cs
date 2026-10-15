using System.Diagnostics;
using System.Security.Cryptography;

namespace Sokna.Maintenance.Core;

public sealed class SecretStore
{
    private readonly MaintenancePaths _paths;

    public SecretStore(MaintenancePaths paths) => _paths = paths;

    public void EnsureKeys()
    {
        Directory.CreateDirectory(_paths.SecretsRoot);
        EnsureKey(_paths.WebAdapterKeyPath);
        EnsureKey(_paths.RecoveryConsoleKeyPath);
        ApplyAdminOnlyDirectoryAcl(_paths.SecretsRoot);
        ApplyAdminOnlyFileAcl(_paths.WebAdapterKeyPath);
        ApplyAdminOnlyFileAcl(_paths.RecoveryConsoleKeyPath);
    }

    public byte[] LoadKey(string keyId)
    {
        var path = keyId switch
        {
            "web-adapter" => _paths.WebAdapterKeyPath,
            "recovery-console" => _paths.RecoveryConsoleKeyPath,
            _ => throw new InvalidOperationException("Unknown maintenance key id.")
        };

        var raw = File.ReadAllText(path).Trim();
        var key = Convert.FromBase64String(raw);
        if (key.Length != 32) throw new InvalidDataException("Maintenance key must be exactly 32 bytes.");
        return key;
    }

    private static void EnsureKey(string path)
    {
        if (File.Exists(path))
        {
            var existing = Convert.FromBase64String(File.ReadAllText(path).Trim());
            if (existing.Length != 32) throw new InvalidDataException($"Invalid key length at {path}.");
            return;
        }

        var key = RandomNumberGenerator.GetBytes(32);
        var temp = path + ".tmp." + Guid.NewGuid().ToString("N");
        File.WriteAllText(temp, Convert.ToBase64String(key));
        File.Move(temp, path, overwrite: false);
    }

    private static void ApplyAdminOnlyDirectoryAcl(string path)
    {
        if (!OperatingSystem.IsWindows()) return;
        RunIcacls(path,
            "/inheritance:r",
            "/grant:r",
            "SYSTEM:(OI)(CI)F",
            "*S-1-5-32-544:(OI)(CI)F");
    }

    private static void ApplyAdminOnlyFileAcl(string path)
    {
        if (!OperatingSystem.IsWindows()) return;
        RunIcacls(path,
            "/inheritance:r",
            "/grant:r",
            "SYSTEM:F",
            "*S-1-5-32-544:F");
    }

    private static void RunIcacls(string path, params string[] arguments)
    {
        var start = new ProcessStartInfo
        {
            FileName = Path.Combine(Environment.SystemDirectory, "icacls.exe"),
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        start.ArgumentList.Add(path);
        foreach (var argument in arguments) start.ArgumentList.Add(argument);
        using var process = Process.Start(start) ?? throw new InvalidOperationException("Unable to start icacls.exe.");
        if (!process.WaitForExit(10000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            throw new InvalidOperationException("icacls.exe timed out while securing Maintenance secrets.");
        }
        if (process.ExitCode != 0)
        {
            var error = process.StandardError.ReadToEnd();
            var output = process.StandardOutput.ReadToEnd();
            throw new InvalidOperationException("Unable to secure Maintenance secret ACL: " + error + output);
        }
    }
}
