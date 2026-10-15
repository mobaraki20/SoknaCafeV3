using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed class ReleaseSetVerifierStager
{
    public const string Format = "sokna-maintenance-release-set-v2.1";
    public const string SchemaVersion = "2.1";
    public const string SignatureAlgorithm = "ecdsa-p256-sha256";
    private const long MaxManifestBytes = 2 * 1024 * 1024;
    private const int MaxArtifacts = 512;
    private static readonly Regex KeyIdPattern = new("^[A-Za-z0-9._-]{1,64}$", RegexOptions.CultureInvariant);
    private static readonly HashSet<string> ReleaseTypes = new(StringComparer.Ordinal)
    {
        "public_only", "local_only", "paired", "windows_components_only", "updater_only", "recovery_forward"
    };

    private readonly MaintenancePaths _paths;

    public ReleaseSetVerifierStager(MaintenancePaths paths) => _paths = paths;

    public StagedReleaseReceipt VerifyAndStage(string packagePath, InstalledReleaseIdentity installed)
    {
        packagePath = Path.GetFullPath(packagePath);
        if (!File.Exists(packagePath)) throw new FileNotFoundException("Release Set package was not found.", packagePath);
        Directory.CreateDirectory(_paths.UpdateRoot);
        Directory.CreateDirectory(_paths.StagingRoot);
        Directory.CreateDirectory(_paths.ReleaseSigningTrustRoot);

        using var updateLock = SingleInstanceLock.Acquire(_paths.UpdateStageLockPath);
        var packageSha256 = HashFile(packagePath);
        using var archive = ZipFile.OpenRead(packagePath);
        var entries = IndexArchive(archive);
        if (!entries.TryGetValue("release-set.json", out var manifestEntry))
            throw new InvalidDataException("Release Set package is missing release-set.json.");
        if (manifestEntry.Length > MaxManifestBytes)
            throw new InvalidDataException("Release Set manifest exceeds the maximum size.");

        byte[] manifestBytes;
        using (var source = manifestEntry.Open())
        using (var memory = new MemoryStream())
        {
            source.CopyTo(memory);
            if (memory.Length > MaxManifestBytes) throw new InvalidDataException("Release Set manifest exceeds the maximum size.");
            manifestBytes = memory.ToArray();
        }

        ReleaseSetManifest manifest;
        try
        {
            manifest = JsonSerializer.Deserialize<ReleaseSetManifest>(manifestBytes, AtomicFile.JsonOptions)
                       ?? throw new InvalidDataException("Release Set manifest is empty.");
        }
        catch (JsonException ex)
        {
            throw new InvalidDataException("Release Set manifest JSON is invalid.", ex);
        }

        ValidateManifest(manifest, installed);
        var canonical = ReleaseSetCanonicalizer.CanonicalBytesWithBlankSignatureValue(manifestBytes);
        var manifestSha256 = ReleaseSetCanonicalizer.Sha256Hex(canonical);
        VerifySignature(manifest, canonical);
        ValidateArchiveAgainstArtifacts(entries, manifest.Artifacts);

        var existingSequence = LoadSequenceState();
        if (existingSequence is not null)
        {
            if (manifest.Sequence < existingSequence.HighestSequence)
                throw new InvalidDataException("Release Set sequence is lower than the anti-rollback high-water mark.");
            if (manifest.Sequence == existingSequence.HighestSequence &&
                (!string.Equals(manifest.ReleaseSetId, existingSequence.ReleaseSetId, StringComparison.Ordinal) ||
                 !string.Equals(manifestSha256, existingSequence.ManifestSha256, StringComparison.Ordinal)))
                throw new InvalidDataException("Release Set sequence collides with a different previously staged release.");
        }

        var safeId = SanitizeId(manifest.ReleaseSetId);
        var finalRoot = Path.Combine(_paths.StagingRoot, $"{manifest.Sequence:D20}-{safeId}");
        var receiptPath = Path.Combine(finalRoot, "stage-receipt.json");
        if (Directory.Exists(finalRoot))
        {
            if (!File.Exists(receiptPath)) throw new InvalidDataException("Existing Release Set stage is incomplete and requires operator inspection.");
            var receipt = AtomicFile.ReadJson<StagedReleaseReceipt>(receiptPath);
            if (receipt.ReleaseSetId != manifest.ReleaseSetId || receipt.Sequence != manifest.Sequence || receipt.ManifestSha256 != manifestSha256 || receipt.PackageSha256 != packageSha256)
                throw new InvalidDataException("Existing Release Set stage identity does not match this package.");
            PersistSequenceState(manifest, manifestSha256);
            return receipt;
        }

        var incoming = finalRoot + ".__incoming." + Guid.NewGuid().ToString("N");
        try
        {
            Directory.CreateDirectory(incoming);
            File.WriteAllBytes(Path.Combine(incoming, "release-set.json"), manifestBytes);
            foreach (var pair in manifest.Artifacts.OrderBy(static p => p.Key, StringComparer.Ordinal))
            {
                var artifact = pair.Value;
                var entry = entries[NormalizeRelativePath(artifact.Path)];
                var destination = SafeDestination(incoming, artifact.Path);
                Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
                using var input = entry.Open();
                using var output = new FileStream(destination, FileMode.CreateNew, FileAccess.Write, FileShare.None, 1024 * 128, FileOptions.WriteThrough);
                using var hasher = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
                var buffer = new byte[1024 * 128];
                long count = 0;
                while (true)
                {
                    var read = input.Read(buffer, 0, buffer.Length);
                    if (read == 0) break;
                    count += read;
                    if (count > artifact.SizeBytes) throw new InvalidDataException($"Artifact size overflow while staging: {pair.Key}.");
                    hasher.AppendData(buffer, 0, read);
                    output.Write(buffer, 0, read);
                }
                output.Flush(flushToDisk: true);
                var actualHash = Convert.ToHexString(hasher.GetHashAndReset()).ToLowerInvariant();
                if (count != artifact.SizeBytes || !actualHash.Equals(artifact.Sha256, StringComparison.OrdinalIgnoreCase))
                    throw new InvalidDataException($"Artifact integrity mismatch while staging: {pair.Key}.");
            }

            var receipt = new StagedReleaseReceipt(
                Format: "sokna-maintenance-staged-release-v1",
                SchemaVersion: 1,
                ReleaseSetId: manifest.ReleaseSetId,
                Sequence: manifest.Sequence,
                ManifestSha256: manifestSha256,
                PackageSha256: packageSha256,
                StageRoot: finalRoot,
                StagedAtUtc: DateTimeOffset.UtcNow,
                ActivationPerformed: false,
                DatabaseMutationPerformed: false,
                PreflightIdentityMatch: true,
                SignatureVerified: true,
                ArtifactIntegrityVerified: true);
            AtomicFile.WriteJson(Path.Combine(incoming, "stage-receipt.json"), receipt);
            Directory.Move(incoming, finalRoot);
            PersistSequenceState(manifest, manifestSha256);
            return receipt;
        }
        catch
        {
            if (Directory.Exists(incoming)) Directory.Delete(incoming, recursive: true);
            throw;
        }
    }

    private static Dictionary<string, ZipArchiveEntry> IndexArchive(ZipArchive archive)
    {
        var result = new Dictionary<string, ZipArchiveEntry>(StringComparer.Ordinal);
        foreach (var entry in archive.Entries)
        {
            var normalized = NormalizeRelativePath(entry.FullName);
            if (entry.FullName.EndsWith('/', StringComparison.Ordinal) || entry.FullName.EndsWith('\\')) continue;
            if (!result.TryAdd(normalized, entry)) throw new InvalidDataException($"Duplicate archive entry: {normalized}.");
        }
        return result;
    }

    private static void ValidateManifest(ReleaseSetManifest manifest, InstalledReleaseIdentity installed)
    {
        if (manifest.Format != Format || manifest.SchemaVersion != SchemaVersion)
            throw new InvalidDataException("Unsupported Release Set format/schema.");
        if (manifest.Status.Contains("DESIGN", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Design-only Release Set manifests cannot be staged.");
        if (string.IsNullOrWhiteSpace(manifest.ReleaseSetId) || manifest.ReleaseSetId.Length > 128)
            throw new InvalidDataException("release_set_id is invalid.");
        if (manifest.Sequence <= 0) throw new InvalidDataException("Release Set sequence must be positive.");
        if (!ReleaseTypes.Contains(manifest.ReleaseType)) throw new InvalidDataException("release_type is unsupported.");
        if (manifest.CreatedAt >= manifest.ExpiresAt) throw new InvalidDataException("Release Set created/expires window is invalid.");
        var now = DateTimeOffset.UtcNow;
        if (manifest.CreatedAt > now.AddMinutes(5)) throw new InvalidDataException("Release Set creation time is in the future.");
        if (manifest.ExpiresAt <= now) throw new InvalidDataException("Release Set has expired.");
        if (manifest.Signature.Algorithm != SignatureAlgorithm || !KeyIdPattern.IsMatch(manifest.Signature.KeyId) || string.IsNullOrWhiteSpace(manifest.Signature.Value))
            throw new InvalidDataException("Release Set signature metadata is invalid.");
        if (manifest.Artifacts.Count is < 1 or > MaxArtifacts) throw new InvalidDataException("Release Set artifact count is invalid.");
        if (manifest.SupportedBaselines.Length == 0 && manifest.ReleaseType is "local_only" or "public_only" or "paired" or "recovery_forward")
            throw new InvalidDataException("Release Set requires at least one exact supported baseline.");
        if (manifest.PreUpdateBackupPolicy.OnFailure != "reject_pre_mutation")
            throw new InvalidDataException("Pre-update backup failure policy must reject before mutation.");
        if (manifest.Database.Local.RollbackAfterBoundary != "forbidden" || manifest.Database.Public.RollbackAfterBoundary != "forbidden")
            throw new InvalidDataException("Rollback after DB forward boundary must be forbidden.");
        if (manifest.Recovery.PostDb != "retain_target_updater_forward_recovery_only")
            throw new InvalidDataException("Post-DB recovery policy is incompatible with H328.");
        if (manifest.Recovery.DisasterRestore != "authority_epoch_reenrollment_required" ||
            manifest.Recovery.MachineIdentity != "fresh_reprovision_required" ||
            manifest.Recovery.PublicReenrollment != "credential_rotation_and_authority_epoch_required")
            throw new InvalidDataException("Restore authority fencing policy is incompatible with Maintenance V4.");
        ValidateForInstallGate(manifest);
        ValidateMinimumVersion(installed.MaintenanceSupervisorVersion, manifest.Requirements.MaintenanceSupervisorMin, "Maintenance Supervisor");
        ValidateMinimumVersion(installed.UpdateEngineVersion, manifest.Requirements.UpdateEngineMin, "Update Engine");
        ValidateOptionalMinimumVersion(installed.BackupRecoveryEngineVersion, manifest.Requirements.BackupRecoveryEngineMin, "Backup & Recovery Engine");
        ValidateOptionalMinimumVersion(installed.PublicMaintenanceKernelVersion, manifest.Requirements.PublicMaintenanceKernelMin, "Public Maintenance Kernel");
        if (!MatchesAnyBaseline(manifest, installed)) throw new InvalidDataException("Installed release identity does not match any exact supported baseline.");
    }

    private static void ValidateForInstallGate(ReleaseSetManifest manifest)
    {
        if (!manifest.ForInstall) return;
        var q = manifest.Qualification;
        if (!manifest.ProductionPass || string.IsNullOrWhiteSpace(q.FailureMatrixId) || q.FailureMatrixCasesRequired < 80 ||
            !q.RealWindows || !q.RealMariaDb || !q.RealPublicHost || !q.Canary || !q.RecoveryConsoleWebDown ||
            !q.BusinessBackupConsistencyRealMariaDb || !q.RemoteVaultRealHost || !q.CleanMachineRemoteRestore)
            throw new InvalidDataException("for_install=true is forbidden until every binding real-host qualification gate passes.");
    }

    private static bool MatchesAnyBaseline(ReleaseSetManifest manifest, InstalledReleaseIdentity installed)
    {
        if (manifest.ReleaseType is "windows_components_only" or "updater_only") return true;
        foreach (var baseline in manifest.SupportedBaselines)
        {
            var localOk = manifest.ReleaseType == "public_only" || ExactIdentity(baseline.Local, installed.Local);
            var publicOk = manifest.ReleaseType == "local_only" || ExactIdentity(baseline.Public, installed.Public);
            if (localOk && publicOk) return true;
        }
        return false;
    }

    private static bool ExactIdentity(ReleaseComponentIdentity? expected, ReleaseComponentIdentity? actual)
    {
        if (expected is null || actual is null) return false;
        return expected.ReleaseId == actual.ReleaseId && expected.Version == actual.Version &&
               expected.ManagedTreeSha256.Equals(actual.ManagedTreeSha256, StringComparison.OrdinalIgnoreCase) &&
               expected.SchemaFingerprint == actual.SchemaFingerprint && expected.MigrationHead == actual.MigrationHead;
    }

    private static void ValidateMinimumVersion(string actual, string? minimum, string label)
    {
        if (string.IsNullOrWhiteSpace(minimum)) return;
        if (!TryVersion(actual, out var actualVersion) || !TryVersion(minimum, out var minVersion) || actualVersion < minVersion)
            throw new InvalidDataException($"{label} does not meet the Release Set minimum version.");
    }

    private static void ValidateOptionalMinimumVersion(string? actual, string? minimum, string label)
    {
        if (string.IsNullOrWhiteSpace(minimum)) return;
        if (string.IsNullOrWhiteSpace(actual)) throw new InvalidDataException($"{label} is required by the Release Set but is not installed/known.");
        ValidateMinimumVersion(actual, minimum, label);
    }

    private static bool TryVersion(string value, out Version version)
        => Version.TryParse(value.Split('-', 2)[0], out version!);

    private void VerifySignature(ReleaseSetManifest manifest, byte[] canonical)
    {
        var trustPath = Path.Combine(_paths.ReleaseSigningTrustRoot, manifest.Signature.KeyId + ".spki.pem");
        if (!File.Exists(trustPath)) throw new InvalidDataException("Release signing key is not trusted on this machine.");
        byte[] signature;
        try { signature = Convert.FromBase64String(manifest.Signature.Value); }
        catch (FormatException ex) { throw new InvalidDataException("Release Set signature value is not valid base64.", ex); }
        using var verifier = ECDsa.Create();
        verifier.ImportFromPem(File.ReadAllText(trustPath, Encoding.ASCII));
        if (verifier.KeySize != 256) throw new InvalidDataException("Release signing key must be ECDSA P-256.");
        if (!verifier.VerifyData(canonical, signature, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            throw new InvalidDataException("Release Set signature verification failed.");
    }

    private static void ValidateArchiveAgainstArtifacts(Dictionary<string, ZipArchiveEntry> entries, Dictionary<string, ReleaseArtifact> artifacts)
    {
        var expectedPaths = new HashSet<string>(StringComparer.Ordinal) { "release-set.json" };
        foreach (var pair in artifacts)
        {
            var artifact = pair.Value;
            var normalized = NormalizeRelativePath(artifact.Path);
            if (!expectedPaths.Add(normalized)) throw new InvalidDataException("Two artifact records resolve to the same package path.");
            if (artifact.SizeBytes < 0 || !IsSha256(artifact.Sha256)) throw new InvalidDataException($"Artifact metadata is invalid: {pair.Key}.");
            if (!entries.TryGetValue(normalized, out var entry)) throw new InvalidDataException($"Declared artifact is missing: {pair.Key}.");
            if (entry.Length != artifact.SizeBytes) throw new InvalidDataException($"Artifact size mismatch: {pair.Key}.");
            using var input = entry.Open();
            using var hash = SHA256.Create();
            var actual = Convert.ToHexString(hash.ComputeHash(input)).ToLowerInvariant();
            if (!actual.Equals(artifact.Sha256, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException($"Artifact SHA256 mismatch: {pair.Key}.");
        }
        foreach (var entry in entries.Keys)
            if (!expectedPaths.Contains(entry)) throw new InvalidDataException($"Unexpected file in Release Set package: {entry}.");
    }

    private ReleaseSequenceState? LoadSequenceState()
    {
        if (!File.Exists(_paths.ReleaseSequenceStatePath)) return null;
        var state = AtomicFile.ReadJson<ReleaseSequenceState>(_paths.ReleaseSequenceStatePath);
        if (state.Format != "sokna-maintenance-release-sequence-v1" || state.SchemaVersion != 1 || state.HighestSequence <= 0)
            throw new InvalidDataException("Release sequence state is corrupt or unsupported.");
        return state;
    }

    private void PersistSequenceState(ReleaseSetManifest manifest, string manifestSha256)
    {
        var current = LoadSequenceState();
        if (current is not null && current.HighestSequence > manifest.Sequence) throw new InvalidDataException("Anti-rollback state advanced during staging.");
        if (current is not null && current.HighestSequence == manifest.Sequence &&
            (current.ReleaseSetId != manifest.ReleaseSetId || current.ManifestSha256 != manifestSha256))
            throw new InvalidDataException("Anti-rollback sequence collision detected while committing stage state.");
        AtomicFile.WriteJson(_paths.ReleaseSequenceStatePath, new ReleaseSequenceState(
            "sokna-maintenance-release-sequence-v1", 1, manifest.Sequence, manifest.ReleaseSetId, manifestSha256, DateTimeOffset.UtcNow));
    }

    private static string NormalizeRelativePath(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) throw new InvalidDataException("Archive path is empty.");
        var replaced = value.Replace('\\', '/');
        if (replaced.StartsWith('/', StringComparison.Ordinal) || replaced.Contains(':', StringComparison.Ordinal))
            throw new InvalidDataException("Archive path must be relative.");
        var parts = replaced.Split('/', StringSplitOptions.RemoveEmptyEntries);
        if (parts.Length == 0 || parts.Any(static p => p is "." or "..")) throw new InvalidDataException("Archive path traversal is forbidden.");
        return string.Join('/', parts);
    }

    private static string SafeDestination(string root, string relative)
    {
        var normalized = NormalizeRelativePath(relative).Replace('/', Path.DirectorySeparatorChar);
        var fullRoot = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var destination = Path.GetFullPath(Path.Combine(root, normalized));
        if (!destination.StartsWith(fullRoot, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("Artifact destination escaped staging root.");
        return destination;
    }

    private static string SanitizeId(string value)
    {
        var chars = value.Select(static ch => char.IsLetterOrDigit(ch) || ch is '-' or '_' or '.' ? ch : '_').ToArray();
        var result = new string(chars).Trim('.', ' ');
        return result.Length == 0 ? "release" : result[..Math.Min(result.Length, 96)];
    }

    private static bool IsSha256(string value)
        => value.Length == 64 && value.All(static ch => char.IsAsciiHexDigit(ch));

    private static string HashFile(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }
}
