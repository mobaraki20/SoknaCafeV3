#!/usr/bin/env python3
from pathlib import Path
import subprocess, shutil

ROOT=Path(__file__).resolve().parents[1]
tool=ROOT/'apps/public/tools/deploy-bootstrap.php'
if not tool.is_file():
    raise SystemExit('Public deploy bootstrap CLI missing')
src=tool.read_text(encoding='utf-8')
for token in ('sokna_public_bootstrap','migrations()->migrate()','health()->status','storage directory','SELECT 1'):
    if token not in src:
        raise SystemExit(f'Public deploy bootstrap invariant missing: {token}')
if 'config.php' not in src or '--config=' not in src:
    raise SystemExit('Public deploy bootstrap config selection missing')
php=shutil.which('php')
if php is None:
    raise SystemExit('PHP CLI is required')
subprocess.run([php,'-l',str(tool)],cwd=ROOT,check=True)
print('Public deploy bootstrap contract: OK')
