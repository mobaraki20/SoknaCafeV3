using System.Security.Cryptography;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

// Transport only. Authentication/ACL/HMAC/nonce are enforced by Supervisor.
// This does not verify release signature or authorize Stage/Activate.
public sealed record LocalInboxChunkRequest(
    [property: JsonPropertyName("upload_id")] string UploadId,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("expected_sha256")] string ExpectedSha256,
    [property: JsonPropertyName("total_bytes")] long TotalBytes,
    [property: JsonPropertyName("offset")] long Offset,
    [property: JsonPropertyName("chunk_base64")] string ChunkBase64);

public sealed record LocalInboxUploadState(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("upload_id")] string UploadId,
    [property: JsonPropertyName("operator_id")] string OperatorId,
    [property: JsonPropertyName("expected_sha256")] string ExpectedSha256,
    [property: JsonPropertyName("total_bytes")] long TotalBytes);

public sealed record LocalInboxChunkOutcome(
    [property: JsonPropertyName("upload_id")] string UploadId,
    [property: JsonPropertyName("received_bytes")] long ReceivedBytes,
    [property: JsonPropertyName("complete")] bool Complete,
    [property: JsonPropertyName("package_ref")] string? PackageRef,
    [property: JsonPropertyName("content_sha256_verified")] bool ContentSha256Verified,
    [property: JsonPropertyName("release_signature_verified")] bool ReleaseSignatureVerified,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady);

// A Windows-owned, content-addressed Inbox boundary. Web PHP can send signed
// chunks but never selects a destination path or writes to Windows ProgramData.
// The canonical signed Preflight remains mandatory before any Stage/Activate.
public sealed class LocalInboxUploadCoordinator
{
    private const long MaxPackageBytes = 268435456;
    private const int MaxChunkBytes = 196608;
    private readonly MaintenancePaths _paths;

    public LocalInboxUploadCoordinator(MaintenancePaths paths) => _paths = paths;

