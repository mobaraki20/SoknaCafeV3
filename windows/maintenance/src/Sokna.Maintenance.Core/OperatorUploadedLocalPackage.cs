namespace Sokna.Maintenance.Core;

/// <summary>
/// Trust boundary for a Local ZIP deliberately uploaded through the authenticated
/// Local Web -> Supervisor content-addressed Inbox. No publisher signature,
/// certificate, enrollment or per-computer secret is required for package origin.
/// This does not skip artifact hashes, exact baseline, recovery or rollback.
/// </summary>
public static class OperatorUploadedLocalPackage
{
    public static bool IsEligible(MaintenancePaths paths, string packagePath, ReleaseSetManifest manifest)
    {
        if (paths is null || manifest?.ReleaseType != "local_only") return false;
        var inbox = Path.GetFullPath(paths.InboxRoot);
        var candidate = Path.GetFullPath(packagePath);
        if (!string.Equals(Path.GetDirectoryName(candidate), inbox,
                StringComparison.OrdinalIgnoreCase) ||
            !string.Equals(Path.GetExtension(candidate), ".zip",
                StringComparison.OrdinalIgnoreCase) ||
            !Directory.Exists(inbox) || !File.Exists(candidate))
            return false;
        if ((File.GetAttributes(inbox) & FileAttributes.ReparsePoint) != 0 ||
            (File.GetAttributes(candidate) & FileAttributes.ReparsePoint) != 0)
            return false;
        // The local HTTP upload endpoint creates ONLY sha256.zip final refs.
        var name = Path.GetFileNameWithoutExtension(candidate);
        if (name.Length != 64 || !name.All(char.IsAsciiHexDigit)) return false;
        using var stream = File.OpenRead(candidate);
        var actual = Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(stream));
        return string.Equals(actual, name, StringComparison.OrdinalIgnoreCase);
    }

    public static bool HasTrustedPackageEvidence(bool signatureVerified, bool operatorApproved)
        => signatureVerified || operatorApproved;
}
