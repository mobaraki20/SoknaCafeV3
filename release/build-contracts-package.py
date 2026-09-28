#!/usr/bin/env python3
from __future__ import annotations
import argparse,hashlib,json,os,re,zipfile
from pathlib import Path

def h(b:bytes)->str:return hashlib.sha256(b).hexdigest()
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--source',default='contracts');ap.add_argument('--out',required=True);ap.add_argument('--source-commit',default='unknown');a=ap.parse_args()
 root=Path(a.source).resolve();version=(root/'VERSION.txt').read_text(encoding='utf-8').strip()
 if re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z._+-]{0,63}',version) is None: raise SystemExit('invalid contracts VERSION.txt')
 manifest_src=json.loads((root/'manifest.json').read_text(encoding='utf-8'))
 rows=[]
 for p in sorted(root.rglob('*'),key=lambda x:x.as_posix()):
  if not p.is_file() or '.git' in p.parts: continue
  rel=p.relative_to(root).as_posix();b=p.read_bytes();rows.append((rel,b,h(b),0o755 if os.access(p,os.X_OK) else 0o644))
 manifest={'format':'sokna-contracts-package-v1','schema_version':1,'component':'shared-contracts','version':version,'source_commit':a.source_commit,'contracts':[{'id':x['id'],'version':x['version']} for x in manifest_src.get('contracts',[])],'files':[{'path':r,'size':len(b),'sha256':d} for r,b,d,_ in rows]}
 raw=(json.dumps(manifest,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode();out=Path(a.out).resolve();out.parent.mkdir(parents=True,exist_ok=True)
 with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  def put(n,b,m=0o644):i=zipfile.ZipInfo(n,(1980,1,1,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=(m&0xffff)<<16;z.writestr(i,b)
  put('package-manifest.json',raw)
  for r,b,_,m in rows: put('contracts/'+r,b,m)
 print(json.dumps({'artifact':str(out),'component':'shared-contracts','version':version,'sha256':h(out.read_bytes()),'files':len(rows)},sort_keys=True))
if __name__=='__main__':main()
