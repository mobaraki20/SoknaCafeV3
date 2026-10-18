using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7E5A: read-only Public Kernel receipt. This is NOT Public Kernel
/// implementation, a mutation authorization, or deployment qualification.
/// Pinned public signing trust MUST come from Supervisor configuration,
/// never from the HTTP response (or a browser/cache).
/// </summary>
public sealed record PublicKernelSignedObservation(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("key_id")] string KeyId,
    [property: JsonPropertyName("nonce")] string Nonce,
    [property: JsonPropertyName("request_id")] string RequestId,
    [property: JsonPropertyName("issued_utc_ticks")] long IssuedUtcTicks,
    [property: JsonPropertyName("expires_utc_ticks")] long ExpiresUtcTicks,
    [property: JsonPropertyName("installation_id")] string InstallationId,
    [property: JsonPropertyName("authority_epoch")] long AuthorityEpoch,
    [property: JsonPropertyName("install_generation")] long InstallGeneration,
    [property: JsonPropertyName("active_release_set_id")] string ActiveReleaseSetId,
    [property: JsonPropertyName("release_sequence")] long ReleaseSequence,
    [property: JsonPropertyName("active_tree_sha256")] string ActiveTreeSha256,
    [property: JsonPropertyName("schema_fingerprint")] string SchemaFingerprint,
    [property: JsonPropertyName("protocol_version")] string ProtocolVersion,
    [property: JsonPropertyName("kernel_health")] string KernelHealth,
    [property: JsonPropertyName("app_health")] string AppHealth,
    [property: JsonPropertyName("lifecycle_state")] string LifecycleState,
    [property: JsonPropertyName("stage_signed_verified")] bool StageSignedVerified,
    [property: JsonPropertyName("exact_base_verified")] bool ExactBaseVerified,
    [property: JsonPropertyName("mutation_fenced")] bool MutationFenced,
    [property: JsonPropertyName("execution_authorized")] bool ExecutionAuthorized,
    [property: JsonPropertyName("signature")] string Signature);

public sealed record PublicKernelReadOnlyEvidence(
    string InstallationId,
    long AuthorityEpoch,
    long InstallGeneration,
    string ActiveReleaseSetId,
    long ReleaseSequence,
    string ActiveTreeSha256,
    string SchemaFingerprint,
    string ProtocolVersion,
    bool KernelHealthy,
    bool ApplicationHealthy,
    bool StageSignedVerified,
    bool ExactBaseVerified,
    bool MutationFenced,
    string LifecycleState,
    bool PublicKernelIntegrated,
    bool ProductionCutoverAuthorized,
    bool PanelUpdateReady);

public static class PublicKernelSignedReceiptInspector
{
    public const string Format = "sokna-public-kernel-observation-v1";
    public const string Rejected = "PUBLIC_KERNEL_UNTRUSTED_OR_UNAVAILABLE";
    private const string Domain = "SOKNA-PUBLIC-KERNEL-READONLY-CHALLENGE-V1\n";
    private static readonly TimeSpan MaxLifetime = TimeSpan.FromSeconds(45);
    private static readonly TimeSpan MaxClockSkew = TimeSpan.FromSeconds(10);
    private const int MaxReceiptBytes = 64 * 1024;

    // OpenSSL/PHP emits ASN.1 DER ECDSA P-256 signatures. P1363 is NOT
    // interoperable with that PHP wire contract; require explicit DER.\n    // Matches the documented kernel's signed bytes. Canonical serialization
    // is a *versioned protocol* and must be agreed by the independent Public
    // owner before production integration.
    public static byte[] CanonicalSignedBytes(PublicKernelSignedObservation receipt) =>
        Encoding.UTF8.GetBytes(Domain +
            JsonSerializer.Serialize(receipt with { Signature = "" }));

    public static PublicKernelReadOnlyEvidence Verify(
        PublicKernelSignedObservation? receipt, byte[] pinnedPublicKeySpki,
        string expectedKeyId, string challengeNonce, string requestId,
        string expectedInstallationId, long minimumAuthorityEpoch,
        DateTimeOffset now)
    {
        if (receipt is null || string.IsNullOrWhiteSpace(expectedInstallationId) ||
            string.IsNullOrWhiteSpace(expectedKeyId) ||
            pinnedPublicKeySpki.Length is < 60 or > 1024 ||
            !ValidHex(challengeNonce, 32) || !ValidHex(requestId, 32) ||
            receipt.Format != Format || receipt.KeyId != expectedKeyId ||
            receipt.Nonce != challengeNonce || receipt.RequestId != requestId ||
            receipt.InstallationId != expectedInstallationId ||
            receipt.AuthorityEpoch < minimumAuthorityEpoch ||
            receipt.AuthorityEpoch < 1 || receipt.InstallGeneration < 1 ||
            receipt.ReleaseSequence < 1 ||
            string.IsNullOrWhiteSpace(receipt.ActiveReleaseSetId) ||
            !ValidHex(receipt.ActiveTreeSha256, 64) ||
            !ValidHex(receipt.SchemaFingerprint, 64) ||
            string.IsNullOrWhiteSpace(receipt.ProtocolVersion) ||
            receipt.KernelHealth != "healthy" ||
            receipt.AppHealth is not ("healthy" or "degraded" or "unavailable") ||
            receipt.LifecycleState is not ("idle" or "staged" or "maintenance" or "recovery") ||
            receipt.ExecutionAuthorized || !ValidBase64(receipt.Signature))
            throw new InvalidDataException(Rejected);

        if (receipt.IssuedUtcTicks <= 0 ||
            receipt.ExpiresUtcTicks <= receipt.IssuedUtcTicks ||
            receipt.ExpiresUtcTicks - receipt.IssuedUtcTicks > MaxLifetime.Ticks ||
            receipt.IssuedUtcTicks > now.UtcTicks + MaxClockSkew.Ticks ||
            receipt.ExpiresUtcTicks < now.UtcTicks - MaxClockSkew.Ticks)
            throw new InvalidDataException(Rejected);

        try
        {
            using var key = ECDsa.Create();
            key.ImportSubjectPublicKeyInfo(pinnedPublicKeySpki, out var consumed);
            if (consumed != pinnedPublicKeySpki.Length ||
                key.KeySize != 256 ||
                !key.VerifyData(CanonicalSignedBytes(receipt),
                    Convert.FromBase64String(receipt.Signature),
                    HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence))
                throw new InvalidDataException(Rejected);
        }
        catch (Exception ex) when (ex is CryptographicException or FormatException
                                   or ArgumentException)
        {
            throw new InvalidDataException(Rejected, ex);
        }

        // Authenticity is NOT a grant. This never changes any installation,
        // queue, epoch or release state.
        return new PublicKernelReadOnlyEvidence(
            receipt.InstallationId, receipt.AuthorityEpoch,
            receipt.InstallGeneration, receipt.ActiveReleaseSetId,
            receipt.ReleaseSequence, receipt.ActiveTreeSha256,
            receipt.SchemaFingerprint, receipt.ProtocolVersion,
            true, receipt.AppHealth == "healthy",
            receipt.StageSignedVerified, receipt.ExactBaseVerified,
            receipt.MutationFenced, receipt.LifecycleState,
            PublicKernelIntegrated: false,
            ProductionCutoverAuthorized: false, PanelUpdateReady: false);
    }

