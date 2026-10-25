using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

// Synthetic Windows test only: model independent installations using two
// unrelated ProgramData roots. Never change a real installed host.
if (args.Length != 2)
    throw new InvalidDataException("Usage: portability-harness <release-set.zip> <publisher.spki.pem>");
var zip = Path.GetFullPath(args[0]);
var publicPem = File.ReadAllText(args[1]);
using var archive = ZipFile.OpenRead(zip);
var manifestEntry = archive.GetEntry("release-set.json")
    ?? throw new InvalidDataException("PORTABILITY_MANIFEST_MISSING");
ReleaseSetManifest manifest;
using (var stream = manifestEntry.Open())
{
    manifest = JsonSerializer.Deserialize<ReleaseSetManifest>(stream, AtomicFile.JsonOptions)
        ?? throw new InvalidDataException("PORTABILITY_MANIFEST_INVALID");
}
if (manifest.SupportedBaselines.Length < 2)
    throw new InvalidDataException("PORTABILITY_TWO_BASELINES_REQUIRED");

var tempRoot = Path.Combine(Path.GetTempPath(), "sokna-v4-portability-fixture-" + Guid.NewGuid().ToString("N"));
try
{
    for (int n = 0; n < 2; n++)
    {
        var baseline = manifest.SupportedBaselines[n].Local
            ?? throw new InvalidDataException("PORTABILITY_BASELINE_MISSING");
        for (var replica = 0; replica < 2; replica++)
        {
            var paths = new MaintenancePaths(Path.Combine(tempRoot, "machine-" + n + "-" + replica));
            Directory.CreateDirectory(paths.ReleaseSigningTrustRoot);
            File.WriteAllText(Path.Combine(paths.ReleaseSigningTrustRoot, manifest.Signature.KeyId + ".spki.pem"), publicPem);
            var installed = new InstalledReleaseIdentity(
                baseline, null, "0.3.10", "0.3.10", null, null);
            var result = new ReleaseSetVerifierStager(paths).VerifyOnly(zip, installed);
            if (!result.SignatureVerified || !result.ArtifactIntegrityVerified ||
                !result.SignedInstallIntent || result.ExactBaselineVerified != true)
                throw new InvalidDataException("PORTABILITY_COMPATIBLE_HOST_REJECTED");
            // Exercise the real durable Windows Stage path, not VerifyOnly.
            // These machine roots are disposable; no service, real DB or active Web touched.
            var receipt = new ReleaseSetVerifierStager(paths).VerifyAndStage(zip, installed);
            if (receipt.ReleaseSetId != manifest.ReleaseSetId ||
                receipt.Sequence != manifest.Sequence ||
                !receipt.SignatureVerified || !receipt.ArtifactIntegrityVerified ||
                !receipt.PreflightIdentityMatch || receipt.ActivationPerformed ||
                receipt.DatabaseMutationPerformed ||
                !File.Exists(Path.Combine(receipt.StageRoot, "stage-receipt.json")))
                throw new InvalidDataException("PORTABILITY_DURABLE_STAGE_INVALID");
            foreach (var artifact in manifest.Artifacts.Values)
            {
                var stored = Path.Combine(receipt.StageRoot, artifact.Path.Replace('/', Path.DirectorySeparatorChar));
                if (!File.Exists(stored) ||
                    !Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(stored)))
                        .Equals(artifact.Sha256, StringComparison.OrdinalIgnoreCase))
                    throw new InvalidDataException("PORTABILITY_STAGED_ARTIFACT_MISMATCH");
            }
            var replay = new ReleaseSetVerifierStager(paths).VerifyAndStage(zip, installed);
            if (replay.StageRoot != receipt.StageRoot || replay.ManifestSha256 != receipt.ManifestSha256 ||
                replay.PackageSha256 != receipt.PackageSha256)
                throw new InvalidDataException("PORTABILITY_STAGE_REPLAY_NOT_IDEMPOTENT");
            var sequence = AtomicFile.ReadJson<ReleaseSequenceState>(paths.ReleaseSequenceStatePath);
            if (sequence.HighestSequence != manifest.Sequence ||
                sequence.ReleaseSetId != manifest.ReleaseSetId ||
                sequence.ManifestSha256 != receipt.ManifestSha256)
                throw new InvalidDataException("PORTABILITY_HIGH_WATER_NOT_PERSISTED");
            Console.WriteLine("INDEPENDENT_HOST_BASELINE_" + n + "_REPLICA_" + replica + "_PASS");
            Console.WriteLine("DURABLE_STAGE_BASELINE_" + n + "_REPLICA_" + replica + "_PASS");
        }
    }

    var first = manifest.SupportedBaselines[0].Local!;
    var wrong = first with { ManagedTreeSha256 = new string('0', 64) };
    var rejectedRoot = new MaintenancePaths(Path.Combine(tempRoot, "incompatible"));
    Directory.CreateDirectory(rejectedRoot.ReleaseSigningTrustRoot);
    File.WriteAllText(Path.Combine(rejectedRoot.ReleaseSigningTrustRoot,
        manifest.Signature.KeyId + ".spki.pem"), publicPem);
    var mismatched = new InstalledReleaseIdentity(wrong, null, "0.3.10", "0.3.10", null, null);
    ExpectReject(zip, rejectedRoot, mismatched, "Installed release identity does not match any exact supported baseline.");
    if (Directory.Exists(rejectedRoot.StagingRoot) || File.Exists(rejectedRoot.ReleaseSequenceStatePath))
        throw new InvalidDataException("PORTABILITY_REJECTED_HOST_STATE_MUTATED");
    Console.WriteLine("INCOMPATIBLE_HOST_FAIL_CLOSED_PASS");

    var untrustedRoot = new MaintenancePaths(Path.Combine(tempRoot, "untrusted"));
    Directory.CreateDirectory(untrustedRoot.ReleaseSigningTrustRoot);
    var validIdentity = new InstalledReleaseIdentity(first, null, "0.3.10", "0.3.10", null, null);
    ExpectReject(zip, untrustedRoot, validIdentity, "Release signing key is not trusted on this machine.");
    if (Directory.Exists(untrustedRoot.StagingRoot) || File.Exists(untrustedRoot.ReleaseSequenceStatePath))
        throw new InvalidDataException("PORTABILITY_UNTRUSTED_HOST_STATE_MUTATED");
    Console.WriteLine("NO_PREPROVISIONED_TRUST_FAIL_CLOSED_PASS");
}
finally { if (Directory.Exists(tempRoot)) Directory.Delete(tempRoot, recursive: true); }

static void ExpectReject(string packagePath, MaintenancePaths paths, InstalledReleaseIdentity installed, string expected)
{
    try
    {
        _ = new ReleaseSetVerifierStager(paths).VerifyOnly(packagePath, installed);
        throw new InvalidDataException("PORTABILITY_FAILURE_NOT_REJECTED");
    }
    catch (InvalidDataException ex) when (ex.Message == expected) { }
}
