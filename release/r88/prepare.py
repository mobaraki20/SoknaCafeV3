import io,json,pathlib,shutil,subprocess,zipfile,importlib.util
ROOT=pathlib.Path(__file__).resolve().parent;OUT=ROOT/'build'
spec=importlib.util.spec_from_file_location('r87_prepare',ROOT.parent/'r87/prepare.py')
previous=importlib.util.module_from_spec(spec);spec.loader.exec_module(previous)
files,digest,write=previous.files,previous.digest,previous.write
def prepare():
 subprocess.run(['python3',str(ROOT.parent/'r87/prepare.py')],check=True)
 for kind in ['local','public']:
  old=files(ROOT.parent/'r87/build/baseline'/kind);data=dict(old)
  for version in ['r81','r83','r84','r85']:write(OUT/version/kind,files(ROOT.parent/'r87/build'/version/kind))
  if kind=='local':
   path=ROOT/'baselines/SOKNA-R87-LOCAL-R86-TO-R87-DELTA-HOME-UAT.zip'
   with zipfile.ZipFile(path) as z:
    assert z.testzip() is None;m=json.loads(z.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(z.read(m['artifacts']['local-web']['path']))) as w:
     d=json.loads(w.read('delta-manifest.json'));assert digest(old)==d['base_managed_tree_sha256']
     for n in d['changed_paths']:data[n]=w.read(n)
     assert not d['deleted_paths'];expected=d['target_managed_tree_sha256']
   assert digest(data)==expected
  else:assert data==files(ROOT.parent/'r87/build/source/public')
  write(OUT/'baseline'/kind,data);write(OUT/'source'/kind,data)
  for p in (ROOT/'changes'/kind).rglob('*'):
   if p.is_file():
    f=OUT/'source'/kind/p.relative_to(ROOT/'changes'/kind);assert f.is_file();shutil.copyfile(p,f)
  print(kind,len(data),'exact published baseline',digest(data))
if __name__=='__main__':prepare()
