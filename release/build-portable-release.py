#!/usr/bin/env python3
from __future__ import annotations
import argparse,hashlib,json,subprocess,sys,zipfile
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def run(cmd):
 p=subprocess.run(cmd,cwd=R,text=True,capture_output=True)
 if p.returncode: raise SystemExit(f"failed: {' '.join(map(str,cmd))}\n{p.stdout}\n{p.stderr}")
 return p.stdout.strip()
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def manifest(zip_path,name='manifest.json'):
 with zipfile.ZipFile(zip_path) as z:return json.loads(z.read(name))
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--out',required=True);ap.add_argument('--source-commit');a=ap.parse_args()
 out=Path(a.out).resolve();out.mkdir(parents=True,exist_ok=True)
 head=a.source_commit or run(['git','rev-parse','HEAD'])
 versions={
  'local-web':(R/'apps/local-web/VERSION.txt').read_text().strip(),
  'public-edge':(R/'apps/public/VERSION.txt').read_text().strip(),
  'shared-contracts':(R/'contracts/VERSION.txt').read_text().strip(),
 }
 paths={
  'local-web':out/f"SoknaCafeV3-local-web-{versions['local-web']}.zip",
  'public-deploy':out/f"SoknaCafeV3-public-edge-{versions['public-edge']}-deploy.zip",
  'public-update':out/f"SoknaCafeV3-public-edge-{versions['public-edge']}-update.zip",
  'shared-contracts':out/f"SoknaContracts-{versions['shared-contracts']}.zip",
 }
 run([sys.executable,'apps/local-web/tools/build-update-package.py','--out',str(paths['local-web']),'--source-commit',head])
 run([sys.executable,'apps/public/tools/build-deploy-package.py','--out',str(paths['public-deploy']),'--source-commit',head])
 run([sys.executable,'apps/public/tools/build-update-package.py','--out',str(paths['public-update']),'--source-commit',head])
 run([sys.executable,'release/build-contracts-package.py','--out',str(paths['shared-contracts']),'--source-commit',head])
 checks=[
  ('local-web',paths['local-web'],'manifest.json','local',versions['local-web']),
  ('public-deploy',paths['public-deploy'],'manifest.json','public-edge',versions['public-edge']),
  ('public-update',paths['public-update'],'manifest.json','public-edge',versions['public-edge']),
  ('shared-contracts',paths['shared-contracts'],'package-manifest.json','shared-contracts',versions['shared-contracts']),
 ]
 arts=[]
 for key,p,mn,component,version in checks:
  m=manifest(p,mn)
  if m.get('component')!=component or m.get('version')!=version: raise SystemExit(f'{key} manifest version/component mismatch')
  arts.append({'id':key,'file':p.name,'component':component,'version':version,'sha256':sha(p),'size':p.stat().st_size})
 index={'format':'sokna-portable-release-index-v1','schema_version':1,'source_commit':head,'artifacts':arts}
 (out/'artifact-index.json').write_text(json.dumps(index,indent=2,sort_keys=True)+'\n')
 print(json.dumps(index,sort_keys=True))
if __name__=='__main__':main()
