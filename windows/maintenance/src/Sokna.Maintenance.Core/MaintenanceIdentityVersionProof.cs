using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;
using Microsoft.Win32;

namespace Sokna.Maintenance.Core;

/// <summary>Only a committed three-component Maintenance upgrade can explain
/// two version fields differing from a previous Local rollback snapshot.</summary>
internal static class MaintenanceIdentityVersionProof
{
    private static void Need(bool ok) { if (!ok) throw new InvalidDataException("R125_MAINTENANCE_IDENTITY_UPGRADE_UNPROVEN"); }
    private static string Hash(string p) => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(p))).ToLowerInvariant();
    private static JsonObject Read(string p)
    {
        Need(File.Exists(p) && new FileInfo(p).Length is > 0 and < 1_000_000 &&
            (File.GetAttributes(p) & FileAttributes.ReparsePoint) == 0);
        for (var dir = Path.GetDirectoryName(Path.GetFullPath(p)); dir is not null; dir = Path.GetDirectoryName(dir))
            Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0);
        return JsonNode.Parse(File.ReadAllBytes(p)) as JsonObject ?? throw new InvalidDataException("R125_MAINTENANCE_IDENTITY_UPGRADE_UNPROVEN");
    }
    internal static string RequireOnlyVersionChange(byte[] before, byte[] current)
    {
        var old = JsonNode.Parse(before) as JsonObject; var now = JsonNode.Parse(current) as JsonObject;
        Need(old is not null && now is not null);
        var version = now!["maintenance_supervisor_version"]?.GetValue<string>();
        Need(version is not null && Regex.IsMatch(version, @"^0\.3\.[0-9]{1,5}$") &&
            now["update_engine_version"]?.GetValue<string>() == version &&
            old!["maintenance_supervisor_version"] is JsonValue && old["update_engine_version"] is JsonValue);
        foreach (var key in new[] { "maintenance_supervisor_version", "update_engine_version" })
        { old!.Remove(key); now.Remove(key); }
        Need(JsonNode.DeepEquals(old, now));
        return version!;
    }
    internal static void Require(byte[] before, string identityPath, string dataRoot)
    {
        var version = RequireOnlyVersionChange(before, File.ReadAllBytes(identityPath));
        using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\SoknaMaintenanceSupervisor");
        var image = Environment.ExpandEnvironmentVariables(key?.GetValue("ImagePath") as string ?? "").Trim();
        var match = Regex.Match(image, "^\"([^\"]+\\.exe)\"(?:\\s|$)", RegexOptions.IgnoreCase);
        if (!match.Success) match = Regex.Match(image, "^([^\\s\"]+\\.exe)(?:\\s|$)", RegexOptions.IgnoreCase);
        Need(match.Success);
        var exe = Path.GetFullPath(match.Groups[1].Value);
        Need(Path.GetFileName(exe) == "SoknaMaintenanceSupervisor.exe" &&
            Path.GetFileName(Path.GetDirectoryName(exe)) == "App" &&
            Path.GetFileName(Path.GetDirectoryName(Path.GetDirectoryName(exe))) == "Maintenance");
        var install = Path.GetDirectoryName(Path.GetDirectoryName(Path.GetDirectoryName(exe)))!;
        var root = Path.Combine(Path.GetDirectoryName(dataRoot)!, "setup", "maintenance-components-v2");
        var marker = Read(Path.Combine(root, "active.json"));
        var id = marker["id"]?.GetValue<string>() ?? "";
        Need(marker["format"]?.GetValue<string>() == "sokna-maintenance-components-transaction-v2" &&
            marker["phase"]?.GetValue<string>() == "COMMITTED" &&
            marker["target"]?.GetValue<string>() == version && Regex.IsMatch(id, "^[a-f0-9]{32}$"));
        var tx = Path.Combine(root, "tx-" + id); var result = Read(Path.Combine(tx, "result.json"));
        Need(result["format"]?.GetValue<string>() == "sokna-maintenance-components-result-v2" &&
            result["status"]?.GetValue<string>() == "COMMITTED" &&
            result["supervisor_version"]?.GetValue<string>() == version &&
            result["engine_version"]?.GetValue<string>() == version);
        foreach (var flag in new[] { "database_mutated", "local_mutated", "public_mutated", "print_mutated" })
            Need(result[flag]?.GetValue<bool>() == false);
        var files = result["files"] as JsonArray;
        Need(files is not null && files.Count == 3);
        var wanted = new[] { "Maintenance/App/SoknaMaintenanceSupervisor.exe", "Maintenance/App/SoknaMaintenanceUpdateEngine.exe", "Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe" };
        foreach (var rel in wanted)
        {
            var rows = files!.OfType<JsonObject>().Where(r => r["path"]?.GetValue<string>() == rel).ToArray();
            Need(rows.Length == 1);
            var p = Path.Combine(install, rel.Replace('/', Path.DirectorySeparatorChar));
            Need(File.Exists(p) && (File.GetAttributes(p) & FileAttributes.ReparsePoint) == 0);
            for (var dir = Path.GetDirectoryName(p); dir is not null; dir = Path.GetDirectoryName(dir))
                Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0);
            Need(Hash(p) == rows[0]["sha256"]?.GetValue<string>());
            var pe = FileVersionInfo.GetVersionInfo(p);
            var physical = $"{pe.FileMajorPart}.{pe.FileMinorPart}.{pe.FileBuildPart}";
            Need(physical == (rel.Contains("/Recovery/") ? result["recovery_console_version"]?.GetValue<string>() : version));
        }
        var snapshot = Read(Path.Combine(tx, "snapshot.json"));
        var original = Path.Combine(tx, "before", "installed-release-identity.json");
        _ = Read(original);
        Need(snapshot["format"]?.GetValue<string>() == "sokna-maintenance-components-snapshot-v2" &&
            Hash(original) == snapshot["identity_sha256"]?.GetValue<string>());
        // Even the upgrade's preimage must match every non-Maintenance field
        // from the Local rollback proof. A receipt cannot bless other edits.
        _ = RequireOnlyVersionChange(before, File.ReadAllBytes(original));
    }
}
