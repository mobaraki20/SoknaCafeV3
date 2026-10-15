using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

var root = string.Empty;
var variant = "valid";
long sequence = 1;
string? releaseId = null;
for (var i = 0; i < args.Length; i++)
{
    switch (args[i])
    {
        case "--root" when i + 1 < args.Length: root = Path.GetFullPath(args[++i]); break;
        case "--variant" when i + 1 < args.Length: variant = args[++i]; break;
        case "--sequence" when i + 1 < args.Length && long.TryParse(args[++i], out var parsed): sequence = parsed; break;
        case "--release-id" when i + 1 < args.Length: releaseId = args[++i]; break;
        default: throw new InvalidOperationException($"Unknown or incomplete argument: {args[i]}.");
    }
}
if (string.IsNullOrWhiteSpace(root)) throw new InvalidOperationException("--root is required.");
Directory.CreateDirectory(root);
releaseId ??= $"release-test-{sequence}";

var trustRoot = Path.Combine(root, "trust", "release-signing");
Directory.CreateDirectory(trustRoot);
using var signer = ECDsa.Create(ECCurve.NamedCurves.nistP256);
File.WriteAllText(Path.Combine(trustRoot, "test-key.spki.pem"), signer.ExportSubjectPublicKeyInfoPem(), Encoding.ASCII);

var baseline = new ReleaseComponentIdentity(
    "local-baseline-release", "3.0.0-test", new string('a', 64), "schema-test-67", "0067", "compatible");
var identity = new InstalledReleaseIdentity(
    baseline, null, "0.1.0", "0.2.0", null, null);
var identityPath = Path.Combine(root, "identity.json");
AtomicFile.WriteJson(identityPath, identity);

var payload = Encoding.UTF8.GetBytes("SOKNA Maintenance V4 P2A deterministic fixture payload\n");
var declaredPayload = payload;
var artifactPath = variant == "path-traversal" ? "../escape.bin" : "artifacts/local/payload.bin";
var artifact = new ReleaseArtifact(
    artifactPath,
    Convert.ToHexString(SHA256.HashData(declaredPayload)).ToLowerInvariant(),
    declaredPayload.LongLength,
    "local-web",
    "payload");
var now = DateTimeOffset.UtcNow;
var created = variant == "expired" ? now.AddHours(-2) : now.AddMinutes(-1);
var expires = variant == "expired" ? now.AddHours(-1) : now.AddHours(2);
var forInstall = variant == "for-install-unqualified";
var format = variant == "design-draft" ? "sokna-maintenance-release-set-v2.1-draft" : ReleaseSetVerifierStager.Format;
var schema = variant == "design-draft" ? "2.1-design" : ReleaseSetVerifierStager.SchemaVersion;
var status = variant == "design-draft" ? "DESIGN_ONLY_NOT_FOR_INSTALL" : "ENGINEERING_TEST_NOT_FOR_INSTALL";

var emptyArray = JsonSerializer.SerializeToElement(Array.Empty<object>());
var emptyObject = JsonSerializer.SerializeToElement(new Dictionary<string, object?>());
var manifest = new ReleaseSetManifest(
    format,
    schema,
    status,
    releaseId,
    sequence,
    "local_only",
    created,
    expires,
    forInstall,
    false,
    new ReleaseSetSignature(ReleaseSetVerifierStager.SignatureAlgorithm, "test-key", string.Empty),
    new ReleaseSetRequirements("0.1.0", "0.2.0", null, null),
    new[] { new ReleaseBaseline(baseline, null) },
    emptyObject,
    new Dictionary<string, ReleaseArtifact>(StringComparer.Ordinal) { ["local_payload"] = artifact },
    emptyArray,
    new[] { new InstallPlanStep("preflight", "all", false), new InstallPlanStep("stage", "local-web", false) },
    new ReleaseCompatibility(emptyArray, 0, emptyObject, emptyObject),
    new ReleaseMutationPolicy("allow", "allow", "allow"),
    new ReleaseDatabase(
        new ReleaseDatabaseSide(emptyArray, "db_forward_boundary", "forbidden"),
        new ReleaseDatabaseSide(emptyArray, "db_forward_boundary", "forbidden")),
    new ReleaseRecovery(
        "rollback_or_discard_allowed",
        "retain_target_updater_forward_recovery_only",
        "authority_epoch_reenrollment_required",
        "clean_target_only",
        "fresh_reprovision_required",
        "credential_rotation_and_authority_epoch_required"),
    new ReleaseQualification("", false, false, false, false, false, false, false, false, 80),
    new PreUpdateBackupPolicy(false, null, false, false, "reject_pre_mutation"));

var unsignedBytes = JsonSerializer.SerializeToUtf8Bytes(manifest, AtomicFile.JsonOptions);
var canonical = ReleaseSetCanonicalizer.CanonicalBytesWithBlankSignatureValue(unsignedBytes);
var signature = signer.SignData(canonical, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
if (variant == "bad-signature") signature[0] ^= 0x40;
manifest = manifest with { Signature = manifest.Signature with { Value = Convert.ToBase64String(signature) } };
var manifestBytes = JsonSerializer.SerializeToUtf8Bytes(manifest, AtomicFile.JsonOptions);

var packagePath = Path.Combine(root, $"release-set-{sequence}-{variant}.zip");
if (File.Exists(packagePath)) File.Delete(packagePath);
using (var archive = ZipFile.Open(packagePath, ZipArchiveMode.Create))
{
    var manifestEntry = archive.CreateEntry("release-set.json", CompressionLevel.Optimal);
    using (var stream = manifestEntry.Open()) stream.Write(manifestBytes);

    var artifactEntry = archive.CreateEntry(artifactPath.Replace('\\', '/'), CompressionLevel.Optimal);
    using (var stream = artifactEntry.Open())
    {
        var actualPayload = variant == "bad-artifact" ? Encoding.UTF8.GetBytes("tampered artifact\n") : payload;
        stream.Write(actualPayload);
    }

    if (variant == "unexpected-entry")
    {
        var extra = archive.CreateEntry("unexpected.txt", CompressionLevel.Optimal);
        using var stream = new StreamWriter(extra.Open(), Encoding.UTF8);
        stream.Write("unexpected");
    }
}

Console.WriteLine(JsonSerializer.Serialize(new
{
    package = packagePath,
    identity = identityPath,
    data_root = root,
    trust_key = Path.Combine(trustRoot, "test-key.spki.pem"),
    variant,
    sequence,
    release_set_id = releaseId
}, AtomicFile.JsonOptions));
