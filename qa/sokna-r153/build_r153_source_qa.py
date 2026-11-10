#!/usr/bin/env python3
"""Build R153 source audit candidates, explicitly non-installable.

This is NOT a signed customer update. It prevents falsely claiming all 2026-11
requests are complete or forging customer backup/restore evidence.
"""
from pathlib import Path
import io, zipfile, hashlib, json, subprocess, tempfile, re, os

SOURCE=Path(os.environ.get('SOKNA_R153_SOURCE_DIR','/mnt/data/sokna-current-r152'))
OUT=SOURCE/'r153-source-qa'
OUT.mkdir(exist_ok=True)
sha=lambda b:hashlib.sha256(b).hexdigest()
def require(x,name):
 if not x:raise RuntimeError(name)
 print('PASS',name)
def readzip(src):
 with zipfile.ZipFile(src) as z:
  require(z.testzip() is None,'ZIP_CRC_'+str(src.name))
  names=z.namelist()
  require(len(set(names))==len(names) and all(n and not n.startswith('/') and '\\' not in n and '..' not in n.split('/') for n in names),'ZIP_FILES_SAFE_'+src.name)
  return {n:z.read(n) for n in names}
def jbytes(o):return (json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode('utf-8')
def archive(d):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for name,b in sorted(d.items()):
   info=zipfile.ZipInfo(name,(2026,11,10,18,15,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,b)
 return buf.getvalue()
def tree_old(d):return sha(''.join(f'{k}\0{sha(v)}\n' for k,v in sorted(d.items())).encode())
def canonical(d,excluded):
 return sha(''.join(f'{k}\0{len(v)}\0{sha(v)}\n' for k,v in sorted(d.items()) if k not in excluded).encode())
localpath=SOURCE/'SOKNA-R152-LOCAL-231EC-44BASE-INVENTORY-CORRECTED-QA-NOT-INSTALL.zip'
publicpath=SOURCE/'SOKNA-R152-PUBLIC-35HJ-31BASE-NEW-QA-NOT-INSTALL.zip'
require(sha(localpath.read_bytes())=='2b28128d5f1d900cc56500ca3ebf9e4cb3d186946734c66fb62812ac97d5521f','PINNED_LOCAL_R152_SHA')
require(sha(publicpath.read_bytes())=='b997d3fb43c79d7a3b94fd81a1029b3c9441e2955ae1f5f4a0e243705b252bbb','PINNED_PUBLIC_R152_SHA')
local_outer=readzip(localpath);public_outer=readzip(publicpath)
require(set(local_outer)=={'release-set.json','artifacts/local/web.zip'},'LOCAL_STANDARD_RELEASE_SET_SHAPE')
local=readzip(io.BytesIO(local_outer['artifacts/local/web.zip'])) if False else None
with zipfile.ZipFile(io.BytesIO(local_outer['artifacts/local/web.zip'])) as z:local={p:z.read(p) for p in z.namelist()}
public={p[len('payload/'):]:v for p,v in public_outer.items() if p.startswith('payload/')}
require(len(local)==583 and len(public)==222,'EXACT_SOURCE_FILE_COUNTS')
require(tree_old(local)=='7b3d73b98a3876dd345d801417b6c045bd9fed8c84d2349a5cb79be840594c9e','R152_LOCAL_MANAGED_TREE_PINNED')
require(tree_old(public)=='ea1773ad5727e1cc2908d1f666346216b4a4051c36b8605b3fbccf3a41f2fed1','R152_PUBLIC_MANAGED_TREE_PINNED')
# Apply only the audited Local guest read-only/variant parity patch.
patch=SOURCE/'R152-LOCAL-guest-readonly-parity.patch'
with tempfile.TemporaryDirectory() as t:
 base=Path(t)
 for path in ['public/assets/scds/guest.js','public/assets/scds/guest.css']:
  p=base/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(local[path])
 p=subprocess.run(['patch','-p1','--batch','--forward','-d',str(base),'-i',str(patch)],text=True,capture_output=True)
 require(p.returncode==0,'EXACT_LOCAL_READONLY_GUEST_PATCH')
 for path in ['public/assets/scds/guest.js','public/assets/scds/guest.css']:local[path]=(base/path).read_bytes()
require(b'hasOrderContext' in local['public/assets/scds/guest.js'] and b'sg-detail-option--static' in local['public/assets/scds/guest.css'],'LOCAL_BROWSER_FIX_INCLUDED')
local_version='3.0.0-g6.2-workspace.231ed-r153-guest-readonly-meta-qa'
public_version='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa'
local_id='sokna-r153-local-44baseline-audited-source-qa-20261110'
public_id='sokna-r153-public-31baseline-identity-source-qa-20261110'
local['VERSION.txt']=(local_version+'\n').encode()
public['VERSION.txt']=(public_version+'\n').encode()
# Correct the Public Emergency owner to the precise unchanged candidate binary.
owner=json.loads(public['src/Update/emergency-owner-v1.json'])
owner['release_version']=public_version
owner['sha256']=sha(public['src/Update/EmergencyOwnerCandidate.php'])
public['src/Update/emergency-owner-v1.json']=jbytes(owner)
# Metadata are excluded by the canonical digest, avoiding circular dependencies.
for side,data,ident,version,newid in [
 ('local',local,'resources/release-identity-v1.json',local_version,local_id),
 ('public',public,'resources/release-identity-v1.json',public_version,public_id)]:
 provenance=json.loads(data['resources/build-provenance.json'])
 provenance.update({'version':version,'release_set_id':newid,'classification':'R153_SOURCE_QA_NOT_FOR_INSTALL',
  'source':'Byte-exact R152 protected QA source + audited guest UX/identity fixes',
  'production_host_install_verified':False,'current_cafe_backup_restored_in_lab':False,
  'public_unchanged':False,'created_at':'2026-11-10T18:15:00Z'})
 if side=='public':
  provenance.update({'qualification_only':True,'target_host_install_verified':False,'source_regressions_host_verified':False,
   'db_migration_mariadb_qualification':'NOT_RUN_R153','staff_remote_login_qualification':'NOT_RUN_R153'})
 data['resources/build-provenance.json']=jbytes(provenance)
 identity=json.loads(data[ident])
 excluded=['resources/build-provenance.json','resources/release-identity-v1.json']
 identity.update({'version':version,'release_set_id':newid,'qualification':{
  'tier':'r153_unapproved_source_only','claim_rule':'Static and browser fixture QA only; no Production approval, live backup/restore or field readback'}})
 identity['source'].update({'canonical_file_count':len(data)-len(excluded),'managed_file_count':len(data),
  'canonical_tree_sha256':canonical(data,set(excluded)),
  'source_ref':'R152 exact QA source + reviewed R153 delta, no inferred customer state',
  'digest_excludes':excluded if side=='local' else excluded+['manifest.json']})
 if side=='local':
  current=json.loads(local_outer['release-set.json'])
  identity['baseline']={'mode':'exact_compatible_allowlist','source_versions':[x['local']['version'] for x in current['supported_baselines']]}
 else:
  current=json.loads(public_outer['manifest.json'])
  identity['baseline']={'mode':'exact_compatible_allowlist','source_versions':[x['version'] for x in current['compatibility']['supported_baselines']]}
 data[ident]=jbytes(identity)
 require(identity['version']==data['VERSION.txt'].decode().strip(),'IDENTITY_VERSION_MATCH_'+side)
 require(identity['source']['canonical_tree_sha256']==canonical(data,set(excluded)),'CANONICAL_DIGEST_'+side)
 require(identity['source']['canonical_file_count']==len(data)-2,'CANONICAL_FILE_COUNT_'+side)
# For Local update from R112, retain all 80 SQL byte-for-byte. Do not change any SQL.
with zipfile.ZipFile(io.BytesIO(local_outer['artifacts/local/web.zip'])) as z:
 oldsql={p:sha(z.read(p)) for p in z.namelist() if p.startswith('database/migrations/') and p.endswith('.sql')}
newsql={p:sha(v) for p,v in local.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
require(len(oldsql)==80 and oldsql==newsql,'ZERO_MIGRATION_CHANGE_0077_TO_0080_UNCHANGED')
# Local inventory lists 583 exact names, no addition/removal.
inv=json.loads(local['resources/local-managed-files-v1.json']);require(set(inv['files'])==set(local),'LOCAL_583_EXACT_INVENTORY')
lm=json.loads(local_outer['release-set.json'])
require(len(lm['supported_baselines'])==44,'EXACT_44_LOCAL_SOURCES')
lm['targets']['local'].update({'version':local_version,'release_id':local_id,'managed_files':len(local),'managed_tree_sha256':tree_old(local)})
lm['release_set_id']=local_id
lm['sequence']=20261110154
lm['for_install']=False;lm['production_pass']=False;lm['signature']=None;lm['install_plan']=[]
lm['status']='R153_SOURCE_AUDIT_QA_NOT_INSTALL'
lm['qa_notes']={'source':'R152 pinned 583 files + Local display-only guest repair + internal identity reconciliation',
 'all_requests_complete':'NOT_VERIFIED','browser_readonly':'PASS_LOCAL_MOCK','real_cafe_backup_restore':False,
 'live_windows_local_install':'NOT_RUN','production_status':'BLOCKED'}
lm['qualification']={'canary':False,'real_windows':False,'real_mariadb':False,'business_backup_consistency_real_mariadb':False}
web=archive(local)
lm['artifacts']['local-web'].update({'sha256':sha(web),'size_bytes':len(web)})
local_out=OUT/'SOKNA-R153-LOCAL-231ED-44BASE-SOURCE-QA-NOT-INSTALL.zip'
local_out.write_bytes(archive({'release-set.json':jbytes(lm),'artifacts/local/web.zip':web}))
pm=json.loads(public_outer['manifest.json'])
require(len(pm['compatibility']['supported_baselines'])==31,'EXACT_31_PUBLIC_SOURCES')
pm['version']=public_version
pm['target_managed_tree_sha256']=tree_old(public)
pm['files']=[{'path':p,'size':len(data),'sha256':sha(data)} for p,data in sorted(public.items())]
pm['provenance'].update({'production_status':'QA_ONLY_NOT_FOR_PRODUCTION','ready_for_production':False,
 'r153_source_meta_owner':'CANONICAL_DIGEST_VERSION_EMERGENCY_OWNER_FIXED',
 'r153_business_requests_complete':'UNVERIFIED',
 'r153_qa_source':'R152 verified parent 222 files; no DB schema change',
 'r153_windows_stage':'NOT_RUN','r153_live_public_host':'NOT_RUN','r153_emergency_native':'NOT_RUN'})
public_out=OUT/'SOKNA-R153-PUBLIC-35HK-31BASE-SOURCE-QA-NOT-INSTALL.zip'
public_out.write_bytes(archive({'manifest.json':jbytes(pm),**{'payload/'+p:b for p,b in public.items()}}))
for f in [local_out,public_out]:require(zipfile.ZipFile(f).testzip() is None,'OUTPUT_ZIP_CRC_'+f.name)
# Preserve source records and explicit stop; no made-up approvals.
(OUT/'SHA256SUMS.txt').write_text(''.join(f'{sha(f.read_bytes())}  {f.name}\n' for f in [local_out,public_out]))
(OUT/'QA-SUMMARY.json').write_bytes(jbytes({'format':'sokna-r153-source-audit-qa-v1','local_source_files':583,'public_source_files':222,
 'local_version':local_version,'public_version':public_version,'local_baselines':44,'public_baselines':31,
 'local_managed_sha':tree_old(local),'public_managed_sha':tree_old(public),
 'local_browser_readonly_fixture':'PASS','php_js_syntax':'SEE_SEPARATE_QA',
 'all_business_changes_verified':False,'live_cafe_backup_restore_verified':False,'production_authorized':False,
 'for_install':False,'installer_release_created':False,'r125_historical_issue':'RECONCILED_PRIOR_FIELD_REPORT'}))
print('QA_ONLY_OUTPUTS',local_out,public_out)