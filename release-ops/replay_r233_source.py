#!/usr/bin/env python3
"""Reconstruct immutable R233 SOURCE-ONLY archives from existing GitHub evidence.

Uses frozen R204 Windows artifact and pinned fixture commit. Never changes source
in GitHub or creates an installable package. All changes are in temporary dirs.
Repackages original SOURCE bytes in their original deterministic ZIP encoding.
"""
from __future__ import annotations

import argparse
import base64
import gzip
import hashlib
import io
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile
import zipfile

from source_gate import inspect

FIXTURE_COMMIT = "120d1fb5107699d6b62eaba74f04fd962338f6a3"
R204_ZIP_SHA256 = "279d64dcf3800a764c3fcf751a0fc800857de501943af32428c87cc8db2539a3"
R220_PUBLIC_SHA256 = "f9392dcfbcbba521bf6f372552080005ba7ff0a94f3369c291090fe8294024a0"
R233_PUBLIC_OVERLAY_SHA256 = "0acc5a47811b9a9cc65e1484a1dc47eda8a7bcd305964131b3fe6df6d832a6ab"
LOCAL_PATCHES = (
    ("qa/sokna-r222/R204-to-R222-exact-cumulative.patch.gz.b64", "56ab7c800a3b0a14c869374095806e4bac821c92647a26d78309b125edb083c5"),
    ("qa/sokna-r225/R222-to-R224-exact.patch.gz.b64", "1ee11d6fb244a59dc9fecc3685317c6c0688ea8623d6560fd2bf6db0bd32d38e"),
    ("qa/sokna-r225/R224-to-R225-clean-setup.patch.gz.b64", "4d05e072c1a06c1319c6e20e4f583ac654b1138ec51c2522b033e8bf95b46985"),
    ("qa/sokna-r231/R225-to-R230.patch.gz.b64", "49dc365bf643bec64c5257a010f76a4edd2967cefff8702bc5984b1fe53ec708"),
    ("qa/sokna-r231/R230-to-R231.patch.gz.b64", "346adb091e0dc7994db81dfd7a88066a902ba48119485116b5e6a38ad761edec"),
    ("qa/sokna-r232/R231-or-R230-to-R232-local.patch.gz.b64", "5971d5570d7259f0e38a6453875e9a87581d083c1a83ed83ccc9db49c2f1f3eb"),
    ("qa/sokna-r233/R232-to-R233-local.patch.gz.b64", "f36d4fae3ec4925b76f3b9248fa7f5d027115239519e5c32b8a4ee55784e1f7a"),
)
EXPECTED_LOCAL_TREE = "f8d6bce01b2cee08b290a361c6759c24af6122ac99c39c1dc65e2bc0d5b60d0f"
EXPECTED_PUBLIC_TREE = "ecb2915c599ba57ed2695fb89b8907046294f797c303f3bb19d6b27b5c84f7d0"
NAME = {
    "local": "SOKNA-R233-LOCAL-FULL-CUMULATIVE-WORKING-SOURCE-NOT-INSTALLABLE.zip",
    "public": "SOKNA-R233-PUBLIC-FULL-CUMULATIVE-WORKING-SOURCE-NOT-INSTALLABLE.zip",
}
EXPECTED_ARCHIVE_SHA = {
    "local": "783bdde1ecd7bad4e8043d8591b52a2e628b99bc6cd32b2247c4786c5eba7a2d",
    "public": "8d89b156b3efcd380763d907796a9c6914f9ec2c27f40c1a61d04ffb6dc4ca7b",
}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def require_digest(blob: bytes, expected: str, label: str) -> None:
    if sha(blob) != expected:
        raise RuntimeError(f"{label}_SHA_MISMATCH: expected {expected}; got {sha(blob)}")


def unpack_zip(blob: bytes, dest: pathlib.Path) -> None:
    with zipfile.ZipFile(io.BytesIO(blob)) as z:
        seen: set[str] = set()
        for info in z.infolist():
            n = info.filename
            if (not n or n.startswith("/") or "\\" in n or
                    any(part in (".", "..") for part in n.rstrip("/").split("/")) or
                    n in seen):
                raise RuntimeError("ARCHIVE_UNSAFE_NAME_OR_DUPLICATE")
            seen.add(n)
        if z.testzip() is not None:
            raise RuntimeError("ARCHIVE_BAD_CRC")
        z.extractall(dest)


def parse_files(root: pathlib.Path) -> dict[str, bytes]:
    # The temporary Git index only applies source patches and is not product.
    return {str(f.relative_to(root)).replace("\\", "/"): f.read_bytes()
            for f in root.rglob("*") if f.is_file() and ".git" not in f.relative_to(root).parts
            and f.name != ".sokna-installation.json"}


def tree(files: dict[str, bytes]) -> str:
    return sha("".join(n + ":" + sha(files[n]) + "\n" for n in sorted(files)).encode())


def original_order(component: str, names) -> list[str]:
    order = sorted(names)
    if component == "local":
        # Preserved R233 serialization order: only five entries differ from
        # lexical sort, inherited from the original Local source archive.
        left = ["public/staff/api.php", "public/staff/index.php"]
        if all(x in order for x in left):
            order = [x for x in order if x not in left]
            ix = order.index("public/staff-consumption/api.php")
            order[ix:ix] = left
    return order


