#!/usr/bin/env python3
"""CI-only: restore exact R277 release package + byte-exact installed R275 managed source.
All binary fixture chunks are SHA-pinned; no production configuration or secrets.
"""
from pathlib import Path
import os, base64, hashlib, json, zipfile, io, zlib, shutil
repo = Path(os.environ.get('GITHUB_WORKSPACE', Path.cwd()))
parts = repo/'r277/ci-binary'
root = Path(os.environ.get('R277_NATIVE_ROOT', '/tmp/sokna-r277-native'))
root.mkdir(parents=True, exist_ok=True)
chunks = sorted(parts.glob('part-*.b64'))
assert len(chunks) == 18, f'R277_PARTS_COUNT_NOT_18: {len(chunks)}'
assert [p.name for p in chunks] == [f'part-{i:03d}.b64' for i in range(1, 19)]
joined = ''.join(p.read_text().strip() for p in chunks)
raw = base64.b64decode(joined, validate=True)
H = lambda x: hashlib.sha256(x).hexdigest()
assert H(raw) == '46381c48eb0266b861e27aa020bbef9a19da325d26b41304e10139b0fee747c5', 'R277_SOURCE_PACKAGE_SHA_MISMATCH'
pkg = root/'SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-QA-DO-NOT-INSTALL.zip'
pkg.write_bytes(raw)
with zipfile.ZipFile(io.BytesIO(raw)) as outer:
    assert outer.testzip() is None
    manifest = json.loads(outer.read('release-set.json'))
    baseline = [x['local'] for x in manifest['supported_baselines'] if (x.get('local') or {}).get('version')=='3.0.0-g6.2-workspace.231fl-r275-owner-discard-public-work']
    assert len(baseline) == 1, 'R277_MISSING_UNIQUE_R275_BASELINE'
    manifest['targets']['local']['version'] == '3.0.0-g6.2-workspace.231fm-r277-local-finance-safety'
    payload = outer.read('artifacts/local/web.zip')
    assert H(payload) == manifest['artifacts']['local-web']['sha256']
    with zipfile.ZipFile(io.BytesIO(payload)) as web:
        assert web.testzip() is None
        contents = {p: web.read(p) for p in web.namelist()}
assert len(contents)==604
managed_hash = lambda d: H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
assert managed_hash(contents)==manifest['targets']['local']['managed_tree_sha256'], 'R277_TARGET_MANAGED_TREE_MISMATCH'
encoded = (parts/'revert-r275-managed.json.zlib.b64').read_text().strip()
old_files = json.loads(zlib.decompress(base64.b64decode(encoded)))
old = dict(contents)
for name,val in old_files.items():
    assert name in old, name
    old[name] = base64.b64decode(val)
assert len(old_files)==7
assert managed_hash(old) == baseline[0]['managed_tree_sha256'] == 'a7407fa1ef4bd3cb17ac68fe6d548330e32a9f3ca47331c47d233b4b6398d9c2','R275_BASELINE_BYTES_INVALID'
assert old['VERSION.txt'].decode().strip()==baseline[0]['version']
base_dir = root/'r275-installed-web'
base_dir.mkdir(exist_ok=True)
for name,data in old.items():
    dst=base_dir/name
    dst.parent.mkdir(parents=True,exist_ok=True)
    dst.write_bytes(data)
(root/'baseline.json').write_text(json.dumps(baseline[0]))
(root/'target.json').write_text(json.dumps(manifest['targets']['local']))
print('R277_CI_ARCHIVE_EXACT_SHA',H(raw))
print('R277_CI_PRACTICAL_INSTALLED_231FL_TREE',managed_hash(old))
print('R277_CI_PROD_DATA_FILES',len(old),'MIGRATIONS',len([k for k in old if k.startswith('database/migrations/')]))
print('R277_CI_NATIVE_EXACT_SOURCE_FIXTURE_PASS')
