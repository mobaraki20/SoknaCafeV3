#!/usr/bin/env python3
import hashlib,json,re,sys
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def need(v,m):
    if not v: print('FAIL G5 prerequisite:',m,file=sys.stderr); raise SystemExit(1)
p=json.loads((R/'platform/windows/prerequisites.json').read_text(encoding='utf-8'))
l=json.loads((R/'platform/windows/release-lock.json').read_text(encoding='utf-8'))
c=json.loads((R/'platform/windows/provider-candidate.json').read_text(encoding='utf-8'))
need(l['source_candidate_sha256']==hashlib.sha256((R/'platform/windows/provider-candidate.json').read_bytes()).hexdigest(),'release lock no longer binds provider candidate')
locked={a['dependency']:a for a in l['artifacts']}
for item in p['items']:
    dep=item['release_lock_dependency']; need(dep in locked,f'no frozen artifact for {dep}')
    a=locked[dep]; need(re.fullmatch(r'[0-9a-f]{64}',a['sha256']) is not None,f'bad hash {dep}'); need(a['size']>0,f'bad size {dep}')
need(c['policy']['automatic_install'] is False and c['policy']['shared_dependency_owner']=='external','candidate ownership drift')
print('G5 prerequisite lock pure self-test: PASS')