    /// <summary>
    /// Makes a fresh network request; no cached browser/Public business
    /// health or redirected endpoint is ever used as authoritative evidence.
    /// Real endpoints MUST be HTTPS. Loopback HTTP exists only inside
    /// isolated GitHub-hosted Windows CI fixtures, and never in production.
    /// </summary>
    public static async Task<PublicKernelReadOnlyEvidence> ProbeAsync(
        Uri endpoint, byte[] pinnedPublicKeySpki, string expectedKeyId,
        string expectedInstallationId, long minimumAuthorityEpoch,
        CancellationToken cancellationToken = default)
    {
        if (!AllowedEndpoint(endpoint))
            throw new InvalidDataException(Rejected);
        var nonce = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        var requestId = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        using var handler = new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false };
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var request = new HttpRequestMessage(HttpMethod.Get, endpoint);
        request.Headers.TryAddWithoutValidation("X-Sokna-Nonce", nonce);
        request.Headers.TryAddWithoutValidation("X-Sokna-Request-Id", requestId);
        request.Headers.TryAddWithoutValidation("Cache-Control", "no-store");
        try
        {
            using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead,
                cancellationToken).ConfigureAwait(false);
            if (response.StatusCode != HttpStatusCode.OK ||
                response.Headers.CacheControl?.NoStore != true ||
                response.Content.Headers.ContentLength is > MaxReceiptBytes)
                throw new InvalidDataException(Rejected);
            await using var body = await response.Content.ReadAsStreamAsync(cancellationToken)
                .ConfigureAwait(false);
            using var buffer = new MemoryStream();
            var chunk = new byte[8192];
            int count;
            while ((count = await body.ReadAsync(chunk, cancellationToken).ConfigureAwait(false)) > 0)
            {
                if (buffer.Length + count > MaxReceiptBytes) throw new InvalidDataException(Rejected);
                buffer.Write(chunk.AsSpan(0, count));
            }
            var receipt = JsonSerializer.Deserialize<PublicKernelSignedObservation>(buffer.ToArray())
                ?? throw new InvalidDataException(Rejected);
            return Verify(receipt, pinnedPublicKeySpki, expectedKeyId, nonce, requestId,
                expectedInstallationId, minimumAuthorityEpoch, DateTimeOffset.UtcNow);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException
                                   or JsonException or IOException)
        {
            throw new InvalidDataException(Rejected, ex);
        }
    }

    private static bool AllowedEndpoint(Uri uri)
    {
        // Bind to the existing Public-owned stable entrypoint, but keep the
        // optional /public/ subfolder (or other configured base path) free.
        // The release-attest ACTION is a proposed Public-owner addition;
        // it is NOT implemented by current Public maintenance.php.
        if (!uri.IsAbsoluteUri || !string.IsNullOrEmpty(uri.UserInfo) ||
            uri.Query != "?action=release-attest" ||
            !string.IsNullOrEmpty(uri.Fragment) ||
            !uri.AbsolutePath.EndsWith("/maintenance.php", StringComparison.Ordinal))
            return false;
        if (uri.Scheme == Uri.UriSchemeHttps) return true;
        return uri.Scheme == Uri.UriSchemeHttp &&
            uri.Host == "127.0.0.1" &&
            Environment.GetEnvironmentVariable("GITHUB_ACTIONS") == "true" &&
            Environment.GetEnvironmentVariable("RUNNER_OS") == "Windows";
    }
    private static bool ValidHex(string? value, int count) =>
        value is not null && value.Length == count &&
        value.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
    private static bool ValidBase64(string? value)
    {
        if (string.IsNullOrEmpty(value) || value.Length > 256) return false;
        return Convert.TryFromBase64String(value,
            new Span<byte>(new byte[256]), out var length) && length is >= 68 and <= 72;
    }
}
