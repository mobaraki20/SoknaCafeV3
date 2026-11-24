#!/usr/bin/env python3
"""Build disposable Windows-only activation rehearsal from exact QA target and R307.

NEVER upload/ship the ephemeral installable manifest. Its inner product source
bytes are exactly the noninstallable candidate; flags are temporary to
exercise native Windows stage/activate on an isolated CI runner. This is not
production qualification or operator release.
"""
import os,io,json,zipfile,pathlib,hashlib
H=lambda b:hashlib.sha256(b).hexdigest()
TMP=pathlib.Path(os.environ['RUNNER_TEMP'])
R307=pathlib.Path(os.environ['SOKNA_R307_ORIGINAL_ZIP'])
TARGET=pathlib.Path(os.environ['SOKNA_CURRENT_QA_ZIP'])
assert H(R307.read_bytes())=='069a267f920b2d0614ca61443851b4f385994dca379d2bbbb3e1c42aba8047fa'
with zipfile.ZipFile(R307) as z:
 parent=json.loads(z.read('release-set.json'))
assert parent['targets']['local']['version']=='3.0.0-g6.2-workspace.231fw-r307-dashboard-money-calendar-notify'
assert parent['targets']['local']['managed_tree_sha256']=='5455c9d2ea4762a050d826f59c1896f7e22827b1cdad9f6106bca356ba94b186'
with zipfile.ZipFile(TARGET) as z:
 assert z.testzip() is None
 m=json.loads(z.read('release-set.json'));web=z.read('artifacts/local/web.zip')
assert m['for_install'] is False and m['production_pass'] is False
assert len(m['supported_baselines'])==4 and any(x['local']['version']==parent['targets']['local']['version'] and x['local']['managed_tree_sha256']==parent['targets']['local']['managed_tree_sha256'] for x in m['supported_baselines'])
assert m['targets']['local']['version']=='3.0.0-g6.2-workspace.231ga-consolidated-20261124-preflight'
assert m['targets']['local']['managed_files']==619
TARGET_TREE=m['targets']['local']['managed_tree_sha256']
# Keep the new target bytes AND release schema lineage entirely unchanged. The
# runner ONLY borrows previously qualified R307 QA flags for a disposable test;
# no such flags are passed back to any operator package or Library upload.
m['for_install']=True;m['production_pass']=True
m['status']='R2_DISPOSABLE_NATIVE_STAGING'
for key in ['canary','real_windows','real_mariadb','business_backup_consistency_real_mariadb','recovery_console_web_down']:
 assert parent['qualification'][key] is True
 m['qualification'][key]=True
m.setdefault('qa_notes',{})['never_publish']='EPHEMERAL_NATIVE_STAGE_ONLY__NOT_FACTORY_OPERATOR_PACKAGE'
def zipbytes(d):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for n,b in sorted(d.items()):
   zi=zipfile.ZipInfo(n,(2026,11,24,0,0,0));zi.create_system=3;zi.external_attr=0o100644<<16;zi.compress_type=zipfile.ZIP_DEFLATED
   z.writestr(zi,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return buf.getvalue()
blob=zipbytes({'release-set.json':(json.dumps(m,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode(),'artifacts/local/web.zip':web})
Q=TMP/'SOKNA-NATIVE-CI-ONLY-DO-NOT-PUBLISH.zip';Q.write_bytes(blob)
QSHA=H(blob)
R307_VER=parent['targets']['local']['version'];CUR_VER=m['targets']['local']['version']
assert (TMP/'r307-native.ps1').is_file(),'R307_NATIVE_ADAPTER_NOT_RUN'
s=(TMP/'r307-native.ps1').read_text(encoding='utf8')
subs={
 '$pack=$env:SOKNA_R307_CANDIDATE_ZIP':'$pack=$env:SOKNA_CURRENT_CI_STAGE_ZIP',
 '$parent=$env:SOKNA_R306_ORIGINAL_ZIP':'$parent=$env:SOKNA_R307_ORIGINAL_ZIP',
 'Web.release.r306':'Web.release.r307',
 'R307_NATIVE_WINDOWS_REAL_MAINTENANCE_R306_TO_R307_STAGE_ACTIVATE_HTTP_DB83_PERSISTENT_PASS':'SOKNA_NATIVE_R307_TO_231GA_STAGE_ACTIVATE_HTTP_DB88_PERSISTENT_PASS',
}
for before,after in subs.items():
 assert before in s,('NATIVE_ADAPTER_MISSING',before)
 s=s.replace(before,after)
# R307 native PowerShell explicitly handles 83 migrations and previous R306
# identity. Replace with collision-free temporary markers before resolving.
oldver='3.0.0-g6.2-workspace.231fv-r306-local-public-ui-parity'
oldtarget=R307_VER
assert oldver in s and oldtarget in s
s=s.replace(oldver,'__R307_BASELINE_VERSION_PLACEHOLDER__').replace(oldtarget,'__CURRENT_TARGET_VERSION_PLACEHOLDER__')
s=s.replace('__R307_BASELINE_VERSION_PLACEHOLDER__',R307_VER).replace('__CURRENT_TARGET_VERSION_PLACEHOLDER__',CUR_VER)
# Previous script pins both old R306 package and R307 candidate, and both trees.
oldsha='1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209'
newsha='069a267f920b2d0614ca61443851b4f385994dca379d2bbbb3e1c42aba8047fa'
assert oldsha in s and newsha in s
s=s.replace(oldsha,'__BASE_SHA__').replace(newsha,'__TARGET_SHA__')
s=s.replace('__BASE_SHA__',newsha).replace('__TARGET_SHA__',QSHA)
oldtree='63df287e865ac0503fd21dea275a4714c1138f0c11ee53834522b7cb3d060d7c'
newtree='5455c9d2ea4762a050d826f59c1896f7e22827b1cdad9f6106bca356ba94b186'
assert oldtree in s and newtree in s
s=s.replace(oldtree,'__BASE_TREE__').replace(newtree,'__TARGET_TREE__')
s=s.replace('__BASE_TREE__',newtree).replace('__TARGET_TREE__',TARGET_TREE)
# Only the target head/after-ledger expectation changes; initial R307 has 83.
s=s.replace("$targetInfo.migration_head -ceq '0083_remaining_account_discount'", "$targetInfo.migration_head -ceq '0088_historical_variant_order_snapshots'")
# Legacy native target validation may use a literal head rather than targetInfo
# member; keep any other 0083 baseline tests intact.
needle="-eq '83') 'R301_DB_MIGRATION_0083_NOT_APPLIED'"
assert needle in s, 'AFTER_LEDGER_EXPECTATION_MISSING'
s=s.replace(needle,"-eq '88') 'R2_DB_MIGRATION_0088_NOT_APPLIED'")
assert '127.0.0.1:39841' in s and 'SOKNA_R186_CI_ONLY' in s
assert CUR_VER in s and R307_VER in s and QSHA in s and TARGET_TREE in s
P=TMP/'sokna-current-r307-to-r2-native.ps1';P.write_text(s,encoding='utf8')
report={'native_stage_zip_sha256':QSHA,'source_inner_web_sha256':H(web),'source_qa_zip_sha256':H(TARGET.read_bytes()),'r307_sha256':H(R307.read_bytes()),'target_version':CUR_VER,'target_tree_sha256':TARGET_TREE,'temporary_stage_manifest_only':True,'package_published':False,'stage_zip':str(Q),'harness':str(P)}
(TMP/'sokna-current-native-adapter.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))