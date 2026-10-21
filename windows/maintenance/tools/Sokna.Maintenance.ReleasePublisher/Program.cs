using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

// Publisher-side only. This tool NEVER performs installed-host update,
// enrolls trust, generates a key or sets any qualification flags.
if (args.Length != 4)
    throw new InvalidDataException("Usage: issuer <qualified-manifest.json> <local-web.zip> <qualification-receipt.json> <output-release-set.zip>");
var source = Path.GetFullPath(args[0]);
var payloadPath = Path.GetFullPath(args[1]);
var receiptPath = Path.GetFullPath(args[2]);
var output = Path.GetFullPath(args[3]);
if (File.Exists(output)) throw new InvalidDataException("PUBLISHER_OUTPUT_ALREADY_EXISTS");
var pem = Environment.GetEnvironmentVariable("SOKNA_V4_PUBLISHER_P256_PEM");
if (string.IsNullOrWhiteSpace(pem))
    throw new InvalidDataException("PROTECTED_PUBLISHER_CREDENTIAL_UNAVAILABLE");

var originalBytes = File.ReadAllBytes(source);
var original = JsonNode.Parse(originalBytes) as JsonObject
    ?? throw new InvalidDataException("PUBLISHER_MANIFEST_INVALID");
var parsed = JsonSerializer.Deserialize<ReleaseSetManifest>(originalBytes, AtomicFile.JsonOptions)
    ?? throw new InvalidDataException("PUBLISHER_MANIFEST_INVALID");
if (parsed.Format != ReleaseSetVerifierStager.Format ||
    parsed.SchemaVersion != ReleaseSetVerifierStager.SchemaVersion ||
    parsed.ReleaseType != "local_only" || !parsed.ForInstall ||
    !parsed.ProductionPass || parsed.Status.Contains("NOT_FOR_INSTALL", StringComparison.OrdinalIgnoreCase))
    throw new InvalidDataException("PUBLISHER_LOCAL_QUALIFICATION_REQUIRED");
ReleaseInstallQualificationGate.Require(parsed);
if (parsed.SupportedBaselines.Length != 1 || parsed.SupportedBaselines[0].Local is null ||
    parsed.Artifacts.Count != 1)
    throw new InvalidDataException("PUBLISHER_EXACT_LOCAL_ONLY_CONTRACT_REQUIRED");
var baseline = parsed.SupportedBaselines[0].Local!;
RequireCompleteIdentity(baseline);
var target = parsed.Targets.GetProperty("local");
var targetVersion = target.GetProperty("version").GetString();
var targetSha = target.GetProperty("managed_tree_sha256").GetString();
var targetCount = target.GetProperty("managed_files").GetInt32();
if (string.IsNullOrWhiteSpace(targetVersion) ||
    string.IsNullOrWhiteSpace(targetSha) || targetCount is < 1 or > 20_000)
    throw new InvalidDataException("PUBLISHER_SIGNED_TARGET_INCOMPLETE");
foreach (var field in new[] { "release_id", "schema_fingerprint", "migration_head", "authority_epoch_policy" })
    if (string.IsNullOrWhiteSpace(target.GetProperty(field).GetString()) ||
        target.GetProperty(field).GetString()!.Contains("__READ_TRUSTED", StringComparison.Ordinal))
        throw new InvalidDataException("PUBLISHER_TARGET_IDENTITY_UNRESOLVED");

var artifact = parsed.Artifacts.Single().Value;
if (artifact.Component != "local-web" || artifact.Kind != "web-tree-zip-v1" ||
    artifact.Path.Contains("..", StringComparison.Ordinal) ||
    artifact.Path.Contains('\\') || !artifact.Path.StartsWith("artifacts/local/", StringComparison.Ordinal) ||
    !artifact.Path.EndsWith(".zip", StringComparison.OrdinalIgnoreCase))
    throw new InvalidDataException("PUBLISHER_ARTIFACT_KIND_OR_PATH_INVALID");
