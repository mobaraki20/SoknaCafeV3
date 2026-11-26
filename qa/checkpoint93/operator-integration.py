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
(src/'config.php').write_text("<?php throw new RuntimeException('CI source config must not execute');\n")
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
# Actual negative test: corrupt one row in disposable clone, restore it afterward.
sql("UPDATE sokna_cp92_clone.installations SET installation_id='wrong-fixture' WHERE installation_id='cp92-fixture'")
invoke('proof',reject='RESTORED_DATABASE_MISMATCH')
sql("UPDATE sokna_cp92_clone.installations SET installation_id='cp92-fixture' WHERE installation_id='wrong-fixture'")
print('PASS actual mismatched restored row rejected')
sql("GRANT SELECT ON sokna_cp92_source.* TO 'cp92_clone'@'%'")
invoke('proof',reject='EXCESS_DB_SCOPE')
sql("REVOKE SELECT ON sokna_cp92_source.* FROM 'cp92_clone'@'%'")
print('PASS excess source grant on clone account rejected')
proof=invoke('proof');assert proof['source']['ledger']==ledger
schema=root/'schema-proof.json';schema.write_bytes(J(proof))
binding=H(J({'scope':'CI_ONLY','schema':proof['source']['schema_sha256']}))
hist={'version':v,'original_tree':b['old_tree'],'fence':fence,'observed_emergency_version':'synthetic-legacy','binding_fingerprint':binding,'schema_sections':{'ledger':8,'proof_sha256':H(schema.read_bytes())}}
histp=root/'synthetic-history.json';histp.write_bytes(J(hist));c['historical_home_contract_sha256']=H(histp.read_bytes());(code/'contract.json').write_bytes(J(c))
plan,before,_,_=inspect(src)
key=Ed25519PrivateKey.generate();pub=root/'fixture-owner.pub';pub.write_bytes(key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw));pub.chmod(0o600)
backup=root/'backup.sql'
permit={'scope':'OFFLINE_PREPARATION_ONLY_NO_DEPLOYMENT','operation':'PREPARE_CONFIGURED_SNAPSHOT','plan_sha256':H(J(plan)),'workspace':str(ws),'issued_unix':int(time.time()),'nonce':os.urandom(16).hex(),'ledger':ledger,'backup_file':str(backup),'backup_sha256':H(backup.read_bytes()),'restored_schema_file':str(schema),'restored_schema_sha256':H(schema.read_bytes()),'quiescence_receipt_file':str(q),'quiescence_receipt_sha256':H(q.read_bytes()),'database_binding_sha256':binding,'disposition':{n:plan[n] for n in ('fence','routine_lkg','emergency_lkg','emergency_version')},'historical_contract_file':str(histp),'historical_schema_sections':hist['schema_sections'],'clone_db_endpoints_sha256':proof['endpoints_sha256']}
p=root/'fixture-permit.json';sig=root/'fixture-permit.sig';opts=['--receipt',str(p),'--signature',str(sig),'--trusted-public-key',str(pub)]
def sign(x):
 p.write_bytes(J(x));sig.write_bytes(key.sign(DOMAIN+p.read_bytes()))
sign(dict(permit,clone_db_endpoints_sha256='0'*64));invoke('upgrade',opts,reject='CLONE_ENDPOINT_NOT_AUTHORIZED')
assert not (ws/'candidate-DO-NOT-SERVE').exists()
print('PASS wrong signed endpoint rejected before handoff')
sign(permit)
result=invoke('upgrade',opts)
assert result['status']=='PRIVATE_CLONE_UPGRADED_NOT_DEPLOYED' and result['deployment_authorized'] is False and result['source_unchanged'] is True
assert inventory(src)==before
print('PASS portable operator private clone upgrade and exact manifest, source unchanged')
assert invoke('upgrade',opts)==result
print('PASS idempotent operator verification after successful upgrade')
