using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

/// <summary>
/// F1-S7E5A REAL loopback HTTP/crypto transport on a disposable Windows
/// GitHub Runner. The server is a synthetic independent Public fixture,
/// NOT the actual Public Maintenance Kernel / production host.
/// </summary>
internal static class PublicKernelWireAttestationProbe
{
    private const string Installation = "ci-public-installation-001";
    private const string KeyId = "ci-public-signing-key-001";
    private static readonly string HexHash = new('a', 64);

    internal static int Run()
    {
        if (!OperatingSystem.IsWindows() ||
            Environment.GetEnvironmentVariable("GITHUB_ACTIONS") != "true" ||
            Environment.GetEnvironmentVariable("RUNNER_OS") != "Windows")
            throw new InvalidDataException("S7E5A_WINDOWS_CI_ONLY");

        using var signing = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var wrong = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var pinnedSpki = signing.ExportSubjectPublicKeyInfo();
        PublicKernelSignedObservation? observed = null;

        using (var listener = new TcpListener(IPAddress.Loopback, 0))
        {
            listener.Start();
            var port = ((IPEndPoint)listener.LocalEndpoint).Port;
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(12));
            var server = Task.Run(async () =>
            {
                using var client = await listener.AcceptTcpClientAsync(cts.Token);
                await using var net = client.GetStream();
                using var reader = new StreamReader(net, Encoding.ASCII,
                    detectEncodingFromByteOrderMarks: false, bufferSize: 4096, leaveOpen: true);
                var first = await reader.ReadLineAsync(cts.Token);
                Check(first is not null &&
                    first.StartsWith("GET /v1/maintenance/attest HTTP/1.", StringComparison.Ordinal),
                    "unexpected HTTP route");
                string? nonce = null;
                string? requestId = null;
                while (true)
                {
                    var line = await reader.ReadLineAsync(cts.Token);
                    if (string.IsNullOrEmpty(line)) break;
                    if (line.StartsWith("X-Sokna-Nonce:", StringComparison.OrdinalIgnoreCase))
                        nonce = line[(line.IndexOf(':') + 1)..].Trim();
                    if (line.StartsWith("X-Sokna-Request-Id:", StringComparison.OrdinalIgnoreCase))
                        requestId = line[(line.IndexOf(':') + 1)..].Trim();
                }
                Check(nonce is {Length:32} && requestId is {Length:32},
                    "no challenge/request on independent HTTP server");
                observed = Sign(signing, Make(nonce, requestId));
                var body = JsonSerializer.SerializeToUtf8Bytes(observed);
                var header = Encoding.ASCII.GetBytes(
                    $"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nCache-Control: no-store\r\nContent-Length: {body.Length}\r\nConnection: close\r\n\r\n");
                await net.WriteAsync(header, cts.Token);
                await net.WriteAsync(body, cts.Token);
                await net.FlushAsync(cts.Token);
            }, cts.Token);
            var endpoint = new Uri($"http://127.0.0.1:{port}/v1/maintenance/attest");
            var proof = PublicKernelSignedReceiptInspector.ProbeAsync(
                endpoint, pinnedSpki, KeyId, Installation, 7, cts.Token)
                .GetAwaiter().GetResult();
            server.GetAwaiter().GetResult();
            Check(proof.KernelHealthy && !proof.ApplicationHealthy &&
                  proof.AuthorityEpoch == 8 && proof.ReleaseSequence == 29 &&
                  !proof.ProductionCutoverAuthorized && !proof.PanelUpdateReady &&
                  !proof.PublicKernelIntegrated,
                "signed observation incorrectly authorized Public deployment");
        }

        var baseReceipt = observed ?? throw new InvalidDataException("S7E5A_MISSING_WIRE_RECEIPT");
        var now = DateTimeOffset.UtcNow;
        var valid = PublicKernelSignedReceiptInspector.Verify(
            baseReceipt, pinnedSpki, KeyId, baseReceipt.Nonce, baseReceipt.RequestId,
            Installation, 7, now);
        Check(valid.AuthorityEpoch == 8, "local epoch floor ignored");

