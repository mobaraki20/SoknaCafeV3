using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

// F1-S7D5B physical NTFS switch experiment, NOT a production updater.
// Hard-bounded to a disposable GitHub Windows runner and a signed Stage.
// Even a valid token cannot target the installed Web, Apache, SQL or Public.
public sealed record CutoverLabSwitchReceipt(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("permit_id")] string PermitId,
    [property: JsonPropertyName("transaction_id")] string TransactionId,
    [property: JsonPropertyName("stage_id")] string StageId,
    [property: JsonPropertyName("phase_event_sha256")] string PhaseEventSha256,
    [property: JsonPropertyName("stage_receipt_sha256")] string StageReceiptSha256,
    [property: JsonPropertyName("installed_sha256")] string InstalledSha256,
    [property: JsonPropertyName("lab_case")] string LabCase,
    [property: JsonPropertyName("mode")] string Mode,
    [property: JsonPropertyName("command_sha256")] string CommandSha256,
    [property: JsonPropertyName("parent_pid")] int ParentPid,
    [property: JsonPropertyName("parent_start_ticks")] long ParentStartTicks,
    [property: JsonPropertyName("issued_ticks")] long IssuedTicks,
    [property: JsonPropertyName("expires_ticks")] long ExpiresTicks,
    [property: JsonPropertyName("production_authorized")] bool ProductionAuthorized,
    [property: JsonPropertyName("signature")] string Signature);

public static class CutoverLabSwitch
{
    public const string Format = "sokna-f1-s7d5b-disposable-ntfs-lab-v1";
    public const string Required = "CUTOVER_LAB_PERMIT_REQUIRED";
    public const string Rejected = "CUTOVER_LAB_PERMIT_REJECTED";
    private const string Domain = "SOKNA-CUTOVER-WINDOWS-LAB-ONLY-SWITCH-V1\n";
    private static readonly TimeSpan Ttl = TimeSpan.FromSeconds(45);

    public static string Issue(MaintenancePaths paths, MachineMutationLease lease,
        string tx, string stageId, string caseName, string mode, IReadOnlyList<string> exactArgs)
    {
        if (lease is null || lease.IsDisposed || lease.Owner != LocalCodeCutoverWriteAheadJournal.Owner
            || lease.ProcessId != Environment.ProcessId)
            throw new InvalidOperationException("CUTOVER_SUPERVISOR_MACHINE_LEASE_REQUIRED");
        var obs = Observe(paths, tx, stageId, caseName, mode, exactArgs);
        RequirePhysicalOld(obs.Layout);
        var now = DateTimeOffset.UtcNow;
        using var current = Process.GetCurrentProcess();
        var permit = new CutoverLabSwitchReceipt(
            Format, Guid.NewGuid().ToString("N"), tx, stageId, obs.Wal.EventSha256,
            obs.ReceiptHash, obs.InstalledHash, caseName, mode, Sha(exactArgs),
            Environment.ProcessId, current.StartTime.ToUniversalTime().Ticks,
            now.UtcTicks, now.Add(Ttl).UtcTicks, ProductionAuthorized:false, Signature:"");
        permit = permit with { Signature = Sign(paths, permit) };
        var folder = Root(paths, create:true);
        AtomicFile.WriteJson(Path.Combine(folder,permit.PermitId+".json"),permit);
        return permit.PermitId;
    }

