#!/usr/bin/env python3
"""Universal Local Release Set contract assembler and safety gate.

All supported Local installations consume THE SAME target web bytes. Never infer
unrecognised baselines, forge Production qualification, or alter a business DB.
Source bytes and a historical, actually install-qualified Local release template
are pinned by expected SHA-256. This tool is source-only and cannot approve a
Production release or manufacture a field backup.
"""
import argparse, hashlib, io, json, os, pathlib, re, sys, zipfile

SHA_R153 = 'd7761df8002fe15680eddda91f3928bdbf8b4e9f731659210cd8885424aa345d'
SHA_APPROVED_TEMPLATE = '968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4'
EXPECTED_PLAN = [
 {'phase':'preflight', 'component':'local-web','mutation':False},
 {'phase':'stage', 'component':'local-web','mutation':False},
 {'phase':'activate', 'component':'local-web','mutation':True},
]

def sha(data):return hashlib.sha256(data).hexdigest()
def must(x,msg):
 if not x:raise ValueError(msg)

def load(path):
 data=pathlib.Path(path).read_bytes()
 with zipfile.ZipFile(io.BytesIO(data)) as z:
  must(z.testzip() is None,'ZIP_CRC_ERROR')
  names=z.namelist()
  must(len(names)==len(set(names)) and len(names)==2 and set(names)=={'release-set.json','artifacts/local/web.zip'},'ZIP_UNEXPECTED_OR_DUPLICATE_NAMES')
  m=json.loads(z.read('release-set.json'))
  web=z.read('artifacts/local/web.zip')
  must(sha(web)==m['artifacts']['local-web']['sha256'] and len(web)==m['artifacts']['local-web']['size_bytes'],'ZIP_ARTIFACT_SHA_OR_SIZE_MISMATCH')
 return data,m,web

def validate_base(src,template):
 d,m,web=load(src)
 dref,t,_=load(template)
 must(sha(d)==SHA_R153,'PINNED_R153_SOURCE_SHA_MISMATCH')
 must(sha(dref)==SHA_APPROVED_TEMPLATE,'PINNED_R149B_QUALIFIED_TEMPLATE_SHA_MISMATCH')
 must(t['install_plan']==EXPECTED_PLAN and t['for_install'] and t['production_pass'],'APPROVED_TEMPLATE_INVALID')
 must(m['format']==t['format']=='sokna-maintenance-release-set-v2.1' and m['schema_version']==t['schema_version']=='2.1','RELEASE_FORMAT_INVALID')
 must(m['release_type']=='local_only' and m['for_install'] is False and m['production_pass'] is False,'R153_SOURCE_NOT_QA_ONLY')
 must(m['install_plan']==[] and m['signature'] is None,'R153_SOURCE_ALREADY_PROMOTED_OR_MUTATED')
 must(m['database']==t['database'] and m['pre_update_backup_policy']==t['pre_update_backup_policy'],'RELEASE_RECOVERY_OR_DB_POLICY_DRIFT')
 must(m['mutation_policy']==t['mutation_policy'] and m['requirements']==t['requirements'],'RELEASE_MUTATION_OR_MIN_VERSION_DRIFT')
 must(m['targets']['local']['managed_files']==583,'TARGET_MANAGED_COUNT_INVALID')
 with zipfile.ZipFile(io.BytesIO(web)) as z:
  must(z.testzip() is None and len(z.namelist())==583 and len(set(z.namelist()))==583,'TARGET_583_MANAGED_SOURCE_NOT_EXACT')
  must(sum(x.startswith('database/migrations/') and x.endswith('.sql') for x in z.namelist())==80,'TARGET_80_MIGRATIONS_MISSING')
  must(z.read('VERSION.txt').decode().strip()==m['targets']['local']['version'],'TARGET_VERSION_SOURCE_MISMATCH')
 baselines=m['supported_baselines']
 must(len(baselines)==44,'NOT_44_EXACT_BASELINES')
 must(all(isinstance(b['local'],dict) and b['public'] is None for b in baselines),'NON_LOCAL_BASELINE_IN_UNIVERSAL_SET')
 ids=[json.dumps(b['local'],sort_keys=True,ensure_ascii=False) for b in baselines]
 must(len(set(ids))==44,'DUPLICATE_BASELINE')
 must(any(b['local']['version']=='3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat' for b in baselines),'CAFE_R112_NOT_INCLUDED')
 must(any(b['local']['version']==t['targets']['local']['version'] for b in baselines),'HOME_R149B_NOT_INCLUDED')
 return m,web

