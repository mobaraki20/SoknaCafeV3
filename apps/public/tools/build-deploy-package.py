#!/usr/bin/env python3
import argparse, hashlib, json, os, re, zipfile
from pathlib import Path

EXCLUDED_NAMES={'.DS_Store','config.php'}
EXCLUDED_DIRS={'storage','var','.git','node_modules'}

def sha256(data:bytes)->str: return hashlib.sha256(data).hexdigest()

def files(root:Path):
    for p in sorted(root.rglob('*'), key=lambda x:x.as_posix()):
        rel=p.relative_to(root)
        if any(part in EXCLUDED_DIRS for part in rel.parts): continue
        if p.is_file() and p.name not in EXCLUDED_NAMES:
            yield p, rel.as_posix()

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--source',default='apps/public')
    ap.add_argument('--version',required=True)
    ap.add_argument('--out',required=True)
    ap.add_argument('--source-commit',default='unknown')
    args=ap.parse_args()
    if re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z._+-]{0,63}',args.version) is None: raise SystemExit('invalid version')
    root=Path(args.source).resolve(); out=Path(args.out).resolve()
    if not (root/'public'/'index.php').is_file(): raise SystemExit('Public document root is incomplete')
    entries=[]
    for p,rel in files(root):
        data=p.read_bytes(); entries.append((p,rel,data,sha256(data)))
    manifest={
        'format':'sokna-public-deploy-v1','component':'public-edge','version':args.version,
        'source_commit':args.source_commit,'document_root':'public','config_template':'config.example.php',
        'files':[{'path':rel,'size':len(data),'sha256':digest} for _,rel,data,digest in entries],
    }
    manifest_bytes=(json.dumps(manifest,ensure_ascii=False,separators=(',',':'),sort_keys=True)+'\n').encode()
    out.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        def put(name,data,mode=0o644):
            info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(mode&0xFFFF)<<16
            z.writestr(info,data)
        put('manifest.json',manifest_bytes)
        for p,rel,data,_ in entries:
            put(rel,data,0o755 if os.access(p,os.X_OK) else 0o644)
    print(json.dumps({'artifact':str(out),'sha256':sha256(out.read_bytes()),'files':len(entries),'version':args.version},sort_keys=True))

if __name__=='__main__': main()
