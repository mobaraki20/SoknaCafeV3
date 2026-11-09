#!/usr/bin/env python3
"""Rebind the mutable Emergency owner to exact final bytes. QA only."""
import hashlib, json, pathlib, sys, zipfile

SOURCE_SHA = '8bcd797b25584d122733242ab16c5b2679503f62aea4d5bca97d4458db52d913'
VERSION = '3.0.0-g3.6-workspace.35hi-safety-owner-qa'
OWNER = 'src/Update/emergency-owner-v1.json'
CANDIDATE = 'src/Update/EmergencyOwnerCandidate.php'
sha = lambda b: hashlib.sha256(b).hexdigest()

def build(source, output):
    raw = pathlib.Path(source).read_bytes()
    assert sha(raw) == SOURCE_SHA, 'Exact audited R149 source required'
    with zipfile.ZipFile(source) as z:
        assert z.testzip() is None
        names = z.namelist()
        assert len(names) == len(set(names))
        m = json.loads(z.read('manifest.json'))
        files = {r['path']: z.read('payload/' + r['path']) for r in m['files']}
        assert set(names) == {'manifest.json'} | {'payload/' + p for p in files}
        for row in m['files']:
            assert len(files[row['path']]) == row['size']
            assert sha(files[row['path']]) == row['sha256']
    original = dict(files)
    assert len({p.casefold() for p in files}) == len(files)
    assert all(not p.startswith(('/', '../')) and '/..' not in p and '\\' not in p for p in files)
    def tree(fs):
        return sha(''.join(p + '\0' + sha(b) + '\n' for p,b in sorted(fs.items())).encode())
    assert tree(files) == m['target_managed_tree_sha256']
    previous = {'version': m['version'], 'managed_tree_sha256': tree(files)}
    bases = m['compatibility']['supported_baselines']
    assert len(bases) == 29 and previous not in bases
    files['VERSION.txt'] = (VERSION + '\n').encode()
    files[OWNER] = (json.dumps({'format':'sokna-emergency-owner-v1',
        'release_version':VERSION, 'sha256':sha(files[CANDIDATE])},
        sort_keys=True, separators=(',', ':')) + '\n').encode()
    assert {p for p in files if files[p] != original[p]} == {'VERSION.txt', OWNER}
    assert all(files[p] == b for p,b in original.items() if p.endswith('.sql'))
    m['version'] = VERSION
    m['compatibility']['supported_baselines'] = bases + [previous]
    m['target_managed_tree_sha256'] = tree(files)
    m['files'] = [{'path':p,'size':len(b),'sha256':sha(b)} for p,b in sorted(files.items())]
    m['provenance'].update({'production_status':'QA_ONLY_NOT_FOR_PRODUCTION',
        'classification':'PUBLIC_OWNER_FINAL_BYTE_BINDING_QA',
        'emergency_owner_aligned':True, 'compatible_baselines':30,
        'source_r149_zip_sha256':SOURCE_SHA,
        'public_emergency_ready':'LIVE_STABLE_ENTRYPOINT_AND_AUTH_NOT_VERIFIED',
        'live_public_host':'NOT_RUN', 'source_builder':'qa/sokna-public-two-route/build-candidate.py'})
    output = pathlib.Path(output); output.parent.mkdir(parents=True, exist_ok=True)
    members = {'manifest.json':json.dumps(m,sort_keys=True,separators=(',',':')).encode()}
    members.update({'payload/'+p:b for p,b in files.items()})
    with zipfile.ZipFile(output, 'w') as z:
        for p,b in sorted(members.items()):
            info=zipfile.ZipInfo(p,(2026,11,9,0,0,0)); info.create_system=3
            info.external_attr=0o100644 << 16; info.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(info,b)
    # Mandatory final ZIP readback, not an assertion on the pre-archive map alone.
    with zipfile.ZipFile(output) as z:
        final=json.loads(z.read('manifest.json'))
        actual={r['path']:z.read('payload/'+r['path']) for r in final['files']}
        assert tree(actual)==final['target_managed_tree_sha256']
        owner=json.loads(actual[OWNER])
        assert owner['release_version']==actual['VERSION.txt'].decode().strip()==final['version']
        assert owner['sha256']==sha(actual[CANDIDATE])
        assert all(sha(actual[r['path']])==r['sha256'] and len(actual[r['path']])==r['size'] for r in final['files'])
    result={'format':'sokna-public-owner-qa-v1','zip_sha256':sha(output.read_bytes()),
        'target_tree':final['target_managed_tree_sha256'],'version':VERSION,
        'compatible_baselines':30,'changed_paths':['VERSION.txt',OWNER],
        'production_authorized':False,'physical_cafe_verified':False}
    output.with_suffix('.json').write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__ == '__main__':
    if len(sys.argv)!=3: raise SystemExit('usage: build-candidate.py exact-r149.zip candidate.zip')
    build(*sys.argv[1:])
