import io,json,pathlib,shutil,subprocess,zipfile,importlib.util,hashlib
ROOT=pathlib.Path(__file__).resolve().parent;OUT=ROOT/'build'
spec=importlib.util.spec_from_file_location('r88_prepare',ROOT.parent/'r88/prepare.py');previous=importlib.util.module_from_spec(spec);spec.loader.exec_module(previous)
files,digest,write=previous.files,previous.digest,previous.write
def prepare():
 subprocess.run(['python3',str(ROOT.parent/'r88/prepare.py')],check=True)
 for kind in ['local','public']:
  old=files(ROOT.parent/'r88/build/baseline'/kind);data=dict(old)
  for version in ['r81','r83','r84','r85','r86']:write(OUT/version/kind,files(ROOT.parent/'r88/build'/version/kind))
  if kind=='local':write(OUT/'r87'/kind,old)
  name='SOKNA-R88-LOCAL-R87-TO-R88-DELTA-HOME-UAT.zip' if kind=='local' else 'SOKNA-R88-PUBLIC-R86-TO-R88-ROUTINE-DELTA-HOME-UAT.zip'
  with zipfile.ZipFile(ROOT/'baselines'/name) as z:
   assert z.testzip() is None
   if kind=='local':
    m=json.loads(z.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(z.read(m['artifacts']['local-web']['path']))) as w:
     d=json.loads(w.read('delta-manifest.json'));assert digest(old)==d['base_managed_tree_sha256']
     for n in d['changed_paths']:data[n]=w.read(n)
     assert not d['deleted_paths'];expected=d['target_managed_tree_sha256']
   else:
    m=json.loads(z.read('manifest.json'));assert digest(old)==m['base']['managed_tree_sha256']
    for e in m['files']:data[e['path']]=z.read('payload/'+e['path'])
    assert not m['deletes'];expected=m['target']['managed_tree_sha256']
  assert digest(data)==expected
  write(OUT/'r88'/kind,data);write(OUT/'source'/kind,data)
 index=json.loads((ROOT/'baseline-parts/index.json').read_text())
 for key,node in index.items():
  raw=b''.join((ROOT/'baseline-parts'/n).read_bytes() for n in node['parts']);assert hashlib.sha256(raw).hexdigest()==node['sha256']
  with zipfile.ZipFile(io.BytesIO(raw)) as z:
   assert z.testzip() is None
   if key=='cafe-local':data={n[4:]:z.read(n) for n in z.namelist() if n.startswith('Web/') and not n.endswith('/')};version='r38';kind='local'
   else:
    m=json.loads(z.read('manifest.json'));data={e['path']:z.read('payload/'+e['path']) for e in m['files']};version='r53';kind='public';assert digest(data)=='35deffeb88b983615f45bcdf69d4feda93e8c1d3a718d91ff524bc7fec86bc16'
   write(OUT/version/kind,data)
 # Retain the intermediate published Local R82 as another accepted exact source.
 b82=files(OUT/'r81/local')
 with zipfile.ZipFile(ROOT.parent/'r83/baseline-local-r82-delta.zip') as z:
  m=json.loads(z.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(z.read(m['artifacts']['local-web']['path']))) as w:
   d=json.loads(w.read('delta-manifest.json'))
   for n in d['changed_paths']:b82[n]=w.read(n)
  assert digest(b82)==m['targets']['local']['managed_tree_sha256']
 write(OUT/'r82/local',b82)
 print('published R88 source and actual Cafe baselines reconstructed')
if __name__=='__main__':prepare()
