from pathlib import Path
import os,hashlib,io,zipfile
root=Path(os.environ['R264_ROOT']);base=Path(os.environ['R264_BASE_DIR'])
local=base/'SOKNA-R262-LOCAL-231FG-CUMULATIVE-PUBLIC-MOVE-NATIVE-UAT-UNQUALIFIED.zip'
public=base/'SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip'
root.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(local) as z:
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:w.extractall(root/'local')
with zipfile.ZipFile(public) as z:
 for src,dest in [('assets/staff-app/hall-workspace.js','public-hall.js'),('assets/product-ui.css','public-product-ui.css')]:
  (root/dest).write_bytes(z.read('payload/'+src))
print('R264_QA_PRISTINE_PARENT_SOURCES_EXTRACTED')