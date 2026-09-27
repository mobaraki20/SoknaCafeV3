#!/usr/bin/env python3
import json,subprocess,tempfile,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
out=Path(tempfile.gettempdir())/'sokna-g33-update-test.zip'
subprocess.run(['python3',str(ROOT/'apps/public/tools/build-update-package.py'),'--source',str(ROOT/'apps/public'),'--version','3.0.0-g3.3-test','--source-commit','g33','--out',str(out)],check=True,capture_output=True,text=True)
with zipfile.ZipFile(out) as z:
    names=set(z.namelist());m=json.loads(z.read('manifest.json'))
    assert m['format']=='sokna-component-package-v1' and m['component']=='public-edge'
    assert m['contracts']['local_public_contract']==1
    assert 'payload/public/emergency.php' not in names
    assert not any(n.startswith('payload/src/Emergency/') for n in names)
    assert 'payload/config.php' not in names and not any('/storage/' in n for n in names)
    assert 'payload/public/index.php' in names and 'payload/bootstrap.php' in names
print('G3.3 Public update package self-test: OK')
