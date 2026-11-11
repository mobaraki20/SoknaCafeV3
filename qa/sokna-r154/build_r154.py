#!/usr/bin/env python3
"""Build a reproducible R154 Local Home-Canary update from the exact R153 Home-Canary ZIP.

The 44 historic exact identities are preserved and active R153 is added as source #45.
No SQL is changed, no Maintenance or Public components are modified.
"""
import argparse,hashlib,io,json,os,pathlib,subprocess,tempfile,zipfile
SHA_R153='61383cf48c88760d7e201130408bf0a3853e665507d40a0ea06ffe7107498e13'
VERSION='3.0.0-g6.2-workspace.231ee-r154-guest-theme-immutable-repair-canary'
RELEASE_ID='sokna-r154-local-45base-guest-theme-repair-home-canary-20261111'
SEQUENCE=20261111170
PATCH_FILES=['src/Domain/GuestContent/GuestContentService.php','public/guest-content/index.php','public/assets/guest-content-workspace.js','public/assets/product-ui.css']
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(cond,msg):
 if not cond:raise RuntimeError(msg)
def jbytes(v):return (json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def archive(items):
 data=io.BytesIO()
 with zipfile.ZipFile(data,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for path,payload in sorted(items.items()):
   info=zipfile.ZipInfo(path,(2026,11,11,6,30,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,payload)
 return data.getvalue()
def legacy_tree(d):return sha(''.join(f'{path}\0{sha(blob)}\n' for path,blob in sorted(d.items())).encode())
def canonical_tree(d):return sha(''.join(f'{path}\0{len(blob)}\0{sha(blob)}\n' for path,blob in sorted(d.items()) if path not in {'resources/build-provenance.json','resources/release-identity-v1.json'}).encode())
def run(src,patch,out):
 raw=pathlib.Path(src).read_bytes();need(sha(raw)==SHA_R153,'PINNED_R153_HOME_CANARY_SHA_MISMATCH')
 with zipfile.ZipFile(io.BytesIO(raw)) as outer:
  need(outer.testzip() is None and set(outer.namelist())=={'release-set.json','artifacts/local/web.zip'},'R153_OUTER_ZIP_INVALID')
  meta=json.loads(outer.read('release-set.json'));wb=outer.read('artifacts/local/web.zip')
 need(sha(wb)==meta['artifacts']['local-web']['sha256'],'R153_WEB_HASH_INVALID')
 with zipfile.ZipFile(io.BytesIO(wb)) as web:
  need(web.testzip() is None,'R153_WEB_CRC_ERROR')
  files={p:web.read(p) for p in web.namelist()}
 need(len(files)==583,'R153_583_MANAGED_FILES_REQUIRED')
 source_mig={p:sha(b) for p,b in files.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 need(len(source_mig)==80,'R153_80_SQL_REQUIRED')
 # Only the four reviewed code/UI files are eligible for patch mutation.
 with tempfile.TemporaryDirectory(prefix='sokna-r154-safe-patch-') as tmp:
  tmp=pathlib.Path(tmp)
  for f in PATCH_FILES:
   dst=tmp/f;dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes(files[f])
  c=subprocess.run(['patch','-p1','--batch','--forward','--fuzz=0','-d',str(tmp),'-i',str(pathlib.Path(patch).resolve())],capture_output=True,text=True)
  need(c.returncode==0,'EXACT_PATCH_APPLY_FAILED '+c.stdout+' '+c.stderr)
  for f in PATCH_FILES:files[f]=(tmp/f).read_bytes()
 files['VERSION.txt']=(VERSION+'\n').encode()
 prior_target=meta['targets']['local'].copy()
 need(prior_target['version']==json.loads(open('/dev/null','r').read() or 'null') if False else prior_target['version'].startswith('3.0.0-g6.2-workspace.231ed-'),'R153_PARENT_TARGET_VERSION_BAD')
 identity=json.loads(files['resources/release-identity-v1.json'])
 identity.update({'version':VERSION,'release_set_id':RELEASE_ID,'qualification':{
  'tier':'r154_home_canary_only_not_cafe_production','claim_rule':'Exact R153 source + four reviewed guest-content code changes; native Windows and MariaDB gates required; no live café restore claim'}})
 identity['baseline']['source_versions']=list(dict.fromkeys(identity['baseline']['source_versions']+[prior_target['version']]))
 identity['source'].update({'canonical_file_count':581,'managed_file_count':583,'canonical_tree_sha256':canonical_tree(files),
  'source_ref':'Exact pinned R153 Home Canary plus R154 guest-content safe repair, zero SQL change',
  'digest_excludes':['resources/build-provenance.json','resources/release-identity-v1.json']})
 files['resources/release-identity-v1.json']=jbytes(identity)
 prov=json.loads(files['resources/build-provenance.json'])
 prov.update({'version':VERSION,'release_set_id':RELEASE_ID,'classification':'R154_HOME_CANARY_ONLY_NOT_CAFE_PRODUCTION',
  'source':'Pinned R153 231ed Home Canary + four-file guest-content repair, exact SQL unchanged',
  'created_at':'2026-11-11T06:30:00Z','public_unchanged':True,
  'current_cafe_backup_restored_in_lab':False,'production_host_install_verified':False})
 files['resources/build-provenance.json']=jbytes(prov)
 need(canonical_tree(files)==identity['source']['canonical_tree_sha256'],'R154_IDENTITY_CANONICAL_MISMATCH')
 need({p:sha(b) for p,b in files.items() if p.startswith('database/migrations/') and p.endswith('.sql')}==source_mig,'MIGRATION_BYTES_CHANGED')
 inv=json.loads(files['resources/local-managed-files-v1.json']);need(len(inv['files'])==len(files)==583 and set(inv['files'])==set(files),'R154_MANAGED_INVENTORY_MISMATCH')
 baselines=meta['supported_baselines'];need(len(baselines)==44,'PARENT_44_BASELINES_REQUIRED')
 need(all(b['local']['version']!=prior_target['version'] for b in baselines),'R153_BASELINE_DUPLICATE')
 baselines.append({'local':prior_target,'public':None})
 need(len({json.dumps(b['local'],sort_keys=True) for b in baselines})==45,'R154_DUPLICATE_BASELINE')
 need(any(b['local']['version'].startswith('3.0.0-g6.2-workspace.231dg-') for b in baselines),'R112_CAFE_BASELINE_LOST')
 need(any(b['local']['version'].startswith('3.0.0-g6.2-workspace.231eb-') for b in baselines),'R149B_HOME_BASELINE_LOST')
 meta['targets']['local'].update({'version':VERSION,'release_id':RELEASE_ID,'managed_files':583,'managed_tree_sha256':legacy_tree(files)})
 meta.update({'release_set_id':RELEASE_ID,'sequence':SEQUENCE,'created_at':'2026-11-11T06:30:00Z','status':'R154_LOCAL_45BASE_HOME_CANARY_INSTALLABLE','for_install':True,'production_pass':True,'signature':None})
 meta['qa_notes']={
  'scope':'HOME_CANARY_ONLY_NOT_CAFE_PRODUCTION_APPROVAL',
  'source':'PINNED_R153_SHA_61383CF...; FOUR_REVIEWED_UI_AND_GUEST_THEME_FILES',
  '45_exact_baselines_includes_installed_home_R153':True,
  'preserved_80_sql_sha256':True,'no_sql_or_public_or_maintenance_change':True,
  'host_specific_backup_required':'FAIL_CLOSED_BEFORE_ANY_MUTATION',
  'actual_cafe_business_restore_proven':False,'all_2_week_requests_accepted':False,
  'db_test':'REQUIRED_MARIADB_IMMUTABLE_THEME_AND_HISTORY',
  'native_windows_stage':'REQUIRED_45_BASELINES_PLUS_R153_ACTUAL_STAGE',
  'unresolved':'CAFE_PRODUCTION_QUALIFICATION_AND_BUSINESS_E2E',
 }
 # Existing recovery/qualification contract is inherited; independent R154 tests
 # must pass before this Home Canary is published.
 meta['qualification']['failure_matrix_id']='R154_R153_EXISTING_308_FAULT_MATRIX_PLUS_NEW_IMMUTABLE_THEME_MARIADB_AND_45_NATIVE_STAGE'
 webbytes=archive(files)
 meta['artifacts']['local-web'].update({'sha256':sha(webbytes),'size_bytes':len(webbytes)})
 output=archive({'release-set.json':jbytes(meta),'artifacts/local/web.zip':webbytes})
 out=pathlib.Path(out);out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(output)
 report={'scope':'R154_HOME_CANARY_NOT_CAFE_PRODUCTION','source_sha256':sha(raw),'package_sha256':sha(output),'web_sha256':sha(webbytes),'bytes':len(output),'managed_files':583,'exact_baselines':45,'unchanged_sql_migrations':80,'target_version':VERSION,'managed_tree_sha256':meta['targets']['local']['managed_tree_sha256'],'changed_business_paths':PATCH_FILES}
 print(json.dumps(report,indent=2,ensure_ascii=False));return report
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--source',required=True);p.add_argument('--patch',required=True);p.add_argument('--out',required=True)
 a=p.parse_args();run(a.source,a.patch,a.out)