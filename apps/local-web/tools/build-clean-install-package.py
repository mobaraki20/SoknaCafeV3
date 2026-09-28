#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, json, os, re, zipfile
from pathlib import Path

INCLUDE_TOP={"VERSION.txt","README.md","bootstrap.php","assets","database","public","resources","src"}
EXCLUDE_NAMES={"config.php","install.lock",".DS_Store"}
EXCLUDE_DIRS={"storage","var","data",".git","node_modules","tests","tools"}

def sha256(b:bytes)->str:return hashlib.sha256(b).hexdigest()

def version(root:Path)->str:
    v=(root/"VERSION.txt").read_text(encoding="utf-8").strip()
    if re.fullmatch(r"[0-9A-Za-z][0-9A-Za-z._+-]{0,63}",v) is None:
        raise SystemExit("invalid Local Web VERSION.txt")
    return v

def rows(root:Path):
    out=[]
    for p in sorted(root.rglob("*"),key=lambda x:x.as_posix()):
        if not p.is_file(): continue
        rel=p.relative_to(root)
        if rel.parts[0] not in INCLUDE_TOP: continue
        if p.name in EXCLUDE_NAMES or any(part in EXCLUDE_DIRS for part in rel.parts): continue
        b=p.read_bytes()
        out.append((rel.as_posix(),b,sha256(b),0o755 if os.access(p,os.X_OK) else 0o644))
    return out

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--source",default="apps/local-web")
    ap.add_argument("--out",required=True)
    ap.add_argument("--source-commit",default="unknown")
    a=ap.parse_args()
    root=Path(a.source).resolve()
    v=version(root)
    rs=rows(root)
    required={"VERSION.txt","bootstrap.php","public/index.php","public/setup/index.php","public/setup/api.php","database/migrations/0001_m2_platform_core.sql"}
    present={r for r,_,_,_ in rs}
    missing=sorted(required-present)
    if missing: raise SystemExit("clean install package missing required files: "+", ".join(missing))
    manifest={
        "format":"sokna-local-web-clean-install-v1",
        "schema_version":1,
        "component":"local-web",
        "version":v,
        "source_commit":a.source_commit,
        "document_root":"public",
        "config_path":"config.php",
        "install_lock_path":"install.lock",
        "files":[{"path":r,"size":len(b),"sha256":d} for r,b,d,_ in rs],
    }
    out=Path(a.out).resolve();out.parent.mkdir(parents=True,exist_ok=True)
    raw=(json.dumps(manifest,ensure_ascii=False,sort_keys=True,separators=(",",":"))+"\n").encode()
    with zipfile.ZipFile(out,"w",zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        def put(name,b,mode=0o644):
            info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(mode&0xffff)<<16;z.writestr(info,b)
        put("sokna-install-manifest.json",raw)
        for rel,b,_,mode in rs:put(rel,b,mode)
    with zipfile.ZipFile(out) as z:
        names=set(z.namelist())
        if "payload/public/index.php" in names or "public/index.php" not in names:
            raise SystemExit("clean install ZIP layout is not direct-extractable")
        if "config.php" in names or "install.lock" in names:
            raise SystemExit("clean install ZIP contains mutable installation state")
    print(json.dumps({"artifact":str(out),"version":v,"sha256":sha256(out.read_bytes()),"files":len(rs),"document_root":"public"},sort_keys=True))

if __name__=="__main__":
    main()
