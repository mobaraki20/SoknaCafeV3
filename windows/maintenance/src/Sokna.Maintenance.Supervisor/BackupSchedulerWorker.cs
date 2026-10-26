using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Hosting;
using Sokna.Maintenance.Core;

namespace Sokna.Maintenance.Supervisor;

// BK-2b Windows source candidate integrated in existing Supervisor.
// Operator setup arrives via authenticated Local admin adapter, never command line.
// An absent policy means DISABLED, never a new automatic backup.
public sealed record BackupSchedulePolicy
{
    public string Format { get; init; } = "sokna-maintenance-backup-policy-v1";
    public bool Enabled { get; init; }
    public int IntervalHours { get; init; } = 24;
    public bool RecoveryCopyExternallyConfirmed { get; init; }
    public string PhpExecutable { get; init; } = "";
    public string ExpectedLocalBuild { get; init; } = "";
    public string ExpectedManagedTreeSha256 { get; init; } = "";
    public string RemoteVaultEndpoint { get; init; } = "";
    public string SourceInstallationId { get; init; } = "";
    public DateTimeOffset? VaultCredentialExpiresAtUtc { get; init; }
}

public sealed record BackupProvisionRequest
{
    public BackupSchedulePolicy? Policy { get; init; }
    public string RecoveryPassphrase { get; init; } = "";
    public string VaultBearer { get; init; } = "";
}

public sealed record BackupCredentialRenewalRequest
{
    public string VaultBearer { get; init; } = "";
    public DateTimeOffset? ExpiresAtUtc { get; init; }
}

public sealed record BackupJobState
{
    public string Format { get; init; } = "sokna-maintenance-backup-job-v1";
    public string State { get; init; } = "DISABLED";
    public string Code { get; init; } = "POLICY_DISABLED";
    public string? OperationId { get; init; }
    public DateTimeOffset? StartedAtUtc { get; init; }
    public DateTimeOffset? LastAttemptAtUtc { get; init; }
    public DateTimeOffset? LastRemoteVerifiedAtUtc { get; init; }
    public DateTimeOffset? NextDueUtc { get; init; }
    public string? LastVerifiedBackupId { get; init; }
    public string? LastVerifiedSha256 { get; init; }
    public string? LastRemoteReceiptId { get; init; }
    // Current immutable artifact identity survives process/service restart before any remote upload.
    public string? BackupId { get; init; }
    public string? ArtifactSha256 { get; init; }
    public long? ArtifactSize { get; init; }
    public string? ArtifactInstallationId { get; init; }
}

