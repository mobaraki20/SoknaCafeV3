#!/usr/bin/env python3
"""General Local Release Set v2.1 structural and readiness gate.

This checks the FINAL exact ZIP, not an upstream source/test fixture. It never
validates the private business DB or substitutes for physical host-side checks.
"""
import argparse,hashlib,io,json,pathlib,re,sys,zipfile

def digest(d):return hashlib.sha256(d).hexdigest()
def require(value,code):
 if not value:raise ValueError(code)

def audit(path,ready=False):
 data=pathlib.Path(path).read_bytes()
 with zipfile.ZipFile(io.BytesIO(data)) as outer:
  names=outer.namelist()
  require(len(names)==len(set(names)) and set(names)=={'release-set.json','artifacts/local/web.zip'},'RELEASE_SET_UNEXPECTED_FILES')
  require(outer.testzip() is None,'RELEASE_SET_CRC_ERROR')
  m=json.loads(outer.read('release-set.json'))
  web=outer.read('artifacts/local/web.zip')
 require(m.get('format')=='sokna-maintenance-release-set-v2.1' and m.get('schema_version')=='2.1','RELEASE_SET_SCHEMA_INVALID')
 require(m.get('release_type')=='local_only','RELEASE_SET_SCOPE_INVALID')
 artifact=m['artifacts']['local-web']
 require(artifact['path']=='artifacts/local/web.zip' and artifact['sha256']==digest(web) and artifact['size_bytes']==len(web),'RELEASE_SET_ARTIFACT_TAMPER')
 plan=m['install_plan'];expect=[('preflight',False),('stage',False),('activate',True)]
 require(isinstance(plan,list) and [(x.get('phase'),x.get('mutation')) for x in plan]==expect and all(x.get('component')=='local-web' for x in plan),'RELEASE_SET_INVALID_INSTALL_PLAN')
 require(m['pre_update_backup_policy']['required'] is True and m['pre_update_backup_policy']['system_recovery_snapshot_required'] is True and m['pre_update_backup_policy']['on_failure']=='reject_pre_mutation','RELEASE_SET_INSECURE_BACKUP_POLICY')
 require(m['database']['local']['rollback_after_boundary']=='forbidden','RELEASE_SET_DB_ROLLBACK_UNSAFE')
 baselines=m['supported_baselines']
 require(len(baselines)>=1 and all(b.get('public') is None and b.get('local') for b in baselines),'RELEASE_SET_LOCAL_BASELINE_INVALID')
 ids=[json.dumps(b['local'],sort_keys=True,ensure_ascii=False) for b in baselines]
 require(len(set(ids))==len(ids),'RELEASE_SET_DUPLICATE_BASELINE')
 require(all(len(str(b['local'].get('managed_tree_sha256','')))==64 for b in baselines),'RELEASE_SET_BAD_BASELINE_TREE')
 with zipfile.ZipFile(io.BytesIO(web)) as src:
  require(src.testzip() is None,'RELEASE_SET_WEB_CRC_ERROR')
  files=src.namelist()
  require(len(files)==len(set(files))==m['targets']['local']['managed_files'],'RELEASE_SET_MANAGED_FILE_COUNT_INVALID')
  require(src.read('VERSION.txt').decode().strip()==m['targets']['local']['version'],'RELEASE_SET_TARGET_VERSION_MISMATCH')
  target_tree=digest(''.join(n+'\0'+digest(src.read(n))+'\n' for n in sorted(files)).encode())
  require(target_tree==m['targets']['local']['managed_tree_sha256'],'RELEASE_SET_TARGET_TREE_MISMATCH')
 ok=m['for_install'] is True and m['production_pass'] is True
 if ready:
  require(ok,'RELEASE_SET_QA_NOT_INSTALLABLE')
  require(not re.search(r'(QA|SYNTHETIC|NOT[_-]?INSTALL|NOT[_-]?PRODUCTION|SOURCE[_-]?ONLY|TEST[_-]?ONLY)',m['status'],re.I),'RELEASE_SET_PRODUCTION_STATUS_UNSAFE')
  q=m['qualification']
  require(q['canary'] is True and q['real_windows'] is True and q['real_mariadb'] is True and q['recovery_console_web_down'] is True and q['business_backup_consistency_real_mariadb'] is True and q['failure_matrix_cases_required']>=80 and q['failure_matrix_id'],'RELEASE_SET_PRODUCTION_QUALIFICATION_INCOMPLETE')
  # This is only an offline policy gate. Actual signer trust and SHA-inbox
  # operator authorization are still verified by the native Maintenance Core.
 print('PASS_LOCAL_RELEASE_SET_FINAL_ZIP',digest(data),'baselines',len(baselines),'install_ready_manifest',ok,'target',m['targets']['local']['version'])
 return m

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('zip_path');p.add_argument('--require-installable',action='store_true');a=p.parse_args()
 audit(a.zip_path,ready=a.require_installable)