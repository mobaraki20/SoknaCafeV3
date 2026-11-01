import hashlib, io, json, pathlib, shutil, zipfile
ROOT=pathlib.Path(__file__).resolve().parent
OUT=ROOT/'build'
def digest(d):
 h=hashlib.sha256()
 for p,b in sorted(d.items()):h.update(p.encode()+b'\0'+hashlib.sha256(b).hexdigest().encode()+b'\n')
 return h.hexdigest()
def archive(kind):
 p=ROOT/f'baseline-{kind}-r81.zip'
 if not p.exists():p.write_bytes(b''.join(x.read_bytes() for x in sorted((ROOT/'baseline-parts').glob(kind+'-*.bin'))))
 return zipfile.ZipFile(p)
for kind in ('local','public'):
 with archive(kind) as z:
  assert z.testzip() is None
  if kind=='local':
   manifest=json.loads(z.read('release-set.json'))
   with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:data={p:w.read(p) for p in w.namelist()}
   expected=manifest['targets']['local']['managed_tree_sha256']
  else:
   manifest=json.loads(z.read('manifest.json'));data={p[len('payload/'):]:z.read(p) for p in z.namelist() if p.startswith('payload/') and not p.endswith('/')}
   expected=manifest.get('target_managed_tree_sha256',digest(data))
  assert digest(data)==expected
  print(kind,len(data),digest(data))
  for area in ('baseline','source'):
   target=OUT/area/kind
   if target.exists():shutil.rmtree(target)
   for p,b in data.items():
    f=target/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
 for p in (ROOT/'changes'/kind).rglob('*'):
  if p.is_file():
   f=OUT/'source'/kind/p.relative_to(ROOT/'changes'/kind);f.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(p,f)
