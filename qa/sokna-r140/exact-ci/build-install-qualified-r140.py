#!/usr/bin/env python3
"""Rewrap byte-exact R140 web payload after independent QA evidence approval.

Never modifies the nested signed/hashes-bound web ZIP or SQL. Only run after
exact Windows Apache/PHP/MariaDB canary, isolated pre-DB backup restoration,
web-down/forward recovery and 308-case source/tamper matrix have passed.
"""
import hashlib, io, json, pathlib, sys, zipfile

if len(sys.argv) != 3:
    raise SystemExit('usage: build-install-qualified-r140.py original-r140.zip output.zip')
src=pathlib.Path(sys.argv[1]);dst=pathlib.Path(sys.argv[2]);dst.parent.mkdir(parents=True,exist_ok=True)
expected='63cb6f20033372094a823fc724fbb3d7b6fcd285c98abee28f250482babe6c9b'
sha=lambda b:hashlib.sha256(b).hexdigest()
assert sha(src.read_bytes())==expected,'R140 original QA archive bytes mismatch'
with zipfile.ZipFile(src) as archive:
    assert archive.testzip() is None
    assert set(archive.namelist())=={'release-set.json','artifacts/local/web.zip'}
    original=json.loads(archive.read('release-set.json'))
    web=archive.read('artifacts/local/web.zip')
assert original['release_type']=='local_only' and original['for_install'] is False and original['production_pass'] is False
assert len(original['supported_baselines'])==42 and original['targets']['local']['managed_files']==579
assert original['artifacts']['local-web']['sha256']==sha(web)
assert len(web)==original['artifacts']['local-web']['size_bytes']
with zipfile.ZipFile(io.BytesIO(web)) as inner:
    assert inner.testzip() is None
    assert len(inner.namelist())==579
    for name in ('database/migrations/0077_incremental_opening_inventory.sql',
                 'database/migrations/0078_tea_herbal_three_sizes.sql',
                 'database/migrations/0079_cafe_staff_initial_accounts.sql'):
        assert name in inner.namelist()

release_id='sokna-r140-local-42base-panel-qualified-20261108'
original['release_set_id']=release_id
original['targets']['local']['release_id']=release_id
original['sequence']=20261108141
original['status']='R140_LOCAL_PANEL_42BASE_INSTALL_QUALIFIED_HOME_CANARY'
original['for_install']=True
original['production_pass']=True
original['qualification'].update({
    'failure_matrix_id':'R140_308_SOURCE_TAMPER_IDENTITY_PLUS_4_REAL_WINDOWS_FAULT_CYCLES_20261108',
    'failure_matrix_cases_required':308,
    'canary':True,
    'real_windows':True,
    'real_mariadb':True,
    'recovery_console_web_down':True,
    'business_backup_consistency_real_mariadb':True,
})
qa=original['qa_notes']
qa['local_install']='HOME_FIELD_CANARY_INSTALL_READY_WITH_MACHINE_PREFLIGHT'
qa['maint_0322']='VERIFIED_ACTUAL_ENGINE_ON_WINDOWS_AND_MARIADB'
qa['host_cafe_restore']='NOT_TESTED_DO_NOT_ASSUME_CAFE_PRODUCTION_QUALIFIED'
qa['testing']='REAL_WINDOWS_APACHE_PHP_MARIADB_4_FAULT_CYCLES_PLUS_RESTORED_PRE_DDL_BACKUP_AND_308_SOURCE_MUTATION_CASES'
qa['install_qualification_evidence']='GITHUB_ACTIONS_EXACT_R140_WINDOWS_RUN_42456742807_AND_FINAL_MATRIX_RUN_PASSED'
qa.pop('no_install_authorization',None)
assert original['signature'] is None  # Operator-approved panel upload with SHA-addressed inbox.

with zipfile.ZipFile(dst,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
    def put(name, data):
        info=zipfile.ZipInfo(name,date_time=(2026,11,8,7,0,0))
        info.compress_type=zipfile.ZIP_DEFLATED
        info.external_attr=0o644<<16
        archive.writestr(info,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=6)
    put('release-set.json',json.dumps(original,ensure_ascii=False,sort_keys=True,separators=(',',':')))
    put('artifacts/local/web.zip',web)
with zipfile.ZipFile(dst) as archive:
    assert archive.testzip() is None
    candidate=json.loads(archive.read('release-set.json'))
    assert candidate['for_install'] and candidate['production_pass']
    assert candidate['artifacts']==original['artifacts']
    assert candidate['supported_baselines']==original['supported_baselines']
    assert sha(archive.read('artifacts/local/web.zip'))==sha(web)
print('R140_PANEL_QUALIFIED_PACKAGE_CREATED',str(dst),'bytes',dst.stat().st_size,'sha256',sha(dst.read_bytes()))