        // Actual signature mismatch / untrusted key (not mocked HMAC).
        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt, wrong.ExportSubjectPublicKeyInfo(), KeyId,
            baseReceipt.Nonce, baseReceipt.RequestId, Installation, 7, now),
            "attacker-controlled signing key");

        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt, pinnedSpki, KeyId, new string('f', 32),
            baseReceipt.RequestId, Installation, 7, now), "replayed nonce");
        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt, pinnedSpki, KeyId, baseReceipt.Nonce,
            new string('f', 32), Installation, 7, now), "request replay");
        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt, pinnedSpki, KeyId, baseReceipt.Nonce,
            baseReceipt.RequestId, "other-installation", 7, now),
            "unbound installation identity");
        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt, pinnedSpki, KeyId, baseReceipt.Nonce,
            baseReceipt.RequestId, Installation, 9, now),
            "authority epoch downgrade");

        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt with {ActiveTreeSha256 = new string('b',64)},
            pinnedSpki, KeyId, baseReceipt.Nonce, baseReceipt.RequestId,
            Installation, 7, now), "modified signed tree");

        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            baseReceipt with { ExecutionAuthorized = true },
            pinnedSpki, KeyId, baseReceipt.Nonce, baseReceipt.RequestId,
            Installation, 7, now), "authorization field injection");

        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            Sign(signing, baseReceipt with {KernelHealth="unavailable"}),
            pinnedSpki, KeyId, baseReceipt.Nonce, baseReceipt.RequestId,
            Installation, 7, now), "signed unhealthy kernel");

        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            Sign(signing, baseReceipt with {
                IssuedUtcTicks = now.AddMinutes(-3).UtcTicks,
                ExpiresUtcTicks = now.AddMinutes(-2).UtcTicks
            }), pinnedSpki, KeyId, baseReceipt.Nonce, baseReceipt.RequestId,
            Installation, 7, now), "authentic but stale signed receipt");

        Reject(() => PublicKernelSignedReceiptInspector.Verify(
            Sign(signing, baseReceipt with {KeyId = "rotated-key-not-pinned"}),
            pinnedSpki, KeyId, baseReceipt.Nonce, baseReceipt.RequestId,
            Installation, 7, now), "unapproved key rotation");

        Reject(() => PublicKernelSignedReceiptInspector.ProbeAsync(
            new Uri("http://example.com/v1/maintenance/attest"),
            pinnedSpki, KeyId, Installation, 7).GetAwaiter().GetResult(),
            "remote HTTP downgrade");
        Reject(() => PublicKernelSignedReceiptInspector.ProbeAsync(
            new Uri("https://example.com/untrusted/health"),
            pinnedSpki, KeyId, Installation, 7).GetAwaiter().GetResult(),
            "business/cached health endpoint");

        Console.WriteLine(JsonSerializer.Serialize(new {
            format = "sokna-f1-s7e5a-public-signed-loopback-wire-v1",
            result = "PASS",
            actual_loopback_http_request = true,
            pinned_ecdsa_p256_signature = true,
            independent_public_fixture_not_real_kernel = true,
            stale_and_replayed_receipts_rejected = true,
            wrong_key_and_wrong_installation_rejected = true,
            authority_epoch_downgrade_rejected = true,
            tampered_tree_and_unhealthy_kernel_rejected = true,
            http_remote_downgrade_rejected = true,
            public_source_or_host_modified = false,
            public_kernel_integrated = false,
            public_kernel_real_uat = false,
            production_cutover_authorized = false,
            panel_update_ready = false
        }));
        return 0;
    }

    private static PublicKernelSignedObservation Make(string nonce, string requestId)
    {
        var now = DateTimeOffset.UtcNow;
        return new(
            PublicKernelSignedReceiptInspector.Format, KeyId, nonce, requestId,
            now.UtcTicks, now.AddSeconds(30).UtcTicks, Installation,
            8, 4, "public-ci-release-004", 29, HexHash,
            new string('c',64), "2.0", "healthy", "unavailable",
            "recovery", false, false, true, false, "");
    }
    private static PublicKernelSignedObservation Sign(ECDsa key,
        PublicKernelSignedObservation observation)
    {
        var data = PublicKernelSignedReceiptInspector.CanonicalSignedBytes(observation);
        var sig = key.SignData(data, HashAlgorithmName.SHA256);
        return observation with {Signature=Convert.ToBase64String(sig)};
    }
    private static void Check(bool condition, string reason)
    {
        if (!condition) throw new InvalidDataException("S7E5A_"+reason);
    }
    private static void Reject(Action action, string label)
    {
        try { action(); }
        catch (InvalidDataException) { return; }
        throw new InvalidDataException("S7E5A_FAIL_OPEN_"+label);
    }
}
