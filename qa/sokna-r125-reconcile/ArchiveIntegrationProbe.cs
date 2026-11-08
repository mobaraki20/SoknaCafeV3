using System.Security.Cryptography;
using System.Text;
using Sokna.Maintenance.Core;

// This test executes the REAL R125 Supervisor archive workflow and durable
// operation store on disposable Windows files. The only synthetic part is
// the host qualification callback, because production MariaDB/Apache must not
// be available to GitHub Actions. It is NOT proof of the cafe's backup safety.
var failures = new List<string>();
void Check(bool condition, string name)
{
    if (!condition) throw new InvalidOperationException("FAILED: " + name);
    Console.WriteLine("PASS " + name);
}
void Reject(Action action, string name)
{
    try { action(); }
    catch (InvalidDataException) { Console.WriteLine("PASS rejected " + name); return; }
    catch (InvalidOperationException) { Console.WriteLine("PASS rejected " + name); return; }
    throw new Exception("UNSAFE ACCEPTANCE: " + name);
}

void HappyAndIdempotent()
{
    using var f = new Fixture();
    var result = f.New().Reconcile(f.Request, "fixture-happy");
    var archived = f.Archive(result.Operation.OperationId);
    Check(result.Operation.State == "committed", "archive committed");
    Check(!File.Exists(f.Source) && File.Exists(archived), "source moved without deletion to protected archive");
    Check(File.Exists(f.Receipt(result.Operation.OperationId)), "durable receipt persisted");
    Check(File.Exists(f.Intent(result.Operation.OperationId)), "durable intent persisted");
    Check(f.FileSha(archived) == f.Sha, "archive byte-exact SHA256");
    var repeat = f.New().Reconcile(f.Request, "fixture-happy");
    Check(repeat.Reconciled && repeat.Operation.OperationId == result.Operation.OperationId,
        "same idempotency returns verified archive and receipt");
    Check(f.Journal.LastSequence > 0, "journal durable");
    Check(f.Operations.ListBlocking().Count == 0, "completed archive operation not busy");
    File.WriteAllText(f.Receipt(result.Operation.OperationId), "{\"format\":\"wrong\"}");
    Reject(() => f.New().Reconcile(f.Request, "fixture-happy"),
        "committed receipt tamper is fail closed");
}
void ExplicitHashAndMissing()
{
    using var f = new Fixture();
    var wrong = f.Request with { ExpectedProofSha256 = new string('b', 64) };
    Reject(() => f.New().Reconcile(wrong, "wrong-hash"),
        "wrong requested file hash");
    Check(File.Exists(f.Source), "wrong hash preserves proof");
    File.Delete(f.Source);
    Reject(() => f.New().Reconcile(f.Request, "missing-proof"),
        "missing proof, no op committed");
}
void PreflightFailure()
{
    using var f = new Fixture();
    f.FailQualification = true; // synthetic read-only MariaDB baseline refusal
    Reject(() => f.New().Reconcile(f.Request, "failed-qualification"),
        "live host/DB qualification rejection");
    Check(File.Exists(f.Source), "DB refusal leaves original proof untouched");
    Check(f.Operations.ListBlocking().Count == 0, "preflight rejection does not create blocking mutation");
}
void MutationBusy()
{
    using var f = new Fixture();
    f.Operations.GetOrCreate("foreign-key",
        MaintenanceOperationStore.CanonicalRequestHash("update.stage", "fixture", "someone"),
        "update.stage", "fixture", "someone");
    Reject(() => f.New().Reconcile(f.Request, "busy-op"),
        "other signed update is in progress");
    Check(File.Exists(f.Source), "foreign mutation lease blocked without evidence movement");
}
void CrashAndResume()
{
    using var f = new Fixture();
    var first = f.New(() => throw new IOException("SIMULATED_CRASH_AFTER_MOVE"))
        .Reconcile(f.Request, "crash-after-move");
    var saved = f.Archive(first.Operation.OperationId);
    Check(first.Operation.State == "recovery_required", "crash retained blocking recovery state");
    Check(!File.Exists(f.Source) && File.Exists(saved), "interrupted archive retained");
    Check(f.Operations.ListBlocking().Count == 1, "crash blocks unrelated update");
    var resumed = f.New().Reconcile(f.Request, "crash-after-move");
    Check(resumed.Operation.State == "committed" &&
        resumed.Operation.OperationId == first.Operation.OperationId, "same signed intent resumed");
    Check(f.FileSha(saved) == f.Sha, "resumed file hash preserved");
    Check(f.Operations.ListBlocking().Count == 0, "no stale blocking ledger after valid resume");
    Check(File.Exists(f.Receipt(resumed.Operation.OperationId)), "resume created receipt");
}
void CrashAndTamper()
{
    using var f = new Fixture();
    var first = f.New(() => throw new IOException("SIMULATED_CRASH_AFTER_MOVE"))
        .Reconcile(f.Request, "crash-then-tamper");
    var saved = f.Archive(first.Operation.OperationId);
    File.AppendAllText(saved, "tamper");
    Reject(() => f.New().Reconcile(f.Request, "crash-then-tamper"),
        "tampered archived snapshot cannot resume");
    Check(f.Operations.Get(first.Operation.OperationId)?.State == "recovery_required",
        "archive tamper retains recovery blocker");
}
void DuplicateSource()
{
    using var f = new Fixture();
    var result = f.New().Reconcile(f.Request, "source-reappears");
    File.WriteAllBytes(f.Source, f.OriginalBytes);
    Reject(() => f.New().Reconcile(f.Request, "source-reappears"),
        "duplicate source after committed archive");
    Check(File.Exists(f.Archive(result.Operation.OperationId)),
        "reappeared source does not destroy protected archived proof");
}
HappyAndIdempotent();
ExplicitHashAndMissing();
PreflightFailure();
MutationBusy();
CrashAndResume();
CrashAndTamper();
DuplicateSource();
Console.WriteLine("PASS R125 ARCHIVE INTEGRATION on actual Windows ledger, lease, ACL and filesystem.");

