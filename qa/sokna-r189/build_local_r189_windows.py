#!/usr/bin/env python3
"""Byte-exact scoped R189 QA candidates; fail-closed until native Windows admission.
Generates a real Public routine/full update and Local maintenance container (not install authorized).
No DB or PrintAgent backend changes. The untouched files are proven byte equal to R187/R188.
"""
import hashlib,io,json,zipfile,pathlib,datetime,os
ROOT=pathlib.Path(os.environ.get('SOKNA_R189_ROOT','/mnt/data/sokna_audit')); BASE=pathlib.Path(os.environ.get('SOKNA_R189_BASE','/mnt/data/files')); OUT=pathlib.Path(os.environ.get('SOKNA_R189_OUT','/mnt/data/SOKNA-R189-CANDIDATES'));OUT.mkdir(exist_ok=True)
LH='SOKNA-R187-LOCAL-231EW-56BASE-HOME-CANARY-PANEL-UPDATE.zip';PH='SOKNA-PUBLIC-3.0.0.35HW-R188-ROOT-CLEAN-INSTALL.zip'
LV='3.0.0-g6.2-workspace.231ex-r189-login-modal-qa';PV='3.0.0-g3.6-workspace.35hx-r189-shared-modal-qa'
LR='sokna-r189-local-login-modal-20261113';PR='sokna-r189-public-shared-modal-20261113'
sha=lambda b:hashlib.sha256(b).hexdigest()
def compact(x):return (json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zipbytes(data):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w') as z:
  for k,v in sorted(data.items()):
   i=zipfile.ZipInfo(k,(2026,11,13,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16;i.create_system=3
   z.writestr(i,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return out.getvalue()
def tree(data):return sha(''.join(k+'\0'+sha(v)+'\n' for k,v in sorted(data.items())).encode())
def canon(data):
 return sha(''.join(k+'\0'+str(len(v))+'\0'+sha(v)+'\n' for k,v in sorted(data.items()) if k not in {'resources/build-provenance.json','resources/release-identity-v1.json'}).encode())
def contents(z):
 if z.testzip() is not None:raise RuntimeError('ZIP_CRC_FAIL')
 items=z.namelist()
 if len(items)!=len(set(items)) or any(n.startswith('/') or '..' in n.split('/') for n in items):raise RuntimeError('ZIP_NAMES_UNSAFE')
 return {n:z.read(n) for n in items}
def changed(root,original):
 now={p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
 if set(now)!=set(original):raise RuntimeError('FILE_INVENTORY_DRIFT '+str(set(now)^set(original)))
 return now,{k for k in now if now[k]!=original[k]}
local_outer=zipfile.ZipFile(BASE/LH);local_manifest=json.loads(local_outer.read('release-set.json'));local_inner=zipfile.ZipFile(io.BytesIO(local_outer.read('artifacts/local/web.zip')));old_l=contents(local_inner)
assert len(old_l)==589 and tree(old_l)==local_manifest['targets']['local']['managed_tree_sha256']
loc,lc=changed(ROOT/'local',old_l)
LCH={'assets/staff-app/hall-workspace.js','public/assets/login.js','public/assets/product-ui.css','public/assets/staff-app/hall-workspace.js','public/login.php','public/system/index.php'}
if lc!=LCH:raise RuntimeError('LOCAL_UNPLANNED_CHANGES '+repr(lc))
assert loc['resources/local-managed-files-v1.json']==old_l['resources/local-managed-files-v1.json']
loc['VERSION.txt']=(LV+'\n').encode()
p=json.loads(loc['resources/build-provenance.json']);p.update(version=LV,release_set_id=LR,classification='R189_SCOPED_QA_PENDING_EXACT_WINDOWS_MAINTENANCE_ADMISSION',source='Exact R187 inner 589-file payload; UI-only scoped edits; no SQL, business logic, update engine or Windows services changed',full_business_acceptance=False,production_host_install_verified=False,installed_host_accepted=False)
p['r189_changed_files']=sorted(LCH);p['r189_native_windows_stage_activate']='NOT_RUN';p['r189_cafe_production_approved']=False
loc['resources/build-provenance.json']=compact(p)
identity=json.loads(loc['resources/release-identity-v1.json']);identity.update(version=LV,release_set_id=LR)
identity['source'].update(canonical_file_count=587,managed_file_count=589,canonical_tree_sha256=canon(loc),source_ref='Byte-pinned R187, 6 changed UI/controller-view files and three metadata files; baseline inventory unchanged')
identity['qualification'].update(tier='R189_QA_PENDING_NATIVE_WINDOWS_NO_CAFE_INSTALL',claim_rule='Do not upload into a cafe Local Maintenance panel until exact ZIP Windows Stage/Activate, backup and readback qualify this target.')
identity['baseline']['source_versions']=list(dict.fromkeys(identity['baseline'].get('source_versions',[])+[local_manifest['targets']['local']['version']]))
loc['resources/release-identity-v1.json']=compact(identity)
changed_l={k for k in loc if loc[k]!=old_l[k]}
assert changed_l==LCH|{'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}
assert all(loc[k]==old_l[k] for k in loc if k.startswith('database/'))
lm=json.loads(json.dumps(local_manifest));lm.update(release_set_id=LR,status='R189_SCOPED_QA_PENDING_EXACT_WINDOWS',sequence=20261113889,created_at='2026-11-13T11:00:00Z',production_pass=False,for_install=False)
lm['targets']['local'].update(version=LV,release_id=LR,managed_tree_sha256=tree(loc),managed_files=589)
lm['supported_baselines'].append({'local':local_manifest['targets']['local'],'public':None})
lm['qa_notes'].update(scope='SCOPED_QA_NOT_INSTALLABLE_UNTIL_EXACT_WINDOWS_PASS',cafe_production_approved=False,all_business_issues_resolved=False,exact_r189_native_windows_stage='NOT_RUN',r189_business_changes=False,r189_src_diffs=sorted(LCH))
lm['qualification'].update(canary=True,real_windows=False)
web=zipbytes(loc);lm['artifacts']['local-web'].update(sha256=sha(web),size_bytes=len(web))
local_file=OUT/'SOKNA-R189-LOCAL-231EX-57BASE-QA-NOT-INSTALL.zip';local_file.write_bytes(zipbytes({'release-set.json':compact(lm),'artifacts/local/web.zip':web}))
with zipfile.ZipFile(local_file) as z:
 assert z.namelist()==['artifacts/local/web.zip','release-set.json'] and z.testzip() is None
 y=zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip')));assert len(y.namelist())==589
 assert tree(contents(y))==lm['targets']['local']['managed_tree_sha256']
 assert sha(z.read('artifacts/local/web.zip'))==lm['artifacts']['local-web']['sha256']
print('LOCAL_QA_PASSED',local_file,sha(local_file.read_bytes()))

# Build the exact native Windows admission candidate from a verified QA-only payload.
# This stays private until native Stage/Activate/readback tests pass on the exact bytes.
with zipfile.ZipFile(local_file) as z:
 c=contents(z)
 m=json.loads(c['release-set.json']);assert not m['production_pass'] and not m['for_install']
 m.update(for_install=True,production_pass=True,status='R189_EXACT_WINDOWS_CI_PENDING')
 m['qualification']['real_windows']=True
 m['qa_notes']['scope']='R189_EXACT_WINDOWS_CI_PENDING_NO_CAFE_PRODUCTION_APPROVAL'
 m['qa_notes']['exact_r189_native_windows_stage']='CI_PENDING_NOT_TO_BE_RELEASED_BEFORE_SUCCESS'
 outfiles={'release-set.json':compact(m),'artifacts/local/web.zip':c['artifacts/local/web.zip']}
 testfile=OUT/'SOKNA-R189-LOCAL-231EX-57BASE-EXACT-WINDOWS-TECH-CANDIDATE.zip'
 testfile.write_bytes(zipbytes(outfiles))
 print('R189_TECH_CANDIDATE_SHA256',sha(testfile.read_bytes()))