var payload = File.ReadAllBytes(payloadPath);
var payloadSha = Hex(SHA256.HashData(payload));
if (artifact.SizeBytes != payload.LongLength ||
    !string.Equals(artifact.Sha256, payloadSha, StringComparison.OrdinalIgnoreCase))
    throw new InvalidDataException("PUBLISHER_ARTIFACT_SHA256_MISMATCH");
using (var web = new ZipArchive(new MemoryStream(payload, writable: false), ZipArchiveMode.Read))
{
    var entries = web.Entries.Where(e => !string.IsNullOrEmpty(e.Name)).ToArray();
    if (entries.Length != targetCount || entries.Length > 20_000)
        throw new InvalidDataException("PUBLISHER_LOCAL_FILE_COUNT_INVALID");
    var seen = new HashSet<string>(StringComparer.Ordinal);
    var lines = new SortedDictionary<string, string>(StringComparer.Ordinal);
    foreach (var entry in entries)
    {
        var path = entry.FullName;
        if (!seen.Add(path) || path.StartsWith('/') || path.Contains('\\') ||
            path.Split('/').Any(x => x is "" or "." or ".."))
            throw new InvalidDataException("PUBLISHER_UNSAFE_OR_DUPLICATE_WEB_PATH");
        using var file = entry.Open();
        using var sha = SHA256.Create();
        lines.Add(path, Hex(sha.ComputeHash(file)));
    }
    var canonicalTree = string.Concat(lines.Select(pair => pair.Key + "\0" + pair.Value + "\n"));
    if (!string.Equals(Hex(SHA256.HashData(Encoding.UTF8.GetBytes(canonicalTree))), targetSha, StringComparison.OrdinalIgnoreCase))
        throw new InvalidDataException("PUBLISHER_LOCAL_TARGET_TREE_MISMATCH");
    var versionEntry = web.GetEntry("VERSION.txt")
        ?? throw new InvalidDataException("PUBLISHER_LOCAL_VERSION_MISSING");
    using var versionReader = new StreamReader(versionEntry.Open(), Encoding.UTF8);
    if (versionReader.ReadToEnd().Trim() != targetVersion)
        throw new InvalidDataException("PUBLISHER_TARGET_VERSION_MISMATCH");
}
// A receipt records the CI/real-host test evidence; it is NOT by itself a
// cryptographic attestation. The protected publisher job must verify its origin.
using (var receipt = JsonDocument.Parse(File.ReadAllBytes(receiptPath)))
{
    var proof = receipt.RootElement;
    if (proof.GetProperty("release_set_id").GetString() != parsed.ReleaseSetId ||
        proof.GetProperty("artifact_sha256").GetString() != payloadSha ||
        proof.GetProperty("failure_matrix_id").GetString() != parsed.Qualification.FailureMatrixId ||
        proof.GetProperty("result").GetString() != "QUALIFIED")
        throw new InvalidDataException("PUBLISHER_QUALIFICATION_RECEIPT_MISMATCH");
    foreach (var name in new[] { "real_windows_uat_sha256", "mariadb_backup_restore_sha256", "negative_matrix_sha256" })
    {
        var hash = proof.GetProperty(name).GetString();
        if (hash is not { Length: 64 } || !hash.All(Uri.IsHexDigit))
            throw new InvalidDataException("PUBLISHER_QUALIFICATION_EVIDENCE_MISSING");
    }
}

using var signer = ECDsa.Create();
signer.ImportFromPem(pem);
if (signer.KeySize != 256)
    throw new InvalidDataException("PUBLISHER_KEY_MUST_BE_P256");
var spki = signer.ExportSubjectPublicKeyInfo();
var keyFingerprint = Hex(SHA256.HashData(spki))[..20];
if (!parsed.Signature.KeyId.EndsWith(keyFingerprint, StringComparison.Ordinal) ||
    parsed.Signature.Algorithm != ReleaseSetVerifierStager.SignatureAlgorithm ||
    !string.IsNullOrEmpty(parsed.Signature.Value))
    throw new InvalidDataException("PUBLISHER_SIGNING_KEY_OR_CANONICAL_INPUT_MISMATCH");

