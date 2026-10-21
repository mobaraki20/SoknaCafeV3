using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// Adopts an externally installed CODE-ONLY Local baseline solely when the
/// publisher's newly selected install-qualified Release Set SIGNED the exact
/// observed baseline. No private signing key or operator-supplied identity.
/// Does not itself settle any recovery_required operation or authorize Stage.
/// </summary>
public sealed class SupervisorSignedLocalIdentityRebind
{
    private readonly MaintenancePaths _paths;

    public SupervisorSignedLocalIdentityRebind(MaintenancePaths paths) => _paths = paths;

    public SignedLocalIdentityRebindReceipt? AdoptForNextRelease(
        string packagePath, InstalledReleaseIdentity previous)
    {
        if (previous.Local is null)
            throw new InvalidDataException("LOCAL_REBIND_TRUSTED_PREVIOUS_IDENTITY_REQUIRED");
        var physical = new LocalSlotManager(_paths).InspectHealthyCurrent();
        if (!physical.WebIsReparsePoint || physical.ActiveSlotTarget is null)
            throw new InvalidDataException("LOCAL_REBIND_VERIFIED_R8_JUNCTION_REQUIRED");
        ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
            physical.ActiveSlotTarget, physical.PackageRoot);
        RoutineLocalBinding.RequireCurrent(physical.WebRoot, physical.PackageRoot, physical.Build);
        RoutineLocalBinding.RequireTarget(
            Path.Combine(physical.PackageRoot, "LocalWeb", "persistent"),
            physical.ActiveSlotTarget, physical.PackageRoot, physical.Build);
        var tree = LocalManagedTreeAttestation.Compute(physical.WebRoot,
            physical.ManagedFileCount);
        if (previous.Local.Version == physical.Build &&
            string.Equals(previous.Local.ManagedTreeSha256, tree,
                StringComparison.OrdinalIgnoreCase))
            return null;

        // The manifest is UNTRUSTED until VerifyOnly below checks its P-256
        // issuer signature, complete package artifacts, anti-rollback sequence,
        // full qualification and exact candidate baseline. It is used here
        // only to select a candidate for that independent verification.
        packagePath = Path.GetFullPath(packagePath);
        using var zip = ZipFile.OpenRead(packagePath);
        var entries = zip.Entries.Where(e => e.FullName == "release-set.json").ToArray();
        if (entries.Length != 1 || entries[0].Length is <= 0 or > 2 * 1024 * 1024)
            throw new InvalidDataException("LOCAL_REBIND_SIGNED_MANIFEST_REQUIRED");
        ReleaseSetManifest manifest;
        using (var stream = entries[0].Open())
        using (var buffer = new MemoryStream())
        {
            stream.CopyTo(buffer);
            if (buffer.Length > 2 * 1024 * 1024)
                throw new InvalidDataException("LOCAL_REBIND_MANIFEST_TOO_LARGE");
            manifest = JsonSerializer.Deserialize<ReleaseSetManifest>(
                buffer.ToArray(), AtomicFile.JsonOptions)
                ?? throw new InvalidDataException("LOCAL_REBIND_MANIFEST_INVALID");
        }
        if (manifest.ReleaseType != "local_only" ||
            manifest.SupportedBaselines is null)
            throw new InvalidDataException("LOCAL_REBIND_QUALIFIED_LOCAL_ONLY_SIGNER_REQUIRED");
        var eligible = manifest.SupportedBaselines
            .Select(x => x.Local)
            .Where(x => x is not null && x.Version == physical.Build &&
                string.Equals(x.ManagedTreeSha256, tree, StringComparison.OrdinalIgnoreCase) &&
                x.SchemaFingerprint == previous.Local.SchemaFingerprint &&
                x.MigrationHead == previous.Local.MigrationHead &&
                x.AuthorityEpochPolicy == previous.Local.AuthorityEpochPolicy)
            .ToArray();
        if (eligible.Length != 1)
            throw new InvalidDataException("LOCAL_REBIND_UNIQUE_SIGNED_BASELINE_REQUIRED");
        var verifiedCandidate = previous with { Local = eligible[0] };
        var proven = new ReleaseSetVerifierStager(_paths)
            .VerifyOnly(packagePath, verifiedCandidate);
        if (!OperatorUploadedLocalPackage.HasTrustedPackageEvidence(
                proven.SignatureVerified, proven.OperatorAuthorized) ||
            !proven.ArtifactIntegrityVerified ||
            !(proven.SignedInstallIntent || proven.OperatorAuthorized) ||
            proven.ExactBaselineVerified != true)
            throw new InvalidDataException("LOCAL_REBIND_PUBLISHER_PROOF_UNQUALIFIED");

