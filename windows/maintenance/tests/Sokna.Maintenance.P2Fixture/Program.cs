using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

var root = string.Empty;
var variant = "valid";
long sequence = 1;
string? releaseId = null;
var seedStage = false;
for (var i = 0; i < args.Length; i++)
{
    switch (args[i])
    {
        case "--root" when i + 1 < args.Length: root = Path.GetFullPath(args[++i]); break;
        case "--variant" when i + 1 < args.Length: variant = args[++i]; break;
        case "--sequence" when i + 1 < args.Length && long.TryParse(args[++i], out var parsed): sequence = parsed; break;
        case "--release-id" when i + 1 < args.Length: releaseId = args[++i]; break;
        case "--seed-stage": seedStage = true; break;
        default: throw new InvalidOperationException($"Unknown or incomplete argument: {args[i]}.");
    }
}
if (string.IsNullOrWhiteSpace(root)) throw new InvalidOperationException("--root is required.");
// "valid" is an install-qualified synthetic fixture ONLY inside the disposable
// GitHub Windows RUNNER_TEMP; on any installed machine it remains read-only.
var runnerTemp = Environment.GetEnvironmentVariable("RUNNER_TEMP");
var validCiFixture = variant == "valid" &&
    string.Equals(Environment.GetEnvironmentVariable("GITHUB_ACTIONS"), "true", StringComparison.Ordinal) &&
    string.Equals(Environment.GetEnvironmentVariable("RUNNER_OS"), "Windows", StringComparison.Ordinal) &&
    !string.IsNullOrWhiteSpace(runnerTemp) &&
    Path.GetFullPath(root).StartsWith(Path.GetFullPath(runnerTemp).TrimEnd(Path.DirectorySeparatorChar) +
        Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase);
if (variant is "for-install-local-synthetic" or "for-install-local-no-db-synthetic" or "for-install-paired-synthetic")
{
    // These self-asserted fixture qualification flags MUST NOT be written to
    // an installed SOKNA data root or used with a production trust anchor.
    var inDisposableCi = string.Equals(Environment.GetEnvironmentVariable("GITHUB_ACTIONS"), "true", StringComparison.Ordinal) &&
        string.Equals(Environment.GetEnvironmentVariable("RUNNER_OS"), "Windows", StringComparison.Ordinal) &&
        !string.IsNullOrWhiteSpace(runnerTemp) &&
        Path.GetFullPath(root).StartsWith(Path.GetFullPath(runnerTemp).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase);
    if (!inDisposableCi)
        throw new InvalidOperationException("SYNTHETIC_INSTALL_QUALIFICATION_CI_ONLY");
}

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
var siblingFixture = variant is "sibling-ready" or "sibling-zip-traversal" or "sibling-zip-overlay" or "sibling-corrupt-tree";
var siblingVersion = "3.0.0-test-sibling-111";
var siblingTree = "";
var siblingCount = 3;
if (siblingFixture)
{
    var source = Path.Combine(root, "fixture-local-web");
    Directory.CreateDirectory(Path.Combine(source, "public"));
    Directory.CreateDirectory(Path.Combine(source, "resources"));
    File.WriteAllText(Path.Combine(source, "VERSION.txt"), siblingVersion, Encoding.UTF8);
    File.WriteAllText(Path.Combine(source, "public", "index.php"), "<?php echo 'private-candidate'; ?>\n", Encoding.UTF8);
    File.WriteAllText(Path.Combine(source, "resources", "local-managed-files-v1.json"),
        JsonSerializer.Serialize(new { format = "sokna-local-managed-files-v1", files = new[] {
            "VERSION.txt", "public/index.php", "resources/local-managed-files-v1.json" } }, AtomicFile.JsonOptions));
    siblingTree = LocalManagedTreeAttestation.Compute(source, siblingCount);
    if (variant == "sibling-corrupt-tree") siblingTree = new string('f', 64);
    var nestedPath = Path.Combine(root, "inner-local-web-" + sequence + "-" + variant + ".zip");
    if (File.Exists(nestedPath)) File.Delete(nestedPath);
    using (var inner = ZipFile.Open(nestedPath, ZipArchiveMode.Create))
    {
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
        {
            var entry = inner.CreateEntry(Path.GetRelativePath(source, file).Replace('\\', '/'));
            using var input = File.OpenRead(file);
            using var output = entry.Open();
            input.CopyTo(output);
        }
        if (variant == "sibling-zip-traversal")
        {
            var traversal = inner.CreateEntry("../escape.php");
            using var output = new StreamWriter(traversal.Open());
            output.Write("unsafe");
        }
        if (variant == "sibling-zip-overlay")
        {
            var overlay = inner.CreateEntry(".sokna-installation.json");
            using var output = new StreamWriter(overlay.Open());
            output.Write("{}");
        }
    }
    payload = File.ReadAllBytes(nestedPath);
}
var declaredPayload = payload;
var artifactPath = variant == "path-traversal" ? "../escape.bin" :
    siblingFixture ? "artifacts/local/web-release.zip" : "artifacts/local/payload.bin";
var artifact = new ReleaseArtifact(
    artifactPath,
    Convert.ToHexString(SHA256.HashData(declaredPayload)).ToLowerInvariant(),
    declaredPayload.LongLength,
    "local-web",
    siblingFixture ? "web-tree-zip-v1" : "payload");
