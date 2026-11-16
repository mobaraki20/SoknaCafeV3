#!/usr/bin/env python3
"""Fail-closed Public Emergency upgrade preflight for artifact release selection.

Source and candidate packages are byte-read, not extracted or installed. The
result is a release-routing gate, never an installation authorization.
"""
import argparse
import hashlib
import json
import pathlib
import re
import sys
import zipfile

SCHEMA_CONTRACT = 'append_only_exact_sha_v1'


def load(path):
    with zipfile.ZipFile(path) as arc:
        manifest = json.loads(arc.read('manifest.json'))
        if manifest.get('component') != 'public-edge':
            raise ValueError('not a Public package')
        files = {}
        for entry in manifest['files']:
            name = entry['path']
            raw = arc.read('payload/' + name)
            if len(raw) != entry['size'] or hashlib.sha256(raw).hexdigest() != entry['sha256']:
                raise ValueError('file hash mismatch: ' + name)
            if name in files:
                raise ValueError('duplicate file: ' + name)
            files[name] = raw
        if not files:
            raise ValueError('empty package')
        return manifest, files


def migrations(files):
    rows = {}
    for name, raw in files.items():
        if not name.startswith('database/migrations/'):
            continue
        if not re.fullmatch(r'database/migrations/[0-9]{4}_[a-z0-9_]+\.sql', name):
            raise ValueError('unknown migration filename: ' + name)
        rows[name] = hashlib.sha256(raw).hexdigest()
    return rows


def check(source_path, candidate_path, current_capabilities):
    source, src = load(source_path)
    target, tgt = load(candidate_path)
    orig = migrations(src)
    nexts = migrations(tgt)
    missing = sorted(set(orig)-set(nexts))
    rewritten = sorted(k for k in orig.keys() & nexts.keys() if orig[k] != nexts[k])
    new = sorted(set(nexts)-set(orig))
    src_largest = max((int(re.search(r'/([0-9]{4})_', n).group(1)) for n in orig),default=0)
    nonappend = [k for k in new if int(re.search(r'/([0-9]{4})_',k).group(1)) <= src_largest]
    expected_base=target.get('compatibility',{}).get('supported_baselines',[])
    # Includes strict schema inventory checks in the emitted release evidence.
    route='BLOCK'
    reason=''
    if missing or rewritten or nonappend:
        reason='immutable_migration_violation'
    elif not any(item.get('version')==source.get('version') and item.get('managed_tree_sha256')==source.get('target_managed_tree_sha256') for item in expected_base):
        reason='exact_source_not_supported'
    elif new and (current_capabilities.get('emergency_schema_migration_contract') != SCHEMA_CONTRACT or current_capabilities.get('emergency_migration_commit_requires_fresh_readback') is not True):
        reason='emergency_schema_mutation_not_qualified'
    else:
        # This is NOT an install-ready decision: host backup and end-to-end acceptance still required.
        route='ELIGIBLE_FOR_HOST_PREFLIGHT_ONLY'
        reason='schema_route_compatible'
    return {
        'status':route,'reason':reason,
        'source_version':source.get('version'),'target_version':target.get('version'),
        'source_zip_sha256':hashlib.sha256(pathlib.Path(source_path).read_bytes()).hexdigest(),
        'target_zip_sha256':hashlib.sha256(pathlib.Path(candidate_path).read_bytes()).hexdigest(),
        'old_migration_count':len(orig),'new_migrations':new,
        'missing_sql':missing,'rewritten_sql':rewritten,'nonappend_sql':nonappend,
        'emergency_schema_contract':current_capabilities.get('emergency_schema_migration_contract'),
        'production_install_authorized':False
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--source',required=True)
    ap.add_argument('--candidate',required=True)
    ap.add_argument('--capabilities-json',help='JSON with verified current Emergency capability fields')
    a=ap.parse_args()
    caps=json.loads(pathlib.Path(a.capabilities_json).read_text()) if a.capabilities_json else {}
    res=check(a.source,a.candidate,caps)
    print(json.dumps(res,ensure_ascii=False,indent=2))
    return 0 if res['status']=='ELIGIBLE_FOR_HOST_PREFLIGHT_ONLY' else 1


if __name__=='__main__':
    try: sys.exit(main())
    except Exception as ex:
        print(json.dumps({'status':'BLOCK','reason':'gate_error','detail':str(ex),'production_install_authorized':False}),file=sys.stderr)
        sys.exit(2)