internal sealed class Fixture : IDisposable
{
    public readonly string Root;
    public readonly MaintenancePaths Paths;
    public readonly MaintenanceOperationStore Operations;
    public readonly DurableJournal Journal;
    public readonly SupervisorState State;
    public readonly string Source;
    public readonly byte[] OriginalBytes;
    public readonly string Sha;
    public readonly R125LifecycleReconciliationRequest Request;
    public bool FailQualification;
    public int QualificationCalls;

    public Fixture()
    {
        Root = Path.Combine(Path.GetTempPath(), "SOKNA-R125-TRANSACTION-TEST-" + Guid.NewGuid().ToString("N"));
        Paths = new MaintenancePaths(Path.Combine(Root, "Maintenance"));
        Directory.CreateDirectory(Paths.LocalSlotStateRoot);
        Operations = new MaintenanceOperationStore(Paths);
        Journal = new DurableJournal(Paths.JournalPath);
        State = new SupervisorStateStore(Paths).LoadOrCreate();
        Source = Path.Combine(Paths.LocalSlotStateRoot, "routine-local-lifecycle-before.json");
        // Synthetic snapshot, never the actual cafe's private 85KB rollback proof.
        OriginalBytes = Encoding.UTF8.GetBytes(
            "{\"format\":\"sokna-routine-local-lifecycle-backup-v1\",\"synthetic\":true," +
            "\"content\":\"" + new string('a', 4096) + "\"}");
        File.WriteAllBytes(Source, OriginalBytes);
        Sha = FileSha(Source);
        Request = new R125LifecycleReconciliationRequest("qa-operator",
            "3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat", Sha);
    }
    public string FileSha(string path)
    {
        using var s = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant();
    }
    public string Archive(string operationId) => Path.Combine(
        Paths.LocalSlotStateRoot, "reconciled-lifecycle-evidence", "proof-" + operationId + ".json");
    public string Receipt(string operationId) => Path.Combine(
        Paths.LocalSlotStateRoot, "reconciled-lifecycle-evidence", "proof-" + operationId + ".receipt.json");
    public string Intent(string operationId) => Path.Combine(
        Paths.LocalSlotStateRoot, "reconciled-lifecycle-evidence", "proof-" + operationId + ".intent.json");
    public SupervisorR125LifecycleEvidenceReconciler New(Action? afterMove = null)
        => new(Paths, Operations, Journal, State, QualifySyntheticOnly, afterMove);
    private void QualifySyntheticOnly(string path, string build, string sha)
    {
        QualificationCalls++;
        if (FailQualification || build != Request.ExpectedBuild || sha != Sha ||
            !File.Exists(path) || FileSha(path) != Sha)
            throw new InvalidDataException("SYNTHETIC_READONLY_DB_QUALIFICATION_FAILED");
    }
    public void Dispose()
    {
        try { Directory.Delete(Root, recursive:true); } catch { /* disposable CI scratch */ }
    }
}
