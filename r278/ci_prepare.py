#!/usr/bin/env python3
"""CI-only materialize pinned R277 from repository-owned byte parts and build R278.

Not a production host installer. No network, no data mutation outside CI directory.
"""
from pathlib import Path
import os,zipfile,io,hashlib,shutil,subprocess
repo=Path(os.environ.get('GITHUB_WORKSPACE',Path.cwd()))
r277_root=Path(os.environ.get('R277_NATIVE_ROOT','/tmp/sokna-r277-native'))
r278_root=Path(os.environ.get('R278_ROOT','/tmp/sokna-r278-native'))
r278_root.mkdir(parents=True,exist_ok=True)
H=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
assert (repo/'r277/ci-binary/part-001.b64').is_file(), 'MISSING_PINNED_PARENT'
subprocess.run(['python',str(repo/'r277/ci_materialize_native.py')],check=True)
subprocess.run(['python',str(repo/'r277/ci_promote_release.py')],check=True)
parent=r277_root/'SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-INSTALL-READY.zip'
assert H(parent)=='8b01a0e50513999376b07583a9d39ccc8352e4798821e42e2b3c5e41beb1d83a'
with zipfile.ZipFile(parent) as outer:
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  assert len(inner.namelist())==604
  inner.extractall(r278_root/'source')
for key,pin in {
  'src/Domain/Admin/AdminControlService.php':'4f26a5a836691221471ad80b84bccd2a05fe85a268dfb1d69f0670c62bc50a0e',
  'public/assets/admin-workspace.js':'d6a6b5ddbd79d893c8d372045b8dafa2fe2271534e4fb10282bb291cf9df0f80',
}.items():
 src=repo/'r278/source'/key
 assert src.is_file(),key
 b=src.read_bytes().replace(b'\r\n',b'\n')
 assert hashlib.sha256(b).hexdigest()==pin,'SOURCE_SHA_MISMATCH '+key
 target=r278_root/'source'/key
 assert target.is_file(),key
 target.write_bytes(b)
# The Public QA fixtures are the exact unchanged R276 protected transport/domain
# source. They are NOT included in, or installed by, the new Local package.
for key,pin in {
  'Core/CanonicalJson.php':'50b19783863d060d920ec1746b24dea39d0d0a98d1dbf06cc30e010e9a748555',
  'Connectivity/ConnectivityService.php':'a18af7dfcfa526b6103a15eabfd2d240ba534839520cb614452a0dae8ecf9138',
  'Remote/RemoteReadModelService.php':'e5b1a3273de227eea82373c9e279cafa7beadb38ceb7f66f7f76ac4d42649e8d',
}.items():
 src=repo/'r278/qa-public/src'/key
 b=src.read_bytes().replace(b'\r\n',b'\n')
 assert hashlib.sha256(b).hexdigest()==pin,'PUBLIC_QA_SHA_MISMATCH '+key
 target=r278_root/'public/src'/key;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(b)
(root_test:=r278_root/'tests').mkdir(parents=True,exist_ok=True)
shutil.copy2(repo/'r278/tests/rial_local_to_public_mariadb.php',root_test/'rial_local_to_public_mariadb.php')
subprocess.run(['python',str(repo/'r278/build_r278.py')],check=True,env={**os.environ,'R278_ROOT':str(r278_root),'R278_SOURCE_DIR':str(r278_root/'source'),'R277_INSTALL_PACKAGE':str(parent),'R278_OUTPUT_DIR':str(r278_root/'output')})
print('R278_FROM_EXACT_PINNED_R277_AND_REAL_PUBLIC_R276_CI_MATERIALIZATION_PASS')
