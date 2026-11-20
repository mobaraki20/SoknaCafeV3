#!/usr/bin/env python3
"""Byte-reproducible R307 from exact R306 ZIP and versioned five-file patch."""
import os,pathlib,zipfile,hashlib,io,subprocess,sys,json
P=pathlib.Path
r=P(__file__).resolve().parents[2]
work=P(os.environ['R307_WORK']);work.mkdir(parents=True,exist_ok=True)
base=P(os.environ['R307_LOCAL_BASE'])
assert hashlib.sha256(base.read_bytes()).hexdigest()=='1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209'
src=work/'local';src.mkdir(exist_ok=True)
assert not any(src.iterdir()),'R307_REBUILD_TARGET_MUST_BE_EMPTY'
with zipfile.ZipFile(base) as z:
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
  for n in inner.namelist():
   if n.endswith('/'):continue
   dest=src/n;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(inner.read(n))
patch=r/'r307/patches/r307-dashboard-ui-from-r306.patch'
proc=subprocess.run(['patch','--batch','--forward','-p1'],cwd=src,input=patch.read_bytes(),stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
if proc.returncode:raise RuntimeError(proc.stdout.decode(errors='replace'))
subprocess.run([sys.executable,str(r/'r307/qa/build_r307.py')],env=dict(os.environ),check=True)
proof=json.loads((work/'artifacts/R307-RELEASE-PROOF.json').read_text())
assert proof['target_zip_sha256']=='069a267f920b2d0614ca61443851b4f385994dca379d2bbbb3e1c42aba8047fa'
print('PASS_R307_BYTE_IDENTICAL_REBUILD_231FV_SINGLE_BASELINE')
