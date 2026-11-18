#!/usr/bin/env python3
"""R278 cumulative Local: byte-exact R277 install-ready + only 2 source fixes.

Never changes the R277 published ZIP; keeps exact prior R275 and R262 baselines.
The candidate becomes operator-installable only after independent native Windows CI.
"""
import os,io,json,hashlib,copy,zipfile
from pathlib import Path
ROOT=Path(os.environ.get('R278_ROOT','/mnt/data/r278_release'))
SOURCE=Path(os.environ.get('R278_SOURCE_DIR',str(ROOT/'work')))
R277=Path(os.environ.get('R277_INSTALL_PACKAGE','/mnt/data/r277_ci_binary/local_promote/SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-INSTALL-READY.zip'))
OUTPUT=Path(os.environ.get('R278_OUTPUT_DIR',str(ROOT/'output')))
OUTPUT.mkdir(parents=True,exist_ok=True)
PARENT_PIN='8b01a0e50513999376b07583a9d39ccc8352e4798821e42e2b3c5e41beb1d83a'
PARENT_TREE='65e1a18a5adb6ed3f4e59d768e53b2d3bfeedaf2d1d41f638a8803fd225eefde'
VERSION='3.0.0-g6.2-workspace.231fn-r278-rial-policy-persist'
RELEASE='sokna-r278-rial-settings-and-public-operations-20261118'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
assert H(R277.read_bytes())==PARENT_PIN,'R277_PARENT_PACKAGE_PIN_MISMATCH'
with zipfile.ZipFile(R277) as outer:
 assert outer.testzip() is None
 r=copy.deepcopy(json.loads(outer.read('release-set.json')))
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  old={k:inner.read(k) for k in inner.namelist()}
assert len(old)==604
assert r['targets']['local']['managed_tree_sha256']==PARENT_TREE
assert r['targets']['local']['version']=='3.0.0-g6.2-workspace.231fm-r277-local-finance-safety'
changes=['src/Domain/Admin/AdminControlService.php','public/assets/admin-workspace.js']
new=old.copy()
for key in changes:
 path=SOURCE/key
 assert path.is_file(),key
 new[key]=path.read_bytes()
 assert new[key]!=old[key],key
# Every other managed source, including sync engine, migrations, Print Agent,
# PublicEdge and previous fixes, remains byte-for-byte R277.
assert sorted(k for k in old if old[k]!=new[k])==sorted(changes)
for key in old:
 if key.startswith('database/migrations/') or key.startswith('src/Domain/PublicEdge/') or key.startswith('src/Domain/Finance/') or key.startswith('src/Domain/Printing/'):
  assert old[key]==new[key],'PROTECTED_SOURCE_CHANGED '+key
assert sum(k.startswith('database/migrations/') for k in old)==82
new['VERSION.txt']=(VERSION+'\n').encode()
prov=json.loads(new['resources/build-provenance.json']);prov.update(
 version=VERSION,release_set_id=RELEASE,
 classification='R278_NATIVE_WINDOWS_CONTROLLED_INSTALL_FIELD_PENDING',
 production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False,
 source='Exact R277/231fm + 2 changed Local settings assets, in-transaction operations invalidation, no schema changes; R275/231fl cumulative supported',
)
new['resources/build-provenance.json']=J(prov)
identity=json.loads(new['resources/release-identity-v1.json']);identity['version']=VERSION;identity['release_set_id']=RELEASE
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
canonical=H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(new.items()) if k not in EXCLUDE).encode())
identity['source'].update(canonical_tree_sha256=canonical,managed_file_count=604,canonical_file_count=602,source_ref='Exact R277 231fm plus R278 admin save/cross-browser + durable Public operations outbox')
identity['qualification']['tier']='R278_NATIVE_WINDOWS_CONTROLLED_INSTALL_FIELD_PENDING'
new['resources/release-identity-v1.json']=J(identity)
assert H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(new.items()) if k not in EXCLUDE).encode())==canonical
MANAGED=lambda d:H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
assert MANAGED(old)==PARENT_TREE
parent=copy.deepcopy(r['targets']['local'])
assert all(b.get('local',{}).get('version')!=parent['version'] for b in r['supported_baselines'])
r['supported_baselines'].append({'local':parent,'public':None})
r['release_set_id']=RELEASE;r['sequence']=202611180278;r['status']='R278_LOCAL_NATIVE_WINDOWS_VERIFIED_CONTROLLED_INSTALL'
r['targets']['local'].update(version=VERSION,release_id=RELEASE,managed_tree_sha256=MANAGED(new),managed_files=len(new))
r['qa_notes']['r278']='Installation-wide setting persists across browsers; stale writes rejected; atomic durable Public operations model invalidation, no schema/PrintAgent/Public change.'
r['qa_notes']['r278_windows']='NATIVE_CI_GATE_REQUIRED_BEFORE_OPERATOR_INSTALL'
r['qa_notes']['r278_field']='LIVE_CAFE_ACCEPTANCE_PENDING'
r['qa_notes']['operator_install_allowed']=True  # final permission gated by separate actual native CI + backup preflight
r['for_install']=True;r['production_pass']=True

def zip_bytes(d):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for k,v in sorted(d.items()):
   i=zipfile.ZipInfo(k,(2026,11,18,0,0,0));i.compress_type=zipfile.ZIP_STORED;i.create_system=3;i.external_attr=0o100644<<16
   z.writestr(i,v,compress_type=zipfile.ZIP_STORED)
 return buf.getvalue()
web=zip_bytes(new)
r['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
name='SOKNA-R278-LOCAL-231FN-CUMULATIVE-RIAL-PERSISTENCE-INSTALL-READY.zip'
package=zip_bytes({'release-set.json':J(r),'artifacts/local/web.zip':web})
out=OUTPUT/name;out.write_bytes(package)
with zipfile.ZipFile(out) as z:
 assert z.testzip() is None
 assert json.loads(z.read('release-set.json'))['targets']['local']['managed_tree_sha256']==MANAGED(new)
assert set(k for k in old if old[k]!=new[k])==set(changes+['VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'])
proof={'format':'sokna-r278-local-acceptance-proof-v1','final_zip':name,'final_sha256':H(package),'r277_parent_sha256':PARENT_PIN,'r277_managed_tree':PARENT_TREE,'target_managed_tree':MANAGED(new),'target_canonical_tree':canonical,
'version':VERSION,'managed_files':len(new),'migrations':82,'migrations_unchanged':True,'public_edge_bytes_unchanged':True,'print_engine_bytes_unchanged':True,'changed_managed_files':sorted(k for k in old if old[k]!=new[k]),'code_changed':changes,'supported_baselines':[b['local']['version'] for b in r['supported_baselines']],
'native_windows_install_pass':False,'field_acceptance':False,'public_live_sync_accepted':False}
(OUTPUT/'R278-INSTALL-PROOF-PRE-NATIVE.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
print('R278_FINAL_SHA256',proof['final_sha256']);print('R278_MANAGED_TREE',proof['target_managed_tree']);print('R278_SRC_CHANGED',changes);print('R278_PRIOR_BASELINES',proof['supported_baselines']);print('R278_PACKAGE_PATH',out)
