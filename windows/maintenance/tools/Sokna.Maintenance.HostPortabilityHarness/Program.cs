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
            Console.WriteLine("INDEPENDENT_HOST_BASELINE_" + n + "_REPLICA_" + replica + "_PASS");
        }
    }

    var first = manifest.SupportedBaselines[0].Local!;
    var wrong = first with { ManagedTreeSha256 = new string('0', 64) };
    var rejectedRoot = new MaintenancePaths(Path.Combine(tempRoot, "incompatible"));
    Directory.CreateDirectory(rejectedRoot.ReleaseSigningTrustRoot);
    File.WriteAllText(Path.Combine(rejectedRoot.ReleaseSigningTrustRoot,
        manifest.Signature.KeyId + ".spki.pem"), publicPem);
    var mismatched = new InstalledReleaseIdentity(wrong, null, "0.3.10", "0.3.10", null, null);
    ExpectReject(rejectedRoot, mismatched, "Installed release identity does not match any exact supported baseline.");
    Console.WriteLine("INCOMPATIBLE_HOST_FAIL_CLOSED_PASS");

    var untrustedRoot = new MaintenancePaths(Path.Combine(tempRoot, "untrusted"));
    Directory.CreateDirectory(untrustedRoot.ReleaseSigningTrustRoot);
    var validIdentity = new InstalledReleaseIdentity(first, null, "0.3.10", "0.3.10", null, null);
    ExpectReject(untrustedRoot, validIdentity, "Release signing key is not trusted on this machine.");
    Console.WriteLine("NO_PREPROVISIONED_TRUST_FAIL_CLOSED_PASS");
}
finally { if (Directory.Exists(tempRoot)) Directory.Delete(tempRoot, recursive: true); }

static void ExpectReject(MaintenancePaths paths, InstalledReleaseIdentity installed, string expected)
{
    try
    {
        _ = new ReleaseSetVerifierStager(paths).VerifyOnly(Path.GetFullPath(args[0]), installed);
        throw new InvalidDataException("PORTABILITY_FAILURE_NOT_REJECTED");
    }
    catch (InvalidDataException ex) when (ex.Message == expected) { }
}
