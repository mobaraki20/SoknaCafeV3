#!/usr/bin/env python3
"""R153 universal Local Home Canary builder; not café Production approval."""
from __future__ import annotations
import argparse,hashlib,io,json,pathlib,zipfile

QA_SHA='4f98e7eb948e78c49b09f4f41299ec7b4c68b562cfac8dbe72e0c827a69ef613'
R149B_SHA='968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4'
WEB_SHA='1d39bbe406929089eac9880a0aec794947112b74c17359c48c4d49893e0183b8'
PLAN=[{'component':'local-web','mutation':False,'phase':'preflight'},{'component':'local-web','mutation':False,'phase':'stage'},{'component':'local-web','mutation':True,'phase':'activate'}]
def digest(b):return hashlib.sha256(b).hexdigest()
def check(ok,msg):
 if not ok:raise ValueError(msg)
def load(p):
 data=pathlib.Path(p).read_bytes()
 with zipfile.ZipFile(io.BytesIO(data)) as z:
  check(z.testzip() is None and len(z.namelist())==2 and set(z.namelist())=={'release-set.json','artifacts/local/web.zip'},'OUTER_ZIP_INVALID')
  m=json.loads(z.read('release-set.json'));w=z.read('artifacts/local/web.zip')
  check(digest(w)==m['artifacts']['local-web']['sha256'],'WEB_ARTIFACT_HASH_MISMATCH')
 return data,m,w
def build(qa,old,evidence,out):
 a,m,w=load(qa);b,t,prior_w=load(old)
 check(digest(a)==QA_SHA and digest(b)==R149B_SHA and digest(w)==WEB_SHA,'SOURCE_PIN_INVALID')
 check(m['install_plan']==t['install_plan']==PLAN,'INSTALL_CONTRACT_CHANGED')
 check(m['for_install'] is False and m['production_pass'] is False and m['signature'] is None,'SOURCE_ALREADY_INSTALLABLE')
 check(t['for_install'] and t['production_pass'] and t['qualification']['canary'] and t['qualification']['failure_matrix_cases_required']>=308,'PARENT_HOME_CANARY_UNQUALIFIED')
 check(m['pre_update_backup_policy']==t['pre_update_backup_policy'] and m['database']==t['database'],'HOST_BACKUP_OR_DB_POLICY_DRIFT')
 check(len(m['supported_baselines'])==44 and m['targets']['local']['managed_files']==583,'SOURCE_NOT_44BASE_R153')
 with zipfile.ZipFile(io.BytesIO(w)) as z, zipfile.ZipFile(io.BytesIO(prior_w)) as p:
  ns=set(z.namelist());ps=set(p.namelist())
  sq={x:digest(z.read(x)) for x in ns if x.startswith('database/migrations/') and x.endswith('.sql')}
  pq={x:digest(p.read(x)) for x in ps if x.startswith('database/migrations/') and x.endswith('.sql')}
  check(sq==pq and len(sq)==80,'R153_MIGRATION_BYTES_NOT_IDENTICAL_TO_R149B')
  changed={n for n in ns & ps if digest(z.read(n))!=digest(p.read(n))}
  allowed={'VERSION.txt','public/assets/scds/guest.css','public/assets/scds/guest.js','resources/build-provenance.json','resources/local-managed-files-v1.json','resources/release-identity-v1.json','src/Domain/GuestLan/GuestLanPageRenderer.php'}
  added={'resources/guest-themes/sokna-coastal-clarity/theme.json','resources/guest-themes/sokna-modern/theme.json'}
  check(changed==allowed and ns-ps==added and not ps-ns,'R153_UNREVIEWED_FILE_CHANGE')
 e=json.loads(pathlib.Path(evidence).read_text(encoding='utf-8'))
 check(e.get('exact_qa_sha256')==QA_SHA and e.get('ci_verified_against_github_api') is True,'CI_EVIDENCE_MISSING')
 runs=e.get('ci_runs') or {}
 check(runs.get('44_native_windows_preflight')=={'run_id':'42896959119','conclusion':'success'},'44_PREFLIGHT_CI_NOT_VERIFIED')
 check(runs.get('12_native_activation_restore')=={'run_id':'42896278262','conclusion':'success'},'12_ACTIVATION_CI_NOT_VERIFIED')
 m=json.loads(json.dumps(m))
 m['for_install']=True;m['production_pass']=True
 m['status']='R153_LOCAL_44BASE_HOME_CANARY_QUALIFIED'
 m['release_set_id']='sokna-r153-local-44base-home-canary-20261111'
 m['sequence']=20261111160;m['created_at']='2026-11-10T21:00:00Z'
 m['qualification']=t['qualification']
 m['qualification']['failure_matrix_id']='R153_INHERITED_R149B_308_SAME_80_SQL_PLUS_R153_44_NATIVE_12_ACTIVATION'
 m['qa_notes']={
  'scope':'HOME_CANARY_ONLY_NOT_CAFE_PRODUCTION_APPROVAL',
  'installation':'PANEL_EXPLICIT_OPERATOR_UPLOAD_ONLY',
  'physical_backup':'REQUIRED_ON_EACH_HOST_BEFORE_ANY_MUTATION_NOT_FIELD_VERIFIED',
  'business_backup_proof':'LAB_REAL_MARIADB_ISOLATED_RESTORE_NOT_CAFE_BUSINESS_DATA',
  'ci_44_native_windows_preflight':'https://github.com/mobaraki20/SoknaCafeV3/actions/runs/42896959119',
  'ci_12_native_windows_mariadb_activation_rollback':'https://github.com/mobaraki20/SoknaCafeV3/actions/runs/42896278262',
  'migration_sql':'80_OF_80_BYTE_IDENTICAL_TO_R149B_HOME_CANARY_QUALIFIED',
  'source_delta':'7_CHANGED_UI_OR_META_AND_2_ADDED_THEMES',
  'all_requests_complete':'NOT_VERIFIED','field_restore_proven':False,
  'cafe_production_approved':False,'operator_signature':'NONE_OPERATOR_SHA256_INBOX_REQUIRED'}
 m['signature']=None
 out=pathlib.Path(out);out.parent.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED) as z:
  for name,raw in sorted({'release-set.json':json.dumps(m,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode('utf-8'),'artifacts/local/web.zip':w}.items()):
   info=zipfile.ZipInfo(name,(2026,11,11,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED
   z.writestr(info,raw)
 print(json.dumps({'type':'HOME_CANARY_NOT_CAFE_PRODUCTION','file':str(out),'sha256':digest(out.read_bytes()),'bytes':out.stat().st_size,'web_sha256':digest(w),'baselines':44,'unchanged_sql':80},sort_keys=True))
if __name__=='__main__':
 p=argparse.ArgumentParser()
 p.add_argument('--qa',required=True);p.add_argument('--parent-qualified',required=True)
 p.add_argument('--evidence',required=True);p.add_argument('--out',required=True)
 x=p.parse_args();build(x.qa,x.parent_qualified,x.evidence,x.out)