public sealed class BackupSchedulerWorker : BackgroundService
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;
    private readonly DurableJournal _journal;
    private readonly SupervisorState _supervisor;
    private readonly object _gate = new();
    private readonly string _root;
    private readonly string _policy;
    private readonly string _state;
    private readonly string _sealed;

    public BackupSchedulerWorker(MaintenancePaths paths, MaintenanceOperationStore operations,
        DurableJournal journal, SupervisorState supervisor)
    {
        _paths = paths;
        _operations = operations;
        _journal = journal;
        _supervisor = supervisor;
        _root = Path.Combine(paths.DataRoot, "backups");
        _policy = Path.Combine(_root, "schedule-policy.json");
        _state = Path.Combine(_root, "schedule-state.json");
        _sealed = Path.Combine(paths.SecretsRoot, "backup-recovery-vault.dpapi"); // SecretStore hardens this directory to SYSTEM/Administrators.
    }

    public object ReadStatus()
    {
        // Never report a successful remote backup from the presence of a local file alone.
        lock (_gate)
        {
            try
            {
                var p = LoadPolicy();
                var s = LoadState();
                return new {
                    format = "sokna-maintenance-backup-status-v1",
                    policy_enabled = p?.Enabled == true,
                    worker_enabled = p is { Enabled: true, RecoveryCopyExternallyConfirmed: true } && File.Exists(_sealed),
                    state = s.State, code = s.Code, operation_id = s.OperationId,
                    last_attempt_at_utc = s.LastAttemptAtUtc,
                    last_remote_verified_at_utc = s.LastRemoteVerifiedAtUtc,
                    next_due_utc = s.NextDueUtc,
                    last_verified_backup_id = s.LastVerifiedBackupId,
                    last_remote_receipt_id = s.LastRemoteReceiptId,
                    pending_backup_id = s.BackupId,
                    pending_artifact_sha256 = s.ArtifactSha256,
                    pending_artifact_size = s.ArtifactSize,
                    vault_credential_expires_at_utc = p?.VaultCredentialExpiresAtUtc,
                    // Last known-good is retained after any failure and never implies current job succeeded.
                    last_verified_sha256 = s.LastVerifiedSha256,
                    credential_material_exposed = false,
                    release_qualified = false
                };
            }
            catch (Exception e) when (e is IOException or UnauthorizedAccessException or JsonException or InvalidDataException)
            {
                return new {
                    format = "sokna-maintenance-backup-status-v1",
                    policy_enabled = false, worker_enabled = false,
                    state = "EVIDENCE_UNAVAILABLE", code = "BACKUP_EVIDENCE_CORRUPT_OR_UNREADABLE",
                    error_type = e.GetType().Name, release_qualified = false
                };
            }
        }
    }

    // Invoked ONLY through the HMAC-authenticated web-adapter route; Local MUST enforce
    // administrator authentication and a retyped owner-held recovery secret first.
    // No file name, path or process argument ever contains either secret.
    public object ProvisionFromAdmin(BackupSchedulePolicy policy, string passphrase, string bearer)
    {
        if (!policy.Enabled || !policy.RecoveryCopyExternallyConfirmed ||
            string.IsNullOrEmpty(passphrase) || passphrase.Length is < 16 or > 1024 ||
            string.IsNullOrEmpty(bearer) || !bearer.StartsWith("vlt_", StringComparison.Ordinal) || bearer.Length is < 60 or > 145 ||
            policy.VaultCredentialExpiresAtUtc is null || policy.VaultCredentialExpiresAtUtc < DateTimeOffset.UtcNow.AddDays(2) ||
            !Path.IsPathFullyQualified(policy.PhpExecutable) || !File.Exists(policy.PhpExecutable) ||
            string.IsNullOrEmpty(policy.ExpectedLocalBuild) || !IsSha(policy.ExpectedManagedTreeSha256) ||
            string.IsNullOrEmpty(policy.SourceInstallationId) ||
            !Uri.TryCreate(policy.RemoteVaultEndpoint, UriKind.Absolute, out var u) ||
            u.Scheme != Uri.UriSchemeHttps || !u.AbsolutePath.EndsWith("/maintenance.php", StringComparison.Ordinal) ||
            u.UserInfo.Length != 0 || u.Query.Length != 0 || u.Fragment.Length != 0)
            throw new InvalidDataException("BACKUP_PROVISIONING_CONTRACT_INVALID");
        using (MachineMutationLease.Acquire(_paths, "backup-provision"))
        {
            lock (_gate)
            {
                var current = LoadState();
                if (current.State is "RUNNING" or "UNKNOWN" or "PREPARED" or "UPLOADING")
                    throw new InvalidOperationException("BACKUP_PENDING_ARTIFACT_MUST_RECONCILE");
                Directory.CreateDirectory(_root);
                var secret = JsonSerializer.SerializeToUtf8Bytes(new Dictionary<string, string>
                {
                    ["recovery_passphrase"] = passphrase, ["vault_bearer"] = bearer
                });
                byte[] encrypted;
                try { encrypted = ProtectedData.Protect(secret,
                    Encoding.UTF8.GetBytes("SOKNA-BK2B-RECOVERY-VAULT-CREDENTIALS-v1"), DataProtectionScope.LocalMachine); }
                finally { CryptographicOperations.ZeroMemory(secret); }
                // SecretsRoot ACL is owned by SecretStore and restricted to SYSTEM/Admin.
                // Fail closed on reparse/unsafe paths rather than falling back to plaintext.
                if ((File.GetAttributes(_paths.SecretsRoot) & FileAttributes.ReparsePoint) != 0 ||
                    (File.Exists(_sealed) && (File.GetAttributes(_sealed) & FileAttributes.ReparsePoint) != 0))
                    throw new InvalidDataException("BACKUP_SECRETS_PATH_UNSAFE");
                var temporary = _sealed + "." + Guid.NewGuid().ToString("N") + ".tmp";
                try
                {
                    using (var file = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write,
                        FileShare.None, 4096, FileOptions.WriteThrough))
                    { file.Write(encrypted); file.Flush(flushToDisk: true); }
                    File.Move(temporary, _sealed, overwrite: true);
                }
                finally { if (File.Exists(temporary)) File.Delete(temporary); CryptographicOperations.ZeroMemory(encrypted); }
                AtomicFile.WriteJson(_policy, policy);
                Save(current with { State = "BLOCKED", Code = "BACKUP_READY_AWAITING_FIRST_SCHEDULE",
                    NextDueUtc = null });
                _journal.Append("backup.provisioned", _supervisor.InstanceId, _supervisor.InstallGeneration,
                    new Dictionary<string, object?> { ["enabled"] = true,
                        ["credential_expiry_utc"] = policy.VaultCredentialExpiresAtUtc });
            }
        }
        return new { configured = true, secrets_returned = false, policy_enabled = true,
            vault_credential_expires_at_utc = policy.VaultCredentialExpiresAtUtc };
    }


    // Rotate only the limited vault.upload credential. This is NOT an admin session,
    // does not replace the user-held recovery passphrase, and never resets UNKNOWN.
    public object RenewUploadCredentialFromAdmin(string bearer, DateTimeOffset? expires)
    {
        if (string.IsNullOrEmpty(bearer) ||
            !bearer.StartsWith("vlt_", StringComparison.Ordinal) ||
            bearer.Length is < 60 or > 145 ||
            expires is null || expires <= DateTimeOffset.UtcNow.AddDays(2))
            throw new InvalidDataException("BACKUP_RENEWAL_CONTRACT_INVALID");
        using (MachineMutationLease.Acquire(_paths, "backup-credential-renew"))
        {
            lock (_gate)
            {
                var p = LoadPolicy() ?? throw new InvalidDataException("BACKUP_POLICY_MISSING");
                if (!File.Exists(_sealed) || (File.GetAttributes(_sealed) & FileAttributes.ReparsePoint) != 0)
                    throw new InvalidDataException("BACKUP_SECRETS_UNAVAILABLE");
                byte[] clear = ProtectedData.Unprotect(File.ReadAllBytes(_sealed),
                    Encoding.UTF8.GetBytes("SOKNA-BK2B-RECOVERY-VAULT-CREDENTIALS-v1"), DataProtectionScope.LocalMachine);
                byte[] replacement;
                try
                {
                    var original = JsonSerializer.Deserialize<Dictionary<string, string>>(clear)
                        ?? throw new InvalidDataException("BACKUP_SEALED_CREDENTIAL_INVALID");
                    if (!original.TryGetValue("recovery_passphrase", out var pass) || pass.Length < 16)
                        throw new InvalidDataException("BACKUP_RECOVERY_CREDENTIAL_INVALID");
                    replacement = JsonSerializer.SerializeToUtf8Bytes(new Dictionary<string, string>
                    { ["recovery_passphrase"] = pass, ["vault_bearer"] = bearer });
                }
                finally { CryptographicOperations.ZeroMemory(clear); }
                var encrypted = ProtectedData.Protect(replacement,
                    Encoding.UTF8.GetBytes("SOKNA-BK2B-RECOVERY-VAULT-CREDENTIALS-v1"), DataProtectionScope.LocalMachine);
                CryptographicOperations.ZeroMemory(replacement);
                var temporary = _sealed + "." + Guid.NewGuid().ToString("N") + ".tmp";
                try
                {
                    using (var file = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write,
                        FileShare.None, 4096, FileOptions.WriteThrough))
                    { file.Write(encrypted); file.Flush(flushToDisk: true); }
                    File.Move(temporary, _sealed, overwrite: true);
                }
                finally { if (File.Exists(temporary)) File.Delete(temporary); CryptographicOperations.ZeroMemory(encrypted); }
                AtomicFile.WriteJson(_policy, p with { VaultCredentialExpiresAtUtc = expires });
                // Existing PREPARED/UNKNOWN artifact stays immutable. Renewing tokens never
                // manufactures a new backup, advances the schedule or marks a receipt valid.
                _journal.Append("backup.credential_renewed", _supervisor.InstanceId, _supervisor.InstallGeneration,
                    new Dictionary<string, object?> { ["expires_at_utc"] = expires });
            }
        }
        return new { credential_renewed = true, vault_credential_expires_at_utc = expires,
            pending_artifact_preserved = true, secrets_returned = false };
    }

    public object DisableFromAdmin()
    {
        using (MachineMutationLease.Acquire(_paths, "backup-disable"))
        {
            lock (_gate)
            {
                var policy = LoadPolicy();
                if (policy is not null) AtomicFile.WriteJson(_policy, policy with { Enabled = false });
                _journal.Append("backup.disabled", _supervisor.InstanceId, _supervisor.InstallGeneration,
                    new Dictionary<string, object?> { ["disabled"] = true });
            }
        }
        return new { policy_enabled = false, secrets_returned = false };
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        try
        {
            Directory.CreateDirectory(_root);
            // A process crash after a committed RUNNING intent is UNKNOWN, NOT a failed job.
            lock (_gate)
            {
                var previous = LoadState();
                if (previous.State == "RUNNING")
                    Save(previous with { State = "UNKNOWN", Code = "BACKUP_RESTART_OUTCOME_UNKNOWN" });
            }
        }
        catch (Exception ex)
        {
            // Corrupt/unknown backup evidence cannot be overwritten by a new attempt.
            Console.Error.WriteLine("Backup scheduler disabled: " + ex.GetType().Name);
            return;
        }

        while (!stoppingToken.IsCancellationRequested)
        {
            try { await TickAsync(stoppingToken); }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested) { break; }

            catch (Exception ex)
            {
                // Only symbolic error evidence is recorded for the standard Support Bundle.
                Console.Error.WriteLine("Backup scheduler tick blocked: " + ex.GetType().Name);
                try
                {
                    _journal.Append("backup.tick.failed", _supervisor.InstanceId, _supervisor.InstallGeneration,
                        new Dictionary<string, object?> { ["error_type"] = ex.GetType().Name,
                            ["code"] = "BACKUP_TICK_EXCEPTION" });
                    ChangeIfNotUnknown("BLOCKED", "BACKUP_TICK_EXCEPTION");
                }
                catch (Exception) { /* evidence remains fail-closed; never launch a second job */ }
            }
            try { await Task.Delay(TimeSpan.FromMinutes(1), stoppingToken); }
            catch (OperationCanceledException) { break; }
        }
    }

    private async Task TickAsync(CancellationToken stop)
    {
        var policy = LoadPolicy();
        if (policy is null || !policy.Enabled) return;
        if (!Validate(policy, out var code)) { ChangeIfNotUnknown("BLOCKED", code); return; }
        var previous = LoadState();
        if (previous.State == "EVIDENCE_UNAVAILABLE") return;
        if (previous.BackupId is not null && previous.State is not ("UNKNOWN" or "PREPARED" or "UPLOADING"))
        { Save(previous with { State = "UNKNOWN", Code = "BACKUP_PENDING_ARTIFACT_PRESERVED" }); previous = LoadState(); }
        if (previous.State == "REMOTE_VERIFIED" && previous.NextDueUtc > DateTimeOffset.UtcNow) return;
        if (previous.State == "BLOCKED" && previous.NextDueUtc > DateTimeOffset.UtcNow) return;
        // Single machine lease spans local snapshot, remote transfer AND reconciliation.
        // Updater uses the same lease; it cannot run concurrently.
        MachineMutationLease lease;
        try { lease = MachineMutationLease.Acquire(_paths, "backup-scheduled"); }
        catch (InvalidOperationException) { ChangeIfNotUnknown("BLOCKED", "BACKUP_MACHINE_MUTATION_LEASE_BUSY"); return; }
        using (lease)
        {
            if (RecoveryRequiredOnDisk() || _operations.ListBlocking().Count > 0)
            { ChangeIfNotUnknown("BLOCKED", "UPDATE_OR_RECOVERY_ACTIVE"); return; }
            LocalInstallLayout layout;
            try { layout = new LocalSlotManager(_paths).InspectHealthyCurrent(); }
            catch (Exception ex) when (ex is InvalidDataException or IOException or InvalidOperationException or UnauthorizedAccessException)
            { ChangeIfNotUnknown("BLOCKED", "LOCAL_CURRENT_UNVERIFIED"); return; }
            if (layout.Build != policy.ExpectedLocalBuild)
            { ChangeIfNotUnknown("BLOCKED", "SOURCE_VERSION_CHANGED"); return; }
            try { VerifyInstalledSource(layout, policy); }
            catch (Exception ex) when (ex is InvalidDataException or IOException or JsonException or UnauthorizedAccessException)
            { ChangeIfNotUnknown("BLOCKED", "BACKUP_INSTALLED_SOURCE_UNVERIFIED"); return; }
            if (_operations.ListBlocking().Count > 0) { ChangeIfNotUnknown("BLOCKED", "UPDATE_OR_RECOVERY_ACTIVE"); return; }
            previous = LoadState();
            if (previous.State == "RUNNING")
            { Save(previous with { State = "UNKNOWN", Code = "BACKUP_INTERRUPTED" }); previous = LoadState(); }
            if (previous.State is "UNKNOWN" or "PREPARED" or "UPLOADING")
            {
                await ResumeExistingAsync(layout, policy, previous, stop);
                return;
            }
            // This step is local-only: it must NOT contact Vault or create a remote artifact.
            // A crash here may leave an orphan LOCAL ciphertext, but no remote duplicate.
            var now = DateTimeOffset.UtcNow;
            var running = previous with { State = "RUNNING", Code = "BACKUP_LOCAL_PREPARE_STARTED",
                OperationId = Guid.NewGuid().ToString("N"), StartedAtUtc = now,
                LastAttemptAtUtc = now, NextDueUtc = null, BackupId = null,
                ArtifactSha256 = null, ArtifactSize = null, ArtifactInstallationId = null };
            Save(running);
            _journal.Append("backup.prepare.started", _supervisor.InstanceId, _supervisor.InstallGeneration,
                new Dictionary<string, object?> { ["operation_id"] = running.OperationId });
            BackupJobReceipt prepared;
            try { prepared = await BackupPhpJobRunner.RunAsync(layout, policy, _sealed, "prepare", null, stop); }
            catch (Exception ex)
            {
                // prepare never transmits remotely. It can safely be retried later.
                Save(running with { State = "BLOCKED", Code = "BACKUP_PREPARE_FAILED", OperationId = null,
                    NextDueUtc = DateTimeOffset.UtcNow.AddMinutes(30) });
                _journal.Append("backup.prepare.failed", _supervisor.InstanceId, _supervisor.InstallGeneration,
                    new Dictionary<string, object?> { ["error_type"] = ex.GetType().Name, ["code"] = "BACKUP_PREPARE_FAILED" });
                return;
            }
            if (prepared.State != "PREPARED" || !ValidArtifact(prepared, policy.SourceInstallationId))
            { Save(running with { State = "UNKNOWN", Code = "BACKUP_PREPARE_IDENTITY_INVALID" }); return; }
            // Durable state with immutable backup_id/SHA/size MUST be committed before upload.
            var pending = running with { State = "PREPARED", Code = "BACKUP_LOCAL_ARTIFACT_PREPARED",
                OperationId = null, BackupId = prepared.BackupId, ArtifactSha256 = prepared.Sha256,
                ArtifactSize = prepared.Size, ArtifactInstallationId = prepared.SourceInstallationId };
            Save(pending);
            _journal.Append("backup.prepared", _supervisor.InstanceId, _supervisor.InstallGeneration,
                new Dictionary<string, object?> { ["backup_id"] = pending.BackupId, ["sha256"] = pending.ArtifactSha256 });
            await ResumeExistingAsync(layout, policy, pending, stop);
        }
    }

    private static bool ValidArtifact(BackupJobReceipt a, string installationId) =>
        a.BackupId.StartsWith("business-", StringComparison.Ordinal) &&
        a.BackupId.Length <= 109 && IsSha(a.Sha256) && a.Size >= 64 &&
        a.SourceInstallationId == installationId;

    private async Task ResumeExistingAsync(LocalInstallLayout layout, BackupSchedulePolicy policy,
        BackupJobState pending, CancellationToken stop)
    {
        // Fail closed on old/corrupt records that have no immutable artifact identity.
        if (pending.BackupId is null || pending.ArtifactSha256 is null || pending.ArtifactSize is null ||
            pending.ArtifactInstallationId != policy.SourceInstallationId ||
            !pending.BackupId.StartsWith("business-", StringComparison.Ordinal) ||
            !IsSha(pending.ArtifactSha256) || pending.ArtifactSize < 64)
        { Save(pending with { State = "UNKNOWN", Code = "BACKUP_RECONCILE_IDENTITY_MISSING" }); return; }
        var identity = new BackupJobReceipt("PREPARED", pending.BackupId,
            pending.ArtifactSha256, "", pending.ArtifactSize.Value, pending.ArtifactInstallationId);
        BackupJobReceipt remote;
        try { remote = await BackupPhpJobRunner.RunAsync(layout, policy, _sealed, "reconcile", identity, stop); }
        catch (Exception ex)
        {
            Save(pending with { State = "UNKNOWN", Code = "BACKUP_RECONCILE_REMOTE_UNAVAILABLE" });
            _journal.Append("backup.reconcile.blocked", _supervisor.InstanceId, _supervisor.InstallGeneration,
                new Dictionary<string, object?> { ["backup_id"] = identity.BackupId, ["error_type"] = ex.GetType().Name });
            return;
        }
        if (!ValidArtifact(remote, policy.SourceInstallationId) || remote.BackupId != identity.BackupId ||
            remote.Sha256 != identity.Sha256 || remote.Size != identity.Size)
        { Save(pending with { State = "UNKNOWN", Code = "BACKUP_RECONCILE_IDENTITY_CONFLICT" }); return; }
        if (remote.State == "REMOTE_VERIFIED") { CommitVerified(pending, policy, remote); return; }
        if (remote.State is not ("REMOTE_UPLOADING" or "REMOTE_NOT_FOUND"))
        { Save(pending with { State = "UNKNOWN", Code = "BACKUP_RECONCILE_UNEXPECTED_REMOTE_STATE" }); return; }
        // Both confirmed absence and partial upload resume ONLY the same immutable artifact.
        var uploading = pending with { State = "UPLOADING", Code = "BACKUP_RESUMING_SAME_ARTIFACT" };
        Save(uploading);
        try
        {
            var receipt = await BackupPhpJobRunner.RunAsync(layout, policy, _sealed, "upload", identity, stop);
            if (!ValidArtifact(receipt, policy.SourceInstallationId) || receipt.State != "REMOTE_VERIFIED" ||
                receipt.BackupId != identity.BackupId || receipt.Sha256 != identity.Sha256 ||
                receipt.Size != identity.Size) throw new InvalidDataException("BACKUP_RECEIPT_IDENTITY_CONFLICT");
            CommitVerified(uploading, policy, receipt);
        }
        catch (Exception ex)
        {
            Save(uploading with { State = "UNKNOWN", Code = "BACKUP_UPLOAD_OUTCOME_UNKNOWN" });
            _journal.Append("backup.upload.unknown", _supervisor.InstanceId, _supervisor.InstallGeneration,
                new Dictionary<string, object?> { ["backup_id"] = identity.BackupId,
                    ["sha256"] = identity.Sha256, ["error_type"] = ex.GetType().Name });
        }
    }

    private void CommitVerified(BackupJobState previous, BackupSchedulePolicy policy, BackupJobReceipt receipt)
    {
        if (!ValidArtifact(receipt, policy.SourceInstallationId) ||
            !receipt.RemoteReceiptId.StartsWith("rct_", StringComparison.Ordinal) ||
            receipt.RemoteReceiptId.Length != 44)
        { Save(previous with { State = "UNKNOWN", Code = "BACKUP_REMOTE_RECEIPT_INVALID" }); return; }
        var now = DateTimeOffset.UtcNow;
        Save(previous with { State = "REMOTE_VERIFIED", Code = "BACKUP_REMOTE_VERIFIED",
            OperationId = null, LastRemoteVerifiedAtUtc = now,
            NextDueUtc = now.AddHours(policy.IntervalHours), LastVerifiedBackupId = receipt.BackupId,
            LastVerifiedSha256 = receipt.Sha256, LastRemoteReceiptId = receipt.RemoteReceiptId,
            BackupId = null, ArtifactSha256 = null, ArtifactSize = null, ArtifactInstallationId = null });
        _journal.Append("backup.remote_verified", _supervisor.InstanceId, _supervisor.InstallGeneration,
            new Dictionary<string, object?> { ["backup_id"] = receipt.BackupId, ["sha256"] = receipt.Sha256,
                ["receipt_id"] = receipt.RemoteReceiptId });
    }

    private bool RecoveryRequiredOnDisk()
    {
        // State may change after worker construction; startup value is NOT authoritative.
        var current = AtomicFile.ReadJson<SupervisorState>(_paths.StatePath);
        if (current.RecoveryRequired) return true;
        var migration = Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");
        if (File.Exists(migration))
        {
            using var d = JsonDocument.Parse(File.ReadAllBytes(migration));
            if (d.RootElement.GetProperty("recovery_required").GetBoolean()) return true;
        }
        return false;
    }

    private bool Validate(BackupSchedulePolicy p, out string code)
    {
        code = "OK";
        if (p.Format != "sokna-maintenance-backup-policy-v1" || p.IntervalHours is < 1 or > 168)
            { code = "BACKUP_POLICY_INVALID"; return false; }
        if (!p.RecoveryCopyExternallyConfirmed) { code = "RECOVERY_COPY_NOT_ACKNOWLEDGED"; return false; }
        if (p.VaultCredentialExpiresAtUtc is null || p.VaultCredentialExpiresAtUtc <= DateTimeOffset.UtcNow.AddHours(24))
            { code = "VAULT_UPLOAD_CREDENTIAL_EXPIRED_OR_RENEWAL_DUE"; return false; }
        if (!File.Exists(_sealed)) { code = "RECOVERY_CREDENTIAL_NOT_PROVISIONED"; return false; }
        if (!Path.IsPathFullyQualified(p.PhpExecutable) || !File.Exists(p.PhpExecutable))
            { code = "PHP_CLI_NOT_VERIFIED"; return false; }
        if (string.IsNullOrWhiteSpace(p.ExpectedLocalBuild) || !IsSha(p.ExpectedManagedTreeSha256)
            || string.IsNullOrWhiteSpace(p.SourceInstallationId))
            { code = "BACKUP_SOURCE_IDENTITY_UNVERIFIED"; return false; }
        if (!Uri.TryCreate(p.RemoteVaultEndpoint, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps
            || !uri.AbsolutePath.EndsWith("/maintenance.php", StringComparison.Ordinal) ||
            !string.IsNullOrEmpty(uri.Query) || !string.IsNullOrEmpty(uri.Fragment) ||
            !string.IsNullOrEmpty(uri.UserInfo))
            { code = "REMOTE_VAULT_ENDPOINT_UNVERIFIED"; return false; }
        return true;
    }

    // Do not trust release metadata supplied by an operator policy on its own.
    // The canonical inventory hash and installation binding are checked AGAINST
    // active installed bytes before any privileged PHP subprocess starts.
    private static void VerifyInstalledSource(LocalInstallLayout layout, BackupSchedulePolicy policy)
    {
        RoutineLocalBinding.RequireCurrent(layout.WebRoot, layout.PackageRoot, layout.Build);
        using (var binding = JsonDocument.Parse(File.ReadAllBytes(Path.Combine(layout.WebRoot, ".sokna-installation.json"))))
        {
            var installation = binding.RootElement.GetProperty("installation_id").GetString();
            if (!string.Equals(installation, policy.SourceInstallationId, StringComparison.Ordinal))
                throw new InvalidDataException("BACKUP_INSTALLATION_ID_MISMATCH");
        }
        using var document = JsonDocument.Parse(File.ReadAllBytes(Path.Combine(layout.WebRoot, "resources", "local-managed-files-v1.json")));
        var paths = document.RootElement.GetProperty("files").EnumerateArray()
            .Select(x => x.GetString() ?? "").ToArray();
        if (paths.Length is < 100 or > 10000 || paths.Distinct(StringComparer.Ordinal).Count() != paths.Length)
            throw new InvalidDataException("BACKUP_MANIFEST_INVALID");
        var canonical = new StringBuilder();
        foreach (var relative in paths.Order(StringComparer.Ordinal))
        {
            var path = relative.Replace('\\', '/');
            if (path.Length == 0 || path.StartsWith('/') || path.Contains("..", StringComparison.Ordinal)
                || path.Contains(':')) throw new InvalidDataException("BACKUP_PATH_INVALID");
            if (path is "resources/release-identity-v1.json" or "resources/build-provenance.json") continue;
            var full = Path.GetFullPath(Path.Combine(layout.WebRoot, path.Replace('/', Path.DirectorySeparatorChar)));
            if (!full.StartsWith(Path.GetFullPath(layout.WebRoot).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar,
                StringComparison.OrdinalIgnoreCase) || !File.Exists(full)
                || (File.GetAttributes(full) & FileAttributes.ReparsePoint) != 0)
                throw new InvalidDataException("BACKUP_MANAGED_TREE_MISSING");
            var bytes = File.ReadAllBytes(full);
            canonical.Append(path).Append('\0').Append(bytes.Length).Append('\0')
                .Append(Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant()).Append('\n');
        }
        var calculated = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(canonical.ToString()))).ToLowerInvariant();
        if (!string.Equals(calculated, policy.ExpectedManagedTreeSha256, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("BACKUP_MANAGED_TREE_CHANGED");
        using var releaseDoc = JsonDocument.Parse(File.ReadAllBytes(Path.Combine(layout.WebRoot, "resources", "release-identity-v1.json")));
        var source = releaseDoc.RootElement.GetProperty("source");
        var identitySha = source.GetProperty("canonical_tree_sha256").GetString();
        var version = releaseDoc.RootElement.GetProperty("version").GetString();
        if (!string.Equals(identitySha, calculated, StringComparison.OrdinalIgnoreCase)
            || !string.Equals(version, layout.Build, StringComparison.Ordinal))
            throw new InvalidDataException("BACKUP_RELEASE_IDENTITY_STALE");
    }

    private static bool IsSha(string x) => x.Length == 64 && x.All(char.IsAsciiHexDigit);
    private BackupSchedulePolicy? LoadPolicy() => File.Exists(_policy)
        ? AtomicFile.ReadJson<BackupSchedulePolicy>(_policy) : null;
    private BackupJobState LoadState()
    {
        if (!File.Exists(_state)) return new BackupJobState();
        var s = AtomicFile.ReadJson<BackupJobState>(_state);
        if (s.Format != "sokna-maintenance-backup-job-v1") throw new InvalidDataException("BACKUP_STATE_FORMAT_INVALID");
        return s;
    }
    private void Save(BackupJobState state) { lock (_gate) AtomicFile.WriteJson(_state, state); }
    private void ChangeIfNotUnknown(string state, string code)
    {
        lock (_gate)
        {
            var previous = LoadState();
            if (previous.State is "RUNNING" or "UNKNOWN" or "PREPARED" or "UPLOADING" ||
                previous.BackupId is not null)
            {
                if (previous.State != "UNKNOWN" || previous.Code != code)
                    Save(previous with { State = "UNKNOWN", Code = code, NextDueUtc = null });
                return;
            }
            if (previous.State == state && previous.Code == code) return;
            Save(previous with { State = state, Code = code, NextDueUtc = DateTimeOffset.UtcNow.AddMinutes(30) });
        }
    }
}

