#!/usr/bin/env python3
import json, subprocess, tempfile, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as d:
    out=Path(d)/'public.zip'
    cp=subprocess.run(['python3',str(ROOT/'apps/public/tools/build-deploy-package.py'),'--source',str(ROOT/'apps/public'),'--version','3.0.0-g31','--source-commit','g31','--out',str(out)],text=True,capture_output=True)
    if cp.returncode: raise SystemExit(cp.stderr or cp.stdout)
    with zipfile.ZipFile(out) as z:
        names=set(z.namelist())
        if 'manifest.json' not in names or 'public/index.php' not in names or 'config.example.php' not in names: raise SystemExit('deploy archive incomplete')
        if 'config.php' in names or any(n.startswith('storage/') for n in names): raise SystemExit('deploy archive leaked runtime config/storage')
        m=json.loads(z.read('manifest.json'))
        if m.get('format')!='sokna-public-deploy-v1' or m.get('document_root')!='public' or m.get('version')!='3.0.0-g31': raise SystemExit('deploy manifest drifted')
        listed={x['path']:x for x in m.get('files',[])}
        if 'public/index.php' not in listed: raise SystemExit('manifest missing front controller')
print('G3.1 Public deploy package self-test: OK')
