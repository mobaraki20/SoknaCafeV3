using System.Text;
using System.Text.Json;

namespace Sokna.Maintenance.Core;

public static class AtomicFile
{
    public static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true
    };

    public static void WriteJson<T>(string path, T value)
    {
        var directory = Path.GetDirectoryName(path) ?? throw new InvalidOperationException("Path has no directory.");
        Directory.CreateDirectory(directory);
        var temp = path + ".tmp." + Guid.NewGuid().ToString("N");
        var json = JsonSerializer.Serialize(value, JsonOptions);
        using (var stream = new FileStream(temp, FileMode.CreateNew, FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough))
        using (var writer = new StreamWriter(stream, new UTF8Encoding(false)))
        {
            writer.Write(json);
            writer.Flush();
            stream.Flush(flushToDisk: true);
        }

        File.Move(temp, path, overwrite: true);

        if (Path.GetFileName(path).Equals("control-plane.json", StringComparison.OrdinalIgnoreCase))
        {
            var paths = new MaintenancePaths(directory);
            WebAdapterAclProvisioner.ReconcileDescriptorAccess(paths);
        }
    }

    public static T ReadJson<T>(string path)
    {
        var json = File.ReadAllText(path, Encoding.UTF8);
        return JsonSerializer.Deserialize<T>(json, JsonOptions)
               ?? throw new InvalidDataException($"Unable to deserialize {path}.");
    }
}
