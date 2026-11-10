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
    internal static readonly HashSet<string> SafeCodes = new(StringComparer.Ordinal) {
        "R125_IDENTITY_JSON_INVALID", "R125_IDENTITY_VERSION_FIELDS_INVALID", "R125_IDENTITY_COMPONENT_VERSION_REGRESSION",
        "R125_IDENTITY_NON_MAINTENANCE_FIELDS_DIFFER", "R125_IDENTITY_EVIDENCE_FILE_UNSAFE",
        "R125_IDENTITY_SERVICE_OWNER_UNPROVEN", "R125_IDENTITY_INSTALL_RECEIPT_UNQUALIFIED",
        "R125_IDENTITY_INSTALLED_BINARY_MISMATCH", "R125_IDENTITY_PREIMAGE_MISMATCH",
        "R125_IDENTITY_LEGACY_SYNC_RECEIPT_MISSING_OR_AMBIGUOUS"
    };
    private static void Need(bool ok, string code = "R125_IDENTITY_INSTALL_RECEIPT_UNQUALIFIED")
    { if (!ok) throw new InvalidDataException(code); }
    private static string Hash(string p) => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(p))).ToLowerInvariant();
    private static JsonObject Read(string p)
    {
        Need(File.Exists(p) && new FileInfo(p).Length is > 0 and < 1_000_000 &&
            (File.GetAttributes(p) & FileAttributes.ReparsePoint) == 0, "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        for (var dir = Path.GetDirectoryName(Path.GetFullPath(p)); dir is not null; dir = Path.GetDirectoryName(dir))
            Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0, "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        return JsonNode.Parse(File.ReadAllBytes(p)) as JsonObject ?? throw new InvalidDataException("R125_IDENTITY_JSON_INVALID");
    }
    internal static string RequireOnlyVersionChange(byte[] before, byte[] current)
    {
        var old = JsonNode.Parse(before) as JsonObject; var now = JsonNode.Parse(current) as JsonObject;
        Need(old is not null && now is not null, "R125_IDENTITY_JSON_INVALID");
        var version = now!["maintenance_supervisor_version"]?.GetValue<string>();
        Need(version is not null && Regex.IsMatch(version, @"^0\.3\.[0-9]{1,5}$") &&
            now["update_engine_version"]?.GetValue<string>() == version &&
            old!["maintenance_supervisor_version"] is JsonValue && old["update_engine_version"] is JsonValue,
            "R125_IDENTITY_VERSION_FIELDS_INVALID");
        foreach (var name in new[] { "maintenance_supervisor_version", "update_engine_version" }) {
            var previous = old![name]?.GetValue<string>();
            Need(previous is not null && Regex.IsMatch(previous, @"^0\.3\.[0-9]{1,5}$"), "R125_IDENTITY_VERSION_FIELDS_INVALID");
            Need(Version.Parse(version!) >= Version.Parse(previous!), "R125_IDENTITY_COMPONENT_VERSION_REGRESSION");
        }
        // Known nullable contract additions are equivalent to an absent null,
        // never to a non-null value. Unknown fields and all Local/Public values stay exact.
        foreach (var name in new[] { "backup_recovery_engine_version", "public_maintenance_kernel_version" })
            NormalizeOptionalNull(old!, now, name);
        if (old!["local"] is JsonObject oldLocal && now["local"] is JsonObject newLocal)
            NormalizeOptionalNull(oldLocal, newLocal, "authority_epoch_policy");
        foreach (var key in new[] { "maintenance_supervisor_version", "update_engine_version" })
        { old!.Remove(key); now.Remove(key); }
        Need(JsonNode.DeepEquals(old, now), "R125_IDENTITY_NON_MAINTENANCE_FIELDS_DIFFER");
        return version!;
    }
    private static void NormalizeOptionalNull(JsonObject old, JsonObject current, string name)
    {
        if (old[name] is null && current[name] is null) { old.Remove(name); current.Remove(name); }
    }
    internal static void Require(byte[] before, string identityPath, string dataRoot)
    {
        var currentBytes = File.ReadAllBytes(identityPath);
        // A different JSON layout with identical identity values is not an upgrade.
        if (JsonNode.DeepEquals(JsonNode.Parse(before), JsonNode.Parse(currentBytes))) return;
        var version = RequireOnlyVersionChange(before, currentBytes);
        using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\SoknaMaintenanceSupervisor");
        var image = Environment.ExpandEnvironmentVariables(key?.GetValue("ImagePath") as string ?? "").Trim();
        var match = Regex.Match(image, "^\"([^\"]+\\.exe)\"(?:\\s|$)", RegexOptions.IgnoreCase);
        if (!match.Success) match = Regex.Match(image, "^([^\\s\"]+\\.exe)(?:\\s|$)", RegexOptions.IgnoreCase);
        Need(match.Success, "R125_IDENTITY_SERVICE_OWNER_UNPROVEN");
        var exe = Path.GetFullPath(match.Groups[1].Value);
        Need(Path.GetFileName(exe) == "SoknaMaintenanceSupervisor.exe" &&
            Path.GetFileName(Path.GetDirectoryName(exe)) == "App" &&
            Path.GetFileName(Path.GetDirectoryName(Path.GetDirectoryName(exe))) == "Maintenance");
        var install = Path.GetDirectoryName(Path.GetDirectoryName(Path.GetDirectoryName(exe)))!;
        var setup = Path.Combine(Path.GetDirectoryName(dataRoot)!, "setup");
        if (version == "0.3.22") { RequireLegacySync(before, install, setup); return; }
        if (version == "0.3.23") { RequireLegacyThreeComponent(before, install, setup); return; }
        // Maintenance 0.3.26.5 is a deliberately independent two-component
        // installation: Recovery is preserved, not installed a third time.
        // Never try to interpret its receipt as a three-component v2 receipt,
        // and never manufacture old receipts to satisfy the R125 archive gate.
        var independentRoot = Path.Combine(setup, "maintenance-independent-pair-v1");
        if (version == "0.3.26" && File.Exists(Path.Combine(independentRoot, "active.json")))
        {
            RequireIndependentPair(before, install, independentRoot, version);
            return;
        }
        var root = Path.Combine(setup, "maintenance-components-v2");
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
                Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0, "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
            Need(Hash(p) == rows[0]["sha256"]?.GetValue<string>(), "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
            var pe = FileVersionInfo.GetVersionInfo(p);
            var physical = $"{pe.FileMajorPart}.{pe.FileMinorPart}.{pe.FileBuildPart}";
            Need(physical == (rel.Contains("/Recovery/") ? result["recovery_console_version"]?.GetValue<string>() : version));
        }
        var snapshot = Read(Path.Combine(tx, "snapshot.json"));
        var original = Path.Combine(tx, "before", "installed-release-identity.json");
        _ = Read(original);
        Need(snapshot["format"]?.GetValue<string>() == "sokna-maintenance-components-snapshot-v2" &&
            Hash(original) == snapshot["identity_sha256"]?.GetValue<string>(), "R125_IDENTITY_PREIMAGE_MISMATCH");
        // Even the upgrade's preimage must match every non-Maintenance field
        // from the Local rollback proof. A receipt cannot bless other edits.
        _ = RequireOnlyVersionChange(before, File.ReadAllBytes(original));
    }

    /// <summary>
    /// Qualify the exact independent 0.3.26 two-component installer receipt
    /// (issued by invoke-maintenance-independent-v1.ps1). Does NOT move or
    /// rewrite the R125 rollback proof or any historical operation. Unknown,
    /// incomplete or reparse-point records fail closed.
    /// </summary>
    private static void RequireIndependentPair(byte[] before, string install, string root, string version)
    {
        Need(version == "0.3.26", "R125_IDENTITY_INSTALL_RECEIPT_UNQUALIFIED");
        var marker = Read(Path.Combine(root, "active.json"));
        var id = marker["id"]?.GetValue<string>() ?? "";
        var baseline = marker["baseline"]?.GetValue<string>() ?? "";
        Need(marker["format"]?.GetValue<string>() == "sokna-maintenance-independent-transaction-v1" &&
             marker["phase"]?.GetValue<string>() == "COMMITTED" &&
             marker["target"]?.GetValue<string>() == version &&
             Regex.IsMatch(baseline, @"^0\.3\.(22|23|24|25)$") &&
             Regex.IsMatch(id, "^[a-f0-9]{32}$"));
        foreach (var flag in new[] { "database_mutated", "local_mutated", "public_mutated", "print_mutated" })
            Need(marker[flag]?.GetValue<bool>() == false);
        var tx = Path.Combine(root, "tx-" + id);
        var result = Read(Path.Combine(tx, "result.json"));
        Need(result["format"]?.GetValue<string>() == "sokna-maintenance-independent-result-v1" &&
             result["status"]?.GetValue<string>() == "COMMITTED" &&
             result["supervisor_version"]?.GetValue<string>() == version &&
             result["engine_version"]?.GetValue<string>() == version &&
             result["source_version"]?.GetValue<string>() == baseline &&
             result["recovery_preserved"]?.GetValue<bool>() == true &&
             result["archive_reconciled"]?.GetValue<bool>() == false &&
             result["source_commit"]?.GetValue<string>() == "aba774d67e9d3890aa0f69d00f214ae82b395927");
        foreach (var flag in new[] { "database_mutated", "local_mutated", "public_mutated", "print_mutated" })
            Need(result[flag]?.GetValue<bool>() == false);
        var installedFiles = result["files"] as JsonArray;
        Need(installedFiles is not null && installedFiles.Count == 2);
        var wanted = new[] {
            "Maintenance/App/SoknaMaintenanceSupervisor.exe",
            "Maintenance/App/SoknaMaintenanceUpdateEngine.exe"
        };
        foreach (var rel in wanted)
        {
            var rows = installedFiles!.OfType<JsonObject>()
                .Where(x => x["path"]?.GetValue<string>() == rel).ToArray();
            Need(rows.Length == 1);
            var p = Path.Combine(install, rel.Replace('/', Path.DirectorySeparatorChar));
            Need(File.Exists(p) && (File.GetAttributes(p) & FileAttributes.ReparsePoint) == 0,
                "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
            for (var dir = Path.GetDirectoryName(p); dir is not null; dir = Path.GetDirectoryName(dir))
                Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0,
                    "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
            var row = rows[0];
            Need(row["bytes"]?.GetValue<long>() == new FileInfo(p).Length &&
                 Hash(p) == row["sha256"]?.GetValue<string>(),
                 "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
            var pe = FileVersionInfo.GetVersionInfo(p);
            Need($"{pe.FileMajorPart}.{pe.FileMinorPart}.{pe.FileBuildPart}" == version,
                 "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
        }
        var recoveryPath = Path.Combine(install, "Maintenance", "Recovery", "SoknaMaintenanceRecoveryConsole.exe");
        Need(File.Exists(recoveryPath) &&
             (File.GetAttributes(recoveryPath) & FileAttributes.ReparsePoint) == 0,
             "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        for (var dir = Path.GetDirectoryName(recoveryPath); dir is not null; dir = Path.GetDirectoryName(dir))
            Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0,
                "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        var snapshot = Read(Path.Combine(tx, "snapshot.json"));
        var original = Path.Combine(tx, "before", "installed-release-identity.json");
        var oldIdentity = Read(original);
        var beforeBytes = File.ReadAllBytes(original);
        Need(snapshot["format"]?.GetValue<string>() == "sokna-maintenance-independent-snapshot-v1" &&
             Hash(original) == snapshot["identity_sha256"]?.GetValue<string>(),
             "R125_IDENTITY_PREIMAGE_MISMATCH");
        Need(oldIdentity["maintenance_supervisor_version"]?.GetValue<string>() == baseline &&
             oldIdentity["update_engine_version"]?.GetValue<string>() == baseline,
             "R125_IDENTITY_PREIMAGE_MISMATCH");
        _ = RequireOnlyVersionChange(before, beforeBytes);
        var savedRecoveryHash = snapshot["recovery_sha256"]?.GetValue<string>() ?? "";
        Need(Regex.IsMatch(savedRecoveryHash, "^[a-f0-9]{64}$") &&
             Hash(recoveryPath) == savedRecoveryHash &&
             result["recovery_console_version"]?.GetValue<string>() ==
                $"{FileVersionInfo.GetVersionInfo(recoveryPath).FileMajorPart}." +
                $"{FileVersionInfo.GetVersionInfo(recoveryPath).FileMinorPart}." +
                $"{FileVersionInfo.GetVersionInfo(recoveryPath).FileBuildPart}",
             "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
        var oldFiles = snapshot["files"] as JsonArray;
        Need(oldFiles is not null && oldFiles.Count == 2);
        foreach (var rel in wanted)
        {
            var rows = oldFiles!.OfType<JsonObject>()
                .Where(x => x["name"]?.GetValue<string>() == rel).ToArray();
            Need(rows.Length == 1);
            var path = Path.Combine(tx, "before", Path.GetFileName(rel));
            _ = ReadSafeBinaryForProof(path);
            Need(Hash(path) == rows[0]["sha256"]?.GetValue<string>(),
                "R125_IDENTITY_PREIMAGE_MISMATCH");
            var pe = FileVersionInfo.GetVersionInfo(path);
            Need($"{pe.FileMajorPart}.{pe.FileMinorPart}.{pe.FileBuildPart}" == baseline,
                "R125_IDENTITY_PREIMAGE_MISMATCH");
        }
    }

    private static long ReadSafeBinaryForProof(string path)
    {
        Need(File.Exists(path) && (File.GetAttributes(path) & FileAttributes.ReparsePoint) == 0,
            "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        for (var dir = Path.GetDirectoryName(path); dir is not null; dir = Path.GetDirectoryName(dir))
            Need((File.GetAttributes(dir) & FileAttributes.ReparsePoint) == 0,
                "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        var size = new FileInfo(path).Length;
        Need(size is > 0 and < 300_000_000, "R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        return size;
    }

    private static void RequirePeHash(string install, string rel, string version, string hash)
    {
        var p=Path.Combine(install,rel.Replace('/',Path.DirectorySeparatorChar));
        Need(File.Exists(p) && (File.GetAttributes(p)&FileAttributes.ReparsePoint)==0,
            "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
        for(var dir=Path.GetDirectoryName(p);dir is not null;dir=Path.GetDirectoryName(dir))
            Need((File.GetAttributes(dir)&FileAttributes.ReparsePoint)==0,"R125_IDENTITY_EVIDENCE_FILE_UNSAFE");
        var v=FileVersionInfo.GetVersionInfo(p);
        Need($"{v.FileMajorPart}.{v.FileMinorPart}.{v.FileBuildPart}.{v.FilePrivatePart}"==version+".0" && Hash(p)==hash,
            "R125_IDENTITY_INSTALLED_BINARY_MISMATCH");
    }
    private static void RequireLegacyThreeComponent(byte[] before,string install,string setup)
    {
        var root=Path.Combine(setup,"r125-0323-three-component");
        var marker=Read(Path.Combine(root,"active.json"));
        var id=marker["id"]?.GetValue<string>()??"";
        Need(marker["format"]?.GetValue<string>()=="sokna-r125-0323-transaction-v1" &&
            marker["phase"]?.GetValue<string>()=="COMMITTED" && marker["target"]?.GetValue<string>()=="0.3.23" &&
            marker["baseline"]?.GetValue<string>()=="0.3.22" && Regex.IsMatch(id,"^[a-f0-9]{32}$"));
        var tx=Path.Combine(root,"tx-"+id);var result=Read(Path.Combine(tx,"result.json"));
        Need(result["format"]?.GetValue<string>()=="sokna-r125-0323-result-v1" && result["status"]?.GetValue<string>()=="COMMITTED" &&
            result["supervisor_version"]?.GetValue<string>()=="0.3.23" && result["engine_version"]?.GetValue<string>()=="0.3.23" &&
            result["recovery_console_version"]?.GetValue<string>()=="0.1.4");
        foreach(var flag in new[]{"database_mutated","local_mutated","public_mutated","print_mutated"})Need(result[flag]?.GetValue<bool>()==false);
        // v1 did not save target hashes in result.json. Bind it to the exact published 0323 binaries,
        // never to any executable merely sharing that version number.
        RequirePeHash(install,"Maintenance/App/SoknaMaintenanceSupervisor.exe","0.3.23","0cf0d9928542399ddd6e04d1b4bef2d8bb752431ed08a32b1ca919500cf73580");
        RequirePeHash(install,"Maintenance/App/SoknaMaintenanceUpdateEngine.exe","0.3.23","72a82d0b9f4e1556bf0686eed72ec0e9073f7bdf064663dab6dc6bc33a231871");
        RequirePeHash(install,"Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe","0.1.4","7b09d80dca2577101092c55134a25ded0f6f3ddcd1eabe5e7edfccda35ab3a1c");
        var snapshot=Read(Path.Combine(tx,"snapshot.json"));var original=Path.Combine(tx,"before","installed-release-identity.json");
        _=Read(original);
        Need(snapshot["format"]?.GetValue<string>()=="sokna-r125-0323-snapshot-v1" &&
            Hash(original)==snapshot["identity_sha256"]?.GetValue<string>(),"R125_IDENTITY_PREIMAGE_MISMATCH");
        _=RequireOnlyVersionChange(before,File.ReadAllBytes(original));
    }
    private static void RequireLegacySync(byte[] before,string install,string setup)
    {
        var receipt=Read(Path.Combine(setup,"maintenance-only-component-receipt-v1.json"));
        Need(receipt["format"]?.GetValue<string>()=="sokna-maintenance-only-component-receipt-v1" && receipt["version"]?.GetValue<string>()=="0.3.22");
        var sh=receipt["supervisor_sha256"]?.GetValue<string>()??"";var eh=receipt["engine_sha256"]?.GetValue<string>()??"";
        Need(Regex.IsMatch(sh,"^[a-f0-9]{64}$") && Regex.IsMatch(eh,"^[a-f0-9]{64}$"));
        RequirePeHash(install,"Maintenance/App/SoknaMaintenanceSupervisor.exe","0.3.22",sh);
        RequirePeHash(install,"Maintenance/App/SoknaMaintenanceUpdateEngine.exe","0.3.22",eh);
        var root=Path.Combine(setup,"component-transactions");
        Need(Directory.Exists(root) && (File.GetAttributes(root)&FileAttributes.ReparsePoint)==0,"R125_IDENTITY_LEGACY_SYNC_RECEIPT_MISSING_OR_AMBIGUOUS");
        var dirs=Directory.GetDirectories(root,"maintenance-*");Need(dirs.Length<=1000);
        var matches=0;
        foreach(var dir in dirs){
            var file=Path.Combine(dir,"component-version-sync.json");if(!File.Exists(file))continue;
            var sync=Read(file);
            if(sync["format"]?.GetValue<string>()!="sokna-maintenance-installed-version-sync-v1")continue;
            var hashes=sync["observed_binary_sha256"] as JsonArray;
            var versions=sync["observed_versions"] as JsonArray;
            if(hashes?.Count!=2 || versions?.Count!=2 || hashes[0]?.GetValue<string>()!=sh || hashes[1]?.GetValue<string>()!=eh ||
               versions[0]?.GetValue<string>()!="0.3.22" || versions[1]?.GetValue<string>()!="0.3.22")continue;
            foreach(var flag in new[]{"local_identity_changed","public_identity_changed","services_changed","database_mutation_performed"})Need(sync[flag]?.GetValue<bool>()==false);
            Need(sync["recovery_records_unchanged"]?.GetValue<bool>()==true);
            var original=Path.Combine(dir,"installed-release-identity.before.json");
            var atomic=Path.Combine(dir,"installed-release-identity.atomic-before.json");
            var originalNode=Read(original);_=Read(atomic);
            Need(Hash(original)==Hash(atomic),"R125_IDENTITY_PREIMAGE_MISMATCH");
            var previous=sync["previous_versions"] as JsonArray;
            Need(previous?.Count==2 && previous[0]?.GetValue<string>()==originalNode["maintenance_supervisor_version"]?.GetValue<string>() &&
                previous[1]?.GetValue<string>()==originalNode["update_engine_version"]?.GetValue<string>(),"R125_IDENTITY_PREIMAGE_MISMATCH");
            _=RequireOnlyVersionChange(before,File.ReadAllBytes(original));
            matches++;
        }
        Need(matches==1,"R125_IDENTITY_LEGACY_SYNC_RECEIPT_MISSING_OR_AMBIGUOUS");
    }

}
