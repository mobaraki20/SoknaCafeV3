#!/usr/bin/env python3
"""Create exact R153 QA fixture for isolated Windows full activation; never customer-installable. Not a distributable release."""
import json,hashlib,pathlib,shutil,sys,zipfile
lab=pathlib.Path(sys.argv[1]).resolve();out=lab/'artifacts';out.mkdir(exist_ok=True,parents=True)
source=sys.argv[2] if len(sys.argv)>2 else 'r140'
assert source in ('r75','r140'),'Unknown source fixture'
with zipfile.ZipFile(lab/('r75.zip' if source=='r75' else 'qualified-r140.zip')) as z:
    old=json.loads(z.read('release-set.json'))['targets']['local']
with zipfile.ZipFile(lab/'r153-synthetic-qualified.zip') as z:
    manifest=json.loads(z.read('release-set.json'))
    web=z.read('artifacts/local/web.zip')
    assert hashlib.sha256(web).hexdigest()==manifest['artifacts']['local-web']['sha256']
    assert manifest['for_install'] and manifest['production_pass'] and 'SYNTHETIC' in manifest['status']
identity_keys=('release_id','version','managed_tree_sha256','schema_fingerprint','migration_head')
assert any(all((b.get('local') or {}).get(k)==old[k] for k in identity_keys)
           for b in manifest['supported_baselines']),'Archived source is not an EXACT supported baseline'
(out/'baseline.json').write_text(json.dumps(old,sort_keys=True),encoding='utf-8')
(out/'target.json').write_text(json.dumps(manifest['targets']['local'],sort_keys=True),encoding='utf-8')
destination=out/'temporary-ci-qualified.zip'
if destination.exists():
    assert destination.read_bytes()==(lab/'r153-synthetic-qualified.zip').read_bytes(),'Target ZIP changed between sources'
else:
    shutil.copyfile(lab/'r153-synthetic-qualified.zip',destination)
assert len(manifest['supported_baselines'])==44
print('R153_EXACT_SHA_SCOPED_WINDOWS_FIXTURE_ONLY',hashlib.sha256((out/'temporary-ci-qualified.zip').read_bytes()).hexdigest())