def deterministic_archive(files):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED) as z:
  for name,value in sorted(files.items()):
   i=zipfile.ZipInfo(name,(2026,11,10,21,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
   z.writestr(i,value)
 return b.getvalue()

def build_qa(source,template,out):
 manifest,web=validate_base(source,template)
 m=json.loads(json.dumps(manifest))
 m['install_plan']=EXPECTED_PLAN
 m['release_set_id']='sokna-r153-universal-local-44base-preflight-contract-qa-20261110'
 m['sequence']=20261110155
 m['status']='R153_UNIVERSAL_LOCAL_44BASE_CONTRACT_QA_NOT_INSTALL'
 m['qa_notes']['install_plan']='NOW_STRUCTURALLY_VALID_BUT_INSTALL_NOT_AUTHORIZED'
 m['qa_notes']['universal_source']='44_EXACT_KNOWN_BASELINES_UNKNOWN_FAIL_CLOSED'
 m['qa_notes']['release_gate']='NO_PRODUCTION_CLAIM_WITHOUT_ACTIVATION_RESTORE_AND_ALL_REQUESTS_QA'
 must(m['for_install'] is False and m['production_pass'] is False and m['signature'] is None,'QA_SAFETY_FLAGS_CHANGED')
 zipbytes=deterministic_archive({'release-set.json':json.dumps(m,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode(),'artifacts/local/web.zip':web})
 p=pathlib.Path(out);p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(zipbytes)
 print('QA_CONTRACT_BUILT',p,'SHA256',sha(zipbytes),'BYTES',len(zipbytes))
 verify(p,expected_web_sha=sha(web))
 return p

def verify(path,expected_web_sha=None):
 data,m,web=load(path)
 must(m['install_plan']==EXPECTED_PLAN,'FINAL_ZIP_INSTALL_PLAN_INVALID')
 must(m['release_type']=='local_only' and len(m['supported_baselines'])==44,'FINAL_ZIP_UNIVERSAL_SCOPE_INVALID')
 must(len(set(json.dumps(b['local'],sort_keys=True) for b in m['supported_baselines']))==44,'FINAL_ZIP_DUPLICATE_BASELINE')
 must(m['requirements']['maintenance_supervisor_min']=='0.3.22','FINAL_ZIP_MINIMUM_DRIFT')
 must(m['pre_update_backup_policy']['required'] is True and m['pre_update_backup_policy']['on_failure']=='reject_pre_mutation','FINAL_ZIP_NO_HOST_BACKUP_GUARD')
 must(m['database']['local']['forward_boundary_phase']=='db_forward_boundary' and m['database']['local']['rollback_after_boundary']=='forbidden','FINAL_ZIP_DB_BOUNDARY_UNSAFE')
 if expected_web_sha:must(sha(web)==expected_web_sha,'FINAL_ZIP_SOURCE_CONTENT_CHANGED')
 if not m['for_install'] or not m['production_pass']:
  must(m['for_install'] is False and m['production_pass'] is False and 'QA' in m['status'],'FINAL_ZIP_MISLABELED_QA')
  approved=False
 else:
  q=m['qualification']
  required=['canary','real_windows','real_mariadb','business_backup_consistency_real_mariadb','recovery_console_web_down']
  must(all(q.get(k) is True for k in required) and q.get('failure_matrix_cases_required',0)>=80 and q.get('failure_matrix_id'),'FINAL_ZIP_PRODUCTION_EVIDENCE_MISSING')
  must(isinstance(m.get('signature'),dict) or os.getenv('SOKNA_RELEASE_ASSERT_OPERATOR_UPLOAD_ORIGIN')=='TRUE','FINAL_ZIP_SIGNING_OR_OPERATOR_AUTH_MISSING')
  approved=True
 print('PASS_FINAL_ZIP_CONTRACT',sha(data),'baselines=44','source_sha='+sha(web),'INSTALLABLE' if approved else 'QA_NOT_INSTALL')
 return approved

def main():
 a=argparse.ArgumentParser()
 sub=a.add_subparsers(dest='command',required=True)
 b=sub.add_parser('prepare-qa');b.add_argument('--source',required=True);b.add_argument('--template',required=True);b.add_argument('--out',required=True)
 v=sub.add_parser('verify');v.add_argument('zip_path')
 args=a.parse_args()
 if args.command=='prepare-qa':build_qa(args.source,args.template,args.out)
 else:verify(args.zip_path)

if __name__=='__main__':main()