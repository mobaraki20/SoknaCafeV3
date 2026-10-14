from __future__ import annotations

from pathlib import Path
import argparse
import hashlib
import sys


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def parse_hunks(patch_text: str) -> list[list[str]]:
    lines = patch_text.replace('\r\n', '\n').replace('\r', '\n').splitlines(keepends=True)
    hunks: list[list[str]] = []
    current: list[str] | None = None
    for line in lines:
        if line.startswith('--- ') or line.startswith('+++ '):
            continue
        if line.startswith('@@'):
            if current is not None:
                hunks.append(current)
            current = []
            continue
        if current is None:
            continue
        if line.startswith('\\ No newline at end of file'):
            continue
        if not line or line[0] not in (' ', '+', '-'):
            raise ValueError(f'unsupported patch line: {line!r}')
        current.append(line)
    if current is not None:
        hunks.append(current)
    if not hunks:
        raise ValueError('patch contains no hunks')
    return hunks


def materialize_block(lines: list[str], *, old: bool) -> str:
    out: list[str] = []
    for line in lines:
        prefix = line[0]
        if old and prefix == '+':
            continue
        if not old and prefix == '-':
            continue
        out.append(line[1:])
    return ''.join(out)


def apply_engineering_patch(source: str, patch_path: Path) -> str:
    patch = patch_path.read_text(encoding='utf-8')
    for index, hunk in enumerate(parse_hunks(patch), start=1):
        old = materialize_block(hunk, old=True)
        new = materialize_block(hunk, old=False)
        if not old:
            raise ValueError(f'{patch_path.name}: hunk {index} has empty old block')
        count = source.count(old)
        if count != 1:
            raise ValueError(f'{patch_path.name}: hunk {index} old block match count={count}, expected 1')
        source = source.replace(old, new, 1)
    return source


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--source', required=True)
    ap.add_argument('--base-blob-sha', required=True)
    ap.add_argument('--arch05', required=True)
    ap.add_argument('--arch05-sha256', required=True)
    ap.add_argument('--arch06', required=True)
    ap.add_argument('--arch06-sha256', required=True)
    args = ap.parse_args()

    source_path = Path(args.source)
    arch05 = Path(args.arch05)
    arch06 = Path(args.arch06)
    if sha256(arch05) != args.arch05_sha256.lower():
        raise SystemExit('ARCH05 patch SHA256 mismatch')
    if sha256(arch06) != args.arch06_sha256.lower():
        raise SystemExit('ARCH06 patch SHA256 mismatch')

    # The Git blob is verified independently by CI before this script is called.
    # This argument is retained in output/provenance so an applicator invocation
    # is self-describing and cannot silently drift to an unpinned baseline.
    if not args.base_blob_sha.strip():
        raise SystemExit('base blob SHA is required')

    raw = source_path.read_bytes()
    try:
        source = raw.decode('utf-8')
    except UnicodeDecodeError as exc:
        raise SystemExit(f'Program.cs is not UTF-8: {exc}')
    source = source.replace('\r\n', '\n').replace('\r', '\n')
    before = hashlib.sha256(source.encode('utf-8')).hexdigest()
    source = apply_engineering_patch(source, arch05)
    source = apply_engineering_patch(source, arch06)
    if 'TriggerBudgetMilliseconds' not in source or 'RetryBudgetMaxAttempts=6' not in source or 'RuntimeTriggerHttpException' not in source:
        raise SystemExit('materialized Runtime 1.0.5 markers are incomplete')
    source_path.write_text(source, encoding='utf-8', newline='\n')
    after = sha256(source_path)
    print(f'Runtime 1.0.5 patch materialization PASS base_blob={args.base_blob_sha} source_before_sha256={before} source_after_sha256={after}')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f'Runtime 1.0.5 patch materialization FAIL: {exc}', file=sys.stderr)
        raise
