using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

// CI-only cross-language interop: PHP/OpenSSL signs with ECDSA-P256 DER;
// .NET must verify actual PHP canonical bytes using pinned SPKI, with no
// Public source/host mutation or production authorization.
if (args.Length != 3 ||
    Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
    Environment.GetEnvironmentVariable("RUNNER_OS") != "Linux")
    throw new InvalidDataException("S7E5B_CI_ONLY");

var dir = Path.GetFullPath(args[0]);
var runner = Path.GetFullPath(Environment.GetEnvironmentVariable("RUNNER_TEMP")
    ?? throw new InvalidDataException("S7E5B_RUNNER_TEMP_MISSING"))
    .TrimEnd(Path.DirectorySeparatorChar);
if (!string.Equals(Path.GetDirectoryName(dir), runner, StringComparison.Ordinal) ||
    !Path.GetFileName(dir).StartsWith("sokna-public-php-der-", StringComparison.Ordinal))
    throw new InvalidDataException("S7E5B_FOREIGN_DIR");

var json = File.ReadAllBytes(args[1]);
var receipt = JsonSerializer.Deserialize<PublicKernelSignedObservation>(json)
    ?? throw new InvalidDataException("S7E5B_INVALID_JSON");
var raw = File.ReadAllBytes(args[2]);
var signedBytes = PublicKernelSignedReceiptInspector.CanonicalSignedBytes(receipt);
Check(raw.AsSpan().SequenceEqual(signedBytes), "PHP canonical bytes diverge from .NET");

using var verifier = ECDsa.Create();
verifier.ImportFromPem(File.ReadAllText(Path.Combine(dir, "public.pem")));
var pinned = verifier.ExportSubjectPublicKeyInfo();
Check(pinned.Length is > 60 and < 200, "invalid pinned SPKI");

var now = DateTimeOffset.UtcNow;
var valid = Verify(receipt,pinned,receipt.KeyId,receipt.Nonce,receipt.RequestId,
    receipt.InstallationId,receipt.AuthorityEpoch,now);
Check(valid.KernelHealthy && !valid.ProductionCutoverAuthorized &&
    !valid.PanelUpdateReady && !valid.PublicKernelIntegrated &&
    valid.ReleaseSequence == 29, "unsafe read-only evidence");

Reject(() => Verify(receipt,pinned,receipt.KeyId,
    new string('f',32),receipt.RequestId,receipt.InstallationId,
    receipt.AuthorityEpoch,now), "replayed nonce");
Reject(() => Verify(receipt,pinned,receipt.KeyId,receipt.Nonce,
    receipt.RequestId,"other-installation",receipt.AuthorityEpoch,now),
    "wrong installation");
Reject(() => Verify(receipt,pinned,receipt.KeyId,receipt.Nonce,
    receipt.RequestId,receipt.InstallationId,receipt.AuthorityEpoch+1,now),
    "old authority epoch");
Reject(() => Verify(receipt with {ActiveTreeSha256=new string('b',64)},
    pinned,receipt.KeyId,receipt.Nonce,receipt.RequestId,receipt.InstallationId,
    receipt.AuthorityEpoch,now), "tampered signed tree");
Reject(() => Verify(receipt,pinned,"unknown-key",receipt.Nonce,
    receipt.RequestId,receipt.InstallationId,receipt.AuthorityEpoch,now),
    "unauthorized key rotation");
Reject(() => Verify(receipt,pinned,receipt.KeyId,receipt.Nonce,
    receipt.RequestId,receipt.InstallationId,receipt.AuthorityEpoch,
    now.AddMinutes(3)), "expired authentic receipt");

using var attacker = ECDsa.Create(ECCurve.NamedCurves.nistP256);
Reject(() => Verify(receipt,attacker.ExportSubjectPublicKeyInfo(),
    receipt.KeyId,receipt.Nonce,receipt.RequestId,receipt.InstallationId,
    receipt.AuthorityEpoch,now), "wrong pinned signer");

Console.WriteLine(JsonSerializer.Serialize(new {
    format="sokna-f1-s7e5b-php-openssl-dotnet-der-interoperability-v1",
    result="PASS",
    real_php_openssl_der_signature=true,
    raw_canonical_serialization_bytes_identical=true,
    dotnet_pinned_spki_validation=true,
    negative_signature_nonce_epoch_installation_expiry_tests=true,
    actual_public_source_or_host_touched=false,
    real_public_kernel_integrated=false,
    production_cutover_authorized=false,
    panel_update_ready=false
}));

static PublicKernelReadOnlyEvidence Verify(
    PublicKernelSignedObservation r, byte[] pub, string keyId,
    string nonce, string request, string installation, long epoch,
    DateTimeOffset now) =>
    PublicKernelSignedReceiptInspector.Verify(
        r,pub,keyId,nonce,request,installation,epoch,now);
static void Check(bool ok,string reason) {
    if(!ok)throw new InvalidDataException("S7E5B_"+reason);
}
static void Reject(Action action,string reason) {
    try { action(); } catch(InvalidDataException){ return; }
    throw new InvalidDataException("S7E5B_FAIL_OPEN_"+reason);
}
