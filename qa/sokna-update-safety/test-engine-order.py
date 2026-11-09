"""Ordering contract supplements actual PHP/MariaDB execution and Engine build."""
from pathlib import Path
root=Path(__file__).resolve().parents[2]
code=(root/'windows/maintenance/src/Sokna.Maintenance.Core/ProductionLocalSlotRoutine.cs').read_text()
backup_check=code.index('RoutineLocalDatabase.RequireBackupPreflight(')
assert backup_check<code.index('state = RoutineEvent(state, events, "APACHE_STOP_INTENT"')
assert code.index('RoutineLocalDatabase.RequireReadOnlyGuardPreflight(')<backup_check
helper=(root/'windows/maintenance/src/Sokna.Maintenance.Core/RoutineLocalDatabase.php').read_text()
assert helper.index("if ($mode==='backup-preflight') { echo 'SOKNA_DATABASE_OK'; exit(0); }")<helper.index("$options=$backup.'.credentials'")
print('PASS actual Engine backup prerequisite call before stop intent; read-only helper exits before credential file')
