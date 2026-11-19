#!/usr/bin/env python3
"""Reproduce exact R303 QA candidates from immutable R301/R275 Actions artifacts.
Fail closed if a source SHA or any output byte differs from the Library originals.
"""
import hashlib, io, os, pathlib, subprocess, sys, zipfile
P=pathlib.Path
sha=lambda b:hashlib.sha256(b).hexdigest()
repo=P(__file__).resolve().parents[2]
work=P(os.environ['R303_CI_WORK']).resolve();work.mkdir(parents=True,exist_ok=True)
arts=P(os.environ['R303_CI_ARTIFACTS']).resolve()
sources={
 'local':('399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73','SOKNA-R301-LOCAL-231FS-PUBLIC-MOVE-CONTROLLED-INSTALL.zip'),
 'public':('63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f','SOKNA-R275-PUBLIC-35IJ-OWNER-DISCARD-CODE-ONLY-QA-DO-NOT-INSTALL.zip')}
found={}
for key,(digest,name) in sources.items():
 candidates=[p for p in arts.rglob('*.zip') if sha(p.read_bytes())==digest]
 if len(candidates)!=1:raise RuntimeError(f'R303_{key}_SOURCE_SHA_NOT_UNIQUE_FOUND_{len(candidates)}')
 found[key]=candidates[0]
 print('PINNED_R303_SOURCE',key,name,digest,flush=True)
 with zipfile.ZipFile(candidates[0]) as z:
  if key=='local':
   with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
    payload={p:inner.read(p) for p in inner.namelist() if not p.endswith('/')}
  else:payload={p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/') and not p.endswith('/')}
 for path,data in payload.items():
  p=work/key/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
 patch=repo/'r303/patches'/f'r303-{key}.patch'
 proc=subprocess.run(['patch','--batch','--fuzz=0','-p1','-i',str(patch)],cwd=work/key,text=True,capture_output=True)
 if proc.returncode: raise RuntimeError('R303_PATCH_NOT_EXACT_'+key+' '+proc.stdout+' '+proc.stderr)
 if ('Hunk #' in proc.stdout and 'offset' in proc.stdout): raise RuntimeError('R303_PATCH_OFFSET_'+key)
 print('R303_SOURCE_PATCH_APPLIED',key,len(payload),flush=True)
env={**os.environ,'R303_CI_BASE_LOCAL':str(found['local']),'R303_CI_BASE_PUBLIC':str(found['public'])}
for key in ['local','public']:
 subprocess.run([sys.executable,str(repo/'r303/qa'/f'build_{key}.py')],env=env,check=True)
for name,digest in [('SOKNA-R303-LOCAL-231FT-QA-NOT-INSTALL.zip','0adb90b62e488f21ed3443608a5a2691445351666c4fecb181e3d8a28839d8aa'),('SOKNA-R303-PUBLIC-35IL-EMERGENCY-UI-QA-NOT-INSTALL.zip','a5c5743e1804dbd10282b399916ae0cd8399aa71dbf79bdf62a4deeef1291afe')]:
 file=work/'out'/name;actual=sha(file.read_bytes())
 if actual!=digest:raise RuntimeError('R303_REPRODUCIBILITY_MISMATCH '+name+' '+actual)
 print('PASS_R303_BYTE_IDENTICAL',name,actual,flush=True)
