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
            var trust = PublisherTrustBootstrap.EnsureEmbedded(paths);
            if (trust.KeyId != manifest.Signature.KeyId ||
                trust.State != "pinned_from_installer")
                throw new InvalidDataException("PORTABILITY_EMBEDDED_TRUST_NOT_PROVISIONED");
            if (PublisherTrustBootstrap.EnsureEmbedded(paths).State != "already_pinned")
                throw new InvalidDataException("PORTABILITY_IDEMPOTENCY_FAILED");
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
    _ = PublisherTrustBootstrap.EnsureEmbedded(rejectedRoot);
    var mismatched = new InstalledReleaseIdentity(wrong, null, "0.3.10", "0.3.10", null, null);
    ExpectReject(zip, rejectedRoot, mismatched, "Installed release identity does not match any exact supported baseline.");
    Console.WriteLine("INCOMPATIBLE_HOST_FAIL_CLOSED_PASS");
    var pinnedPath = Path.Combine(rejectedRoot.ReleaseSigningTrustRoot,
        manifest.Signature.KeyId + ".spki.pem");
    File.WriteAllText(pinnedPath, publicPem.Replace("PUBLIC KEY", "PRIVATE KEY"));
    try
    {
        _ = PublisherTrustBootstrap.EnsureEmbedded(rejectedRoot);
        throw new InvalidDataException("PORTABILITY_TAMPERED_TRUST_ACCEPTED");
    }
    catch (InvalidDataException ex) when (ex.Message == "PUBLISHER_TRUST_PIN_CONFLICT") { }
    Console.WriteLine("TAMPERED_LOCAL_TRUST_REJECTED_PASS");

    var untrustedRoot = new MaintenancePaths(Path.Combine(tempRoot, "untrusted"));
    Directory.CreateDirectory(untrustedRoot.ReleaseSigningTrustRoot);
    var validIdentity = new InstalledReleaseIdentity(first, null, "0.3.10", "0.3.10", null, null);
    ExpectReject(zip, untrustedRoot, validIdentity, "Release signing key is not trusted on this machine.");
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
