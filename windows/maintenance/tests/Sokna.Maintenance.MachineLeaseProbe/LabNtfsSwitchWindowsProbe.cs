using System.Diagnostics;
using System.Security.Cryptography;
using Sokna.Maintenance.Core;

// F1-S7D5B: signed staged release + real Win32 job + actual NTFS junction
// switch under RUNNER_TEMP only. Durable WAL deliberately remains blocked
// on interrupted physical cutover; NO automatic rollback, HTTP, SQL, Public.
internal static class LabNtfsSwitchWindowsProbe
{
    internal static int Run(string rootInput, string stageId, string engineDll)
    {
        var root = Validate(rootInput,stageId);
        var engine = Path.GetFullPath(engineDll);
        Check(File.Exists(engine),"signed-lab engine assembly missing");
        var paths = new MaintenancePaths(root);
        using var lease = MachineMutationLease.Acquire(
            paths,LocalCodeCutoverWriteAheadJournal.Owner);
        var wal = new LocalCodeCutoverWriteAheadJournal(paths);
        var evidence = new string('a',64);

        foreach(var name in new[]{"success","gap","target"})
        {
            var tx = Seed(root,paths,stageId,name,lease);
            var exact = CutoverLabSwitch.ExactArgs(tx,stageId,root,name,
                name=="success" ? "success" : name=="gap" ? "pause-after-old" : "pause-after-new");
            var args = exact.Concat(new[]{"--delegation-id"}).ToArray();
            var package = Path.Combine(root,"s7d5b-"+name,"package");
            var web = Path.Combine(package,"Web");
            var pending = Path.Combine(package,"Web.pending");
            var backup = Path.Combine(package,"Web.previous");
            var previous = Path.Combine(package,"Web.release.old");
            var next = Path.Combine(package,"Web.release.new");
            var hashPrevious = Sha(File.ReadAllBytes(Path.Combine(previous,"VERSION.txt")));
            var hashNext = Sha(File.ReadAllBytes(Path.Combine(next,"VERSION.txt")));
            if(name=="success")
            {
                var direct = RunEngine(engine,exact);
                Check(direct.ExitCode==75 && direct.Combined.Contains(CutoverLabSwitch.Required),
                    "direct unpermitted mutation command accepted");
                Check(PathEqual(Link(web),previous),"direct command moved the junction");
            }
            var permit = CutoverLabSwitch.Issue(paths,lease,tx,stageId,name,exact[^1],exact);
            if(name=="success")
            {
                var file = Path.Combine(paths.CodeCutoverJournalRoot,
                    "_disposable_lab_permits",permit+".json");
                var original = File.ReadAllBytes(file);
                try
                {
                    var node = System.Text.Json.Nodes.JsonNode.Parse(File.ReadAllText(file))!;
                    node["signature"] = new string('b',64);
                    File.WriteAllText(file,node.ToJsonString());
                    var forged = RunEngine(engine,args.Concat(new[]{permit}).ToArray());
                    Check(forged.ExitCode==75 &&
                          forged.Combined.Contains(CutoverLabSwitch.Rejected),
                        "modified HMAC authorized NTFS mutation");
                    Check(PathEqual(Link(web),previous),"forged token changed junction");
                }
                finally { File.WriteAllBytes(file,original); }
            }
            var command = args.Concat(new[]{permit}).ToArray();
            var exe = Environment.ProcessPath
                ?? throw new InvalidDataException("S7D5B_DOTNET_HOST_MISSING");
            var dllArgs = new[]{engine}.Concat(command).ToArray();
            if(name=="success")
            {
                using var child = WindowsJobChildProcess.Start(exe,dllArgs,root);
                var exit = child.WaitAndCapture(20000);
                Check(exit.Finished && exit.ExitCode==0 &&
                      exit.Stdout.Contains("CUTOVER_LAB_PHYSICAL_SWITCH"),
                    "Job-bound Engine could not switch disposable NTFS junction: "+
                      exit.Stdout+" "+exit.Stderr);
                Check(PathEqual(Link(web),next) && PathEqual(Link(backup),previous)
                      && !Directory.Exists(pending),
                    "signed Engine did not produce expected target/backup junction");
                // Physical completion alone does NOT mark a release healthy.
                var before = wal.Classify(tx,next);
                Check(!before.ActivationAllowed && !before.AutomaticRollbackAllowed &&
                      before.LastPhase=="pointer_switch_intent",
                    "physical success bypassed explicit supervisor WAL observation");
                wal.Advance(lease,tx,"pointer_switch_observed",evidence);
                var after=wal.Classify(tx,next);
                Check(!after.ActivationAllowed && !after.PanelUpdateReady,
                    "WAL observed switch unlocked real install");
                var replay=RunEngine(engine,command);
                Check(replay.ExitCode==75 && replay.Combined.Contains(CutoverLabSwitch.Rejected),
                    "one-use physical mutation permit replayed");
            }
            else
            {
                using(var child = WindowsJobChildProcess.Start(exe,dllArgs,root))
                {
                    var pid=child.ProcessId;
                    WaitFor(Path.Combine(root,"s7d5b-"+name,"pause-ready"),pid,15000);
                    // Close the OS Job while the Engine is suspended at an
                    // actual physical cutover boundary, never kill via tree only.
                }
                Check(PathEqual(Link(backup),previous),
                    "crashed child lost previously active immutable slot");
                Check(!Directory.Exists(pending) == (name=="target"),
                    "interrupted junction pending link invariant failed");
                var expected = name=="gap" ? null : next;
                Check(PathEqual(Link(web),expected),
                    "crash physical pointer does not match held cutover boundary");
                var recovered = new LocalCodeCutoverWriteAheadJournal(paths)
                    .Classify(tx,expected);
                Check(recovered.LastPhase=="pointer_switch_intent" &&
                      recovered.ObservedPointer==(name=="gap"?"unknown":"target") &&
                      !recovered.ActivationAllowed && !recovered.AutomaticRollbackAllowed &&
                      !recovered.PanelUpdateReady && !recovered.DatabaseForwardOnly,
                    "interrupted cutover was automatically cleared");
                var replay=RunEngine(engine,command);
                Check(replay.ExitCode==75 && replay.Combined.Contains(CutoverLabSwitch.Rejected),
                    "crashed mutation permit reused to bypass quarantine");
            }
            Check(Sha(File.ReadAllBytes(Path.Combine(previous,"VERSION.txt")))==hashPrevious &&
                  Sha(File.ReadAllBytes(Path.Combine(next,"VERSION.txt")))==hashNext,
                "immutable code slot bytes modified");
        }
        Console.WriteLine(System.Text.Json.JsonSerializer.Serialize(new {
            format="sokna-f1-s7d5b-physical-ntfs-switch-lab-v1",
            result="PASS",signed_release_reverified_in_engine=true,
            physical_ntfs_junction_switched=true,
            wal_write_ahead_intent_required=true,
            engine_direct_mutation_rejected=true,forged_token_rejected=true,
            job_bound_engine=true,
            gap_crash_quarantined=true,target_switched_pre_observation_crash_quarantined=true,
            permit_replay_rejected=true,immutable_slots_unchanged=true,
            automatic_rollback_allowed=false,
            real_apache_http_tested=false,real_mariadb_tested=false,
            real_host_uat=false,public_mutation=false,panel_update_ready=false
        }));
        return 0;
    }

