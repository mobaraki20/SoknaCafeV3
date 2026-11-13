#!/usr/bin/env python3
"""Exact immutable R204 Local technical canary from SHA-pinned R201; NOT approved for café production."""
import os,sys,io,json,hashlib,zipfile,pathlib
BASE=pathlib.Path(os.environ.get('SOKNA_R204_PARENT_ZIP','/mnt/data/SOKNA-LOCAL-R201-231FA-TECHNICAL-CANDIDATE.zip'))
SRC=pathlib.Path(os.environ.get('SOKNA_R204_SRC','/mnt/data/SOKNA-R203-UX-QA/local'))
OUT=pathlib.Path(os.environ.get('SOKNA_R204_OUT','/mnt/data/SOKNA-R204-QUALIFICATION'))
PROOF=pathlib.Path(os.environ.get('SOKNA_R204_PROOF','/mnt/data/SOKNA-R203-UX-QA/qa/R201-TO-R203-LOCAL-INTEGRATED-QA-SHA256.json'))
OUT.mkdir(parents=True,exist_ok=True)
PARENT_SHA='911ea673dc1863bee3ce372dbc692602aa3a32439d2fc9ccba69dc2a6c243897'
PARENT_VERSION='3.0.0-g6.2-workspace.231fa-r201-cash-refund-cancel-canary'
VERSION='3.0.0-g6.2-workspace.231fb-r204-ux-zero-empty-canary'
RELEASE_ID='sokna-r204-local-ux-zero-empty-cafe-canary-20261114'
NAME='SOKNA-R204-LOCAL-231FB-61BASE-EXACT-WINDOWS-TECH-CANDIDATE.zip'
H=lambda b:hashlib.sha256(b).hexdigest()
def J(x):return (json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def Z(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for k,v in sorted(d.items()):
   zi=zipfile.ZipInfo(k,(2026,11,14,0,0,0));zi.compress_type=zipfile.ZIP_DEFLATED;zi.external_attr=0o100644<<16;zi.create_system=3
   z.writestr(zi,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def get(z):
 assert z.testzip() is None
 names=z.namelist();assert len(names)==len(set(names));assert all(not n.startswith('/') and '..' not in n.split('/') for n in names)
 return {n:z.read(n) for n in names}
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def canonical(d):return H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(d.items()) if k not in {'resources/build-provenance.json','resources/release-identity-v1.json'}).encode())
assert H(BASE.read_bytes())==PARENT_SHA
with zipfile.ZipFile(BASE) as z:
 manifest=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:parent=get(inner)
assert manifest['targets']['local']['version']==PARENT_VERSION
assert tree(parent)==manifest['targets']['local']['managed_tree_sha256']
source={p.relative_to(SRC).as_posix():p.read_bytes() for p in SRC.rglob('*') if p.is_file()}
assert len(source)==len(parent)==590 and set(source)==set(parent)
proof=json.loads(PROOF.read_text())
diff={p for p in parent if source[p]!=parent[p]}
expected={p['file']:p for p in proof['changed']}
assert len(diff)==18 and diff==set(expected),('SOURCE_SCOPE',sorted(diff^set(expected)))
assert all(H(source[p])==expected[p]['after'] and H(parent[p])==expected[p]['before'] for p in diff)
assert all(source[k]==parent[k] for k in parent if k.startswith('database/'))
for name in ['resources/local-managed-files-v1.json','resources/golive-clearance-policy.json','VERSION.txt']:
 assert source[name]==parent[name]
files=json.loads(source['resources/local-managed-files-v1.json']);assert set(files['files'])==set(source)
source['VERSION.txt']=(VERSION+'\n').encode()
p=json.loads(source['resources/build-provenance.json']);p.update(version=VERSION,release_set_id=RELEASE_ID,
 classification='R204_EXACT_WINDOWS_CI_PENDING_CAFE_NO_GO',full_business_acceptance=False,production_host_install_verified=False,
 installed_host_accepted=False,workspace_source_only=True,r204_windows_status='PENDING',r204_cafe_production_approved=False,
 r204_changed_files=sorted(diff),source='R201 native Windows qualified + 18 exact scoped R202/R203 updates, 81-migration QA')
source['resources/build-provenance.json']=J(p)
i=json.loads(source['resources/release-identity-v1.json']);i.update(version=VERSION,release_set_id=RELEASE_ID)
i['source'].update(canonical_file_count=588,managed_file_count=590,canonical_tree_sha256=canonical(source),
 source_ref='R201 exact Windows 231fa + R202 zero settlement reporting + R203 catalog, prep, digits, empty-table guards')
i['qualification'].update(tier='R204_EXACT_WINDOWS_CI_PENDING_CAFE_NO_GO',claim_rule='No cafe install without final Windows Maintenance and actual onsite backup verification')
i['baseline']['source_versions']=list(dict.fromkeys(i['baseline'].get('source_versions',[])+[PARENT_VERSION]))
source['resources/release-identity-v1.json']=J(i)
assert len(source)==590
m=json.loads(json.dumps(manifest));assert len(m['supported_baselines'])==60
m.update(release_set_id=RELEASE_ID,sequence=20261114999,status='R204_LOCAL_UX_ZERO_EMPTY_CANARY',created_at='2026-11-14T00:00:00Z',for_install=True,production_pass=True)
m['targets']['local'].update(version=VERSION,release_id=RELEASE_ID,managed_tree_sha256=tree(source),managed_files=590)
m['supported_baselines'].append({'local':manifest['targets']['local'],'public':None});assert len(m['supported_baselines'])==61
m['qa_notes'].update(scope='R204_CANARY_ONLY_NOT_CAFE_PRODUCTION_APPROVED',all_business_issues_resolved=False,cafe_production_approved=False,
 exact_r204_native_windows_stage='PENDING',r203_real_mariadb_qa_run=43511936699,r204_print_agent_6_2_11_reconciled=False,
 r204_public_emergency_bridge=False,operator_upload_single_ZIP_only=True)
m['qualification'].update(canary=True,real_windows=True)
web=Z(source);m['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
outer=Z({'release-set.json':J(m),'artifacts/local/web.zip':web})
f=OUT/NAME;f.write_bytes(outer)
with zipfile.ZipFile(f) as z:
 d=get(z);mm=json.loads(d['release-set.json']);assert H(d['artifacts/local/web.zip'])==mm['artifacts']['local-web']['sha256']
 with zipfile.ZipFile(io.BytesIO(d['artifacts/local/web.zip'])) as zin:
  actual=get(zin);assert len(actual)==590 and tree(actual)==mm['targets']['local']['managed_tree_sha256'] and actual==source
receipt={'format':'sokna-r204-technical-evidence-v1','file':NAME,'sha256':H(outer),'bytes':len(outer),'version':VERSION,
 'supported_baselines':61,'src_files':590,'diff_from_r201':sorted(diff),'windows_native':'PENDING',
 'cafe_production_approved':False,'migrations_changed':False,'print_agent_changed':False,'public_changed':False,
 'r203_real_mariadb_run':43511936699,'source_parent_sha256':PARENT_SHA}
(OUT/'R204-STATIC-EVIDENCE.json').write_text(json.dumps(receipt,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(receipt,ensure_ascii=False))
