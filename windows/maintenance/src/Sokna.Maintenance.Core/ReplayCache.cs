using System.Collections.Concurrent;

namespace Sokna.Maintenance.Core;

public sealed class ReplayCache
{
    private readonly ConcurrentDictionary<string, DateTimeOffset> _seen = new(StringComparer.Ordinal);

    public bool TryAccept(string keyId, string nonce, DateTimeOffset now)
    {
        foreach (var entry in _seen)
        {
            if (entry.Value <= now) _seen.TryRemove(entry.Key, out _);
        }
        var composite = keyId + ":" + nonce;
        return _seen.TryAdd(composite, now + HmacProtocol.AllowedClockSkew + HmacProtocol.AllowedClockSkew);
    }
}
