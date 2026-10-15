using System.Diagnostics;
using System.Security.Principal;
using System.Text.Json.Serialization;
using Microsoft.Win32;

namespace Sokna.Maintenance.Core;

public sealed record WebAdapterAclEvidence(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("service_name")] string ServiceName,
    [property: JsonPropertyName("service_account")] string? ServiceAccount,
    [property: JsonPropertyName("service_sid")] string? ServiceSid,
    [property: JsonPropertyName("image_path")] string? ImagePath,
    [property: JsonPropertyName("php_execution_contract")] string PhpExecutionContract,
    [property: JsonPropertyName("key_acl_mode")] string KeyAclMode,
    [property: JsonPropertyName("exact_identity_read_granted")] bool ExactIdentityReadGranted,
    [property: JsonPropertyName("descriptor_read_granted")] bool DescriptorReadGranted,
    [property: JsonPropertyName("broad_read_granted")] bool BroadReadGranted,
    [property: JsonPropertyName("recorded_at_utc")] DateTimeOffset RecordedAtUtc,
    [property: JsonPropertyName("message")] string Message);

public static class WebAdapterAclProvisioner
{
    public const string ApacheServiceName = "SoknaApache";
    private const string LocalSystemSid = "S-1-5-18";
    private const string ExactVirtualAccount = @"NT SERVICE\SoknaApache";
    private const string PhpExecutionContract = "mod_php_in_soknaapache_service_token";

    public static WebAdapterAclEvidence TryProvision(MaintenancePaths paths)
    {
        if (!OperatingSystem.IsWindows())
            return Persist(paths, Pending("unsupported_platform", null, null, null,
                "Windows ACL provisioning is unavailable on this platform."));

        try
        {
            using var serviceKey = Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{ApacheServiceName}", writable: false);
            if (serviceKey is null)
                return Persist(paths, Pending("acl_pending", null, null, null,
                    "SoknaApache service is not installed; Web adapter authority remains unavailable."));

            var account = serviceKey.GetValue("ObjectName") as string;
            var imagePath = serviceKey.GetValue("ImagePath") as string;
            if (string.IsNullOrWhiteSpace(account) || string.IsNullOrWhiteSpace(imagePath))
                return Persist(paths, Pending("acl_pending", account, null, imagePath,
                    "SoknaApache service identity or ImagePath could not be established."));

            var executable = ExtractExecutable(imagePath);
            if (!Path.GetFileName(executable).Equals("httpd.exe", StringComparison.OrdinalIgnoreCase))
                return Persist(paths, Pending("acl_pending", account, null, imagePath,
                    "SoknaApache ImagePath is not an Apache httpd executable; refusing Maintenance secret access."));

            if (IsLocalSystem(account))
            {
                var evidence = new WebAdapterAclEvidence(
                    "sokna-web-adapter-acl-v1", 1, "ready", ApacheServiceName, account, LocalSystemSid, imagePath,
                    PhpExecutionContract, "existing_system_acl", true, false, false, DateTimeOffset.UtcNow,
                    "SoknaApache resolves to LocalSystem; existing P1 SYSTEM-only ACL is sufficient and no broader grant was added.");
                return Persist(paths, evidence);
            }

            if (!account.Trim().Equals(ExactVirtualAccount, StringComparison.OrdinalIgnoreCase))
                return Persist(paths, Pending("acl_pending", account, TryTranslateSid(account), imagePath,
                    "SoknaApache uses an account that is not separately qualified. Shared/custom service accounts are not auto-granted Maintenance secret access."));

            var sid = TryTranslateSid(ExactVirtualAccount);
            if (string.IsNullOrWhiteSpace(sid))
                return Persist(paths, Pending("acl_pending", account, null, imagePath,
                    "The exact SoknaApache virtual service account could not be translated to a SID."));

            GrantTraverse(paths.DataRoot, sid);
            GrantTraverse(paths.SecretsRoot, sid);
            GrantReadOnly(paths.WebAdapterKeyPath, sid);

            var virtualEvidence = new WebAdapterAclEvidence(
                "sokna-web-adapter-acl-v1", 1, "ready", ApacheServiceName, account, sid, imagePath,
                PhpExecutionContract, "exact_virtual_service_account", true, false, false, DateTimeOffset.UtcNow,
                "Exact NT SERVICE\\SoknaApache identity received traverse-only access to required directories and read-only access to web-adapter.key.");
            return Persist(paths, virtualEvidence);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidOperationException or System.Security.SecurityException)
        {
            return Persist(paths, Pending("acl_pending", null, null, null,
                "Web adapter ACL provisioning failed closed: " + ex.Message));
        }
    }

