using System.Security.Cryptography;
using System.Diagnostics;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Sokna.Maintenance.Core;

/// <summary>Transactional bridge for the Local-owned active_version and managed inventory.
/// It only runs when the Local updater is idle, and retains byte-exact rollback evidence.</summary>
internal sealed class RoutineLocalLifecycle
{
    private readonly string _statePath;
    private readonly string _managedPath;
    private readonly string _currentBuild;
    private readonly byte[] _stateBefore;
    private readonly byte[]? _managedBefore;
    private readonly string _backupPath;

    private RoutineLocalLifecycle(string statePath, string managedPath, string currentBuild, string backupPath)
    {
        _statePath = statePath; _managedPath = managedPath; _currentBuild = currentBuild; _backupPath = backupPath;
        _stateBefore = File.ReadAllBytes(statePath);
        _managedBefore = File.Exists(managedPath) ? File.ReadAllBytes(managedPath) : null;
        RequireIdle();
    }

    public static RoutineLocalLifecycle Open(string packageRoot, string evidenceRoot, string currentBuild)
    {
        var lifeRoot = ResolveLifecycleRoot(packageRoot);
        if (File.Exists(Path.Combine(lifeRoot, "recovery-required.json")))
            throw new InvalidDataException("Local updater has a recovery-required marker.");
        var backupPath = Path.Combine(evidenceRoot, "routine-local-lifecycle-before.json");
        if (File.Exists(backupPath))
            throw new InvalidDataException("Prior routine lifecycle rollback evidence still exists; inspect/recover before a new activation.");
        return new RoutineLocalLifecycle(Path.Combine(lifeRoot, "state.json"),
            Path.Combine(lifeRoot, "managed-files.json"), currentBuild, backupPath);
    }

    private static string ResolveLifecycleRoot(string packageRoot)
    {
        var php = FindPhpCli(Path.Combine(packageRoot, "Infrastructure"));
        // This invokes only a fixed, read-only snippet; no user-supplied PHP code
        // or DB credentials are printed to the Windows maintenance log.
        const string script = "$c=require $argv[1].DIRECTORY_SEPARATOR.'config.php';"
            + "if(!is_array($c))exit(65);$d=trim((string)($c['app']['data_dir']??''));"
            + "if($d==='')exit(66);echo $d;";
        var psi = new ProcessStartInfo(php) { UseShellExecute = false, CreateNoWindow = true,
            RedirectStandardOutput = true, RedirectStandardError = true };
        psi.ArgumentList.Add("-r"); psi.ArgumentList.Add(script); psi.ArgumentList.Add(packageRoot);
        using var proc = Process.Start(psi) ?? throw new InvalidOperationException("Local PHP CLI could not start.");
        if (!proc.WaitForExit(8000)) { proc.Kill(entireProcessTree:true); throw new TimeoutException("Local data-root discovery timed out."); }
        var output = proc.StandardOutput.ReadToEnd().Trim();
        if (proc.ExitCode != 0 || output.Length == 0 || !Path.IsPathFullyQualified(output))
            throw new InvalidDataException("Local data-root discovery failed (CLI status " + proc.ExitCode + ").");
        var dataRoot = Path.GetFullPath(output);
        var lifeRoot = Path.Combine(dataRoot, "lifecycle", "local");
        return lifeRoot;
    }

    public static void RequireTargetSetupBoot(string packageRoot, string targetSlot)
    {
        var php = FindPhpCli(Path.Combine(packageRoot, "Infrastructure"));
        const string script = "require $argv[2].DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Setup'.DIRECTORY_SEPARATOR.'BrowserSetupService.php';"
            + @"$s=(new \Sokna\Local\Setup\BrowserSetupService($argv[1],$argv[2]))->status();"
            + "if(empty($s['installed'])||empty($s['binding_current']))exit(72);echo 'ready';";
        RunPhp(php, new[] { "-r", script, packageRoot, targetSlot }, "Target Local browser setup preflight");
        foreach (var rel in new[] { Path.Combine("public", "local-recovery.php"), Path.Combine("public", "local-update-runner.php") })
        {
            var path=Path.Combine(targetSlot,rel);
            if (File.Exists(path)) RunPhp(php, new[] { "-l", path }, "Machine-local PHP syntax preflight");
        }
    }

