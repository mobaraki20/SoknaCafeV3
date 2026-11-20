#!/usr/bin/env python3
"""Recreate exact R306 artifacts from immutable R304 packages and versioned patches."""
import os,pathlib,zipfile,io,hashlib,subprocess,shutil,sys
P=pathlib.Path
r=P(__file__).resolve().parents[2]
work=P(os.environ['R306_WORK']);work.mkdir(parents=True,exist_ok=True)
lbase=P(os.environ['R306_LOCAL_BASE']);pbase=P(os.environ['R306_PUBLIC_BASE'])
assert hashlib.sha256(lbase.read_bytes()).hexdigest()=='6dccf704f130610821e2727858bbcdd2531780ede5781c896153fb480201f1e1'
assert hashlib.sha256(pbase.read_bytes()).hexdigest()=='d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c'
for typ,archive in [('local',lbase),('public',pbase)]:
 dest=work/typ;dest.mkdir(exist_ok=True)
 if list(dest.iterdir()):raise RuntimeError('R306_EXACT_REBUILD_TARGET_NOT_EMPTY '+str(dest))
 with zipfile.ZipFile(archive) as z:
  if typ=='local':
   with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
    for n in w.namelist():
     if n.endswith('/'):continue
     p=dest/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(w.read(n))
  else:
   for n in z.namelist():
    if n.startswith('payload/') and not n.endswith('/'):
     p=dest/n[8:];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
for component,rel in [('local','r305/patches/local-staff-order-note-flush.patch'),('local','r305b/patches/local-catalog-none-explanation.patch'),('public','r305/patches/public-preparation-remove-duplicate-summary.patch'),('public','r306/patches/public-shared-refresh-parity.patch')]:
 patch=r/rel
 if not patch.is_file():raise RuntimeError('PATCH_MISSING '+str(patch))
 proc=subprocess.run(['patch','--batch','--forward','-p1'],cwd=work/component,input=patch.read_bytes(),stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
 if proc.returncode:raise RuntimeError('R306_PATCH_FAILED '+rel+' '+proc.stdout.decode(errors='replace'))
 print('R306_PATCH_APPLIED',rel,proc.stdout.decode(errors='replace').strip().split('\n')[-1],flush=True)
env=dict(os.environ)
subprocess.run([sys.executable,str(r/'r306/qa/build_r306.py')],env=env,check=True)
proof=work/'artifacts/R306-EXACT-CANDIDATE-VERIFY.json'
import json
checks=json.loads(proof.read_text())
assert checks['local']['sha256']=='1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209'
assert checks['public']['sha256']=='e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51'
print('R306_REBUILT_BYTE_IDENTICAL_LOCAL_AND_PUBLIC')
