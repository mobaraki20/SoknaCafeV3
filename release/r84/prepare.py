import hashlib,io,json,pathlib,shutil,subprocess,zipfile
ROOT=pathlib.Path(__file__).resolve().parent
OUT=ROOT/'build'
def files(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def digest(data):
 h=hashlib.sha256()
 for n,b in sorted(data.items()):h.update(n.encode()+b'\0'+hashlib.sha256(b).hexdigest().encode()+b'\n')
 return h.hexdigest()
def write(root,data):
 if root.exists():shutil.rmtree(root)
 for n,b in data.items():
  p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
def prepare():
 subprocess.run(['python3',str(ROOT.parent/'r83/prepare.py')],check=True)
 for kind in ['local','public']:
  r81=files(ROOT.parent/'r83/build/baseline'/kind)
  write(OUT/'r81'/kind,r81)
  path=ROOT/'baselines'/('SOKNA-R83-LOCAL-R81-TO-R83-DELTA-HOME-UAT.zip' if kind=='local' else 'SOKNA-R83-PUBLIC-R81-TO-R83-ROUTINE-DELTA-HOME-UAT.zip')
  data=dict(r81)
  with zipfile.ZipFile(path) as z:
   assert z.testzip() is None
   if kind=='local':
    m=json.loads(z.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(z.read(m['artifacts']['local-web']['path']))) as w:
     d=json.loads(w.read('delta-manifest.json'));assert digest(r81)==d['base_managed_tree_sha256']
     for n in d['changed_paths']:data[n]=w.read(n)
     assert not d['deleted_paths'];expected=d['target_managed_tree_sha256']
   else:
    m=json.loads(z.read('manifest.json'));assert digest(r81)==m['base']['managed_tree_sha256']
    for entry in m['files']:
     n=entry['path'];b=z.read('payload/'+n);assert hashlib.sha256(b).hexdigest()==entry['sha256'];data[n]=b
    assert not m['deletes'];expected=m['target']['managed_tree_sha256']
  assert digest(data)==expected
  write(OUT/'baseline'/kind,data);write(OUT/'source'/kind,data)
  for p in (ROOT/'changes'/kind).rglob('*'):
   if p.is_file():
    f=OUT/'source'/kind/p.relative_to(ROOT/'changes'/kind);assert f.is_file();shutil.copyfile(p,f)
  print(kind,len(data),'exact R83',digest(data))
if __name__=='__main__':prepare()