var now = DateTimeOffset.UtcNow;
var created = variant == "expired" ? now.AddHours(-2) : now.AddMinutes(-1);
var expires = variant == "expired" ? now.AddHours(-1) : now.AddHours(2);
var syntheticLocal = validCiFixture ||
    variant is "for-install-local-synthetic" or "for-install-local-no-db-synthetic" or "for-install-paired-synthetic";
var forInstall = variant == "for-install-unqualified" || syntheticLocal;
var format = variant == "design-draft" ? "sokna-maintenance-release-set-v2.1-draft" : ReleaseSetVerifierStager.Format;
var schema = variant == "design-draft" ? "2.1-design" : ReleaseSetVerifierStager.SchemaVersion;
var status = variant == "design-draft" ? "DESIGN_ONLY_NOT_FOR_INSTALL" :
    syntheticLocal ? "CI_SYNTHETIC_STAGE_FIXTURE_NOT_PRODUCTION" : "ENGINEERING_TEST_NOT_FOR_INSTALL";

var emptyArray = JsonSerializer.SerializeToElement(Array.Empty<object>());
var emptyObject = JsonSerializer.SerializeToElement(new Dictionary<string, object?>());
var manifest = new ReleaseSetManifest(
    format,
    schema,
    status,
    releaseId,
    sequence,
    variant == "for-install-paired-synthetic" ? "paired" : "local_only",
    created,
    expires,
    forInstall,
    syntheticLocal,
    new ReleaseSetSignature(ReleaseSetVerifierStager.SignatureAlgorithm, "test-key", string.Empty),
    new ReleaseSetRequirements("0.1.0", "0.2.0", null, null),
    new[] { new ReleaseBaseline(baseline, null) },
    siblingFixture
        ? JsonSerializer.SerializeToElement(new { local = new {
            version = siblingVersion, managed_tree_sha256 = siblingTree, managed_files = siblingCount } })
        : emptyObject,
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
    syntheticLocal
        ? new ReleaseQualification("CI-SYNTHETIC-NOT-PRODUCTION", true,
            variant != "for-install-local-no-db-synthetic", false,
            true, true, true, false, false, 80)
        : new ReleaseQualification("", false, false, false, false, false, false, false, false, 80),
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

// Regression: a correctly signed, intact, exact-baseline test-only release
// must pass VerifyOnly but cannot create a trusted Stage/sequence. This
// fixture is strictly restricted to the disposable Windows CI sandbox.
if (variant == "signed-readonly-stage-forbidden")
{
    var ciRoot = string.Equals(Environment.GetEnvironmentVariable("GITHUB_ACTIONS"), "true", StringComparison.Ordinal) &&
        string.Equals(Environment.GetEnvironmentVariable("RUNNER_OS"), "Windows", StringComparison.Ordinal) &&
        !string.IsNullOrWhiteSpace(runnerTemp) &&
        Path.GetFullPath(root).StartsWith(Path.GetFullPath(runnerTemp).TrimEnd(Path.DirectorySeparatorChar) +
            Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase);
    if (!ciRoot) throw new InvalidOperationException("SIGNED_READONLY_STAGE_TEST_CI_ONLY");
    var paths = new MaintenancePaths(root);
    var verifier = new ReleaseSetVerifierStager(paths);
    var inspected = verifier.VerifyOnly(packagePath, identity);
    if (!inspected.SignatureVerified || !inspected.ArtifactIntegrityVerified ||
        inspected.ExactBaselineVerified != true || inspected.StagePerformed)
        throw new InvalidDataException("SIGNED_READONLY_PREFLIGHT_INVALID");
    var denied = false;
    try { _ = verifier.VerifyAndStage(packagePath, identity); }
    catch (InvalidDataException ex) when (ex.Message == "RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN")
    { denied = true; }
    var trustedStageCreated = Directory.Exists(paths.StagingRoot) &&
        Directory.EnumerateFiles(paths.StagingRoot, "stage-receipt.json", SearchOption.AllDirectories).Any();
    if (!denied || File.Exists(paths.ReleaseSequenceStatePath) || trustedStageCreated)
        throw new InvalidDataException("SIGNED_READONLY_STAGE_MUTATED_SEQUENCE_OR_STAGE");
    Console.WriteLine(JsonSerializer.Serialize(new {
        result = "PASS", test = "signed_verifyonly_not_installable_stage_denied",
        verified = true, stage_denied = true, anti_rollback_mutation = false,
        stage_created = false, database_mutation_performed = false
    }, AtomicFile.JsonOptions));
    return;
}

// Only the isolated signed-fixture executable may seed a Stage directly.
// The shipped Update Engine still REQUIRES a Supervisor-issued permit.
StagedReleaseReceipt? seededStage = null;
if (seedStage)
{
    if (variant != "valid") throw new InvalidDataException("Only valid fixture Stage can be seeded.");
    var fixturePaths = new MaintenancePaths(root);
    seededStage = new ReleaseSetVerifierStager(fixturePaths).VerifyAndStage(packagePath, identity);
}

Console.WriteLine(JsonSerializer.Serialize(new
{
    package = packagePath,
    identity = identityPath,
    data_root = root,
    trust_key = Path.Combine(trustRoot, "test-key.spki.pem"),
    seeded_stage = seededStage,
    variant,
    sequence,
    release_set_id = releaseId
}, AtomicFile.JsonOptions));