def original_zip(files: dict[str, bytes], component: str) -> bytes:
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for n in original_order(component, files):
            info = zipfile.ZipInfo(n, (2026, 11, 15, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, files[n], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return out.getvalue()


def reconstruct_local(fixtures: pathlib.Path, r204_path: pathlib.Path, out: pathlib.Path) -> dict:
    parent = r204_path.read_bytes()
    require_digest(parent, R204_ZIP_SHA256, "R204_PARENT")
    with tempfile.TemporaryDirectory(prefix="sokna-r233-local-") as tmp:
        root = pathlib.Path(tmp)
        outer = root / "outer"
        work = root / "work"
        work.mkdir()
        unpack_zip(parent, outer)
        inner = (outer / "artifacts/local/web.zip").read_bytes()
        unpack_zip(inner, work)
        subprocess.run(["git", "init", "-q"], cwd=work, check=True)
        subprocess.run(["git", "config", "core.autocrlf", "false"], cwd=work, check=True)
        for idx, (rel, digest) in enumerate(LOCAL_PATCHES, 1):
            b64 = (fixtures / rel).read_bytes()
            patch = gzip.decompress(base64.b64decode(b64))
            require_digest(patch, digest, f"LOCAL_PATCH_{idx}")
            patched = root / f"patch-{idx}.patch"
            patched.write_bytes(patch)
            subprocess.run(["git", "apply", "--check", str(patched)], cwd=work, check=True)
            subprocess.run(["git", "apply", str(patched)], cwd=work, check=True)
        files = parse_files(work)
        if len(files) != 593 or tree(files) != EXPECTED_LOCAL_TREE:
            raise RuntimeError(f"LOCAL_SOURCE_TREE_MISMATCH:{len(files)}:{tree(files)}")
        archive = original_zip(files, "local")
        require_digest(archive, EXPECTED_ARCHIVE_SHA["local"], "LOCAL_SOURCE_ARCHIVE")
        out.write_bytes(archive)
        return {"source_files": len(files), "source_tree_sha256": tree(files), "source_zip_sha256": sha(archive)}


def reconstruct_public(fixtures: pathlib.Path, out: pathlib.Path) -> dict:
    with tempfile.TemporaryDirectory(prefix="sokna-r233-public-") as tmp:
        temp = pathlib.Path(tmp)
        work = temp / "public"
        work.mkdir()
        first = base64.b64decode((fixtures / "qa/sokna-r228/R220-PUBLIC-EXACT-HTTP-SERVER-SOURCES.zip.b64").read_bytes())
        require_digest(first, R220_PUBLIC_SHA256, "R220_PUBLIC")
        unpack_zip(first, work)
        overlay = base64.b64decode((fixtures / "qa/sokna-r233/R220-TO-R233-PUBLIC-EXACT-OVERLAY.zip.b64").read_bytes())
        require_digest(overlay, R233_PUBLIC_OVERLAY_SHA256, "R233_PUBLIC_OVERLAY")
        unpack_zip(overlay, work)
        files = parse_files(work)
        if len(files) != 233 or tree(files) != EXPECTED_PUBLIC_TREE:
            raise RuntimeError(f"PUBLIC_SOURCE_TREE_MISMATCH:{len(files)}:{tree(files)}")
        archive = original_zip(files, "public")
        require_digest(archive, EXPECTED_ARCHIVE_SHA["public"], "PUBLIC_SOURCE_ARCHIVE")
        out.write_bytes(archive)
        return {"source_files": len(files), "source_tree_sha256": tree(files), "source_zip_sha256": sha(archive)}


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--fixtures", type=pathlib.Path, required=True,
                   help="Checkout of exact pinned GitHub historical commit")
    p.add_argument("--r204", type=pathlib.Path, required=True)
    p.add_argument("--out", type=pathlib.Path, required=True)
    p.add_argument("--register", type=pathlib.Path, default=pathlib.Path(__file__).with_name("source-register.json"))
    args = p.parse_args()
    args.out.mkdir(parents=True, exist_ok=True)
    local = args.out / NAME["local"]
    public = args.out / NAME["public"]
    data = {
        "format": "sokna-source-replay-r233-v1", "fixture_commit": FIXTURE_COMMIT,
        "r204_artifact_sha256": R204_ZIP_SHA256,
        "source_only": True, "production_installable": False,
    }
    data["local"] = reconstruct_local(args.fixtures, args.r204, local)
    data["public"] = reconstruct_public(args.fixtures, public)
    for component, path in (("local", local), ("public", public)):
        proof = inspect(path, "R233-20261115", component, args.register)
        if proof["source_integrity"] != "PASS":
            raise RuntimeError("SOURCE_REGISTER_ADMISSION_FAILED:" + component + ":" + str(proof["errors"]))
        data[component]["source_gate"] = proof["source_integrity"]
        data[component]["release_blockers"] = proof["release_blockers"]
    (args.out / "R233-SOURCE-REPLAY-ONLY.json").write_text(
        json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
