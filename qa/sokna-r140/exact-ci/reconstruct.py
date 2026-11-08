#!/usr/bin/env python3
"""Disposable CI only: reconstruct owner-authorized exact local ZIPs from QA blob chunks."""
import hashlib
import io
import json
from pathlib import Path
import shutil
import sys
import zipfile

BASE = Path(__file__).resolve().parents[1]
OUT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path.cwd() / "r140-exact-ci"
DEFS = (
    ("r75", "exact-r75-fragments", "cbab0538d9ea8034f43c1e3202adb7f079b12f6d33f6bf0bc1a992033264c5f6", 534),
    ("r140", "exact-zip-fragments", "63cb6f20033372094a823fc724fbb3d7b6fcd285c98abee28f250482babe6c9b", 579),
)

def sha(buf):
    return hashlib.sha256(buf).hexdigest()

def safe_extract(z, dest):
    root = dest.resolve()
    for x in z.infolist():
        name = x.filename
        target = (dest / name).resolve()
        if name.startswith("/") or not target.is_relative_to(root) or "\\\\" in name or name.startswith("../"):
            raise ValueError("Unsafe ZIP path")
    z.extractall(dest)

def load_archive(name, folder, digest, managed_files):
    fragments = sorted((BASE / folder).glob("chunk_*.bin"))
    if not fragments or any(int(f.stem[6:]) != i for i, f in enumerate(fragments)):
        raise ValueError(name + " chunk set incomplete")
    OUT.mkdir(parents=True, exist_ok=True)
    archive_path = OUT / (name + ".zip")
    with archive_path.open("wb") as target:
        for f in fragments:
            with f.open("rb") as s:
                shutil.copyfileobj(s, target, 1024 * 1024)
    if sha(archive_path.read_bytes()) != digest:
        raise ValueError(name + " exact archive SHA mismatch")
    with zipfile.ZipFile(archive_path) as outer:
        if outer.testzip() is not None:
            raise ValueError(name + " outer CRC")
        manifest = json.loads(outer.read("release-set.json"))
        artifact = manifest["artifacts"]["local-web"]
        inner_blob = outer.read(artifact["path"])
        if sha(inner_blob) != artifact["sha256"] or len(inner_blob) != artifact["size_bytes"]:
            raise ValueError(name + " nested artifact mismatch")
    with zipfile.ZipFile(io.BytesIO(inner_blob)) as inner:
        if inner.testzip() is not None:
            raise ValueError(name + " inner CRC")
        files = [n for n in inner.namelist() if not n.endswith("/")]
        if len(files) != managed_files or len(files) != len(set(files)):
            raise ValueError(name + " managed files mismatch")
        catalog = "".join(n + "\0" + sha(inner.read(n)) + "\n" for n in sorted(files)).encode()
        if sha(catalog) != manifest["targets"]["local"]["managed_tree_sha256"]:
            raise ValueError(name + " managed-tree digest mismatch")
        root = OUT / (name + "-web")
        root.mkdir(parents=True, exist_ok=True)
        safe_extract(inner, root)
    print("PASS", name, "exact ZIP outer SHA, nested CRC/hash, managed-tree and", managed_files, "files")
    return manifest

def main():
    a = load_archive(*DEFS[0])
    b = load_archive(*DEFS[1])
    aa = a["targets"]["local"]
    matching = [
        x["local"] for x in b["supported_baselines"]
        if x["local"] is not None
        and x["local"]["version"] == aa["version"]
        and x["local"]["managed_tree_sha256"] == aa["managed_tree_sha256"]
        and x["local"]["schema_fingerprint"] == aa["schema_fingerprint"]
        and x["local"]["migration_head"] == aa["migration_head"]
    ]
    if len(matching) != 1 or len(b["supported_baselines"]) != 42:
        raise ValueError("exact R75 -> R140 baseline mismatch")
    for name in ("0077_incremental_opening_inventory", "0078_tea_herbal_three_sizes", "0079_cafe_staff_initial_accounts"):
        if not (OUT / "r140-web" / "database" / "migrations" / (name + ".sql")).is_file():
            raise ValueError("Missing migration " + name)
    if b["for_install"] is not False or b["production_pass"] is not False:
        raise ValueError("Unexpectedly production-authorized original")
    print("PASS exact source R75/231cd -> R140 42-baseline identity and three DB migrations")
    print("QA_ORIGINAL_UNSIGNED_UNQUALIFIED_DO_NOT_INSTALL")

if __name__ == "__main__":
    main()
