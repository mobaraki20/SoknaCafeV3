from pathlib import Path
p=Path('windows/maintenance/src/Sokna.Maintenance.Core/ReleaseSetVerifierStager.cs').read_text(encoding='utf-8')
q=Path('windows/maintenance/src/Sokna.Maintenance.Core/ReleaseInstallQualificationGate.cs').read_text(encoding='utf-8')
assert 'RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN' in p
assert 'manifest.ProductionPass' in p
assert 'q.RealWindows' in q and 'q.RealMariaDb' in q
assert 'q.RecoveryConsoleWebDown' in q and 'q.BusinessBackupConsistencyRealMariaDb' in q
print('PASS R182_NO_STAGE_GATE_BYPASS_POLICY_IN_SOURCE')