    public static (string PhysicalPointer, bool Interrupted) ConsumeAndSwitch(
        MaintenancePaths paths, string permitId, string tx, string stageId,
        string caseName, string mode, IReadOnlyList<string> exactArgs)
    {
        if (!Id(permitId)) throw new InvalidDataException(Rejected);
        var folder = Root(paths);
        var file = Path.Combine(folder,permitId+".json");
        if (!File.Exists(file) || Reparse(file)) throw new InvalidDataException(Rejected);
        var p = AtomicFile.ReadJson<CutoverLabSwitchReceipt>(file);
        if (p is null || p.Format != Format || p.PermitId != permitId ||
            p.TransactionId != tx || p.StageId != stageId ||
            p.LabCase != caseName || p.Mode != mode || p.ProductionAuthorized ||
            p.ParentPid <= 0 || p.ParentPid == Environment.ProcessId ||
            p.IssuedTicks <= 0 || p.ExpiresTicks - p.IssuedTicks != Ttl.Ticks ||
            DateTimeOffset.UtcNow.UtcTicks > p.ExpiresTicks ||
            DateTimeOffset.UtcNow.UtcTicks < p.IssuedTicks - TimeSpan.FromSeconds(5).Ticks ||
            p.CommandSha256 != Sha(exactArgs) || !Hash(p.Signature))
            throw new InvalidDataException(Rejected);
        var signature = Convert.FromHexString(p.Signature);
        if (!CryptographicOperations.FixedTimeEquals(signature,
            Convert.FromHexString(Sign(paths,p with {Signature=""}))))
            throw new InvalidDataException(Rejected);
        EngineDelegationPermit.RequireParentAndMachineLease(paths, p.ParentPid,
            p.ParentStartTicks, LocalCodeCutoverWriteAheadJournal.Owner);
        var obs = Observe(paths,tx,stageId,caseName,mode,exactArgs);
        if (p.PhaseEventSha256!=obs.Wal.EventSha256 ||
            p.StageReceiptSha256!=obs.ReceiptHash || p.InstalledSha256!=obs.InstalledHash)
            throw new InvalidDataException(Rejected);
        RequirePhysicalOld(obs.Layout);
        var consumed = Path.Combine(folder,permitId+".consumed");
        try
        {
            using var stream = new FileStream(consumed,FileMode.CreateNew,
                FileAccess.Write,FileShare.None,4096,FileOptions.WriteThrough);
            stream.Write(Encoding.ASCII.GetBytes("pid="+Environment.ProcessId));
            stream.Flush(flushToDisk:true);
        }
        catch(IOException) { throw new InvalidDataException(Rejected); }
        // Re-validate after the one-use burn marker, immediately before first
        // physical rename. No reissue/automatic rollback on an interruption.
        EngineDelegationPermit.RequireParentAndMachineLease(paths,p.ParentPid,
            p.ParentStartTicks,LocalCodeCutoverWriteAheadJournal.Owner);
        obs=Observe(paths,tx,stageId,caseName,mode,exactArgs);
        if (p.PhaseEventSha256!=obs.Wal.EventSha256 ||
            p.StageReceiptSha256!=obs.ReceiptHash || p.InstalledSha256!=obs.InstalledHash)
            throw new InvalidDataException(Rejected);
        RequirePhysicalOld(obs.Layout);
        Directory.Move(obs.Layout.Web,obs.Layout.Previous);
        if(mode=="pause-after-old") Pause(obs.Layout,mode);
        Directory.Move(obs.Layout.Pending,obs.Layout.Web);
        if(mode=="pause-after-new") Pause(obs.Layout,mode);
        if (!string.Equals(Target(obs.Layout.Web),obs.Layout.New,
            StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException(Rejected);
        return (obs.Layout.New,false);
    }

    public static string[] ExactArgs(string tx,string stageId,string root,
        string caseName,string mode) => new[] {
            "cutover-lab-switch","--transaction-id",tx,
            "--stage-operation-id",stageId,"--data-root",root,
            "--lab-case",caseName,"--lab-mode",mode
        };

    private sealed record Layout(string Web, string Pending, string Previous,
        string Old, string New, string CaseRoot);
    private sealed record Observed(LocalCutoverJournalEvent Wal,string ReceiptHash,
        string InstalledHash,Layout Layout);
    private static Observed Observe(MaintenancePaths paths,string tx,string stage,
        string name,string mode,IReadOnlyList<string> args)
    {
        if ((name=="success" && mode!="success") ||
            (name=="gap" && mode!="pause-after-old") ||
            (name=="target" && mode!="pause-after-new"))
            throw new InvalidDataException(Rejected);
        var l = SafeLayout(paths,tx,name);
        var expected=ExactArgs(tx,stage,paths.DataRoot,name,mode);
        if(args.Count!=expected.Length || !args.SequenceEqual(expected,StringComparer.Ordinal))
            throw new InvalidDataException(Rejected);
        var wal=new LocalCodeCutoverWriteAheadJournal(paths).ReadVerified(tx);
        if(wal.StageOperationId!=stage || wal.Phase!="pointer_switch_intent" ||
            wal.Sequence!=4 || wal.ExecutionAuthorized || !wal.DryRunOnly ||
            !string.Equals(wal.PreviousSlot,l.Old,StringComparison.OrdinalIgnoreCase) ||
            !string.Equals(wal.TargetSlot,l.New,StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException(Rejected);
        var inspected=SupervisorLocalStageRecoveryCoordinator
            .InspectSignedStageReadOnly(paths,stage);
        if(!inspected.StageEvidenceVerified || inspected.ReleaseType!="local_only" ||
            inspected.ActivationAllowed || inspected.PanelUpdateReady)
            throw new InvalidDataException(Rejected);
        var installed=paths.InstalledReleaseIdentityPath;
        if(!File.Exists(installed) || Reparse(installed))throw new InvalidDataException(Rejected);
        var installedHash=Sha(File.ReadAllBytes(installed));
        if(wal.InstalledIdentitySha256!=installedHash)throw new InvalidDataException(Rejected);
        var op=new MaintenanceOperationStore(paths).Get(stage);
        if(op is null || op.State!="staged" || op.OperationType!="update.local-stage" ||
            string.IsNullOrEmpty(op.StageReceiptPath) || !File.Exists(op.StageReceiptPath) ||
            Reparse(op.StageReceiptPath))throw new InvalidDataException(Rejected);
        return new(wal,Sha(File.ReadAllBytes(op.StageReceiptPath)),installedHash,l);
    }
    private static Layout SafeLayout(MaintenancePaths paths,string tx,string name)
    {
        if(!OperatingSystem.IsWindows() ||
           Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" ||
           !Id(tx) || name is not ("success" or "gap" or "target") ||
           Path.GetFileName(name)!=name)
            throw new InvalidDataException("CUTOVER_LAB_ONLY_DISPOSABLE_WINDOWS_RUNNER");
        var runner=Environment.GetEnvironmentVariable("RUNNER_TEMP")
            ?? throw new InvalidDataException(Rejected);
        var temp=Path.TrimEndingDirectorySeparator(Path.GetFullPath(runner));
        var root=Path.TrimEndingDirectorySeparator(Path.GetFullPath(paths.DataRoot));
        if(!string.Equals(Path.GetDirectoryName(root),temp,StringComparison.OrdinalIgnoreCase) ||
           !Path.GetFileName(root).StartsWith("sokna-f1-p2d-",StringComparison.OrdinalIgnoreCase) ||
           !Directory.Exists(root) || Reparse(root))
            throw new InvalidDataException(Rejected);
        var caseRoot=Path.Combine(root,"s7d5b-"+name);
        var package=Path.Combine(caseRoot,"package");
        if(!Directory.Exists(caseRoot) || Reparse(caseRoot) ||
           !Directory.Exists(package) || Reparse(package) ||
           !File.Exists(Path.Combine(caseRoot,"lab-transaction.id")) ||
           File.ReadAllText(Path.Combine(caseRoot,"lab-transaction.id"))!=tx)
            throw new InvalidDataException(Rejected);
        var old=Path.Combine(package,"Web.release.old");
        var newer=Path.Combine(package,"Web.release.new");
        foreach(var slot in new[]{old,newer})
            if(!Directory.Exists(slot) || Reparse(slot))throw new InvalidDataException(Rejected);
        if(File.ReadAllText(Path.Combine(old,"VERSION.txt"))!="old-ci-lab" ||
           File.ReadAllText(Path.Combine(newer,"VERSION.txt"))!="new-ci-lab")
            throw new InvalidDataException(Rejected);
        return new(Path.Combine(package,"Web"),Path.Combine(package,"Web.pending"),
            Path.Combine(package,"Web.previous"),old,newer,caseRoot);
    }
    private static void RequirePhysicalOld(Layout l)
    {
        if(Directory.Exists(l.Previous) || File.Exists(l.Previous) ||
           !string.Equals(Target(l.Web),l.Old,StringComparison.OrdinalIgnoreCase) ||
           !string.Equals(Target(l.Pending),l.New,StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException(Rejected);
    }
    private static string? Target(string path)
    {
        if(!Directory.Exists(path))return null;
        if(!Reparse(path))throw new InvalidDataException(Rejected);
        return Directory.ResolveLinkTarget(path,true)?.FullName
            ?? throw new InvalidDataException(Rejected);
    }
    private static void Pause(Layout layout,string mode)
    {
        File.WriteAllText(Path.Combine(layout.CaseRoot,"pause-ready"),mode);
        Thread.Sleep(30000);
        throw new InvalidDataException("CUTOVER_LAB_EXPECTED_CHILD_TERMINATION");
    }
    private static string Root(MaintenancePaths paths,bool create=false)
    {
        var parent=paths.CodeCutoverJournalRoot;
        if(!Directory.Exists(parent) || Reparse(parent))throw new InvalidDataException(Rejected);
        var folder=Path.Combine(parent,"_disposable_lab_permits");
        if(create)Directory.CreateDirectory(folder);
        if(!Directory.Exists(folder) || Reparse(folder))throw new InvalidDataException(Rejected);
        return folder;
    }
    private static string Sign(MaintenancePaths paths,CutoverLabSwitchReceipt item)=>
        Convert.ToHexString(HMACSHA256.HashData(new SecretStore(paths).LoadKey("engine-delegation"),
            Encoding.UTF8.GetBytes(Domain+JsonSerializer.Serialize(item with{Signature=""})))).ToLowerInvariant();
    private static string Sha(IReadOnlyList<string> args)=>Sha(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(args)));
    private static string Sha(byte[] bytes)=>Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
    private static bool Reparse(string path)=>(File.GetAttributes(path)&FileAttributes.ReparsePoint)!=0;
    private static bool Id(string? x)=>x is {Length:32} && x.All(c=>c is >= '0' and <= '9' or >= 'a' and <= 'f');
    private static bool Hash(string? x)=>x is {Length:64} && x.All(c=>c is >= '0' and <= '9' or >= 'a' and <= 'f');
}
