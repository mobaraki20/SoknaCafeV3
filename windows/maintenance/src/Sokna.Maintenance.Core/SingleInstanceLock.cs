using System.Text;

namespace Sokna.Maintenance.Core;

public sealed class SingleInstanceLock : IDisposable
{
    private readonly FileStream _stream;

    private SingleInstanceLock(FileStream stream) => _stream = stream;

    public static SingleInstanceLock Acquire(string path)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path) ?? throw new InvalidOperationException("Lock path has no directory."));
        try
        {
            var stream = new FileStream(path, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None, 1, FileOptions.WriteThrough);
            stream.SetLength(0);
            using var writer = new StreamWriter(stream, Encoding.UTF8, 1024, leaveOpen: true);
            writer.Write($"pid={Environment.ProcessId};started={DateTimeOffset.UtcNow:O}");
            writer.Flush();
            stream.Flush(flushToDisk: true);
            stream.Position = 0;
            return new SingleInstanceLock(stream);
        }
        catch (IOException ex)
        {
            throw new InvalidOperationException("Maintenance Supervisor data root is already owned by another process.", ex);
        }
    }

    public void Dispose() => _stream.Dispose();
}