public sealed record BackupJobReceipt(string State,string BackupId,string Sha256,string RemoteReceiptId,long Size,string SourceInstallationId);

internal static class BackupPhpJobRunner
{
    public static async Task<BackupJobReceipt> RunAsync(LocalInstallLayout layout, BackupSchedulePolicy policy,
        string sealedPath, string mode, BackupJobReceipt? artifact, CancellationToken stop)
    {
        var sourceScript = Path.GetFullPath(Path.Combine(layout.WebRoot, "tools", "maintenance-backup-job.php"));
        if (!File.Exists(sourceScript) || (File.GetAttributes(sourceScript) & FileAttributes.ReparsePoint) != 0)
            throw new InvalidDataException("BACKUP_CLI_NOT_IN_MANAGED_SOURCE");
        // DPAPI LocalMachine only protects the cached operational copy. Recovery remains
        // user-held, portable, and MUST be externally escrowed before policy enabled.
        byte[] clear = ProtectedData.Unprotect(File.ReadAllBytes(sealedPath),
            Encoding.UTF8.GetBytes("SOKNA-BK2B-RECOVERY-VAULT-CREDENTIALS-v1"), DataProtectionScope.LocalMachine);
        Dictionary<string,string> parsed;
        try { parsed = JsonSerializer.Deserialize<Dictionary<string,string>>(clear)
            ?? throw new InvalidDataException("BACKUP_SEALED_CREDENTIAL_INVALID"); }
        finally { Array.Clear(clear); }
        if (!parsed.TryGetValue("recovery_passphrase", out var pass) || pass.Length < 12 ||
            !parsed.TryGetValue("vault_bearer", out var bearer) || !bearer.StartsWith("vlt_", StringComparison.Ordinal))
            throw new InvalidDataException("BACKUP_SEALED_CREDENTIAL_INVALID");
        var payload = JsonSerializer.Serialize(new {
            mode, backup_id = artifact?.BackupId, sha256 = artifact?.Sha256,
            size = artifact?.Size, recovery_passphrase = pass, vault_bearer = bearer,
            vault_endpoint = policy.RemoteVaultEndpoint,
            source_installation_id = policy.SourceInstallationId,
            source_release = new { version = layout.Build, managed_tree_sha256 = policy.ExpectedManagedTreeSha256 },
            protection_key_id = "user-held-recovery-passphrase-v1"
        });
        var start = new ProcessStartInfo {
            FileName = policy.PhpExecutable, UseShellExecute = false,
            CreateNoWindow = true, RedirectStandardInput = true,
            RedirectStandardOutput = true, RedirectStandardError = true
        };
        start.ArgumentList.Add(sourceScript); // NO secret, token, or passphrase in argv.
        using var child = Process.Start(start) ?? throw new IOException("BACKUP_PHP_FAILED_TO_START");
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(stop);
        timeout.CancelAfter(TimeSpan.FromMinutes(40));
        try
        {
            await child.StandardInput.WriteAsync(payload.AsMemory(), timeout.Token);
            child.StandardInput.Close();
            // Never log stderr or stdout. These may contain sensitive system context.
            var readOut = child.StandardOutput.ReadToEndAsync(timeout.Token);
            var readErr = child.StandardError.ReadToEndAsync(timeout.Token);
            await child.WaitForExitAsync(timeout.Token);
            var output = await readOut;
            _ = await readErr;
            if (child.ExitCode != 0 || output.Length > 8192) throw new IOException("BACKUP_PHP_JOB_UNKNOWN");
            using var doc = JsonDocument.Parse(output);
            var value = doc.RootElement;
            if (value.GetProperty("format").GetString() != "sokna-maintenance-backup-job-result-v2")
                throw new InvalidDataException("BACKUP_CHILD_CONTRACT_INVALID");
            var code = value.GetProperty("code").GetString() ?? "";
            if (code.Length == 0) throw new InvalidDataException("BACKUP_CHILD_CODE_MISSING");
            return new BackupJobReceipt(value.GetProperty("state").GetString() ?? "",
                value.GetProperty("backup_id").GetString() ?? "",
                value.GetProperty("sha256").GetString() ?? "",
                value.TryGetProperty("remote_receipt_id", out var rec) ? rec.GetString() ?? "" : "",
                value.GetProperty("size").GetInt64(),
                value.GetProperty("source_installation_id").GetString() ?? "");
        }
        catch
        {
            try { if (!child.HasExited) child.Kill(entireProcessTree:true); } catch { }
            throw;
        }
    }
}