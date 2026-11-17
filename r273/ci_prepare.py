#!/usr/bin/env python3
"""Materialize exactly two pinned prior release payloads and source diffs.
No customer credentials, no host mutation. Fail closed on ANY byte divergence.
"""
from pathlib import Path
import os,zipfile,io,hashlib,subprocess,json,shutil
root=Path(os.environ['R273_SOURCE_ROOT']);archive=Path(os.environ['R273_ARCHIVE_ROOT']);dest=Path(os.environ['R273_EXTRACT_ROOT']);dest.mkdir(parents=True,exist_ok=True)
H=lambda b:hashlib.sha256(b).hexdigest()
expected={
 'local':'3d12ea375a307e58c8e36b2036a4fea9dec9ef05330f4a046234102c32164b8c',
 'public':'b6d4d2236ab3da126a4070d75802a9cac6719c139a188808d1712b8c1827bd35'}
candidates=list(archive.rglob('*.zip'));found={}
for p in candidates:
 h=H(p.read_bytes())
 for k,v in expected.items():
  if h==v:found[k]=p
if set(found)!={'local','public'}:raise SystemExit('PINNED_BASELINES_NOT_FOUND '+str({k:str(v) for k,v in found.items()}))
with zipfile.ZipFile(found['local']) as z:
 content=z.read('artifacts/local/web.zip')
with zipfile.ZipFile(io.BytesIO(content)) as z:z.extractall(dest/'local')
with zipfile.ZipFile(found['public']) as z:
 for n in z.namelist():
  if n.startswith('payload/') and not n.endswith('/'):
   t=dest/'public'/n[len('payload/'):];t.parent.mkdir(parents=True,exist_ok=True);t.write_bytes(z.read(n))
for group in ['local','public']:
 for p in sorted(x for x in (root/'patches').glob(group+'-*.patch') if '__' in x.name):
  process=subprocess.run(['patch','-p1','--fuzz=0','--batch','-i',str(p.resolve())],cwd=dest/group,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
  if process.returncode:raise SystemExit('PATCH_FAIL '+p.name+' '+process.stdout)
 count=len(list((dest/group).rglob('*')))
 print('PATCHED_EXACT_'+group.upper()+'_SOURCE '+str(count))
# Cryptographically require all patched payloads to agree with the native authored source,
# not an optionally trimmed tool re-extraction.
checks=json.load(open(root/'checksums.json'))
for group,mapping in checks.items():
 for name,digest in mapping.items():
  got=H((dest/group/name).read_bytes())
  if got!=digest:raise SystemExit('PATCH_OUTPUT_SHA_MISMATCH '+group+'/'+name+' expected '+digest+' got '+got)
print('R273_EXACT_BYTES_RECONSTRUCTED_FROM_IMMUTABLE_ARCHIVES')
(dest/'baselines.json').write_text(json.dumps({k:str(v) for k,v in found.items()}))
