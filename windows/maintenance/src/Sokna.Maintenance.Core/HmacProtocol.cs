using System.Globalization;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;

namespace Sokna.Maintenance.Core;

public static class HmacProtocol
{
    public const string Protocol = "1.0";
    public const string HeaderProtocol = "X-Sokna-Maintenance-Protocol";
    public const string HeaderTimestamp = "X-Sokna-Timestamp";
    public const string HeaderNonce = "X-Sokna-Nonce";
    public const string HeaderKeyId = "X-Sokna-Key-Id";
    public const string HeaderBodySha256 = "X-Sokna-Body-SHA256";
    public const string HeaderSignature = "X-Sokna-Signature";
    public static readonly TimeSpan AllowedClockSkew = TimeSpan.FromSeconds(90);

    public static string BodySha256(ReadOnlySpan<byte> body) => Convert.ToHexString(SHA256.HashData(body)).ToLowerInvariant();

    public static string BuildCanonical(string method, string pathAndQuery, string protocol, string timestamp, string nonce, string bodySha256)
        => string.Join('\n', method.ToUpperInvariant(), pathAndQuery, protocol, timestamp, nonce, bodySha256);

    public static string Sign(byte[] key, string canonical)
    {
        using var hmac = new HMACSHA256(key);
        return Convert.ToHexString(hmac.ComputeHash(Encoding.UTF8.GetBytes(canonical))).ToLowerInvariant();
    }

    public static bool FixedTimeEqualsHex(string expectedLowerHex, string actualHex)
    {
        try
        {
            var expected = Convert.FromHexString(expectedLowerHex);
            var actual = Convert.FromHexString(actualHex);
            return expected.Length == actual.Length && CryptographicOperations.FixedTimeEquals(expected, actual);
        }
        catch (FormatException)
        {
            return false;
        }
    }

    public static bool TryParseTimestamp(string value, out DateTimeOffset timestamp)
        => DateTimeOffset.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out timestamp);

    public static HttpRequestMessage CreateSignedGet(Uri baseUri, string relativePath, string keyId, byte[] key, string? nonce = null, string? timestamp = null)
    {
        var request = new HttpRequestMessage(HttpMethod.Get, new Uri(baseUri, relativePath));
        var ts = timestamp ?? DateTimeOffset.UtcNow.ToString("O", CultureInfo.InvariantCulture);
        var n = nonce ?? Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        var bodyHash = BodySha256(ReadOnlySpan<byte>.Empty);
        var pathAndQuery = request.RequestUri!.PathAndQuery;
        var canonical = BuildCanonical("GET", pathAndQuery, Protocol, ts, n, bodyHash);
        request.Headers.TryAddWithoutValidation(HeaderProtocol, Protocol);
        request.Headers.TryAddWithoutValidation(HeaderTimestamp, ts);
        request.Headers.TryAddWithoutValidation(HeaderNonce, n);
        request.Headers.TryAddWithoutValidation(HeaderKeyId, keyId);
        request.Headers.TryAddWithoutValidation(HeaderBodySha256, bodyHash);
        request.Headers.TryAddWithoutValidation(HeaderSignature, Sign(key, canonical));
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        return request;
    }
}
