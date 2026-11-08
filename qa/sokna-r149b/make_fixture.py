#!/usr/bin/env python3
"""Create exact QA fixture for isolated Windows full activation. Not a distributable release."""
import json,hashlib,pathlib,shutil,sys,zipfile
lab=pathlib.Path(sys.argv[1]).resolve();out=lab/'artifacts';out.mkdir(exist_ok=True,parents=True)
with zipfile.ZipFile(lab/'qualified-r140.zip') as z:old=json.loads(z.read('release-set.json'))['targets']['local']
with zipfile.ZipFile(lab/'r149b-qualified.zip') as z:
    manifest=json.loads(z.read('release-set.json'))
    web=z.read('artifacts/local/web.zip')
    assert hashlib.sha256(web).hexdigest()==manifest['artifacts']['local-web']['sha256']
    assert manifest['for_install'] and manifest['production_pass']
(out/'baseline.json').write_text(json.dumps(old,sort_keys=True),encoding='utf-8')
(out/'target.json').write_text(json.dumps(manifest['targets']['local'],sort_keys=True),encoding='utf-8')
shutil.copyfile(lab/'r149b-qualified.zip',out/'temporary-ci-qualified.zip')
assert len(manifest['supported_baselines'])==43
print('R149B_EXACT_SHA_SCOPED_WINDOWS_FIXTURE_ONLY',hashlib.sha256((out/'temporary-ci-qualified.zip').read_bytes()).hexdigest())