# CI-only configured snapshot authority fixture. No real host identifiers or keys.
import pathlib as P,os,sys,json,hashlib,base64,zipfile,io,time,shutil,subprocess
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
root=P.Path(os.environ['RUNNER_TEMP']); code=root/'handoff-code';code.mkdir()
assert os.environ['GITHUB_ACTIONS']=='true'
raw=base64.b64decode((P.Path(os.environ['GITHUB_WORKSPACE'])/'qa/checkpoint92/handoff-code-only.b64').read_bytes(),validate=True)
assert hashlib.sha256(raw).hexdigest()=='0fa4dd8bb3cbf0aa8a9a3789a53c483f9cd6f2bfca7f06fc321f0682ad530c32'
with zipfile.ZipFile(io.BytesIO(raw)) as z:
 assert z.testzip() is None and set(z.namelist())=={'handoff.py','manifest_gate.py','contract.json'}
 z.extractall(code)
transfer=base64.b64decode((P.Path(os.environ['GITHUB_WORKSPACE'])/'qa/checkpoint93/operator-code.b64').read_bytes(),validate=True)
assert hashlib.sha256(transfer).hexdigest()=='564adbcc9e4f8cbc6bb780447c211ca26ac7e1cdd77c619174adc9a2c1886ce2'
with zipfile.ZipFile(io.BytesIO(transfer)) as z:
 assert z.testzip() is None and set(z.namelist())=={'owner_clone.py','clone.php'}
 z.extractall(code)
extra=base64.b64decode((P.Path(os.environ['GITHUB_WORKSPACE'])/'qa/checkpoint94/config-code.b64').read_bytes(),validate=True)
assert hashlib.sha256(extra).hexdigest()=='2b01cff739c5e7a75895931f3b9f3c4aa052f332e3ddd3a90a86fc2de37fc438'
with zipfile.ZipFile(io.BytesIO(extra)) as z:
 assert z.testzip() is None and set(z.namelist())=={'config_binding.php','seal_runtime.py','test_config_binding.php'}
 z.extractall(code)
sys.path.insert(0,str(code))
from handoff import H,J,strict,R,E,inspect,prepare,inventory,DOMAIN
assert H((code/'handoff.py').read_bytes())=='15a72bf6f95634ae3be16ee87e3799ef857078b59b1cb132e8e5a4ee547f6350'
assert H((code/'manifest_gate.py').read_bytes())=='bdde0ff21f4e4d97be5f5c584b6450292be9e2bb4f896bf6650855ab2d072682'
c=strict((code/'contract.json').read_bytes());assert H((code/'contract.json').read_bytes())=='42b7722b3215dd87d8765c160f4e51fd77dcbc090e8bb1253db3892aed52a09f'
new=root/'fixture/new-web'
for n,w in c['new_pins'].items():
 b=(new/n).read_bytes();assert H(b)==w;p=code/'patch'/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
src=root/'old-web';v=(src/'VERSION.txt').read_text().strip();b=c['baselines'][v]
ledger=c['ledger'][:8]
fence={'transaction_id':'20260101-000000-cp93-fixture','schema_owner_version':'synthetic-historical-owner','pending_at_boundary':ledger[-2:],'policy':'forward_recovery_only'}
config={'db':{'host':'127.0.0.1','port':'3306','name':'sokna_cp92_source','charset':'utf8mb4','user':'unused_original_app','pass':'disposable-original-not-used'},'app':{'default_installation_id':'cp92-fixture','cookie_secure':True,'storage_dir':str(src/'storage'),'base_path':'/public','base_url':'https://fixture.invalid/public'},'relay':{'installation_secrets':{},'clock_skew_seconds':300,'secret_encryption_key_base64':base64.b64encode(b'k'*32).decode()},'auth':{'session_ttl_seconds':28800,'failure_limit':5,'failure_window_seconds':900,'block_seconds':900}}
raw_config=subprocess.check_output(['php','-r','echo "<?php\\ndeclare(strict_types=1);\\nreturn ".var_export(json_decode(stream_get_contents(STDIN),true),true).";\\n";'],input=J(config))
(src/'config.php').write_bytes(raw_config)
(src/'install.lock').write_text('CI fixture\n')
r={'format':'sokna-public-routine-update-state-v2','schema_version':2,'active_version':v,'active_managed_tree_sha256':b['old_tree'],'previous_version':'fixture','lkg_recovery_id':'','staged':None,'pending_verification':None,'activation_journal':None,'db_forward_fence':fence,'history':[{'action':'fixture_historical_commit'}]}
e={'format':'sokna-public-update-state-v1','schema_version':1,'active_version':'synthetic-legacy','previous_version':'fixture','lkg_recovery_id':'20260101-000000-aaaaaaaa','staged':None,'recovery_required':None,'history':[{'action':'fixture_legacy_commit'}]}
for n,x in [(R,r),(E,e)]:
 p=src/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(J(x))
ws=root/'private';ws.mkdir(0o700)
q=root/'quiescence.json';q.write_bytes(J({'scope':'CI_ONLY','web_server_started':False,'legacy_workers_started':False}))
pkg=root/'fixture/PUBLIC-ORIGIN-TO-35IU-FIXTURE-QA-ONLY.zip'
cred=root/'credentials.json';cred.write_bytes(J({'source':{'host':'127.0.0.1','port':3306,'database':'sokna_cp92_source','user':'cp92_read','password':os.environ['CP92_READ_PASS']},'clone':{'host':'127.0.0.1','port':3306,'database':'sokna_cp92_clone','user':'cp92_clone','password':os.environ['CP92_CLONE_PASS']}}));cred.chmod(0o600)
def invoke(op,extra=(),reject=None):
 cmd=[sys.executable,str(code/'owner_clone.py'),op,'--source',str(src),'--workspace',str(ws),'--db-credentials',str(cred),'--package',str(pkg),*extra]
 result=subprocess.run(cmd,capture_output=True,text=True)
 if reject:
  assert result.returncode==2 and reject in result.stderr,(reject,result.stderr)
  return
 assert result.returncode==0,result.stderr
 return strict(result.stdout)
