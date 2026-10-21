using System.Reflection;
using System.Security.Cryptography;
using System.Text;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Pins the publisher's PUBLIC key to the installed Maintenance binary.
/// The resource is included only by the authenticated Windows Services build,
/// NEVER read from a user-selected Release Set ZIP or a machine-specific file.
/// No private keys are generated, imported or exported.
/// </summary>
public static class PublisherTrustBootstrap
{
    public const string EmbeddedResourceName = "Sokna.Maintenance.Core.publisher.spki.pem";
    public const string KeyIdPrefix = "sokna-rel-";

    public static PublisherTrustBootstrapResult EnsureEmbedded(MaintenancePaths paths)
    {
        ArgumentNullException.ThrowIfNull(paths);
        using var embedded = typeof(PublisherTrustBootstrap).Assembly
            .GetManifestResourceStream(EmbeddedResourceName);
        if (embedded is null)
            return new PublisherTrustBootstrapResult("not_bundled", "", "", false);
        using var memory = new MemoryStream();
        embedded.CopyTo(memory);
        if (memory.Length is < 60 or > 4096)
            throw new InvalidDataException("PUBLISHER_EMBEDDED_PUBLIC_KEY_SIZE_INVALID");
        return InstallPinnedPublicKey(paths, memory.ToArray());
    }

    // Deliberately internal: only the embedded, installer-proven resource is
    // usable from the production Supervisor. CI exercises this via test access.
    internal static PublisherTrustBootstrapResult InstallPinnedPublicKey(
        MaintenancePaths paths, byte[] publicPem)
    {
        ArgumentNullException.ThrowIfNull(paths);
        ArgumentNullException.ThrowIfNull(publicPem);
        var text = new UTF8Encoding(false, true).GetString(publicPem).Trim();
        if (!text.StartsWith("-----BEGIN PUBLIC KEY-----", StringComparison.Ordinal) ||
            !text.EndsWith("-----END PUBLIC KEY-----", StringComparison.Ordinal) ||
            text.Contains("PRIVATE KEY", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("PUBLISHER_TRUST_EXPECTS_PUBLIC_SPKI_ONLY");
        byte[] canonicalSpki;
        try
        {
            using var ecdsa = ECDsa.Create();
            ecdsa.ImportFromPem(text);
            if (ecdsa.KeySize != 256)
                throw new InvalidDataException("PUBLISHER_TRUST_EXPECTS_ECDSA_P256");
            canonicalSpki = ecdsa.ExportSubjectPublicKeyInfo();
            // Require a public-only importable P-256 point, including known OID.
            using var second = ECDsa.Create();
            second.ImportSubjectPublicKeyInfo(canonicalSpki, out var read);
            if (read != canonicalSpki.Length || second.KeySize != 256)
                throw new InvalidDataException("PUBLISHER_TRUST_SPKI_INVALID");
        }
        catch (CryptographicException ex)
        {
            throw new InvalidDataException("PUBLISHER_TRUST_SPKI_INVALID", ex);
        }
        var sha = Convert.ToHexString(SHA256.HashData(canonicalSpki)).ToLowerInvariant();
        var keyId = KeyIdPrefix + sha[..20];
        var keyPath = Path.Combine(paths.ReleaseSigningTrustRoot, keyId + ".spki.pem");

        // ProgramData may not be an NTFS fixed location on every host: resolve
        // dynamically from the MaintenancePaths used by THIS Supervisor.
        var root = Path.GetFullPath(paths.ReleaseSigningTrustRoot);
        AssertNoReparseInOwnedPath(paths.DataRoot, root);
        Directory.CreateDirectory(root);
        AssertNoReparseInOwnedPath(paths.DataRoot, root);

        var canonicalText = "-----BEGIN PUBLIC KEY-----\n"
            + Convert.ToBase64String(canonicalSpki, Base64FormattingOptions.InsertLineBreaks)
            + "\n-----END PUBLIC KEY-----\n";
        if (File.Exists(keyPath))
        {
            AssertOrdinaryFile(keyPath);
            var existing = File.ReadAllText(keyPath, Encoding.ASCII);
            if (!EquivalentPublicKey(existing, canonicalSpki))
                throw new InvalidDataException("PUBLISHER_TRUST_PIN_CONFLICT");
            return new PublisherTrustBootstrapResult("already_pinned", keyId, sha, false);
        }

        var pending = Path.Combine(root, "." + keyId + "." + Guid.NewGuid().ToString("N") + ".tmp");
        try
        {
            using (var f = new FileStream(pending, FileMode.CreateNew, FileAccess.Write,
                       FileShare.None, 4096, FileOptions.WriteThrough))
            {
                var bytes = Encoding.ASCII.GetBytes(canonicalText);
                f.Write(bytes);
                f.Flush(true);
            }
            // A clash/reparse point must be rejected, not overwritten.
            File.Move(pending, keyPath, overwrite: false);
            AssertOrdinaryFile(keyPath);
            if (!EquivalentPublicKey(File.ReadAllText(keyPath, Encoding.ASCII), canonicalSpki))
                throw new InvalidDataException("PUBLISHER_TRUST_READBACK_MISMATCH");
            return new PublisherTrustBootstrapResult("pinned_from_installer", keyId, sha, true);
        }
        finally { if (File.Exists(pending)) File.Delete(pending); }
    }

    private static bool EquivalentPublicKey(string pem, byte[] expected)
    {
        try
        {
            using var key = ECDsa.Create();
            key.ImportFromPem(pem);
            return CryptographicOperations.FixedTimeEquals(
                key.ExportSubjectPublicKeyInfo(), expected);
        }
        catch (Exception ex) when (ex is CryptographicException or ArgumentException)
        { return false; }
    }

    private static void AssertOrdinaryFile(string path)
    {
        if ((File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("PUBLISHER_TRUST_REPARSE_FILE_FORBIDDEN");
    }

    private static void AssertNoReparseInOwnedPath(string rootPath, string targetPath)
    {
        var root = Path.GetFullPath(rootPath).TrimEnd(
            Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var target = Path.GetFullPath(targetPath).TrimEnd(
            Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        if (!target.StartsWith(root + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("PUBLISHER_TRUST_OUTSIDE_OWNED_ROOT");
        // Never traverse attacker-supplied junctions under ProgramData.
        var current = root;
        if (Directory.Exists(current) &&
            (File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("PUBLISHER_TRUST_REPARSE_DIRECTORY_FORBIDDEN");
        foreach (var part in target[(root.Length + 1)..].Split(Path.DirectorySeparatorChar))
        {
            current = Path.Combine(current, part);
            if (Directory.Exists(current) &&
                (File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("PUBLISHER_TRUST_REPARSE_DIRECTORY_FORBIDDEN");
        }
    }
}

public sealed record PublisherTrustBootstrapResult(
    string State, string KeyId, string PublicKeySha256, bool Mutated);
