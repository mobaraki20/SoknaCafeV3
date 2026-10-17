using System.Net;
using System.Net.Sockets;
using System.ServiceProcess;
using System.Text;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.FakeApache;

internal static class Program
{
    public static void Main(string[] args)
    {
        if (args.Any(a => string.Equals(a, "-V", StringComparison.OrdinalIgnoreCase)))
        {
            var root = Directory.GetParent(AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar))?.FullName
                       ?? throw new InvalidOperationException("Fake Apache root could not be resolved.");
            Console.WriteLine($"-D HTTPD_ROOT=\"{root}\"");
            Console.WriteLine("-D SERVER_CONFIG_FILE=\"conf/httpd.conf\"");
            return;
        }

        ServiceBase.Run(new FakeApacheService());
    }
}

internal sealed class FakeApacheService : ServiceBase
{
    private CancellationTokenSource? _cts;
    private TcpListener? _listener;
    private Task? _loop;
    private string _documentRoot = string.Empty;
    private int _port;

    public FakeApacheService()
    {
        ServiceName = "SoknaApache";
        CanStop = true;
        AutoLog = false;
    }

    protected override void OnStart(string[] args)
    {
        var root = Directory.GetParent(AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar))?.FullName
                   ?? throw new InvalidOperationException("Fake Apache root could not be resolved.");
        var managed = Path.Combine(root, "conf", "sokna-local.conf");
        var text = File.ReadAllText(managed);
        var portMatch = Regex.Match(text, @"(?im)<VirtualHost\s+\*:(?<port>\d{2,5})\s*>");
        var docMatch = Regex.Match(text, "(?im)^\\s*DocumentRoot\\s+\\\"(?<path>[^\\\"]+)\\\"\\s*$");
        if (!portMatch.Success || !int.TryParse(portMatch.Groups["port"].Value, out _port))
            throw new InvalidDataException("Fake Apache fixture port is invalid.");
        if (!docMatch.Success) throw new InvalidDataException("Fake Apache fixture DocumentRoot is invalid.");
        _documentRoot = Path.GetFullPath(docMatch.Groups["path"].Value.Replace('/', Path.DirectorySeparatorChar));

        _cts = new CancellationTokenSource();
        _listener = new TcpListener(IPAddress.Loopback, _port);
        _listener.Start();
        _loop = Task.Run(() => ServeAsync(_cts.Token));
    }

    protected override void OnStop()
    {
        try { _cts?.Cancel(); } catch { }
        try { _listener?.Stop(); } catch { }
        try { _loop?.Wait(TimeSpan.FromSeconds(3)); } catch { }
        _loop = null;
        _listener = null;
        _cts?.Dispose();
        _cts = null;
    }

    private async Task ServeAsync(CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            TcpClient client;
            try
            {
                client = await _listener!.AcceptTcpClientAsync(cancellationToken);
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (ObjectDisposedException)
            {
                break;
            }

            _ = Task.Run(() => HandleAsync(client, cancellationToken), cancellationToken);
        }
    }

    private async Task HandleAsync(TcpClient client, CancellationToken cancellationToken)
    {
        using (client)
        using (var stream = client.GetStream())
        using (var reader = new StreamReader(stream, Encoding.ASCII, false, 1024, leaveOpen: true))
        {
            try
            {
                var requestLine = await reader.ReadLineAsync(cancellationToken) ?? string.Empty;
                string? line;
                do
                {
                    line = await reader.ReadLineAsync(cancellationToken);
                } while (!string.IsNullOrEmpty(line));

                var versionPath = Path.Combine(Directory.GetParent(_documentRoot)?.FullName ?? string.Empty, "VERSION.txt");
                var build = File.Exists(versionPath) ? File.ReadAllText(versionPath).Trim() : "missing";
                var ok = requestLine.Contains("/login.php", StringComparison.OrdinalIgnoreCase) && build != "missing";
                var body = ok ? "OK" : "NO";
                var status = ok ? "200 OK" : "503 Service Unavailable";
                var response = new StringBuilder()
                    .Append("HTTP/1.1 ").Append(status).Append("\r\n")
                    .Append("Content-Type: text/plain\r\n")
                    .Append("Content-Length: ").Append(Encoding.ASCII.GetByteCount(body)).Append("\r\n")
                    .Append("Connection: close\r\n")
                    .Append("X-Sokna-Build: ").Append(build).Append("\r\n")
                    .Append("X-Sokna-Runtime: php-ready\r\n")
                    .Append("\r\n")
                    .Append(body)
                    .ToString();
                var bytes = Encoding.ASCII.GetBytes(response);
                await stream.WriteAsync(bytes, cancellationToken);
                await stream.FlushAsync(cancellationToken);
            }
            catch
            {
                // Test fixture intentionally ignores client-side disconnects.
            }
        }
    }
}
