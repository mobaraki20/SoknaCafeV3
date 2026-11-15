"""Failure-mode test on the disposable fixture created by test_r247.py."""
from pathlib import Path
import subprocess,json,hashlib
R=Path('/mnt/data/sokna_emergency/test-r247-public')
# restore test script already created a protected repair marker; for the failure matrix
# remove it only on this disposable QA fixture (not on production).
marker=R/'storage/emergency/r247-repair/latest.json'
if marker.exists():marker.unlink()
probe=R/'tools/negative_test.php'
probe.write_text('''<?php
$_SERVER['HTTPS']='on';$_SERVER['SCRIPT_NAME']='/public/tools/sokna-emergency-r247-safe-installer.php';
ob_start();require __DIR__.'/sokna-emergency-r247-safe-installer.php';ob_end_clean();
$root=dirname(__DIR__);$storage=$root.'/storage';
$s=r247_gate($root,$storage);echo json_encode($s),"\\n";
''')
def check(label,mutate,undo):
 try:
  mutate()
  p=subprocess.run(['php',str(probe)],capture_output=True,text=True,timeout=12)
  assert p.returncode==0,(label,p.stderr)
  response=json.loads(p.stdout.strip());assert response['ok'] is False,(label,response)
  assert not marker.exists(),label+': unexpected mutation'
  print('PASS fail closed',label,', blocked=',','.join(k for k,v in response['checks'].items() if not v))
 finally:undo()
vers=R/'VERSION.txt';original=vers.read_bytes()
check('wrong_version',lambda:vers.write_bytes(b'3.0.0.WRONG\n'),lambda:vers.write_bytes(original))
bridge=R/'src/Update/IndependentEmergencyRoutineBridge.php';original=bridge.read_bytes()
check('changed_bridge_hash',lambda:bridge.write_bytes(original+b'\n// tampered\n'),lambda:bridge.write_bytes(original))
entry=R/'emergency.php';original=entry.read_bytes()
check('unknown_live_entrypoint',lambda:entry.write_bytes(b'<?php echo 1;'),lambda:entry.write_bytes(original))
state=R/'storage/routine-update-v2/state.json';original=state.read_bytes();object=json.loads(original);object['pending_verification']={'test':True}
check('pending_update',lambda:state.write_text(json.dumps(object)),lambda:state.write_bytes(original))
probe.unlink()
print('PASS all negative scenarios preserve live files in disposable fixture')