    public static void RequireUnchangedMigrationCatalog(string currentRoot, string targetSlot)
    {
        var current = Path.Combine(currentRoot, "database", "migrations");
        var target = Path.Combine(targetSlot, "database", "migrations");
        if (!Directory.Exists(current) || !Directory.Exists(target))
            throw new InvalidDataException("Local migration catalogs are missing.");
        var original = Directory.EnumerateFiles(current, "*.sql", SearchOption.TopDirectoryOnly)
            .ToDictionary(path => Path.GetFileName(path) ?? throw new InvalidDataException("Invalid migration filename."), StringComparer.Ordinal);
        var candidate = Directory.EnumerateFiles(target, "*.sql", SearchOption.TopDirectoryOnly)
            .ToDictionary(path => Path.GetFileName(path) ?? throw new InvalidDataException("Invalid migration filename."), StringComparer.Ordinal);
        if (original.Count==0 || original.Count!=candidate.Count || original.Keys.Except(candidate.Keys,StringComparer.Ordinal).Any())
            throw new InvalidDataException("Cross-version code-only Local activation cannot change migration catalog membership.");
        foreach (var name in original.Keys)
        {
            var source=SHA256.HashData(File.ReadAllBytes(original[name]));
            var next=SHA256.HashData(File.ReadAllBytes(candidate[name]));
            if (!source.SequenceEqual(next))
                throw new InvalidDataException("Cross-version code-only Local activation cannot change an existing migration.");
        }
    }

    private static void RunPhp(string php, IReadOnlyList<string> args, string what)
    {
        var psi=new ProcessStartInfo(php){UseShellExecute=false,CreateNoWindow=true,RedirectStandardOutput=true,RedirectStandardError=true};
        foreach(var a in args)psi.ArgumentList.Add(a);
        using var p=Process.Start(psi) ?? throw new InvalidOperationException(what+" could not start.");
        if (!p.WaitForExit(8000)) {p.Kill(entireProcessTree:true);throw new TimeoutException(what+" timed out.");}
        if (p.ExitCode!=0)throw new InvalidDataException(what+" failed (status "+p.ExitCode+").");
    }

    private static string FindPhpCli(string infrastructureRoot)
    {
        if (!Directory.Exists(infrastructureRoot)) throw new DirectoryNotFoundException("Local Infrastructure is missing.");
        var current = new Queue<(string path, int depth)>(); current.Enqueue((infrastructureRoot,0));
        while (current.Count > 0)
        {
            var (path,depth)=current.Dequeue();
            foreach (var file in Directory.EnumerateFiles(path,"php.exe",SearchOption.TopDirectoryOnly))
                if ((File.GetAttributes(file) & FileAttributes.ReparsePoint)==0) return file;
            if(depth>=7) continue;
            foreach(var dir in Directory.EnumerateDirectories(path))
                if ((File.GetAttributes(dir) & FileAttributes.ReparsePoint)==0) current.Enqueue((dir,depth+1));
        }
        throw new FileNotFoundException("Local PHP CLI cannot be found in Infrastructure.");
    }

    private static JsonObject ReadObject(byte[] raw)
    {
        try { return JsonNode.Parse(raw) as JsonObject ?? throw new JsonException(); }
        catch (JsonException e) { throw new InvalidDataException("Local lifecycle JSON is invalid.", e); }
    }

    public void RequireIdle()
    {
        var state = ReadObject(File.ReadAllBytes(_statePath));
        if ((string?)state["format"]!="sokna-local-update-state-v1" ||
            (string?)state["active_version"] != _currentBuild)
            throw new InvalidDataException("Local lifecycle version/format does not match the active Web release.");
        foreach (var field in new[]{"staged","activation_journal","recovery_mode"})
        {
            if (state[field] is JsonObject item && (field!="recovery_mode" || (bool?)item["required"]!=false) && item.Count>0)
                throw new InvalidDataException("Local lifecycle is not idle: "+field);
            if (state[field] is JsonArray arr && arr.Count>0)
                throw new InvalidDataException("Local lifecycle is not idle: "+field);
        }
        if (File.Exists(_statePath) && !File.ReadAllBytes(_statePath).SequenceEqual(_stateBefore))
            throw new InvalidDataException("Local lifecycle state drifted during cross-version activation.");
        if (_managedBefore!=null && !File.ReadAllBytes(_managedPath).SequenceEqual(_managedBefore))
            throw new InvalidDataException("Local managed inventory drifted during cross-version activation.");
    }

    public void SaveRollbackEvidence()
    {
        RequireIdle();
        Directory.CreateDirectory(Path.GetDirectoryName(_backupPath)!);
        var proof = new JsonObject { ["format"]="sokna-routine-local-lifecycle-backup-v1",
            ["active_build"]=_currentBuild, ["state_path"]=_statePath, ["managed_path"]=_managedPath,
            ["state_base64"]=Convert.ToBase64String(_stateBefore),
            ["managed_base64"]=_managedBefore == null ? null : Convert.ToBase64String(_managedBefore) };
        AtomicWrite(_backupPath,JsonSerializer.SerializeToUtf8Bytes(proof));
    }

