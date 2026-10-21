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
// Windows CI-only regression: durable legacy operation is retained but ceases
// to block ONLY with a causally newer committed migration's verifiable proof.
if (variant == "legacy-reconcile-policy")
{
    var ciTemp = Environment.GetEnvironmentVariable("RUNNER_TEMP");
    if (!string.Equals(Environment.GetEnvironmentVariable("GITHUB_ACTIONS"), "true", StringComparison.Ordinal) ||
        !string.Equals(Environment.GetEnvironmentVariable("RUNNER_OS"), "Windows", StringComparison.Ordinal) ||
        string.IsNullOrWhiteSpace(ciTemp) ||
        !Path.GetFullPath(root).StartsWith(Path.GetFullPath(ciTemp).TrimEnd(Path.DirectorySeparatorChar) +
            Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
        throw new InvalidOperationException("LEGACY_RECONCILE_TEST_CI_ONLY");
    var paths = new MaintenancePaths(root);
    var store = new MaintenanceOperationStore(paths);
    var t0 = DateTimeOffset.UtcNow.AddDays(-5);
    var t1 = DateTimeOffset.UtcNow.AddDays(-1);
    var tree = new string('a', 64);
    var build = "3.0.0-test-231ar";
    var refString = $"production-local-slot-migration:{build}:509:{tree}";
    var statePath = Path.Combine(paths.LocalSlotStateRoot, "migration-state.json");
    var old = new MaintenanceOperation(
        "sokna-maintenance-operation-v1", 1, Guid.NewGuid().ToString("N"),
        "old-home-cycle", MaintenanceOperationStore.CanonicalRequestHash(
            "update.local-slot-home-cycle", "phase3-local-slots:legacy:497:" + tree, "ci"),
        "update.local-slot-home-cycle", "ci", "recovery_required",
        "phase3-local-slots:legacy:497:" + tree, t0, t0, 1,
        "UPDATE_ENGINE_TIMEOUT_STATE_UNPROVEN", "Original uncertainty retained",
        Path.Combine(paths.LocalSlotStateRoot, "phase3-state.json"), true, false);
    var newer = new MaintenanceOperation(
        "sokna-maintenance-operation-v1", 1, Guid.NewGuid().ToString("N"),
        "new-migration", MaintenanceOperationStore.CanonicalRequestHash(
            "update.local-slot-migrate", refString, "ci"),
        "update.local-slot-migrate", "ci", "committed", refString,
        t1, t1.AddMinutes(3), 1, "LOCAL_SLOT_MIGRATION_COMMITTED",
        "Verified R8 migration", statePath, true, false);
    var migration = new ProductionLocalSlotMigrationState(
        "sokna-production-local-slot-migration-state-v1", 1, "COMMITTED",
        build, 509, tree, Path.Combine(root, "app"),
        Path.Combine(root, "app", "LocalWeb"), "apache", "http://127.0.0.1:1/health",
        Path.Combine(root, "releases"), Path.Combine(root, "releases", "b"),
        null, Path.Combine(root, "releases", "b"), null,
        true, false, false, false, false, t1.AddMinutes(1),
        new[] { new ProductionLocalSlotMigrationEvent("COMMITTED", t1.AddSeconds(30)) });
    static void Need(bool b, string code) { if (!b) throw new InvalidDataException(code); }
    Need(SupervisorLegacySlotReconciler.SupersessionProven(old, newer, migration, statePath),
        "NEWER_COMMIT_SHOULD_AUTHORIZE_SETTLEMENT");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old,
        newer with { State = "recovery_required" }, migration, statePath),
        "UNCOMMITTED_NEWER_MUST_BLOCK");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old,
        newer with { CreatedAtUtc = t0.AddMinutes(-1) }, migration, statePath),
        "OLDER_MIGRATION_MUST_BLOCK");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old,
        newer with { CanonicalRequestSha256 = new string('f', 64) }, migration, statePath),
        "FORGED_OPERATION_LINK_MUST_BLOCK");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old,
        newer with { StageReceiptPath = Path.Combine(root, "wrong.json") }, migration, statePath),
        "WRONG_MIGRATION_RECEIPT_MUST_BLOCK");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old, newer,
        migration with { Phase = "RECOVERY_REQUIRED" }, statePath),
        "UNRESOLVED_PHYSICAL_STATE_MUST_BLOCK");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old, newer,
        migration with { DatabaseMutationPerformed = true }, statePath),
        "DB_FORWARD_BOUNDARY_MUST_BLOCK");
    Need(!SupervisorLegacySlotReconciler.SupersessionProven(old with
        { DatabaseMutationPerformed = true }, newer, migration, statePath),
        "OLD_DB_MUTATION_MUST_BLOCK");

    var hash = MaintenanceOperationStore.CanonicalRequestHash(
        old.OperationType, old.PackageRef, old.OperatorId);
    var (entry, _) = store.GetOrCreate("legacy-case-ci", hash, old.OperationType,
        old.PackageRef, old.OperatorId);
    entry = store.Update(entry, "recovery_required",
        "UPDATE_ENGINE_TIMEOUT_STATE_UNPROVEN", "Original uncertainty retained");
    Need(store.ListBlocking().Count == 1, "LEGACY_RECOVERY_MUST_BLOCK_FIRST");
    entry = store.Update(entry, "superseded_verified",
        "LOCAL_SLOT_SUPERSEDED_BY_VERIFIED_MIGRATION",
        "Reconciled after newer committed migration and live host proof");
    Need(store.ListBlocking().Count == 0 && store.ListActive().Count == 0,
        "RESOLVED_LEGACY_MUST_NOT_BLOCK_NEXT_UPDATE");
    Need(store.Get(entry.OperationId)?.State == "superseded_verified" &&
        store.ListRecent().Any(x => x.OperationId == entry.OperationId) &&
        store.GetByIdempotencyKey("legacy-case-ci")?.OperationId == entry.OperationId,
        "AUDIT_HISTORY_MUST_REMAIN");
    var (next, _) = store.GetOrCreate("next-local-ci",
        MaintenanceOperationStore.CanonicalRequestHash("update.local-package-preflight",
            "next.zip", "ci"), "update.local-package-preflight", "next.zip", "ci");
    Need(next.State == "accepted", "NEXT_UPDATE_MUST_BE_ADMISSIBLE");
    Console.WriteLine(JsonSerializer.Serialize(new {
        result = "PASS", legacy_audited = true, next_update_admitted = true,
        fail_closed_variants = 7
    }));
    return;
}

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
        inspected.ExactBaselineVerified != true || inspected.StagePerformed ||
        inspected.SignedInstallIntent)
        throw new InvalidDataException("SIGNED_READONLY_PREFLIGHT_INVALID");
    var denied = false;
    try { _ = verifier.VerifyAndStage(packagePath, identity); }
    catch (InvalidDataException ex) when (ex.Message == "RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN")
    { denied = true; }
    // The canonical Supervisor path must also reject the authenticated
    // read-only package BEFORE it reserves a durable Stage operation. A 422
    // must never become a sticky recovery_required blocker for later updates.
    Directory.CreateDirectory(paths.InboxRoot);
    AtomicFile.WriteJson(paths.InstalledReleaseIdentityPath, identity);
    var packageRef = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(packagePath)))
        .ToLowerInvariant() + ".zip";
    File.Copy(packagePath, Path.Combine(paths.InboxRoot, packageRef));
    var operations = new MaintenanceOperationStore(paths);
    var state = new SupervisorStateStore(paths).LoadOrCreate();
    var journal = new DurableJournal(paths.JournalPath);
    const string actor = "ci-signed-readonly";
    var preflight = new SupervisorLocalPackagePreflightCoordinator(
        paths, operations, journal, state).Verify(
        packageRef, actor, Guid.NewGuid().ToString("N"));
    if (preflight.Operation.State != "verified" || preflight.Receipt is null)
        throw new InvalidDataException("SIGNED_READONLY_SUPERVISOR_PREFLIGHT_INVALID");
    var supervisorStageDenied = false;
    try
    {
        _ = new SupervisorLocalReleaseStageCoordinator(paths, operations, journal, state)
            .Stage(preflight.Operation.OperationId, actor, Guid.NewGuid().ToString("N"));
    }
    catch (InvalidDataException ex) when (ex.Message == "RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN")
    {
        supervisorStageDenied = true;
    }
    if (!supervisorStageDenied ||
        operations.ListRecent().Any(op => op.OperationType == "update.local-stage") ||
        operations.ListBlocking().Count != 0)
        throw new InvalidDataException("SIGNED_READONLY_SUPERVISOR_STAGE_POISONED_LEDGER");
    var trustedStageCreated = Directory.Exists(paths.StagingRoot) &&
        Directory.EnumerateFiles(paths.StagingRoot, "stage-receipt.json", SearchOption.AllDirectories).Any();
    if (!denied || File.Exists(paths.ReleaseSequenceStatePath) ||
        Directory.Exists(paths.StagingRoot) || trustedStageCreated)
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