    private static string Seed(string root,MaintenancePaths paths,string stage,
        string name,MachineMutationLease lease)
    {
        var dir=Path.Combine(root,"s7d5b-"+name);
        var package=Path.Combine(dir,"package");
        var previous=Path.Combine(package,"Web.release.old");
        var target=Path.Combine(package,"Web.release.new");
        Directory.CreateDirectory(previous);Directory.CreateDirectory(target);
        File.WriteAllText(Path.Combine(previous,"VERSION.txt"),"old-ci-lab");
        File.WriteAllText(Path.Combine(target,"VERSION.txt"),"new-ci-lab");
        Junction(Path.Combine(package,"Web"),previous);
        Junction(Path.Combine(package,"Web.pending"),target);
        var tx=Guid.NewGuid().ToString("N");
        File.WriteAllText(Path.Combine(dir,"lab-transaction.id"),tx);
        var journal=new LocalCodeCutoverWriteAheadJournal(paths);
        journal.Begin(lease,tx,stage,previous,target,
            Sha(File.ReadAllBytes(paths.InstalledReleaseIdentityPath)));
        foreach(var phase in new[]{"apache_stop_intent","apache_stop_observed",
            "pointer_switch_intent"})
            journal.Advance(lease,tx,phase,new string('a',64));
        return tx;
    }
    private static void Junction(string link,string target)
    {
        var psi=new ProcessStartInfo("cmd.exe") { UseShellExecute=false,
            CreateNoWindow=true,RedirectStandardOutput=true,RedirectStandardError=true };
        foreach(var arg in new[]{"/d","/c","mklink","/J",link,target})
            psi.ArgumentList.Add(arg);
        using var process=Process.Start(psi) ?? throw new InvalidDataException("S7D5B_MKLINK_START");
        if(!process.WaitForExit(10000)||process.ExitCode!=0||
           !PathEqual(Link(link),target))
            throw new InvalidDataException("S7D5B_MKLINK_FAIL");
    }
    private static string? Link(string link)
    {
        if(!Directory.Exists(link))return null;
        if((File.GetAttributes(link)&FileAttributes.ReparsePoint)==0)
            throw new InvalidDataException("S7D5B_NOT_A_JUNCTION");
        return Directory.ResolveLinkTarget(link,true)?.FullName;
    }
    private static bool PathEqual(string? a,string? b)=>
        a is null || b is null ? a is null && b is null :
        string.Equals(Path.GetFullPath(a),Path.GetFullPath(b),StringComparison.OrdinalIgnoreCase);
    private static void WaitFor(string path,int childPid,int millis)
    {
        var time=Stopwatch.StartNew();
        while(time.ElapsedMilliseconds<millis)
        {
            if(File.Exists(path))return;
            try
            {
                using var process=Process.GetProcessById(childPid);
                if(process.HasExited)
                    throw new InvalidDataException("S7D5B_CHILD_EXITED_BEFORE_CRASH_BOUNDARY");
            }
            catch(ArgumentException)
            { throw new InvalidDataException("S7D5B_CHILD_DISAPPEARED"); }
            Thread.Sleep(40);
        }
        throw new TimeoutException("S7D5B_CUTOVER_PAUSE_TIMEOUT");
    }
    private sealed record Exit(int ExitCode,string Combined);
    private static Exit RunEngine(string engine,IReadOnlyList<string> args)
    {
        var psi=new ProcessStartInfo(Environment.ProcessPath ?? "dotnet")
        {UseShellExecute=false,CreateNoWindow=true,
         RedirectStandardOutput=true,RedirectStandardError=true};
        psi.ArgumentList.Add(engine);
        foreach(var arg in args)psi.ArgumentList.Add(arg);
        using var process=Process.Start(psi)??throw new InvalidDataException("S7D5B_ENGINE_START");
        var stdout=process.StandardOutput.ReadToEndAsync();
        var stderr=process.StandardError.ReadToEndAsync();
        if(!process.WaitForExit(20000))
        {
            process.Kill(entireProcessTree:true);process.WaitForExit(5000);
            throw new TimeoutException("S7D5B_ENGINE_TIMED_OUT");
        }
        return new(process.ExitCode,stdout.GetAwaiter().GetResult()+stderr.GetAwaiter().GetResult());
    }
    private static string Validate(string raw,string stage)
    {
        if(!OperatingSystem.IsWindows() ||
           Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" ||
           stage.Length!=32 || !stage.All(char.IsAsciiHexDigit))
            throw new InvalidDataException("S7D5B_WINDOWS_CI_ONLY");
        var root=Path.GetFullPath(raw);
        var runner=Path.TrimEndingDirectorySeparator(Path.GetFullPath(
            Environment.GetEnvironmentVariable("RUNNER_TEMP")
            ?? throw new InvalidDataException("RUNNER_TEMP_REQUIRED")));
        if(!string.Equals(Path.GetDirectoryName(root),runner,StringComparison.OrdinalIgnoreCase) ||
            !Path.GetFileName(root).StartsWith("sokna-f1-p2d-",StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("S7D5B_NOT_DISPOSABLE_P2D_ROOT");
        return root;
    }
    private static string Sha(byte[] data)=>
        Convert.ToHexString(SHA256.HashData(data)).ToLowerInvariant();
    private static void Check(bool result,string message)
    {
        if(!result)throw new InvalidDataException("S7D5B_TEST_FAILED: "+message);
    }
}
