using System.Text;
using System.Text.Json;

namespace Sokna.Maintenance.Core;

public sealed class DurableJournal
{
    private readonly object _gate = new();
    private readonly string _path;
    private long _sequence;

    public DurableJournal(string path)
    {
        _path = path;
        Directory.CreateDirectory(Path.GetDirectoryName(path) ?? throw new InvalidOperationException("Journal path has no directory."));
        _sequence = ReadLastSequence(path);
    }

    public long LastSequence => Interlocked.Read(ref _sequence);

    public JournalEntry Append(string eventType, string instanceId, long installGeneration, IReadOnlyDictionary<string, object?>? details = null)
    {
        lock (_gate)
        {
            var entry = new JournalEntry(
                Sequence: ++_sequence,
                EventId: Guid.NewGuid().ToString("N"),
                EventType: eventType,
                OccurredAtUtc: DateTimeOffset.UtcNow,
                InstanceId: instanceId,
                InstallGeneration: installGeneration,
                Details: details ?? new Dictionary<string, object?>());

            var line = JsonSerializer.Serialize(entry) + Environment.NewLine;
            using var stream = new FileStream(_path, FileMode.Append, FileAccess.Write, FileShare.Read, 4096, FileOptions.WriteThrough);
            var bytes = Encoding.UTF8.GetBytes(line);
            stream.Write(bytes, 0, bytes.Length);
            stream.Flush(flushToDisk: true);
            return entry;
        }
    }

    private static long ReadLastSequence(string path)
    {
        if (!File.Exists(path)) return 0;
        long last = 0;
        foreach (var line in File.ReadLines(path, Encoding.UTF8))
        {
            if (string.IsNullOrWhiteSpace(line)) continue;
            try
            {
                using var doc = JsonDocument.Parse(line);
                if (doc.RootElement.TryGetProperty("sequence", out var sequence) && sequence.TryGetInt64(out var value))
                {
                    last = Math.Max(last, value);
                }
            }
            catch (JsonException)
            {
                throw new InvalidDataException("Maintenance journal contains an invalid JSONL entry.");
            }
        }

        return last;
    }
}