    public LocalInboxChunkOutcome Append(LocalInboxChunkRequest request)
    {
        if (request is null || request.UploadId is not { Length: 32 } ||
            !request.UploadId.All(char.IsAsciiHexDigit) ||
            request.ExpectedSha256 is not { Length: 64 } ||
            !request.ExpectedSha256.All(char.IsAsciiHexDigit) ||
            string.IsNullOrEmpty(request.OperatorId) || request.OperatorId.Length > 128 ||
            request.OperatorId.Any(ch => !(char.IsLetterOrDigit(ch) || ch is '-' or '_' or '.' or ':')) ||
            request.TotalBytes is < 128 or > MaxPackageBytes ||
            request.Offset < 0 || request.Offset >= request.TotalBytes ||
            request.ChunkBase64 is null ||
            request.ChunkBase64.Length < 4 || request.ChunkBase64.Length > 4 * ((MaxChunkBytes + 2) / 3))
            throw new InvalidDataException("INBOX_UPLOAD_REQUEST_INVALID");

        byte[] bytes;
        try { bytes = Convert.FromBase64String(request.ChunkBase64); }
        catch (FormatException e) { throw new InvalidDataException("INBOX_CHUNK_BASE64_INVALID", e); }
        if (bytes.Length is < 1 or > MaxChunkBytes ||
            bytes.Length > request.TotalBytes - request.Offset)
            throw new InvalidDataException("INBOX_CHUNK_LENGTH_INVALID");

        lock (LocalUpdateOperationGate.Sync)
        {
            using var lease = MachineMutationLease.Acquire(_paths, "supervisor-inbox-upload");
            var inbox = Path.GetFullPath(_paths.InboxRoot);
            var incoming = Path.Combine(inbox, "incoming");
            if (!Directory.Exists(inbox) ||
                (File.GetAttributes(inbox) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("INBOX_DIRECTORY_UNSAFE");
            Directory.CreateDirectory(incoming);
            if ((File.GetAttributes(incoming) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("INBOX_INCOMING_UNSAFE");
            var uploadId = request.UploadId.ToLowerInvariant();
            var expectedHash = request.ExpectedSha256.ToLowerInvariant();
            var metadataPath = Path.Combine(incoming, uploadId + ".json");
            var partPath = Path.Combine(incoming, uploadId + ".part");
            var finalRef = expectedHash + ".zip";
            var finalPath = Path.Combine(inbox, finalRef);
            foreach (var path in new[] { metadataPath, partPath, finalPath })
            {
                if (File.Exists(path) && (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
                    throw new InvalidDataException("INBOX_UPLOAD_REPARSE_FORBIDDEN");
            }

            if (File.Exists(metadataPath))
            {
                var old = AtomicFile.ReadJson<LocalInboxUploadState>(metadataPath);
                if (old.Format != "sokna-local-inbox-upload-v1" || old.UploadId != uploadId ||
                    old.OperatorId != request.OperatorId || old.ExpectedSha256 != expectedHash ||
                    old.TotalBytes != request.TotalBytes)
                    throw new InvalidDataException("INBOX_UPLOAD_IDENTITY_CONFLICT");
            }
            else
            {
                if (File.Exists(partPath) || File.Exists(finalPath))
                    throw new InvalidDataException("INBOX_UPLOAD_STATE_MISSING");
                AtomicFile.WriteJson(metadataPath, new LocalInboxUploadState(
                    "sokna-local-inbox-upload-v1", uploadId, request.OperatorId,
                    expectedHash, request.TotalBytes));
            }

            if (File.Exists(finalPath))
            {
                RequireContentHash(finalPath, expectedHash, request.TotalBytes);
                return new LocalInboxChunkOutcome(uploadId, request.TotalBytes, true,
                    finalRef, true, false, false);
            }
            using (var stream = new FileStream(partPath, FileMode.OpenOrCreate,
                       FileAccess.ReadWrite, FileShare.None, 65536, FileOptions.WriteThrough))
            {
                if (stream.Length > request.TotalBytes)
                    throw new InvalidDataException("INBOX_UPLOAD_OVERSIZED");
                if (request.Offset < stream.Length)
                {
                    // Lost HTTP response: replay is read-only if bytes already match.
                    if (request.Offset + bytes.Length > stream.Length)
                        throw new InvalidDataException("INBOX_UPLOAD_OFFSET_CONFLICT");
                    stream.Position = request.Offset;
                    var existing = new byte[bytes.Length];
                    stream.ReadExactly(existing);
                    if (!CryptographicOperations.FixedTimeEquals(existing, bytes))
                        throw new InvalidDataException("INBOX_UPLOAD_CHUNK_MISMATCH");
                }
                else if (request.Offset == stream.Length)
                {
                    stream.Position = stream.Length;
                    stream.Write(bytes);
                    stream.Flush(flushToDisk: true);
                }
                else
                    throw new InvalidDataException("INBOX_UPLOAD_OFFSET_CONFLICT");
                if (stream.Length < request.TotalBytes)
                    return new LocalInboxChunkOutcome(uploadId, stream.Length, false,
                        null, false, false, false);
            }

            // No completed file is placed in the trusted Preflight inbox until
            // the entire byte stream matches the caller's content address.
            RequireContentHash(partPath, expectedHash, request.TotalBytes);
            using (var input = File.OpenRead(partPath))
            {
                Span<byte> magic = stackalloc byte[4];
                input.ReadExactly(magic);
                if (magic[0] != 0x50 || magic[1] != 0x4b ||
                    magic[2] != 0x03 || magic[3] != 0x04)
                    throw new InvalidDataException("INBOX_NOT_ZIP");
            }
            File.Move(partPath, finalPath);
            return new LocalInboxChunkOutcome(uploadId, request.TotalBytes, true,
                finalRef, true, false, false);
        }
    }

    private static void RequireContentHash(string path, string expectedHash, long expectedLength)
    {
        using var input = new FileStream(path, FileMode.Open, FileAccess.Read,
            FileShare.Read, 65536, FileOptions.SequentialScan);
        if (input.Length != expectedLength)
            throw new InvalidDataException("INBOX_PACKAGE_LENGTH_MISMATCH");
        var actual = Convert.ToHexString(SHA256.HashData(input)).ToLowerInvariant();
        if (!CryptographicOperations.FixedTimeEquals(Convert.FromHexString(expectedHash),
                                                      Convert.FromHexString(actual)))
            throw new InvalidDataException("INBOX_PACKAGE_HASH_MISMATCH");
    }
}
