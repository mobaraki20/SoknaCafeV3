#!/usr/bin/env python3
"""Deterministic staged promotion of a byte-pinned tested R189 Local into R194 source-only canary.

Never changes production. The exact ZIP is a CI *candidate* until native Windows Stage/Activate passes.
The business scope is LIMITED (R191/R193), not a declaration that all cafe issues are closed.
"""
import io,json,hashlib,os,zipfile,pathlib
BASE=pathlib.Path(os.environ.get('SOKNA_R194_PARENT_ZIP','/mnt/data/SOKNA-R189-DELIVERY/SOKNA-R189-LOCAL-231EX-57BASE-NATIVE-WINDOWS-PASSED-PANEL-UPDATE.zip'))
SRC=pathlib.Path(os.environ.get('SOKNA_R194_SRC','/mnt/data/SOKNA-R193-QA/local'))
OUT=pathlib.Path(os.environ.get('SOKNA_R194_OUT','/mnt/data/SOKNA-R194-QUALIFICATION'))
OUT.mkdir(parents=True,exist_ok=True)
PARENT_SHA='61366cf4b7970bd2fadf5955264daaa59629f0508f94b30e04586cc5c1996229'
PARENT_VERSION='3.0.0-g6.2-workspace.231ex-r189-login-modal-qa'
VERSION='3.0.0-g6.2-workspace.231ey-r194-combined-canary'
RELEASE_ID='sokna-r194-local-combined-cafe-canary-20261113'
NAME='SOKNA-R194-LOCAL-231EY-58BASE-EXACT-WINDOWS-TECH-CANDIDATE.zip'
H=lambda b:hashlib.sha256(b).hexdigest()
def J(x):return (json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode('utf8')
def Z(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for k,v in sorted(d.items()):
   zi=zipfile.ZipInfo(k,(2026,11,13,0,0,0));zi.compress_type=zipfile.ZIP_DEFLATED;zi.external_attr=0o100644<<16;zi.create_system=3
   z.writestr(zi,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def validate(z):
 assert z.testzip() is None
 names=z.namelist(); assert len(names)==len(set(names)); assert all(not n.startswith('/') and '..' not in n.split('/') for n in names)
 return {n:z.read(n) for n in names}
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def canonical(d):return H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(d.items()) if k not in {'resources/build-provenance.json','resources/release-identity-v1.json'}).encode())
assert H(BASE.read_bytes())==PARENT_SHA
with zipfile.ZipFile(BASE) as z:
 parent_manifest=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:parent=validate(inner)
assert parent_manifest['targets']['local']['version']==PARENT_VERSION
assert tree(parent)==parent_manifest['targets']['local']['managed_tree_sha256']
source={p.relative_to(SRC).as_posix():p.read_bytes() for p in SRC.rglob('*') if p.is_file()}
assert len(source)==590 and len(parent)==589
assert set(source)-set(parent)=={'src/Core/RememberDeviceStore.php'}
assert set(parent)<=set(source)
diff={p for p in parent if source[p]!=parent[p]}
EXPECTED_CHANGED={
 'bootstrap.php','public/assets/guest-content-workspace.js','public/guest-content/index.php','public/login.php','public/logout.php','resources/local-managed-files-v1.json','src/Core/Auth.php','src/Core/Bootstrap.php',
 'public/assets/staff-app/hall-workspace.js','public/operator/api.php','src/Domain/Orders/OrderStaffActionService.php','src/Domain/Orders/OrderWorkspaceService.php',
}
assert diff==EXPECTED_CHANGED,('SOURCE_CHANGE_DRIFT',sorted(diff^EXPECTED_CHANGED))
assert all(source[k]==parent[k] for k in parent if k.startswith('database/')), 'UNAPPROVED_MIGRATION_CHANGE'
assert source['VERSION.txt']==parent['VERSION.txt'], 'SOURCE_BASE_NOT_R189'
assert source['resources/golive-clearance-policy.json']==parent['resources/golive-clearance-policy.json']
inventory=json.loads(source['resources/local-managed-files-v1.json']);assert set(inventory['files'])==set(source) and len(inventory['files'])==590
source['VERSION.txt']=(VERSION+'\n').encode()
p=json.loads(source['resources/build-provenance.json']);p.update(version=VERSION,release_set_id=RELEASE_ID,
 classification='R194_EXACT_WINDOWS_CI_PENDING_SOURCE_ONLY_CAFE_NO_GO',
 source='Byte-pinned 231ex R189 + exact R191 and R193 combined QA-only changes with 590/590 file proof',
 full_business_acceptance=False,production_host_install_verified=False,installed_host_accepted=False,workspace_source_only=True,
 r194_source_qa_run='https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43456276659',
 r194_native_windows_status='PENDING',r194_cafe_production_approved=False,
 r194_changed_files=sorted(diff|{'src/Core/RememberDeviceStore.php'}))
source['resources/build-provenance.json']=J(p)
i=json.loads(source['resources/release-identity-v1.json']);i.update(version=VERSION,release_set_id=RELEASE_ID)
i['source'].update(canonical_file_count=588,managed_file_count=590,canonical_tree_sha256=canonical(source),source_ref='Exact R189 + R191/R193 cumulative source QA; one added security component; no SQL changes')
i['qualification'].update(tier='R194_EXACT_WINDOWS_CI_PENDING_CAFE_NO_GO',claim_rule='No cafe production install until exact native Windows Maintenance Stage/Activate passed, cafe backup and post-install readback verified.')
i['baseline']['source_versions']=list(dict.fromkeys(i['baseline'].get('source_versions',[])+[PARENT_VERSION]))
source['resources/release-identity-v1.json']=J(i)
assert len(source)==590
manifest=json.loads(json.dumps(parent_manifest)); assert len(manifest['supported_baselines'])==57
manifest.update(release_set_id=RELEASE_ID,sequence=20261113994,status='R194_LOCAL_COMBINED_CANARY',created_at='2026-11-13T16:30:00Z',for_install=True,production_pass=True)
manifest['targets']['local'].update(version=VERSION,release_id=RELEASE_ID,managed_tree_sha256=tree(source),managed_files=590)
manifest['supported_baselines'].append({'local':parent_manifest['targets']['local'],'public':None})
assert len(manifest['supported_baselines'])==58
manifest['qa_notes'].update(scope='R194_CANARY_ONLY_NOT_CAFE_PRODUCTION_APPROVED',all_business_issues_resolved=False,cafe_production_approved=False,
 exact_r194_native_windows_stage='PENDING_NOT_FOR_CAFE',r194_source_qa_run=43456276659,r194_cancellation_after_settlement=False,
 r194_print_agent_root_cause_resolved=False,operator_upload_single_ZIP_only=True)
manifest['qualification'].update(canary=True,real_windows=True)
web=Z(source);manifest['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
outer=Z({'release-set.json':J(manifest),'artifacts/local/web.zip':web})
file=OUT/NAME;file.write_bytes(outer)
with zipfile.ZipFile(file) as z:
 d=validate(z); mm=json.loads(d['release-set.json']);assert mm['artifacts']['local-web']['sha256']==H(d['artifacts/local/web.zip'])
 with zipfile.ZipFile(io.BytesIO(d['artifacts/local/web.zip'])) as zin:
  actual=validate(zin); assert len(actual)==590 and tree(actual)==mm['targets']['local']['managed_tree_sha256']
  assert actual==source
receipt={'format':'sokna-r194-canary-static-evidence-v1','file':NAME,'sha256':H(outer),'bytes':len(outer),'version':VERSION,'supported_baselines':58,'src_files':590,'diff_from_r189':sorted(diff), 'new_files':['src/Core/RememberDeviceStore.php'],'native_windows':'PENDING','cafe_production_approved':False,'migrations_changed':False,'print_agent_changed':False,'public_changed':False,'all_business_issues_resolved':False}
(OUT/'R194-STATIC-EVIDENCE.json').write_text(json.dumps(receipt,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(receipt,ensure_ascii=False))
