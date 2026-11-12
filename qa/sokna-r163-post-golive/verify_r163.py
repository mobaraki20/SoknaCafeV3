#!/usr/bin/env python3
"""Read-only reconstruction of the verified R162 Local source; R163 scope is 3 markup diffs."""
import argparse,hashlib,io,os,pathlib,subprocess,tempfile,zipfile,json
H=lambda b:hashlib.sha256(b).hexdigest()
PARENT_SHA='16adab9910c017d97ebc256e721bfbc22e26103979adc59763c6bf978b6f5471'
PARENT_TREE='6a9cc49f2d4d09e02547d8450ab0334818b933c481cbca3d8f6cfd87dc52b0b3'
RENDERER='src/Domain/GuestLan/GuestLanPageRenderer.php'
PATCH_SHA='06951915bc42dad59cb07a0400f1dc28b319819c4e53064c27a5a7355b4ca8bc'
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def main():
 p=argparse.ArgumentParser();p.add_argument('--release',required=True);p.add_argument('--patch',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 f=pathlib.Path(a.release);assert H(f.read_bytes())==PARENT_SHA,'R162 release changed'
 patch=pathlib.Path(a.patch).resolve();assert H(patch.read_bytes())==PATCH_SHA,'unexpected source patch'
 with zipfile.ZipFile(f) as z:
  manifest=json.loads(z.read('release-set.json'))
  assert manifest['targets']['local']['managed_tree_sha256']==PARENT_TREE
  with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
   old={x:inner.read(x) for x in inner.namelist() if not x.endswith('/')}
 assert len(old)==588 and tree(old)==PARENT_TREE
 with tempfile.TemporaryDirectory() as tmp:
  path=pathlib.Path(tmp)/RENDERER;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(old[RENDERER]);
  run=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',tmp,'-i',str(patch)],capture_output=True,text=True)
  assert run.returncode==0,run.stdout+run.stderr
  repaired=path.read_bytes()
  php=subprocess.run(['php','-l',str(path)],capture_output=True,text=True)
  assert php.returncode==0,php.stdout+php.stderr
 new=dict(old);new[RENDERER]=repaired
 changes=[n for n in old if old[n]!=new[n]]
 assert changes==[RENDERER]
 src=repaired.decode('utf-8');assert src.count('id="sg-menu-search"')==1
 assert "self::e(self::faDigits($tableName !== '' ? $tableName : 'میز'))" in src
 assert "self::e(self::faDigits((string)($table['name']??'میز')))" in src
 assert '<a class="sg-menu-back-top" href="#sg-menu-search">' in src
 oldmig={k:H(v) for k,v in old.items() if k.startswith('database/migrations/')}
 newmig={k:H(v) for k,v in new.items() if k.startswith('database/migrations/')}
 assert oldmig==newmig and len(oldmig)==80
 assert all(old[x]==new[x] for x in old if x!=RENDERER)
 o=pathlib.Path(a.output);o.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(o/'SOKNA-R163-LOCAL-FULL-SOURCE-QA-NOT-INSTALL.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
  for n,b in sorted(new.items()):z.writestr(n,b)
 report={'base_version':manifest['targets']['local']['version'],'base_release_sha256':PARENT_SHA,'base_tree':PARENT_TREE,'new_tree':tree(new),'file_count':len(new),'changed_paths':changes,'migration_count':len(oldmig),'migrations_identical':True,'release_ready':False,'production_host_accepted':False,'database_mutation':False,'php_lint':'PASS','static_source_contracts':['FA_TABLE_BADGE','FA_ABOUT_RETURN','SEARCH_TOP_ANCHOR'], 'artifact':'SOKNA-R163-LOCAL-FULL-SOURCE-QA-NOT-INSTALL.zip'}
 (o/'SOKNA-R163-SOURCE-QA-REPORT.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps(report,ensure_ascii=False,indent=2))
if __name__=='__main__':main()
