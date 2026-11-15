from pathlib import Path
from zipfile import ZipFile
import hashlib,json,subprocess,shutil,base64
R=Path('/mnt/data/sokna_emergency'); OUT=R/'R247-OWNER-RECOVERY'; ROOT=R/'test-r247-public'
if ROOT.exists():shutil.rmtree(ROOT)
ROOT.mkdir()
with ZipFile(R/'SOKNA-R171-PUBLIC-35HT-FROM-35HK-PANEL-UPDATE.zip') as z:
 for item in z.namelist():
  if not item.startswith('payload/') or item.endswith('/'):continue
  p=ROOT/item[8:];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(item))
with ZipFile('/mnt/data/SOKNA-Public-35es-UNIFIED-SOURCE-QUALIFICATION-20261024.zip') as z:
 for n in ['EmergencyAccessService.php','PublicUpdateException.php','PairingSecretStore.php','PublicTakeoverService.php','PublicUpdateService.php']:
  p=ROOT/'src/Emergency'/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read('source/public/src/Emergency/'+n))
old_access=(ROOT/'src/Emergency/EmergencyAccessService.php').read_bytes()+b'\n// historical service fixture\n'
(ROOT/'src/Emergency/EmergencyAccessService.php').write_bytes(old_access)
old_entry=b'<?php declare(strict_types=1); echo "Historical owner fixture";\n'
(ROOT/'emergency.php').write_bytes(old_entry)
(ROOT/'config.php').write_text("<?php return ['app'=>['storage_dir'=>__DIR__.'/storage']];\n")
(ROOT/'storage/emergency').mkdir(parents=True)
(ROOT/'storage/emergency/access.json').write_text(json.dumps({'format':'sokna-public-emergency-access-v1','password_hash': '$2y$12$invalid_fixture_for_direct_function_test'}))
(ROOT/'storage/emergency/update').mkdir(parents=True)
(ROOT/'storage/emergency/update/state.json').write_text(json.dumps({'staged_version':''}))
(ROOT/'storage/routine-update-v2').mkdir(parents=True)
manifest=json.loads(ZipFile(R/'SOKNA-R171-PUBLIC-35HT-FROM-35HK-PANEL-UPDATE.zip').read('manifest.json'))
state={'format':'sokna-public-routine-update-state-v2','schema_version':2,'active_version':manifest['version'],'active_managed_tree_sha256':manifest['target_managed_tree_sha256'],'previous_version':'','lkg_recovery_id':'','staged':None,'pending_verification':None,'activation_journal':None,'db_forward_fence':{'protected':'keep'},'history':[]}
(ROOT/'storage/routine-update-v2/state.json').write_text(json.dumps(state))
(ROOT/'tools').mkdir()
inst=(OUT/'sokna-emergency-r247-safe-installer.php').read_text()
blob=lambda b:hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
inst=inst.replace('85b028d832788a06d5d6ba9f8255b8094f50faee',blob(old_entry)).replace('54e2ed8fcd8100863ca3686a22789d5a1b46766e',blob(old_access))
(ROOT/'tools/sokna-emergency-r247-safe-installer.php').write_text(inst)
php=ROOT/'tools'/'test_cli.php'
php.write_text('''<?php
$_SERVER['HTTPS']='on';$_SERVER['SCRIPT_NAME']='/public/tools/sokna-emergency-r247-safe-installer.php';
ob_start();require __DIR__.'/sokna-emergency-r247-safe-installer.php';ob_end_clean();
$root=dirname(__DIR__);$storage=$root.'/storage';
$pre=r247_gate($root,$storage);
if(!$pre['ok']||!$pre['installable']){echo "PREFAIL ".json_encode($pre)."\\n";exit(1);}echo "PASS preflight\\n";
$oldManaged=(new \\Sokna\\PublicEdge\\Update\\RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json'))->managedTreeDigest();
$id=r247_install($root,$storage);echo "PASS install id $id\\n";
$after=r247_gate($root,$storage);
if(!$after['ok']||!$after['installed']){echo json_encode($after)."\\n";exit(2);}echo "PASS readback\\n";
$managed=(new \\Sokna\\PublicEdge\\Update\\RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json'))->managedTreeDigest();
if($managed!==$oldManaged)exit(3);echo "PASS managed tree preserved\\n";
$state=json_decode(file_get_contents($storage.'/routine-update-v2/state.json'),true);
if(!isset($state['db_forward_fence']))exit(4);echo "PASS db forward fence preserved\\n";
// Isolated web-like GET of the actual new console (no writes).
$probe=__DIR__.'/probe_new_ui.php';
file_put_contents($probe,'<?php $_SERVER["HTTPS"]="on";$_SERVER["SERVER_PORT"]=443;$_SERVER["REQUEST_URI"]="/public/emergency.php";$_SERVER["SCRIPT_NAME"]="/public/emergency.php";require dirname(__DIR__)."/emergency.php";');
$command='php '.escapeshellarg($probe);
$screen=shell_exec($command);
if(!is_string($screen)||!str_contains($screen,'SOKNA')||!str_contains($screen,'رمز دسترسی اضطراری')){echo "UI_RENDER_FAIL ".substr((string)$screen,0,100)."\n";exit(11);}
unlink($probe);echo "PASS new GUI HTTP-style render\n";
r247_restore($root,$storage);
if(file_get_contents($root.'/emergency.php')!=="<?php declare(strict_types=1); echo \\\"Historical owner fixture\\\";\\n")exit(5);
echo "PASS rollback exact old entry\\n";
$end=r247_gate($root,$storage);echo "DONE ".json_encode(['reinstall_permitted'=>$end['ok']])."\\n";
''')
p=subprocess.run(['php','-d','display_errors=1','-d','error_reporting=E_ALL',str(php)],capture_output=True,text=True,timeout=20)
print(p.stdout,p.stderr,'returncode=',p.returncode)
if p.returncode!=0:raise SystemExit(p.returncode)
