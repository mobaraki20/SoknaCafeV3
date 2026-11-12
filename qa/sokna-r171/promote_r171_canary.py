#!/usr/bin/env python3
"""Promote an immutable, byte-verified R171 UAT source candidate to a *canary install* candidate.
Safety gate remains unchanged. Source web.zip and Public bridge payloads are not edited.
Promotion is only to be called after separate R171 MariaDB and source gates pass.
Never claims real host installation/production business acceptance.
"""
from pathlib import Path
import argparse,hashlib,json,zipfile,io,datetime
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda o:(json.dumps(o,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode('utf-8')
UAT_HASH='eaa7284c07096bcb8c869007b64586c869ece7eadf3f8322b1cc65e958f30179'
CANARY_ID='sokna-r171-cafe-canary-qualified-gates-20261112'
CANARY_NAME='SOKNA-R171-CANARY-LOCAL-231EO-52BASE-PANEL-UPDATE.zip'
ALL_NAME='SOKNA-R171-CANARY-LOCAL-PUBLIC-ALL.zip'
def zip_bytes(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for n,v in sorted(d.items()):
   info=zipfile.ZipInfo(n,(2026,11,12,13,45,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,v)
 return b.getvalue()
p=argparse.ArgumentParser();p.add_argument('--source-dir',required=True);p.add_argument('--output-dir',required=True);p.add_argument('--ci-run',required=True);a=p.parse_args()
src=Path(a.source_dir);dst=Path(a.output_dir);dst.mkdir(parents=True,exist_ok=True)
uat=src/'SOKNA-R171-LOCAL-231EO-52BASE-PANEL-UPDATE.zip'
assert H(uat.read_bytes())==UAT_HASH,'R171_UAT_PARENT_BYTE_MISMATCH'
with zipfile.ZipFile(uat) as z:
 assert z.testzip() is None and set(z.namelist())=={'artifacts/local/web.zip','release-set.json'}
 web=z.read('artifacts/local/web.zip');old=json.loads(z.read('release-set.json'))
assert old['status']=='R171_CUMULATIVE_INSTALLABLE_UAT_PENDING_FIELD'
assert old['release_set_id']=='sokna-r171-cumulative-staff-inventory-expenses-20261112'
assert old['artifacts']['local-web']['sha256']==H(web)
assert len(old['supported_baselines'])==52
assert old['qa_notes']['r171_mariadb_qualified']=='PENDING_CI'
# Record only proven pre-promotion gates; Windows Stage is still pending on THIS exact canary ZIP.
old['release_set_id']=CANARY_ID
old['sequence']=20261112711
old['status']='R171_CUMULATIVE_INSTALLABLE_CANARY_PRE_RELEASE'
old['qa_notes'].update({
 'r171_mariadb_qualified':'PASS_GITHUB_ACTIONS_'+a.ci_run,
 'r171_windows_qualified':'PENDING_EXACT_CANARY_STAGE',
 'canary_install_qualification':'READONLY_IDENTITY_AND_DISPOSABLE_DB_PASSED_PENDING_NATIVE_STAGE',
 'actual_cafe_business_restore_proven':False,
 'field_install_pending':True,
 'real_cafe_or_home_install_verified':False,
})
newbytes=zip_bytes({'artifacts/local/web.zip':web,'release-set.json':J(old)})
with zipfile.ZipFile(io.BytesIO(newbytes)) as z:
 assert z.read('artifacts/local/web.zip')==web
 assert json.loads(z.read('release-set.json'))['status']=='R171_CUMULATIVE_INSTALLABLE_CANARY_PRE_RELEASE'
(dst/CANARY_NAME).write_bytes(newbytes)
bridges={x.name:x.read_bytes() for x in sorted(src.glob('SOKNA-R171-PUBLIC-35HT-FROM-*.zip'))}
assert len(bridges)==7
report={'release_id':CANARY_ID,'classification':'INSTALLABLE_CANARY_CANDIDATE_NOT_FIELD_VERIFIED','mariadb_qa':'PASS_IN_DISPOSABLE_CI','windows_exact_stage':'PENDING','cafe_install':'NOT_TESTED','local_name':CANARY_NAME,'local_sha256':H(newbytes),'local_web_sha256':H(web),'reused_unchanged_public_bridges':{k:H(v) for k,v in bridges.items()},'source_parent_uat_sha256':UAT_HASH,'ci_run_id':a.ci_run,'field_install_pending':True}
(dst/'R171-CANARY-PACKAGE-IDENTITY.json').write_bytes(J(report))
(dst/ALL_NAME).write_bytes(zip_bytes({CANARY_NAME:newbytes,'R171-CANARY-PACKAGE-IDENTITY.json':J(report),**bridges}))
print(json.dumps(report,ensure_ascii=False,indent=2))
