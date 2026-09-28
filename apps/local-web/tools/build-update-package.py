#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json, os, re, zipfile
from pathlib import Path

EXCLUDED_NAMES={'.DS_Store','config.php'}
EXCLUDED_DIRS={'storage','var','.git','node_modules','data'}
STABLE=('public/local-recovery.php',)

def h(b:bytes)->str:return hashlib.sha256(b).hexdigest()
def own_version(root:Path)->str:
    v=(root/'VERSION.txt').read_text(encoding='utf-8').strip()
    if re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z._+-]{0,63}',v) is None: raise SystemExit('invalid Local component VERSION.txt')
    return v

def rows(root:Path):
    out=[]
    for p in sorted(root.rglob('*'),key=lambda x:x.as_posix()):
        if not p.is_file(): continue
        rel=p.relative_to(root).as_posix()
        if p.name in EXCLUDED_NAMES or any(x in EXCLUDED_DIRS for x in p.relative_to(root).parts) or rel in STABLE: continue
        b=p.read_bytes();out.append((rel,b,h(b),0o755 if os.access(p,os.X_OK) else 0o644))
    return out

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--source',default='apps/local-web');ap.add_argument('--version');ap.add_argument('--out',required=True);ap.add_argument('--source-commit',default='unknown');a=ap.parse_args()
    root=Path(a.source).resolve(); version=own_version(root)
    if a.version and a.version!=version: raise SystemExit(f'--version {a.version} does not match Local VERSION.txt {version}')
    rs=rows(root);out=Path(a.out).resolve();out.parent.mkdir(parents=True,exist_ok=True)
    manifest={'format':'sokna-component-package-v1','schema_version':1,'component':'local','version':version,'source_commit':a.source_commit,'contracts':{'runtime_contract':'1.0.0','print_server_protocol':4},'files':[{'path':r,'size':len(b),'sha256':d} for r,b,d,_ in rs]}
    raw=(json.dumps(manifest,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
    with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        def put(n,b,m=0o644):
            i=zipfile.ZipInfo(n,(1980,1,1,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=(m&0xffff)<<16;z.writestr(i,b)
        put('manifest.json',raw)
        for r,b,_,m in rs: put('payload/'+r,b,m)
    print(json.dumps({'artifact':str(out),'component':'local-web','version':version,'sha256':h(out.read_bytes()),'files':len(rs)},sort_keys=True))
if __name__=='__main__':main()