    public static WebAdapterAclEvidence ApplyDescriptorAccess(MaintenancePaths paths, WebAdapterAclEvidence evidence)
    {
        if (!OperatingSystem.IsWindows() || evidence.State != "ready") return evidence;

        try
        {
            if (!File.Exists(paths.DescriptorPath))
                throw new FileNotFoundException("Maintenance control-plane descriptor does not exist.", paths.DescriptorPath);

            HardenDescriptor(paths.DescriptorPath, evidence.ServiceSid);
            var updated = evidence with
            {
                DescriptorReadGranted = true,
                RecordedAtUtc = DateTimeOffset.UtcNow,
                Message = evidence.Message + " control-plane.json read access is qualified for the same exact identity."
            };
            return Persist(paths, updated);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or InvalidOperationException or System.Security.SecurityException)
        {
            var failed = evidence with
            {
                State = "acl_pending",
                DescriptorReadGranted = false,
                RecordedAtUtc = DateTimeOffset.UtcNow,
                Message = "Descriptor ACL provisioning failed closed: " + ex.Message
            };
            return Persist(paths, failed);
        }
    }

    private static WebAdapterAclEvidence Pending(string state, string? account, string? sid, string? imagePath, string message)
        => new("sokna-web-adapter-acl-v1", 1, state, ApacheServiceName, account, sid, imagePath,
            PhpExecutionContract, "none", false, false, false, DateTimeOffset.UtcNow, message);

    private static bool IsLocalSystem(string account)
    {
        var value = account.Trim();
        return value.Equals("LocalSystem", StringComparison.OrdinalIgnoreCase) ||
               value.Equals(@"NT AUTHORITY\SYSTEM", StringComparison.OrdinalIgnoreCase) ||
               value.Equals("SYSTEM", StringComparison.OrdinalIgnoreCase);
    }

    private static string? TryTranslateSid(string account)
    {
        try
        {
            return ((SecurityIdentifier)new NTAccount(account.Trim()).Translate(typeof(SecurityIdentifier))).Value;
        }
        catch (IdentityNotMappedException)
        {
            return null;
        }
    }

    private static void GrantTraverse(string path, string sid)
    {
        Directory.CreateDirectory(path);
        RunIcacls(path, "/grant:r", $"*{sid}:(X)");
    }

    private static void GrantReadOnly(string path, string sid)
    {
        if (!File.Exists(path)) throw new FileNotFoundException("Maintenance Web adapter key does not exist.", path);
        RunIcacls(path, "/grant:r", $"*{sid}:(R)");
    }

    private static void HardenDescriptor(string path, string? sid)
    {
        var args = new List<string>
        {
            "/inheritance:r",
            "/grant:r",
            "SYSTEM:F",
            "*S-1-5-32-544:F"
        };
        if (!string.IsNullOrWhiteSpace(sid) && !sid.Equals(LocalSystemSid, StringComparison.Ordinal))
            args.Add($"*{sid}:(R)");
        RunIcacls(path, args.ToArray());
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
            throw new InvalidOperationException("icacls.exe timed out while provisioning Web adapter ACL.");
        }
        if (process.ExitCode != 0)
        {
            var error = process.StandardError.ReadToEnd();
            var output = process.StandardOutput.ReadToEnd();
            throw new InvalidOperationException("icacls.exe rejected Web adapter ACL: " + error + output);
        }
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