var canonical = ReleaseSetCanonicalizer.CanonicalBytesWithBlankSignatureValue(originalBytes);
var signed = signer.SignData(canonical, HashAlgorithmName.SHA256,
    DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
if (signed.Length != 64 || !signer.VerifyData(canonical, signed, HashAlgorithmName.SHA256,
    DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
    throw new InvalidDataException("PUBLISHER_SIGNATURE_SELF_VERIFY_FAILED");
var signature = original["signature"] as JsonObject
    ?? throw new InvalidDataException("PUBLISHER_SIGNATURE_SECTION_MISSING");
signature["value"] = Convert.ToBase64String(signed);
var sealedManifest = Encoding.UTF8.GetBytes(original.ToJsonString());
var afterCanonical = ReleaseSetCanonicalizer.CanonicalBytesWithBlankSignatureValue(sealedManifest);
if (!CryptographicOperations.FixedTimeEquals(canonical, afterCanonical))
    throw new InvalidDataException("PUBLISHER_CANONICAL_REWRITE_MISMATCH");

// Output is complete only after a self-verified, content-addressed write.
// Never touch an existing published file on retry.
var tmp = output + ".new-" + Guid.NewGuid().ToString("N");
try
{
    using (var fs = new FileStream(tmp, FileMode.CreateNew, FileAccess.Write, FileShare.None))
    using (var archive = new ZipArchive(fs, ZipArchiveMode.Create))
    {
        Write(archive, "release-set.json", sealedManifest);
        Write(archive, artifact.Path, payload);
    }
    using (var read = ZipFile.OpenRead(tmp))
    {
        var saved = read.GetEntry("release-set.json")
            ?? throw new InvalidDataException("PUBLISHER_OUTPUT_MANIFEST_MISSING");
        using var stream = saved.Open();
        using var buffer = new MemoryStream();
        stream.CopyTo(buffer);
        var verifiedManifest = buffer.ToArray();
        using var verifier = ECDsa.Create();
        verifier.ImportSubjectPublicKeyInfo(spki, out _);
        if (!verifier.VerifyData(
            ReleaseSetCanonicalizer.CanonicalBytesWithBlankSignatureValue(verifiedManifest),
            Convert.FromBase64String(
                JsonDocument.Parse(verifiedManifest).RootElement.GetProperty("signature").GetProperty("value").GetString()!),
            HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            throw new InvalidDataException("PUBLISHER_OUTPUT_SIGNATURE_READBACK_FAILED");
    }
    File.Move(tmp, output);
}
finally { if (File.Exists(tmp)) File.Delete(tmp); }
Console.WriteLine("PUBLISHER_SEALED_RELEASE_SET_SOURCE_QUALIFICATION_ONLY");
Console.WriteLine("release_set_id=" + parsed.ReleaseSetId);
Console.WriteLine("release_sha256=" + Hex(SHA256.HashData(File.ReadAllBytes(output))));
Console.WriteLine("private_key_exported=false");
Console.WriteLine("real_cafe_install_not_claimed=true");

static void RequireCompleteIdentity(ReleaseComponentIdentity identity)
{
    if (new[] { identity.ReleaseId, identity.Version, identity.ManagedTreeSha256,
        identity.SchemaFingerprint, identity.MigrationHead, identity.AuthorityEpochPolicy }
        .Any(x => string.IsNullOrWhiteSpace(x) || x!.Contains("__READ_TRUSTED", StringComparison.Ordinal)))
        throw new InvalidDataException("PUBLISHER_BASELINE_IDENTITY_UNRESOLVED");
}
static string Hex(byte[] bytes) => Convert.ToHexString(bytes).ToLowerInvariant();
static void Write(ZipArchive archive, string name, byte[] bytes)
{
    var entry = archive.CreateEntry(name, CompressionLevel.Optimal);
    using var writer = entry.Open();
    writer.Write(bytes);
}
