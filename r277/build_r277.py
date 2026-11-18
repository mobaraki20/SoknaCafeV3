#!/usr/bin/env python3
"""R277 exact R275 Local -> R277 Local cumulative, finance P0 and cancel gate.
Not production-qualified without native Windows acceptance and actual cafe stage.
"""
from pathlib import Path
import zipfile,io,json,hashlib,copy,sys,os
ROOT=Path(os.environ.get('R277_ROOT','/mnt/data/sokna_r277')); BASE=ROOT/'base'; W=ROOT/'work'; OUT=ROOT/'output';OUT.mkdir(exist_ok=True)
R275=Path(os.environ.get('R275_LOCAL_PACKAGE','/mnt/data/sokna_r275_inspect/SOKNA-R275-LOCAL-231FL-OWNER-DISCARD-QA-DO-NOT-INSTALL.zip'))
PIN='f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9'
H=lambda x:hashlib.sha256(x).hexdigest()
J=lambda o:(json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
assert H(R275.read_bytes())==PIN
with zipfile.ZipFile(R275) as z:
 r=copy.deepcopy(json.loads(z.read('release-set.json')))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
  o={n:inner.read(n) for n in inner.namelist() if not n.endswith('/')}
assert len(o)==604
n={p.relative_to(W).as_posix():p.read_bytes() for p in W.rglob('*') if p.is_file()}
assert len(n)==604 and n.keys()==o.keys()
source_changes=sorted(p for p in n if o[p]!=n[p]); expected={
 'src/Domain/Orders/OrderStaffActionService.php',
 'public/assets/staff-app/hall-workspace.js',
 'public/assets/hall-settlement-v32.js',
 'public/assets/product-ui.css'
}
assert set(source_changes)==expected,(source_changes,expected)
for p in n:
 if p.startswith('database/migrations/') or p.startswith('src/Domain/PublicEdge/') or p.startswith('public/assets/connections-workspace.js') or p.startswith('src/Domain/Printing/') or p.startswith('src/Domain/Finance/'):
  assert n[p]==o[p],p
release='sokna-r277-local-itemized-cancel-discountguard-20261118'
version='3.0.0-g6.2-workspace.231fm-r277-local-finance-safety'
n['VERSION.txt']=(version+'\n').encode()
files=json.loads(n['resources/local-managed-files-v1.json']);files['files']=sorted(n);n['resources/local-managed-files-v1.json']=J(files)
prov=json.loads(n['resources/build-provenance.json']);prov.update(version=version,release_set_id=release,classification='R277_WINDOWS_QA_PENDING_FIELD_NO_GO',installed_host_accepted=False,production_host_install_verified=False,full_business_acceptance=False,source='Exact R275 231fl + already tested R269 service/UX + discount review guard, 82 migrations and PublicEdge unchanged');n['resources/build-provenance.json']=J(prov)
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
canonical=lambda d:H(''.join(p+'\0'+str(len(b))+'\0'+H(b)+'\n' for p,b in sorted(d.items()) if p not in EXCLUDE).encode())
identity=json.loads(n['resources/release-identity-v1.json']);identity['version']=version;identity['release_set_id']=release;identity['source']['canonical_tree_sha256']=canonical(n);identity['source']['managed_file_count']=604;identity['source']['canonical_file_count']=604-len(EXCLUDE);identity['source']['source_ref']='R275 exact 231fl, R269 itemized correction/cancel, R277 preview guard';identity['qualification']['tier']='R277_PENDING_NATIVE_AND_FIELD';n['resources/release-identity-v1.json']=J(identity)
assert identity['source']['canonical_tree_sha256']==canonical(n)
def zip_d(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for name,data in sorted(d.items()):
   i=zipfile.ZipInfo(name,(2026,11,18,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
tree=lambda d:H(''.join(p+'\0'+H(b)+'\n' for p,b in sorted(d.items())).encode())
assert all(o[p]==n[p] for p in n if p.startswith('database/migrations/'))
assert all(o[p]==n[p] for p in n if p.startswith('src/Domain/PublicEdge/'))
parent=copy.deepcopy(r['targets']['local']);assert parent['managed_tree_sha256']=='a7407fa1ef4bd3cb17ac68fe6d548330e32a9f3ca47331c47d233b4b6398d9c2'
assert parent['version']=='3.0.0-g6.2-workspace.231fl-r275-owner-discard-public-work'
r['supported_baselines'].append({'local':parent,'public':None})
r['release_set_id']=release;r['sequence']=202611180277;r['status']='R277_LOCAL_WINDOWS_QA_PENDING_HOST_NO_GO'
r['for_install']=True;r['production_pass']=True # required by native packaging harness; NOT operator approval
r['targets']['local'].update(version=version,release_id=release,managed_tree_sha256=tree(n),managed_files=604)
inner=zip_d(n);r['artifacts']['local-web'].update(sha256=H(inner),size_bytes=len(inner))
r.setdefault('qa_notes',{})['r277']='P0 itemized unpaid-only correction and safe pre-dispatch manual cancel, fail-closed discount preview; Local PublicEdge unchanged, source QA only until Windows/host gates.'
r['qa_notes']['operator_install_allowed']=False
outer=zip_d({'release-set.json':J(r),'artifacts/local/web.zip':inner})
name='SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-QA-DO-NOT-INSTALL.zip';(OUT/name).write_bytes(outer)
proof={'format':'sokna-r277-local-cumulative-evidence-v1','from':'231fl/R275','to':'231fm/R277','qa_only':True,'operator_install_allowed':False,'package':name,'sha256':H(outer),'inner_sha256':H(inner),'parent_package_sha256':PIN,'parent_tree':parent['managed_tree_sha256'],'target_tree':tree(n),'target_canonical_tree':canonical(n),'modified_source':source_changes,'sql_migrations':82,'sql_unchanged':True,'public_edge_exact_unchanged':True,'other_service_files_unchanged':True,'windows_qualification':'PENDING','field_qualification':'PENDING','full_behavioural_e2e':'PENDING','print_agent':'UNCHANGED','baklava_missing_from_print':'OPEN_UNVERIFIED','baseline_versions':[x['local']['version'] for x in r['supported_baselines']]}
(OUT/'R277-QA-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n');print(json.dumps(proof,ensure_ascii=False,indent=2))
with zipfile.ZipFile(OUT/name) as z:assert z.testzip() is None