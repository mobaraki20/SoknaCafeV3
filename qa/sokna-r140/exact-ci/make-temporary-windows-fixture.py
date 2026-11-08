#!/usr/bin/env python3
"""CI only: build disposable qualified release-set envelope; NEVER DISTRIBUTE."""
import json,sys,zipfile,hashlib
from pathlib import Path
lab=Path(sys.argv[1]); out=lab/'artifacts'
out.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(lab/'r75.zip') as archive:
    src=json.loads(archive.read('release-set.json'))['targets']['local']
with zipfile.ZipFile(lab/'r140.zip') as archive:
    manifest=json.loads(archive.read('release-set.json'))
    web=archive.read('artifacts/local/web.zip')
(out/'baseline.json').write_text(json.dumps(src,sort_keys=True),encoding='utf-8')
(out/'target.json').write_text(json.dumps(manifest['targets']['local'],sort_keys=True),encoding='utf-8')
assert manifest['for_install'] is False and manifest['production_pass'] is False
assert manifest['release_type']=='local_only'
manifest['for_install']=True
manifest['production_pass']=True
manifest['status']='R140_DISPOSABLE_WINDOWS_CI_FIXTURE_NOT_FOR_DISTRIBUTION'
q=manifest['qualification']
q.update(failure_matrix_id='CI_FAKE_QUALIFICATION_DO_NOT_DISTRIBUTE',
         failure_matrix_cases_required=80,canary=True,real_windows=True,real_mariadb=True,
         recovery_console_web_down=True,business_backup_consistency_real_mariadb=True)
archive=out/'temporary-ci-qualified.zip'
with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED) as z:
    z.writestr('release-set.json',json.dumps(manifest,ensure_ascii=False,separators=(',',':'),sort_keys=True))
    z.writestr('artifacts/local/web.zip',web)
print('SOKNA_R140_CI_ONLY_SYNTHETIC_RELEASE_ENVELOPE',hashlib.sha256(archive.read_bytes()).hexdigest())
print('WARNING_THIS_CI_FIXTURE_IS_NOT_EVIDENCE_OF_PRODUCTION_QUALIFICATION')