    public void Commit(string targetBuild, string targetSlot, int targetManagedFiles)
    {
        if (!File.Exists(_backupPath)) throw new InvalidDataException("Local lifecycle rollback evidence is missing.");
        RequireIdle();
        var state = ReadObject(_stateBefore);
        state["previous_version"] = _currentBuild;
        state["active_version"] = targetBuild;
        state["staged"] = null; state["activation_journal"] = null;
        state["last_failure"] = null; state["recovery_mode"] = null;
        state["lkg_recovery_id"] = "";
        var history=state["history"] as JsonArray ?? new JsonArray();
        history.Add(new JsonObject { ["at"]=DateTimeOffset.UtcNow.ToString("O"),
            ["action"]="maintenance_routine_slot_activate", ["version"]=targetBuild,
            ["actor_user_id"]=0, ["migrations"]=new JsonArray() });
        while(history.Count>100)history.RemoveAt(0);
        if (state["history"] is null) state["history"]=history;
        var inv=ReadObject(File.ReadAllBytes(Path.Combine(targetSlot,"resources","local-managed-files-v1.json")));
        var files=inv["files"] as JsonArray;
        if ((string?)inv["format"]!="sokna-local-managed-files-v1" || files==null || files.Count!=targetManagedFiles)
            throw new InvalidDataException("Target managed inventory cannot be reconciled.");
        var managed = new JsonObject { ["format"]="sokna-local-managed-files-v1",
            ["schema_version"]=1,["updated_at"]=DateTimeOffset.UtcNow.ToString("O"),
            ["files"]=files.DeepClone() };
        AtomicWrite(_statePath,JsonSerializer.SerializeToUtf8Bytes(state));
        AtomicWrite(_managedPath,JsonSerializer.SerializeToUtf8Bytes(managed));
        var after=ReadObject(File.ReadAllBytes(_statePath));
        if ((string?)after["active_version"]!=targetBuild)
            throw new InvalidDataException("Local lifecycle target was not committed.");
    }

    public void Restore()
    {
        if (!File.Exists(_backupPath)) return;
        // This rollback evidence is written before the pointer is switched;
        // it remains usable after a process crash/restart.
        var proof=ReadObject(File.ReadAllBytes(_backupPath));
        if ((string?)proof["format"]!="sokna-routine-local-lifecycle-backup-v1" ||
            (string?)proof["active_build"]!=_currentBuild ||
            (string?)proof["state_path"]!=_statePath || (string?)proof["managed_path"]!=_managedPath)
            throw new InvalidDataException("Local lifecycle rollback evidence is inconsistent.");
        AtomicWrite(_statePath,Convert.FromBase64String((string?)proof["state_base64"]??""));
        if ((string?)proof["managed_base64"] is string m) AtomicWrite(_managedPath,Convert.FromBase64String(m));
        else if (File.Exists(_managedPath))File.Delete(_managedPath);
    }

    public void Complete() { if (File.Exists(_backupPath)) File.Delete(_backupPath); }

    public static void RestoreCrashBackup(string packageRoot, string evidenceRoot, string expectedPreviousBuild)
    {
        var backupPath = Path.Combine(evidenceRoot, "routine-local-lifecycle-before.json");
        if (!File.Exists(backupPath)) return;
        var proof = ReadObject(File.ReadAllBytes(backupPath));
        if ((string?)proof["format"] != "sokna-routine-local-lifecycle-backup-v1" ||
            (string?)proof["active_build"] != expectedPreviousBuild)
            throw new InvalidDataException("Routine lifecycle backup does not match requested previous build.");
        var lifeRoot = ResolveLifecycleRoot(packageRoot);
        var statePath = Path.Combine(lifeRoot, "state.json");
        var managedPath = Path.Combine(lifeRoot, "managed-files.json");
        if ((string?)proof["state_path"] != statePath || (string?)proof["managed_path"] != managedPath)
            throw new InvalidDataException("Routine lifecycle rollback paths are not authoritative.");
        AtomicWrite(statePath, Convert.FromBase64String((string?)proof["state_base64"] ?? ""));
        if ((string?)proof["managed_base64"] is string managed) AtomicWrite(managedPath, Convert.FromBase64String(managed));
        else if (File.Exists(managedPath)) File.Delete(managedPath);
    }

    public static void CompleteCrashBackup(string evidenceRoot)
    {
        var backupPath = Path.Combine(evidenceRoot, "routine-local-lifecycle-before.json");
        if (File.Exists(backupPath)) File.Delete(backupPath);
    }

    private static void AtomicWrite(string path, byte[] bytes)
    {
        var tmp=path+".tmp-"+Guid.NewGuid().ToString("N");
        try { File.WriteAllBytes(tmp,bytes); File.Move(tmp,path,overwrite:true); }
        finally { if(File.Exists(tmp))File.Delete(tmp); }
    }
}