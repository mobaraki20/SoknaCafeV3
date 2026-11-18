#!/usr/bin/env python3
"""Elevate exact already-native-proven Local R277 source to controlled operator UAT.
Only release-set metadata changes. All 604 installed payload files remain exact.
Never call this on a machine with production data.
"""
from pathlib import Path
import json,io,zipfile,hashlib,sys,copy
if len(sys.argv)!=2:raise SystemExit('Usage: promote.py CANDIDATE_OUTPUT_DIRECTORY')
out=Path(sys.argv[1])
qa=out/'SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-QA-DO-NOT-INSTALL.zip'
target=out/'SOKNA-R277-LOCAL-231FM-OPERATOR-UAT-20261118.zip'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda o:(json.dumps(o,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
proof=json.loads((out/'R277-QA-PROOF.json').read_text())
assert H(qa.read_bytes())==proof['sha256']
assert proof['target_tree']=='7f11ae1e6f5c88454d39b759cee076e2ce9e4f534f86a06b926e88b553fbdca5'
assert proof['parent_package_sha256']=='f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9'
with zipfile.ZipFile(qa) as z:
 assert z.testzip() is None
 original=json.loads(z.read('release-set.json'))
 web=z.read('artifacts/local/web.zip')
assert original['status']=='R277_LOCAL_WINDOWS_QA_PENDING_HOST_NO_GO'
assert original['qa_notes']['operator_install_allowed'] is False
assert original['targets']['local']['version']=='3.0.0-g6.2-workspace.231fm-r277-local-finance-safety'
assert original['artifacts']['local-web']['sha256']==H(web)
assert any(x['local']['version']=='3.0.0-g6.2-workspace.231fl-r275-owner-discard-public-work' and x['local']['managed_tree_sha256']=='a7407fa1ef4bd3cb17ac68fe6d548330e32a9f3ca47331c47d233b4b6398d9c2' for x in original['supported_baselines'])
m=copy.deepcopy(original)
m['status']='R277_CONTROLLED_OPERATOR_RELEASE_FIELD_ACCEPTANCE_PENDING'
m['qa_notes'].update(
 operator_install_allowed=True,
 additional_install_safety='CONTROLLED_OPERATOR_UAT_ONLY: source R275 231fl, verified backup required, one component at a time; field behavioural acceptance pending',
 r277='Local finance P0 corrections, 82 unchanged migrations, PublicEdge unchanged. Native Windows exact source passed run 44253462149; promoted outer manifest must also pass native Stage/Activate before delivery.',
 r277_native_qa_pass='https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44253462149',
 r277_operator_field_acceptance='PENDING_CAFE_BACKUP_INSTALL_AND_FINANCE_UI_CHECK',
 windows_stage_activate_readback_rollback='NATIVE_QA_PASS; PROMOTED_MANIFEST_REQUIRES_OWN_GATE; no production rollback tested'
)
def archive(values):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for name,data in sorted(values.items()):
   info=zipfile.ZipInfo(name,(2026,11,18,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
   z.writestr(info,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return buf.getvalue()
data=archive({'release-set.json':J(m),'artifacts/local/web.zip':web})
target.write_bytes(data)
with zipfile.ZipFile(target) as z:
 assert z.testzip() is None
 assert z.read('artifacts/local/web.zip')==web
 assert set(k for k in original if original[k]!=m[k])=={'status','qa_notes'}
 assert z.read('release-set.json')==J(m)
promoted={'format':'sokna-r277-operator-uat-promotion-v1','native_qa_source_run':'44253462149','source_qa_zip_sha256':H(qa.read_bytes()),'operator_zip_sha256':H(data),'operator_zip':target.name,'operator_install_allowed':True,'operator_gate':'PENDING_THIS_CI_RUN','changed_outer_manifest_fields':['status','qa_notes'],'install_payload_sha256':H(web),'managed_tree_sha256':m['targets']['local']['managed_tree_sha256'],'real_windows_existing_r275_to_r277':'PASS_ONLY_IF_THIS_CI_SUCCEEDS','real_cafe_install':'PENDING','migrations_added':0,'public_edge_changed':False}
(out/'R277-OPERATOR-UAT-PROMOTION-PROOF.json').write_text(json.dumps(promoted,ensure_ascii=False,indent=2)+'\n')
print('R277_OPERATOR_UAT_MANIFEST_ONLY_PROMOTION_PASS',json.dumps(promoted,ensure_ascii=False))
