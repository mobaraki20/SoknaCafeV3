using System.Text.Json;
using System.Text.Json.Nodes;

namespace Sokna.Maintenance.Core;

/// <summary>
/// A Local Web installation binding is bound to a *specific* Web build.  The
/// current slot and the machine-persistent snapshot are immutable during a
/// routine cross-version activation; only the inactive target slot is rebound.
/// </summary>
public static class RoutineLocalBinding
{
    private const string BindingFile = ".sokna-installation.json";
    private const string BindingFormat = "sokna-local-installation-binding-v1";
    private const string LockFormat = "sokna-install-lock-v3";

    public static void PrepareTarget(string persistentRoot, string targetSlot, string packageRoot,
        string currentBuild, string targetBuild)
    {
        var sourcePath = Path.Combine(persistentRoot, BindingFile);
        var targetPath = Path.Combine(targetSlot, BindingFile);
        // Persistent is an installation snapshot, deliberately unchanged by
        // cutover. After A -> B it can still name A. Version authority is the
        // active Web binding, while BOTH bindings must match install.lock.
        var persistent = RequireBinding(sourcePath, packageRoot);
        var source = RequireBinding(Path.Combine(packageRoot, "Web", BindingFile),
            packageRoot, currentBuild);
        if (!string.Equals(Id(persistent), Id(source), StringComparison.Ordinal))
            throw new InvalidDataException("ROUTINE_ACTIVE_PERSISTENT_IDENTITY_MISMATCH");
        if (string.Equals(currentBuild, targetBuild, StringComparison.Ordinal))
        {
            File.Copy(Path.Combine(packageRoot, "Web", BindingFile), targetPath, overwrite: true);
            RequireTarget(persistentRoot, targetSlot, packageRoot, targetBuild);
            return;
        }
        // Do not modify persistentRoot or the previous release; only the
        // inactive target gets the new, version-specific binding.
        var target = (JsonObject)source.DeepClone();
        target["web_version"] = targetBuild;
        target["source"] = "maintenance-routine-slot-activation";
        target["bound_at"] = DateTimeOffset.UtcNow.ToString("O");
        var bytes = JsonSerializer.SerializeToUtf8Bytes(target, new JsonSerializerOptions { WriteIndented = true });
        var temp = targetPath + ".new-" + Guid.NewGuid().ToString("N");
        try
        {
            File.WriteAllBytes(temp, bytes);
            File.Move(temp, targetPath, overwrite: true);
        }
        finally { if (File.Exists(temp)) File.Delete(temp); }
        RequireTarget(persistentRoot, targetSlot, packageRoot, targetBuild);
    }

    public static void RequireTarget(string persistentRoot, string targetSlot, string packageRoot,
        string targetBuild)
    {
        var source = RequireBinding(Path.Combine(persistentRoot, BindingFile), packageRoot);
        var target = RequireBinding(Path.Combine(targetSlot, BindingFile), packageRoot, targetBuild);
        if (!string.Equals(Id(source), Id(target), StringComparison.Ordinal))
            throw new InvalidDataException("Target binding installation identity changed during routine activation.");
    }

    public static void RequireCurrent(string webRoot, string packageRoot, string currentBuild)
        => _ = RequireBinding(Path.Combine(webRoot, BindingFile), packageRoot, currentBuild);

    /// <summary>
    /// Rebind ONLY the installation's machine-local version marker after a
    /// separately verified exact physical tree + unchanged DB schema. An
    /// arbitrary ZIP or unverified manual copy can never call this as an install
    /// authority. Both bindings must still match install.lock.
    /// </summary>
    public static void AdoptVerifiedExternalBuild(string persistentRoot, string activeSlot,
        string packageRoot, string previousBuild, string provenBuild)
    {
        var persistent = RequireBinding(Path.Combine(persistentRoot, BindingFile), packageRoot);
        var path = Path.Combine(activeSlot, BindingFile);
        var source = RequireBinding(path, packageRoot);
        if (!string.Equals(Id(persistent), Id(source), StringComparison.Ordinal))
            throw new InvalidDataException("LOCAL_REBIND_INSTALLATION_ID_MISMATCH");
        var current = (string?)source["web_version"];
        if (string.Equals(current, provenBuild, StringComparison.Ordinal))
            return; // idempotent recovery after interrupted identity write
        if (!string.Equals(current, previousBuild, StringComparison.Ordinal))
            throw new InvalidDataException("LOCAL_REBIND_UNEXPECTED_ACTIVE_BINDING_VERSION");

        var target = (JsonObject)source.DeepClone();
        target["web_version"] = provenBuild;
        target["source"] = "maintenance-verified-external-code-only-rebind";
        target["bound_at"] = DateTimeOffset.UtcNow.ToString("O");
        var bytes = JsonSerializer.SerializeToUtf8Bytes(target, new JsonSerializerOptions { WriteIndented = true });
        var tmp = path + ".new-" + Guid.NewGuid().ToString("N");
        try
        {
            File.WriteAllBytes(tmp, bytes);
            File.Move(tmp, path, overwrite: true);
        }
        finally { if (File.Exists(tmp)) File.Delete(tmp); }
        RequireTarget(persistentRoot, activeSlot, packageRoot, provenBuild);
    }

    private static string Id(JsonObject o) => (string?)o["installation_id"] ?? string.Empty;

    private static JsonObject RequireBinding(string path, string packageRoot, string? expectedBuild = null)
    {
        if (!File.Exists(path) || (File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("Routine Local installation binding is missing or redirected.");
        JsonObject o;
        try { o = JsonNode.Parse(File.ReadAllText(path)) as JsonObject ?? throw new JsonException(); }
        catch (JsonException e) { throw new InvalidDataException("Routine Local installation binding JSON is invalid.", e); }
        if ((string?)o["format"] != BindingFormat || string.IsNullOrWhiteSpace(Id(o)))
            throw new InvalidDataException("Routine Local binding identity/format is invalid.");
        if (string.IsNullOrWhiteSpace((string?)o["web_version"]))
            throw new InvalidDataException("Routine Local binding has no Web build identity.");
        if (expectedBuild != null && !string.Equals((string?)o["web_version"], expectedBuild, StringComparison.Ordinal))
            throw new InvalidDataException("Routine Local binding web_version does not match the expected build.");
        var lockPath = Path.Combine(packageRoot, "install.lock");
        if (!File.Exists(lockPath) || (File.GetAttributes(lockPath) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("Routine Local install.lock is missing or redirected.");
        try
        {
            using var lockDoc = JsonDocument.Parse(File.ReadAllText(lockPath));
            var root = lockDoc.RootElement;
            if (root.GetProperty("format").GetString() != LockFormat ||
                root.GetProperty("installation_id").GetString() != Id(o))
                throw new InvalidDataException("Routine Local binding does not match the install lock.");
        }
        catch (Exception e) when (e is JsonException or KeyNotFoundException or InvalidOperationException)
        {
            throw new InvalidDataException("Routine Local install lock is invalid.", e);
        }
        return o;
    }
}
