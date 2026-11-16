#!/usr/bin/env python3
"""R264 QA cumulative source overlay atop exact R262 Local/Public, no installed env.
Only order item correction UI, redundant Rial helper, printable template export.
No changes to SQL/order/print backend, Windows Maintenance, or original Public.
"""
from __future__ import annotations
from pathlib import Path
import copy,hashlib,io,json,zipfile
R=Path(__import__('os').environ.get('R264_ROOT','/mnt/data/sokna_r264_work'));OUT=R/'candidate';OUT.mkdir(exist_ok=True)
BASE=Path(__import__('os').environ.get('R264_BASE_DIR','/mnt/data'))
LOCAL_ZIP=BASE/'SOKNA-R262-LOCAL-231FG-CUMULATIVE-PUBLIC-MOVE-NATIVE-UAT-UNQUALIFIED.zip'
PUBLIC_ZIP=BASE/'SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip'
CLEAN_ZIP=BASE/'SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip'
PINS={LOCAL_ZIP:'3d12ea375a307e58c8e36b2036a4fea9dec9ef05330f4a046234102c32164b8c',PUBLIC_ZIP:'5aef4cd781fbd450d3e454f324e294da6f4180bfe1e5eb1871cb4818dc20c93a',CLEAN_ZIP:'37242dabf6893bfc4bf26e8233485802c197ea8b742ae264b8aed9e0971f0d21'}
for p,d in PINS.items():assert hashlib.sha256(p.read_bytes()).hexdigest()==d, f'NOT_PINNED {p.name}'
H=lambda b:hashlib.sha256(b).hexdigest()
JSON=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zip_d(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for name,data in sorted(d.items()):
   v=zipfile.ZipInfo(name,(2026,11,16,0,0,0));v.compress_type=zipfile.ZIP_DEFLATED;v.external_attr=(0o100644<<16)
   z.writestr(v,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def hash_tree(d):return H(''.join(n+'\0'+H(data)+'\n' for n,data in sorted(d.items())).encode())
def readz(path):
 with zipfile.ZipFile(path) as z:
  assert z.testzip() is None
  return {n:z.read(n) for n in z.namelist() if not n.endswith('/')}
def patch(path,text,expect=None):
 if expect:assert H(text)==expect,('QA_PATCH_SHA_MISMATCH',path)
 # Only four actual source assets can be different; their original bytes came from matching source ZIP.
 new={
 'public/assets/staff-app/hall-workspace.js':R/'local/public/assets/staff-app/hall-workspace.js',
 'public/assets/product-ui.css':R/'local/public/assets/product-ui.css',
 'public/assets/printing-workspace.js':R/'local/public/assets/printing-workspace.js',
 'assets/staff-app/hall-workspace.js':R/'public-hall.js',
 'assets/product-ui.css':R/'public-product-ui.css',
 }
 assert path in new,path
 return new[path].read_bytes()
# Local identity: preserve exact parent R262, 82 SQLs, all other asset hashes.
with zipfile.ZipFile(LOCAL_ZIP) as outer:
 release=json.loads(outer.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  old={n:inner.read(n) for n in inner.namelist() if not n.endswith('/')}
assert len(old)==604 and H(old['VERSION.txt']) and old['VERSION.txt'].decode().strip().endswith('r262-public-move-qa')
new=dict(old)
paths=['public/assets/staff-app/hall-workspace.js','public/assets/product-ui.css','public/assets/printing-workspace.js']
for p in paths:new[p]=patch(p,old[p])
V='3.0.0-g6.2-workspace.231fh-r264-order-edit-qa';new['VERSION.txt']=(V+'\n').encode()
files=json.loads(new['resources/local-managed-files-v1.json'])
files['files']=sorted(new);new['resources/local-managed-files-v1.json']=JSON(files)
prov=json.loads(new['resources/build-provenance.json'])
prov.update(version=V,release_set_id='sokna-r264-local-r262-ui-print-export-20261116',classification='R264_QA_PRELIMINARY_NOT_PRODUCTION',installed_host_accepted=False,production_host_install_verified=False,full_business_acceptance=False,source='byte-exact R262 + 3 changed UI assets and metadata, no DB/printing backend change')
new['resources/build-provenance.json']=JSON(prov)
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
canonical=lambda d:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in EXCLUDE).encode())
identity=json.loads(new['resources/release-identity-v1.json'])
identity['version']=V;identity['release_set_id']=prov['release_set_id'];identity['source']['canonical_tree_sha256']=canonical(new)
identity['source']['source_ref']='Exact R262 231fg, + local hall item correction UX and template export R264'
identity['qualification']['tier']='R264_QA_GATE_PENDING' # preserve source baseline metadata
new['resources/release-identity-v1.json']=JSON(identity)
assert len(new)==len(old)==604
assert all(new[n]==data for n,data in old.items() if n.startswith('database/migrations/'))
changed=sorted(n for n in old if old[n]!=new[n]);assert set(changed)==set(paths)|{'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'},changed
old_target=copy.deepcopy(release['targets']['local']);assert old_target['version']== '3.0.0-g6.2-workspace.231fg-r262-public-move-qa'
release['release_set_id']=prov['release_set_id'];release['sequence']=202611160264;release['status']='R264_QA_NOT_FIELD_ACCEPTED'
# Flags are required by CI native stage, but do not grant actual operator authorization.
release['for_install']=True;release['production_pass']=True
assert not any((v.get('local') or {}).get('version')==old_target['version'] for v in release['supported_baselines'])
release['supported_baselines'].append({'local':old_target,'public':None})
release['targets']['local'].update(version=V,release_id=release['release_set_id'],managed_files=len(new),managed_tree_sha256=hash_tree(new))
release['artifacts']['local-web'].update(sha256=H(zip_d(new)),size_bytes=len(zip_d(new)))
release['qa_notes']['r264']='R262 byte-exact base, no SQL or Local-Public sync changes; UI mobile count fixes and canonical print template export only; host acceptance pending.'
release['qa_notes']['r264_host_install']='BLOCKED_PENDING_FIELD_TEST'
local_zip=zip_d({'release-set.json':JSON(release),'artifacts/local/web.zip':zip_d(new)})
local_name='SOKNA-R264-LOCAL-231FH-CUMULATIVE-UI-PRINT-QA-NOT-FOR-CAFE.zip'
(OUT/local_name).write_bytes(local_zip)
# Public: preserve R262 protected updater, change two UI assets, version and provenance only.
update=readz(PUBLIC_ZIP);manifest=json.loads(update.pop('manifest.json'))
old_pub={k.removeprefix('payload/'):v for k,v in update.items() if k.startswith('payload/')}
assert len(old_pub)==244
new_pub=dict(old_pub)
for p in ['assets/staff-app/hall-workspace.js','assets/product-ui.css']:new_pub[p]=patch(p,old_pub[p])
VP='3.0.0-g3.6-workspace.35ie-r264-order-edit-qa';new_pub['VERSION.txt']=(VP+'\n').encode()
if 'resources/build-provenance.json' in new_pub:
 p=json.loads(new_pub['resources/build-provenance.json']);p.update(version=VP,classification='R264_UI_ONLY_QA_PENDING_FIELD',production_host_install_verified=False,installed_host_accepted=False);new_pub['resources/build-provenance.json']=JSON(p)
pub_changes=sorted(p for p in old_pub if old_pub[p]!=new_pub[p])
assert set(pub_changes)=={'VERSION.txt','assets/staff-app/hall-workspace.js','assets/product-ui.css','resources/build-provenance.json'},pub_changes
assert all(old_pub[n]==new_pub[n] for n in old_pub if n.startswith('database/migrations/'))
old_pub_version=manifest['version'];old_pub_tree=manifest['target_managed_tree_sha256']
manifest['version']=VP;manifest['target_managed_tree_sha256']=hash_tree(new_pub)
manifest['compatibility']['supported_baselines'].append({'version':old_pub_version,'managed_tree_sha256':old_pub_tree})
manifest['files']=[{'path':p,'sha256':H(v),'size':len(v)} for p,v in sorted(new_pub.items())]
manifest['provenance'].update(build='R264-print-ux-correction-qa-only',production_host_qualified=False,qa_only_hold=['Cafe P0 missing Baklava printing unresolved; host acceptance pending'])
public_update=zip_d({'manifest.json':JSON(manifest),**{'payload/'+p:v for p,v in new_pub.items()}})
pub_name='SOKNA-R264-PUBLIC-35IE-CUMULATIVE-UI-QA-NOT-FOR-CAFE.zip';(OUT/pub_name).write_bytes(public_update)
# Fresh host must include all seven protected static runtime files from truly self-contained R263 clean source.
clean_old=readz(CLEAN_ZIP)
assert len(clean_old)==251
for p in old_pub:assert p in clean_old and clean_old[p]==old_pub[p], ('CLEAN_SOURCE_BASE_DIFF',p)
clean_new=dict(clean_old);clean_new.update(new_pub)
assert len(clean_new)==251
assert all(new_pub[p]==clean_new[p] for p in new_pub)
assert not any(p in clean_new for p in ('config.php','install.lock','.env','emergency.php'))
clean_zip=zip_d(clean_new)
clean_name='SOKNA-R264-PUBLIC-35IE-SELFCONTAINED-CLEAN-UI-QA-NOT-FOR-CAFE.zip';(OUT/clean_name).write_bytes(clean_zip)
proof={'format':'sokna-r264-ui-and-print-export-preflight-v1','base_local_sha256':PINS[LOCAL_ZIP],'base_public_update_sha256':PINS[PUBLIC_ZIP],'base_public_clean_sha256':PINS[CLEAN_ZIP],'target_local_version':V,'target_public_version':VP,'local_update_sha256':H(local_zip),'public_update_sha256':H(public_update),'public_clean_sha256':H(clean_zip),'local_existing_files':len(old),'public_existing_files':len(old_pub),'new_migrations':0,'local_migration_count':82,'public_migration_count':11,'local_asset_changes':paths,'public_asset_changes':['assets/staff-app/hall-workspace.js','assets/product-ui.css'],'local_managed_tree_sha256':hash_tree(new),'public_managed_tree_sha256':hash_tree(new_pub),'supported_local_baselines':[x['local']['version'] for x in release['supported_baselines']],'qa_only':True,'operator_install_allowed':False,'cafe_missing_baklava_bug':'NOT_RESOLVED_UNTIL_REAL_CAFE_PREPARATION_STATION_AND_PRINT_PAYLOAD_AUDIT','windows_native_gate':'PENDING','physical_paper_gate':'PENDING'}
(OUT/'R264-QA-PACKAGE-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
for name in (local_name,pub_name,clean_name):
 with zipfile.ZipFile(OUT/name) as z:assert z.testzip() is None
print(json.dumps(proof,ensure_ascii=False,indent=2))