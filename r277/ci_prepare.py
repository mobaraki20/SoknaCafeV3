#!/usr/bin/env python3
from pathlib import Path
import io,zipfile,hashlib,os
root=Path(os.environ.get('R277_ROOT','/mnt/data/sokna_r277'))
base=Path(os.environ['R277_INPUT_DIR'])
repo=Path(os.environ.get('GITHUB_WORKSPACE','/mnt/data/sokna_r277'))
files=[p for p in base.rglob('*.zip') if p.name=='SOKNA-R275-LOCAL-231FL-OWNER-DISCARD-QA-DO-NOT-INSTALL.zip']
assert len(files)==1,files
r275=files[0]
assert hashlib.sha256(r275.read_bytes()).hexdigest()=='f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9'
with zipfile.ZipFile(r275) as z:
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
  w.extractall(root/'base');w.extractall(root/'work')
for rel in ['src/Domain/Orders/OrderStaffActionService.php','public/assets/staff-app/hall-workspace.js','public/assets/hall-settlement-v32.js','public/assets/product-ui.css']:
 file=repo/'r277/source'/rel
 assert file.is_file(),file
 target=root/'work'/rel
 target.write_bytes(file.read_bytes())
print('R277_R275_BASE_PINNED_AND_LOCAL_P0_FILES_OVERLAID',r275)
