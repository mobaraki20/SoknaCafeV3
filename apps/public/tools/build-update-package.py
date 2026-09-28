#!/usr/bin/env python3
import argparse,hashlib,json,os,re,zipfile
from pathlib import Path
EXCLUDED={'config.php'}; EXCLUDED_DIRS={'storage','.git','node_modules'}
STABLE=('public/emergency.php','src/Emergency/','resources/update-trust-v1.json')
def h(b): return hashlib.sha256(b).hexdigest()
def stable(rel): return rel=='public/emergency.php' or rel.startswith('src/Emergency/') or rel=='resources/update-trust-v1.json'
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--source',default='apps/public');ap.add_argument('--version');ap.add_argument('--out',required=True);ap.add_argument('--source-commit',default='unknown');a=ap.parse_args()
 source_version=(Path(a.source).resolve()/'VERSION.txt').read_text(encoding='utf-8').strip()
 if a.version and a.version!=source_version: raise SystemExit(f'--version {a.version} does not match Public VERSION.txt {source_version}')
 a.version=source_version
 if re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z._+-]{0,63}',a.version) is None: raise SystemExit('invalid version')
 root=Path(a.source).resolve(); out=Path(a.out).resolve(); rows=[]
 for p in sorted(root.rglob('*'),key=lambda x:x.as_posix()):
  if not p.is_file(): continue
  rel=p.relative_to(root).as_posix()
  if p.name in EXCLUDED or any(x in EXCLUDED_DIRS for x in p.relative_to(root).parts) or stable(rel): continue
  b=p.read_bytes();rows.append((rel,b,h(b),0o755 if os.access(p,os.X_OK) else 0o644))
 manifest={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':a.version,'source_commit':a.source_commit,'contracts':{'local_public_contract':1},'files':[{'path':r,'size':len(b),'sha256':d} for r,b,d,_ in rows]}
 raw=(json.dumps(manifest,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode();out.parent.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  def put(n,b,m=0o644): i=zipfile.ZipInfo(n,(1980,1,1,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=(m&0xffff)<<16;z.writestr(i,b)
  put('manifest.json',raw)
  for r,b,_,m in rows: put('payload/'+r,b,m)
 print(json.dumps({'artifact':str(out),'sha256':h(out.read_bytes()),'files':len(rows),'version':a.version},sort_keys=True))
if __name__=='__main__':main()
