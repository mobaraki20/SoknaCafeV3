#!/usr/bin/env python3
"""Read-only R153 Public 35HK baseline reconstruction from prior R249 proof.

Original R249 QA already replayed this exact tree. This tool only reconstructs
the same 222 files in a disposable directory, no product modification.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import io
import json
import pathlib
import shutil
import zipfile

OVERLAY_SHA = "b9d3326e522c3646d1e831cea738352664ab69b6a49f418db32fc88f49c65997"
TREE_SHA = "0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537"
VERSION = "3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa"

def sha(b:bytes)->str:
    return hashlib.sha256(b).hexdigest()

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--r171",type=pathlib.Path,required=True)
    p.add_argument("--fixtures",type=pathlib.Path,required=True)
    p.add_argument("--out",type=pathlib.Path,required=True)
    a=p.parse_args()
    source=a.r171
    fixture=a.fixtures/"r249/fixtures"
    parts=sorted(fixture.glob("r153-source-overlay.part*.b64"))
    if len(parts)!=9:
        raise RuntimeError("HISTORICAL_R153_OVERLAY_PARTS_MISSING")
    if a.out.exists():
        raise RuntimeError("R153_TARGET_MUST_BE_NEW_DIRECTORY")
    shutil.copytree(source,a.out)
    overlay=base64.b64decode("".join(x.read_text().strip() for x in parts),validate=True)
    if sha(overlay)!=OVERLAY_SHA:
        raise RuntimeError("R153_OVERLAY_ARCHIVE_SHA_MISMATCH")
    names=json.loads((fixture/"R153-35HK-SOURCE-FILES.json").read_text())
    if not isinstance(names,list) or len(set(names))!=222:
        raise RuntimeError("R153_HISTORICAL_FILE_INVENTORY_INVALID")
    wanted=set(names)
    for file in a.out.rglob("*"):
        if file.is_file() and file.relative_to(a.out).as_posix() not in wanted:
            file.unlink()
    with zipfile.ZipFile(io.BytesIO(overlay)) as z:
        if z.testzip() is not None:
            raise RuntimeError("R153_OVERLAY_ZIP_CRC_FAIL")
        members=z.namelist()
        if len(set(members))!=len(members):
            raise RuntimeError("R153_OVERLAY_DUPLICATES")
        for n in members:
            if n.startswith("/") or "\\" in n or any(x in ("..",".") for x in n.split("/")):
                raise RuntimeError("R153_UNSAFE_OVERLAY_PATH")
            dest=a.out/n
            dest.parent.mkdir(parents=True,exist_ok=True)
            dest.write_bytes(z.read(n))
    actual={p.relative_to(a.out).as_posix():p.read_bytes() for p in a.out.rglob("*") if p.is_file()}
    if set(actual)!=wanted:
        raise RuntimeError("R153_SOURCE_FILE_SET_MISMATCH")
    tree=sha("".join(n+"\0"+sha(actual[n])+"\n" for n in sorted(actual)).encode())
    if tree!=TREE_SHA or actual["VERSION.txt"].decode().strip()!=VERSION:
        raise RuntimeError("R153_SOURCE_TREE_OR_VERSION_MISMATCH")
    print(json.dumps({"r153_35hk":"PASS_EXACT_HISTORICAL_TREE","files":222,
                      "source_tree_sha256":tree,"production_install":False}))

if __name__=="__main__":
    main()
