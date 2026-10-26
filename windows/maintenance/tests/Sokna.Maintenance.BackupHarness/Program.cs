using System.Reflection;
using Sokna.Maintenance.Core;
using Sokna.Maintenance.Supervisor;

var root = Path.Combine(Path.GetTempPath(), "sokna-bk2b-windows-" + Guid.NewGuid().ToString("N"));
try
{
    Directory.CreateDirectory(root);
    var paths = new MaintenancePaths(root);
    var stateStore = new SupervisorStateStore(paths);
    var supervisor = stateStore.LoadOrCreate();
    stateStore.Save(supervisor);
    var worker = new BackupSchedulerWorker(paths, new MaintenanceOperationStore(paths),
        new DurableJournal(paths.JournalPath), supervisor);
    var diskState = Path.Combine(root, "backups", "schedule-state.json");
    // Crash during local-only preparation cannot have transmitted to Public.
    // It must recover after a bounded delay without indefinitely locking UNKNOWN.
    AtomicFile.WriteJson(diskState, new BackupJobState {
        State = "RUNNING", Code = "BACKUP_LOCAL_PREPARE_STARTED",
        OperationId = "lab-crashed-local-preparation", LastAttemptAtUtc = DateTimeOffset.UtcNow,
        LastVerifiedBackupId = "business-known-good-before-crash",
        LastVerifiedSha256 = new string('e', 64)
    });
    await worker.StartAsync(CancellationToken.None);
    using (var shutdown = new CancellationTokenSource(TimeSpan.FromSeconds(10)))
        await worker.StopAsync(shutdown.Token);
    var interrupted = AtomicFile.ReadJson<BackupJobState>(diskState);
    Check(interrupted.State == "BLOCKED" &&
        interrupted.Code == "BACKUP_LOCAL_PREPARE_INTERRUPTED" &&
        interrupted.BackupId is null && interrupted.NextDueUtc > DateTimeOffset.UtcNow &&
        interrupted.LastVerifiedBackupId == "business-known-good-before-crash",
        "interrupted local-only prepare retries safely and preserves LKG");

    var artifactId = "business-incident-20261026-01234567";
    var sha = new string('a', 64);
    var oldGood = "business-known-good-0123456789";
    var prepared = new BackupJobState
    {
        State = "PREPARED", Code = "BACKUP_LOCAL_ARTIFACT_PREPARED",
        BackupId = artifactId, ArtifactSha256 = sha, ArtifactSize = 4096,
        ArtifactInstallationId = "lab-installation-1",
        LastVerifiedBackupId = oldGood, LastVerifiedSha256 = new string('f', 64),
        LastRemoteReceiptId = "rct_" + new string('a', 40)
    };
    // A contradictory RUNNING record with remote identity is ambiguous, never retried.
    AtomicFile.WriteJson(diskState, prepared with {
        State = "RUNNING", Code = "BACKUP_LOCAL_PREPARE_STARTED"
    });
    var restartWorker = new BackupSchedulerWorker(paths, new MaintenanceOperationStore(paths),
        new DurableJournal(paths.JournalPath), supervisor);
    await restartWorker.StartAsync(CancellationToken.None);
    using (var shutdown = new CancellationTokenSource(TimeSpan.FromSeconds(10)))
        await restartWorker.StopAsync(shutdown.Token);
    var interruptedRemote = AtomicFile.ReadJson<BackupJobState>(diskState);
    Check(interruptedRemote.State == "UNKNOWN" && interruptedRemote.BackupId == artifactId &&
        interruptedRemote.NextDueUtc is null && interruptedRemote.LastVerifiedBackupId == oldGood,
        "ambiguous interrupted remote identity remains UNKNOWN and LKG is intact");
    AtomicFile.WriteJson(diskState, prepared);
    var block = typeof(BackupSchedulerWorker).GetMethod("ChangeIfNotUnknown",
        BindingFlags.NonPublic | BindingFlags.Instance)
        ?? throw new Exception("Missing fail-closed transition");
    block.Invoke(worker, ["BLOCKED", "VAULT_UPLOAD_CREDENTIAL_EXPIRED_OR_RENEWAL_DUE"]);
    var unknown = AtomicFile.ReadJson<BackupJobState>(diskState);
    Check(unknown.State == "UNKNOWN" && unknown.BackupId == artifactId &&
        unknown.ArtifactSha256 == sha && unknown.ArtifactSize == 4096 &&
        unknown.LastVerifiedBackupId == oldGood && unknown.NextDueUtc is null,
        "pending identity and known-good survive token expiry");
    block.Invoke(worker, ["BLOCKED", "LOCAL_CURRENT_UNVERIFIED"]);
    unknown = AtomicFile.ReadJson<BackupJobState>(diskState);
    Check(unknown.State == "UNKNOWN" && unknown.BackupId == artifactId,
        "repeated errors cannot launch a new backup");

    var mockBin=Path.Combine(root,"test-runtime"); Directory.CreateDirectory(mockBin);
    // Fake name for path validation only; this test never launches a PHP subprocess.
    var fakePhp=Path.Combine(mockBin,"php.exe");
    File.Copy(Path.Combine(Environment.SystemDirectory,"cmd.exe"),fakePhp);
    var policy = new BackupSchedulePolicy {
        Enabled = true, RecoveryCopyExternallyConfirmed = true, IntervalHours = 24,
        PhpExecutable = fakePhp,
        RemoteVaultEndpoint = "https://backup.invalid/maintenance.php",
        ExpectedLocalBuild = "test-build",
        ExpectedManagedTreeSha256 = sha,
        SourceInstallationId = "lab-installation-1",
        VaultCredentialExpiresAtUtc = DateTimeOffset.UtcNow.AddDays(30)
    };
    var fakeBearer1 = "vlt_" + new string('a',24) + "." + new string('b',48);
    var fakeBearer2 = "vlt_" + new string('c',24) + "." + new string('d',48);
    var passphrase = "fake-lab-only-owner-held-key-20261026";
    // Provision is not allowed while an unresolved artifact exists.
    var refused = false;
    try { worker.ProvisionFromAdmin(policy, passphrase, fakeBearer1); }
    catch (InvalidOperationException) { refused = true; }
    Check(refused, "unresolved artifact blocks reprovision with a new recovery secret");
    AtomicFile.WriteJson(diskState, new BackupJobState());
    new SecretStore(paths).EnsureKeys();
    var leaseRefusal = false;
    using (MachineMutationLease.Acquire(paths, "test-update"))
    {
        try { worker.ProvisionFromAdmin(policy, passphrase, fakeBearer1); }
        catch (InvalidOperationException ex) when (ex.Message == MachineMutationLease.ConflictCode)
        { leaseRefusal = true; }
    }
    Check(leaseRefusal, "shared updater mutation lease blocks backup provisioning");
    worker.ProvisionFromAdmin(policy, passphrase, fakeBearer1);
    Check(File.Exists(Path.Combine(paths.SecretsRoot, "backup-recovery-vault.dpapi")),
        "operational DPAPI cache created by existing Supervisor");
    var pending = prepared with { State = "UNKNOWN", Code = "BACKUP_UPLOAD_OUTCOME_UNKNOWN" };
    AtomicFile.WriteJson(diskState, pending);
    worker.RenewUploadCredentialFromAdmin(fakeBearer2, DateTimeOffset.UtcNow.AddDays(45));
    var after = AtomicFile.ReadJson<BackupJobState>(diskState);
    Check(after.State == "UNKNOWN" && after.BackupId == artifactId &&
          after.ArtifactSha256 == sha && after.LastVerifiedBackupId == oldGood,
          "scoped bearer rotation leaves UNKNOWN and LKG unchanged");
    Check(worker.ReadStatus().ToString()?.Contains("pending_backup_id") == true,
          "status remains available without leaking passphrase");
    Console.WriteLine("BK2B WINDOWS STATE / DPAPI RENEWAL HARNESS PASS");
}
finally
{
    try { Directory.Delete(root, recursive: true); } catch { }
}
static void Check(bool value, string name)
{
    if (!value) throw new InvalidOperationException("FAIL " + name);
    Console.WriteLine("PASS " + name);
}
