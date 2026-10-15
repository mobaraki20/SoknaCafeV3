using System.Diagnostics;
using System.Security.Principal;
using Microsoft.Win32;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

public sealed record WebAdapterAclEvidence(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("service_name")] string ServiceName,
    [property: JsonPropertyName("service_account")] string? ServiceAccount,
    [property: JsonPropertyName("service_sid")] string? ServiceSid,
    [property: JsonPropertyName("image_path")] string? ImagePath,
    [property: JsonPropertyName("web_adapter_key_path")] string WebAdapterKeyPath,
    [property: JsonPropertyName("exact_identity_read_granted")] bool ExactIdentityReadGranted,
    [property: JsonPropertyName("broad_read_granted")] bool BroadReadGranted,
    [property: JsonPropertyName("recorded_at_utc")] DateTimeOffset RecordedAtUtc,
    [property: JsonPropertyName("message")] string Message);

public static class WebAdapterAclProvisioner
{
    public const string ApacheServiceName = "SoknaApache";

    public static WebAdapterAclEvidence TryProvision(MaintenancePaths paths)
    {
        if (!OperatingSystem.IsWindows())
            return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "unsupported_platform", ApacheServiceName, null, null, null,
                paths.WebAdapterKeyPath, false, false, DateTimeOffset.UtcNow, "Windows ACL provisioning is unavailable on this platform."));

        try
        {
            using var serviceKey = Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{ApacheServiceName}", writable: false);
            if (serviceKey is null)
                return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "acl_pending", ApacheServiceName, null, null, null,
                    paths.WebAdapterKeyPath, false, false, DateTimeOffset.UtcNow, "SoknaApache service is not installed; web-adapter authority remains unavailable."));

            var account = serviceKey.GetValue("ObjectName") as string;
            var imagePath = serviceKey.GetValue("ImagePath") as string;
            if (string.IsNullOrWhiteSpace(account) || string.IsNullOrWhiteSpace(imagePath))
                return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "acl_pending", ApacheServiceName, account, null, imagePath,
                    paths.WebAdapterKeyPath, false, false, DateTimeOffset.UtcNow, "SoknaApache service identity/ImagePath could not be established."));

            var executable = ExtractExecutable(imagePath);
            if (!Path.GetFileName(executable).Equals("httpd.exe", StringComparison.OrdinalIgnoreCase))
                return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "acl_pending", ApacheServiceName, account, null, imagePath,
                    paths.WebAdapterKeyPath, false, false, DateTimeOffset.UtcNow, "SoknaApache ImagePath is not an Apache httpd executable; refusing secret grant."));

            var sid = TranslateServiceAccount(account);
            if (sid is null)
                return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "acl_pending", ApacheServiceName, account, null, imagePath,
                    paths.WebAdapterKeyPath, false, false, DateTimeOffset.UtcNow, "SoknaApache service account could not be translated to a SID."));

            if (sid.Value is "S-1-1-0" or "S-1-5-11" or "S-1-5-32-545" or "S-1-5-32-546")
                return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "acl_pending", ApacheServiceName, account, sid.Value, imagePath,
                    paths.WebAdapterKeyPath, false, true, DateTimeOffset.UtcNow, "SoknaApache resolved to a broad identity; refusing maintenance secret grant."));

            Directory.CreateDirectory(paths.SecretsRoot);
            if (!File.Exists(paths.WebAdapterKeyPath))
                throw new FileNotFoundException("web-adapter key does not exist before ACL provisioning.", paths.WebAdapterKeyPath);

            // Root remains SYSTEM/Admin-owned. Grant only traversal to the exact Apache service SID; do not grant list/read-data.
            RunIcacls(paths.SecretsRoot, "/grant", $"*{sid.Value}:(X)");
            // Grant read only on the one Web adapter key; recovery-console.key remains inaccessible to Apache.
            RunIcacls(paths.WebAdapterKeyPath, "/grant:r", $"*{sid.Value}:(R)");

            var evidence = new WebAdapterAclEvidence("sokna-web-adapter-acl-v1", 1, "ready", ApacheServiceName, account, sid.Value, imagePath,
                paths.WebAdapterKeyPath, true, false, DateTimeOffset.UtcNow,
                "Exact SoknaApache service SID has traverse-only access to the secrets directory and read-only access to web-adapter.key.");
            return Persist(paths, evidence);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidOperationException or System.Security.SecurityException)
        {
            return Persist(paths, new("sokna-web-adapter-acl-v1", 1, "acl_pending", ApacheServiceName, null, null, null,
                paths.WebAdapterKeyPath, false, false, DateTimeOffset.UtcNow, "ACL provisioning failed closed: " + ex.Message));
        }
    }

    private static SecurityIdentifier? TranslateServiceAccount(string account)
    {
        var normalized = account.Trim();
        if (normalized.Equals("LocalSystem", StringComparison.OrdinalIgnoreCase)) normalized = "NT AUTHORITY\\SYSTEM";
        if (normalized.Equals("NT AUTHORITY\\LocalService", StringComparison.OrdinalIgnoreCase) || normalized.Equals("LocalService", StringComparison.OrdinalIgnoreCase)) normalized = "NT AUTHORITY\\LOCAL SERVICE";
        if (normalized.Equals("NT AUTHORITY\\NetworkService", StringComparison.OrdinalIgnoreCase) || normalized.Equals("NetworkService", StringComparison.OrdinalIgnoreCase)) normalized = "NT AUTHORITY\\NETWORK SERVICE";
        try
        {
            return (SecurityIdentifier)new NTAccount(normalized).Translate(typeof(SecurityIdentifier));
        }
        catch (IdentityNotMappedException)
        {
            return null;
        }
    }

    private static void RunIcacls(string path, string operation, string grant)
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
        start.ArgumentList.Add(operation);
        start.ArgumentList.Add(grant);
        using var process = Process.Start(start) ?? throw new InvalidOperationException("Unable to start icacls.exe.");
        if (!process.WaitForExit(10000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            throw new InvalidOperationException("icacls.exe timed out while provisioning Web adapter ACL.");
        }
        if (process.ExitCode != 0)
            throw new InvalidOperationException("icacls.exe rejected Web adapter ACL: " + process.StandardError.ReadToEnd());
    }

    private static string ExtractExecutable(string imagePath)
    {
        var text = Environment.ExpandEnvironmentVariables(imagePath.Trim());
        if (text.StartsWith('"'))
        {
            var end = text.IndexOf('"', 1);
            if (end > 1) return text[1..end];
        }
        var exe = text.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
        return exe >= 0 ? text[..(exe + 4)].Trim() : text.Split(' ', 2)[0];
    }

    private static WebAdapterAclEvidence Persist(MaintenancePaths paths, WebAdapterAclEvidence evidence)
    {
        try { AtomicFile.WriteJson(paths.WebAdapterAclEvidencePath, evidence); } catch { }
        return evidence;
    }
}
