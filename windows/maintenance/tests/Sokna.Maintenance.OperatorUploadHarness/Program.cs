using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

var root = Path.Combine(Path.GetTempPath(), "sokna-operator-keyless-" + Guid.NewGuid().ToString("N"));
try
{
    var paths = new MaintenancePaths(Path.Combine(root, "instance-one"));
    Directory.CreateDirectory(paths.InboxRoot);
    var source = new ReleaseComponentIdentity("previous-release", "3.0.0.test-previous",
        new string('1', 64), "schema1", "001", "preserved");
    var installed = new InstalledReleaseIdentity(source, null, "0.3.10", "0.3.10", null, null);
    var webBytes = CreateZip(("VERSION.txt", "3.0.0.test-target\n"),
        ("database/migrations/001.sql", "-- fixture\n"));
    var innerFiles = new Dictionary<string, byte[]>
    {
        ["VERSION.txt"] = Encoding.UTF8.GetBytes("3.0.0.test-target\n"),
        ["database/migrations/001.sql"] = Encoding.UTF8.GetBytes("-- fixture\n")
    };
    var treeCanonical = string.Concat(innerFiles.OrderBy(x => x.Key, StringComparer.Ordinal)
        .Select(x => x.Key + "\0" + Sha(x.Value) + "\n"));
    var targetTree = Sha(Encoding.UTF8.GetBytes(treeCanonical));
    var manifest = new
    {
        format = ReleaseSetVerifierStager.Format,
        schema_version = ReleaseSetVerifierStager.SchemaVersion,
        status = "OPERATOR_UPLOAD_NOT_PUBLISHER_SIGNED",
        release_set_id = "operator-update-test",
        sequence = 1,
        release_type = "local_only",
        created_at = DateTimeOffset.UtcNow.AddYears(-2),
        expires_at = DateTimeOffset.UtcNow.AddYears(-1),
        for_install = false,
        production_pass = false,
        requirements = new { maintenance_supervisor_min = "0.3.10", update_engine_min = "0.3.10" },
        supported_baselines = new[] { new { local = source, @public = (object?)null } },
        targets = new
        {
            local = new { release_id = "operator-update-test",
                version = "3.0.0.test-target", managed_tree_sha256 = targetTree,
                schema_fingerprint = "schema1", migration_head = "001",
                authority_epoch_policy = "preserved", managed_files = 2 }
        },
        artifacts = new Dictionary<string, object> {
            ["local-web"] = new { path = "artifacts/local/web.zip", sha256 = Sha(webBytes),
                                 size_bytes = webBytes.Length, component = "local-web",
                                 kind = "web-tree-zip-v1" }
        },
        dependency_graph = Array.Empty<object>(),
        install_plan = new[] { new { phase = "preflight", component = "local-web", mutation = false } },
        compatibility = new { allowed_intermediate_pairs = Array.Empty<object>(),
            max_stagger_seconds = 0, client_build_window = new { }, protocol_window = new { } },
        mutation_policy = new { guest = "queue", staff_public = "queue", local = "drain_then_block" },
        database = new
        {
            local = new { migration_dag = Array.Empty<object>(), forward_boundary_phase = "db_forward_boundary",
                rollback_after_boundary = "forbidden" },
            @public = new { migration_dag = Array.Empty<object>(), forward_boundary_phase = "db_forward_boundary",
                rollback_after_boundary = "forbidden" }
        },
        recovery = new { pre_db = "rollback_or_discard_allowed",
            post_db = "retain_target_updater_forward_recovery_only",
            disaster_restore = "authority_epoch_reenrollment_required",
            business_backup_restore = "clean_target_only",
            machine_identity = "fresh_reprovision_required",
            public_reenrollment = "credential_rotation_and_authority_epoch_required" },
        pre_update_backup_policy = new { required = true, recent_local_verified_max_age_seconds = 86400,
            require_remote_verified_before_db_boundary = true,
            system_recovery_snapshot_required = true, on_failure = "reject_pre_mutation" },
        qualification = new { }
        // Deliberately NO signature, publisher key, canary or production_pass.
    };
    var manifestBytes = JsonSerializer.SerializeToUtf8Bytes(manifest, new JsonSerializerOptions {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower
    });
    var unsignedZip = CreateZipBytes(("release-set.json", manifestBytes),
        ("artifacts/local/web.zip", webBytes));
    var packageHash = Sha(unsignedZip);
    var uploaded = Path.Combine(paths.InboxRoot, packageHash + ".zip");
    File.WriteAllBytes(uploaded, unsignedZip);
    var inspector = new ReleaseSetVerifierStager(paths);
    var preflight = inspector.VerifyOnly(uploaded, installed);
    Check(preflight.OperatorAuthorized && !preflight.SignatureVerified &&
        preflight.ArtifactIntegrityVerified && preflight.ExactBaselineVerified == true &&
        !preflight.SignedInstallIntent, "operator provenance without any signature");
    Console.WriteLine("PASS operator-uploaded unsigned Local, expired/test flags ignored");
    // A valid operator-authorized Local Stage must remain installable all
    // the way to inactive candidate promotion; the old publisher failure
    // matrix/production flag must not reappear as a downstream blocker.
    var parsed = JsonSerializer.Deserialize<ReleaseSetManifest>(manifestBytes,
        AtomicFile.JsonOptions) ?? throw new InvalidDataException("TEST_MANIFEST_PARSE_FAILED");
    SupervisorInactiveLocalCandidateCoordinator.RequireInstallableLegacyRelease(
        parsed, operatorAuthorized: true);
    ExpectReject(() =>
        SupervisorInactiveLocalCandidateCoordinator.RequireInstallableLegacyRelease(
            parsed, operatorAuthorized: false));
    Console.WriteLine("PASS downstream Local candidate qualification without issuer flags");
    var staged = inspector.VerifyAndStage(uploaded, installed);
    Check(staged.OperatorAuthorized && !staged.SignatureVerified &&
        staged.ArtifactIntegrityVerified && staged.PreflightIdentityMatch,
        "unsigned Stage receipt explicitly records operator authority");
    Check(File.Exists(Path.Combine(staged.StageRoot, "artifacts", "local", "web.zip")),
        "staged artifact exists");
    var repeated = inspector.VerifyAndStage(uploaded, installed);
    Check(repeated.PackageSha256 == staged.PackageSha256 &&
        repeated.OperatorAuthorized, "idempotent Stage readback");
    Console.WriteLine("PASS unsigned Local Stage/receipt and idempotency, no private/public signer key");

    var foreign = Path.Combine(root, "outside-inbox.zip");
    File.WriteAllBytes(foreign, unsignedZip);
    ExpectReject(() => inspector.VerifyOnly(foreign, installed));
    Console.WriteLine("PASS non-panel file rejected");
    var wrongHashName = Path.Combine(paths.InboxRoot, new string('f', 64) + ".zip");
    File.WriteAllBytes(wrongHashName, unsignedZip);
    ExpectReject(() => inspector.VerifyOnly(wrongHashName, installed));
    Console.WriteLine("PASS untrusted content-address rejected");
    var other = installed with { Local = source with { ManagedTreeSha256 = new string('0', 64) } };
    ExpectReject(() => inspector.VerifyOnly(uploaded, other));
    Console.WriteLine("PASS incompatible installed baseline rejected");
    var tampered = CreateZipBytes(("release-set.json", manifestBytes),
        ("artifacts/local/web.zip", Encoding.UTF8.GetBytes("corrupt inner payload")));
    var tamperedPath = Path.Combine(paths.InboxRoot, Sha(tampered) + ".zip");
    File.WriteAllBytes(tamperedPath, tampered);
    ExpectReject(() => inspector.VerifyOnly(tamperedPath, installed));
    Console.WriteLine("PASS broken artifact digest rejected");

    Check(!Directory.Exists(paths.ReleaseSigningTrustRoot),
        "keyless Local upload does not create or read signing trust");
    Console.WriteLine("KEYLESS_OPERATOR_LOCAL_CONTRACT_PASS");
}
finally
{
    if (Directory.Exists(root)) Directory.Delete(root, recursive: true);
}
static void Check(bool ok, string label)
{
    if (!ok) throw new InvalidDataException("HARNESS_FAIL_" + label);
}
static void ExpectReject(Action action)
{
    try
    {
        action();
        throw new InvalidDataException("HARNESS_EXPECTED_REJECTION_BUT_ACCEPTED");
    }
    catch (InvalidDataException ex) when (!ex.Message.StartsWith("HARNESS_", StringComparison.Ordinal)) { }
}
static byte[] CreateZip(params (string, string)[] entries) =>
    CreateZipBytes(entries.Select(x => (x.Item1, Encoding.UTF8.GetBytes(x.Item2))).ToArray());
static byte[] CreateZipBytes(params (string, byte[])[] entries)
{
    using var stream = new MemoryStream();
    using (var zip = new ZipArchive(stream, ZipArchiveMode.Create, leaveOpen: true))
        foreach (var (name, bytes) in entries)
        {
            var entry = zip.CreateEntry(name, CompressionLevel.Fastest);
            using var dest = entry.Open();
            dest.Write(bytes);
        }
    return stream.ToArray();
}
static string Sha(byte[] content) =>
    Convert.ToHexString(SHA256.HashData(content)).ToLowerInvariant();