        // Preserve original trusted identity and bind exactly the issuer's
        // signed baseline fields, not newly fabricated release/schema values.
        var originalBytes = File.ReadAllBytes(_paths.InstalledReleaseIdentityPath);
        var original = JsonSerializer.Deserialize<InstalledReleaseIdentity>(
            originalBytes, AtomicFile.JsonOptions);
        if (original != previous)
            throw new InvalidDataException("LOCAL_REBIND_PREVIOUS_IDENTITY_CHANGED");
        var originalSha = Hex(SHA256.HashData(originalBytes));
        var receipt = new SignedLocalIdentityRebindReceipt(
            "sokna-signed-local-identity-rebind-v1", 1, DateTimeOffset.UtcNow,
            originalSha, previous.Local.ReleaseId, previous.Local.Version,
            verifiedCandidate.Local!.ReleaseId, verifiedCandidate.Local.Version,
            verifiedCandidate.Local.ManagedTreeSha256, verifiedCandidate.Local.MigrationHead,
            proven.ReleaseSetId, proven.PackageSha256, proven.ManifestSha256,
            physical.ActiveSlotTarget, proven.OperatorAuthorized
                ? "operator_uploaded_exact_live_code_only_baseline"
                : "publisher_signed_exact_live_code_only_baseline");
        var receiptPath = Path.Combine(_paths.OperationsRoot, "identity-rebind-" +
            receipt.PreviousIdentitySha256 + "-" + proven.ManifestSha256 + ".json");
        // Both records are written atomically; an interrupted adoption leaves
        // the original identity or a traceable signed receipt for readback.
        AtomicFile.WriteJson(receiptPath, receipt);
        AtomicFile.WriteJson(_paths.InstalledReleaseIdentityPath, verifiedCandidate);
        var readback = AtomicFile.ReadJson<InstalledReleaseIdentity>(
            _paths.InstalledReleaseIdentityPath);
        if (readback != verifiedCandidate)
            throw new InvalidDataException("LOCAL_REBIND_ATOMIC_READBACK_MISMATCH");
        return receipt;
    }

    private static string Hex(byte[] data) =>
        Convert.ToHexString(data).ToLowerInvariant();
}

public sealed record SignedLocalIdentityRebindReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("rebound_at_utc")] DateTimeOffset ReboundAtUtc,
    [property: JsonPropertyName("previous_identity_sha256")] string PreviousIdentitySha256,
    [property: JsonPropertyName("previous_release_id")] string PreviousReleaseId,
    [property: JsonPropertyName("previous_version")] string PreviousVersion,
    [property: JsonPropertyName("adopted_release_id")] string AdoptedReleaseId,
    [property: JsonPropertyName("adopted_version")] string AdoptedVersion,
    [property: JsonPropertyName("adopted_managed_tree_sha256")] string AdoptedManagedTreeSha256,
    [property: JsonPropertyName("migration_head_unchanged")] string MigrationHeadUnchanged,
    [property: JsonPropertyName("signed_release_set_id")] string SignedReleaseSetId,
    [property: JsonPropertyName("signed_package_sha256")] string SignedPackageSha256,
    [property: JsonPropertyName("signed_manifest_sha256")] string SignedManifestSha256,
    [property: JsonPropertyName("observed_active_slot")] string ObservedActiveSlot,
    [property: JsonPropertyName("evidence")] string Evidence);
