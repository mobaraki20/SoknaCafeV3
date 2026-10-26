#!/usr/bin/env python3
"""Source guards only; runtime verification lives in the Windows runner."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/"src/Sokna.Maintenance.Supervisor/BackupSchedulerWorker.cs").read_text()
p=(Path(__file__).resolve().parents[1]/"src/Sokna.Maintenance.Supervisor/Program.cs").read_text()
checks={
"hosted in existing supervisor": 'AddHostedService(provider => provider.GetRequiredService<BackupSchedulerWorker>())' in p,
"shared updater mutex": 'MachineMutationLease.Acquire(_paths, "backup-scheduled")' in s,
"immutable ledger": all(x in s for x in ('BackupId { get; init; }','ArtifactSha256 { get; init; }','ArtifactSize { get; init; }','ArtifactInstallationId { get; init; }')),
"durable before upload": s.index('Save(pending);')<s.index('await ResumeExistingAsync(layout, policy, pending, stop);'),
"remote reconciliation": s.index('"reconcile", identity')<s.index('"upload", identity'),
"no blind retry": 'previous.State is "UNKNOWN" or "PREPARED" or "UPLOADING"' in s,
"scoped admin adapter": 'keyId != "web-adapter"' in p and 'isBackupProvision' in p,
"vault secrets in DPAPI": 'ProtectedData.Protect' in s and 'ProtectedData.Unprotect' in s,
"secrets via child stdin": 'RedirectStandardInput = true' in s and 'WriteAsync(payload.AsMemory()' in s,
"no runtime success claim": 'release_qualified = false' in s,
"real backup status replaces placeholder": '"BACKUP_ENGINE_PENDING"' not in p and 'worker.ReadStatus()' in p,
}
for name, good in checks.items(): print(("PASS" if good else "FAIL")+" "+name)
if not all(checks.values()): raise SystemExit(1)