def sql(statement):
 subprocess.run(['docker','exec',os.environ['DB_CONTAINER'],'mariadb','-uroot','-p'+os.environ['ROOT_DB_PASS'],'-e',statement],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
proof=invoke('proof');assert proof['source']['ledger']==ledger
schema=root/'schema-proof.json';schema.write_bytes(J(proof))
binding=H(J({'scope':'CI_ONLY','schema':proof['source']['schema_sha256']}))
hist={'version':v,'original_tree':b['old_tree'],'fence':fence,'observed_emergency_version':'synthetic-legacy','binding_fingerprint':binding,'schema_sections':{'ledger':8,'proof_sha256':H(schema.read_bytes())}}
histp=root/'synthetic-history.json';histp.write_bytes(J(hist));c['historical_home_contract_sha256']=H(histp.read_bytes());(code/'contract.json').write_bytes(J(c))
plan,before,_,_=inspect(src)
key=Ed25519PrivateKey.generate();pub=root/'fixture-owner.pub';pub.write_bytes(key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw));pub.chmod(0o600)
backup=root/'backup.sql'
permit={'scope':'OFFLINE_PREPARATION_ONLY_NO_DEPLOYMENT','operation':'PREPARE_CONFIGURED_SNAPSHOT','plan_sha256':H(J(plan)),'workspace':str(ws),'issued_unix':int(time.time()),'nonce':os.urandom(16).hex(),'ledger':ledger,'backup_file':str(backup),'backup_sha256':H(backup.read_bytes()),'restored_schema_file':str(schema),'restored_schema_sha256':H(schema.read_bytes()),'quiescence_receipt_file':str(q),'quiescence_receipt_sha256':H(q.read_bytes()),'database_binding_sha256':binding,'disposition':{n:plan[n] for n in ('fence','routine_lkg','emergency_lkg','emergency_version')},'historical_contract_file':str(histp),'historical_schema_sections':hist['schema_sections'],'clone_db_endpoints_sha256':proof['endpoints_sha256'],'original_component_root':str(src)}
p=root/'fixture-permit.json';sig=root/'fixture-permit.sig';opts=['--receipt',str(p),'--signature',str(sig),'--trusted-public-key',str(pub)]
def sign(x):
 p.write_bytes(J(x));sig.write_bytes(key.sign(DOMAIN+p.read_bytes()))
def seal(reject=None):
 cmd=[sys.executable,str(code/'seal_runtime.py'),'--source',str(src),'--workspace',str(ws),'--db-credentials',str(cred),'--package',str(pkg),*opts]
 rr=subprocess.run(cmd,capture_output=True,text=True)
 if reject:
  assert rr.returncode==2 and reject in rr.stderr,(reject,rr.stderr)
  return
 assert rr.returncode==0,rr.stderr
 return strict(rr.stdout)
sign(dict(permit,original_component_root='/unrelated'));seal('EXTERNAL_STORAGE_REQUIRES_PROVIDER')
assert not (ws/'candidate-DO-NOT-SERVE').exists()
print('PASS unsafe signed storage binding rejected before DB or handoff')
sign(permit);r=seal();assert r['status']=='PRIVATE_RUNTIME_CONFIG_SEALED_NOT_DEPLOYED' and r['deployment_authorized'] is False
runtime=ws/'runtime-config.private.php';candidate=ws/'candidate-DO-NOT-SERVE'
assert H(runtime.read_bytes())==r['config_sha256'] and (runtime.stat().st_mode&0o777)==0o600
assert inventory(src)==before and b'disposable-original-not-used' not in runtime.read_bytes()
assert (candidate/'config.php').read_bytes().find(b'verified independent DB binding required')>=0
print('PASS exact operator prepared clone and sealed separate private runtime config without enabling web')
# A real product bootstrap uses ONLY the newly generated clone binding.
boot=root/'boot-private.php';boot.write_text("""<?php
declare(strict_types=1);
require $argv[1].'/bootstrap.php';
$c=require $argv[2];
$core=sokna_public_bootstrap($c);$db=$core->database();$h=$core->health()->status();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='sokna_cp92_clone'||$h['status']!==200||$h['body']['applied_migrations']!==12||$h['body']['pending_migrations']!==0||$h['body']['version']!=='3.0.0-g3.6-workspace.35iu-checkpoint76-qa'||$h['body']['deployment']['coherent']!==true||$h['body']['deployment']['base_path']!=='/public'||$c['app']['default_installation_id']!=='cp92-fixture'||$c['relay']['secret_encryption_key_base64']!==base64_encode(str_repeat('k',32))||(int)$db->query("SELECT COUNT(*) FROM installations WHERE installation_id='cp92-fixture'")->fetchColumn()!==1)exit(1);
echo "PASS actual product bootstrap health with private clone config, marker and identity preserved\\n";
""")
subprocess.run(['php',str(boot),str(candidate),str(runtime)],check=True)
assert seal()==r and inventory(src)==before
print('PASS idempotent runtime seal with exact original preserved')
runtime.write_bytes(runtime.read_bytes()+b'// drift')
seal('RUNTIME_CONFIG_DRIFT')
print('PASS drifted sealed config refused without deployment')
