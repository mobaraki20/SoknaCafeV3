from pathlib import Path
import json,re,sys,csv
R=Path(__file__).resolve().parents[1]

def fail(msg):
    print(msg,file=sys.stderr); raise SystemExit(1)

# Active source/config/package paths must never contain retired Center/Core integration tokens.
roots=[R/'apps',R/'windows',R/'packaging',R/'contracts']
allow={
    R/'apps/local-web/tests/g1-center-retirement-contract.py',
    R/'apps/local-web/tests/g1-admin-controls-contract.py',
    R/'apps/local-web/tests/g1-printing-integrations-ui-contract.py',
    R/'contracts/runtime-api/historical-audit-v1.json',
}
patterns=[
    re.compile(r'centerintegration',re.I),
    re.compile(r'center_projection',re.I),
    re.compile(r'center_entitlement',re.I),
    re.compile(r'center\.user_projection',re.I),
    re.compile(r'center_machine_signing_secret',re.I),
    re.compile(r'module\.center',re.I),
    re.compile(r'sokna[ _-]?center',re.I),
    re.compile(r'sokna[ _-]?core',re.I),
]
for root in roots:
    for p in root.rglob('*'):
        if not p.is_file() or '.git' in p.parts or p in allow: continue
        if p.suffix.lower() not in {'.php','.py','.json','.md','.cs','.ps1','.iss','.yml','.yaml','.txt','.template','.sql','.js'}: continue
        t=p.read_text(encoding='utf-8',errors='ignore')
        for pat in patterns:
            if pat.search(t): fail(f'retired Center/Core token {pat.pattern!r} remains in active path {p.relative_to(R)}')

# Historical audit may mention the retired worker only in an explicit retired bucket.
h=json.loads((R/'contracts/runtime-api/historical-audit-v1.json').read_text(encoding='utf-8'))
if 'center_projection' in h.get('historical_worker_registry',[]): fail('center_projection remains in active historical worker registry')
if h.get('retired_historical_workers')!=['center_projection']: fail('historical Center worker is not explicitly classified retired')

# Runtime example must not schedule it.
runtime=json.loads((R/'windows/runtime/runtime-config.example.json').read_text(encoding='utf-8'))
if any(x.get('key')=='center.user_projection' for x in runtime.get('triggers',[])): fail('runtime example still schedules Center projection')

# Recovery identities must be exactly current machine-bound owners.
mandatory={'runtime_machine_secret','print_agent_identity','tls_private_key'}
life=(R/'packaging/tools/lifecycle.py').read_text(encoding='utf-8')
for x in mandatory:
    if x not in life: fail('recovery lifecycle missing '+x)
if 'center_machine_signing_secret' in life: fail('retired Center secret remains in lifecycle validator')

# Migration matrix must record retirement, not an open migration target.
with (R/'docs/migration/MIGRATION_MATRIX.csv').open(encoding='utf-8') as f:
    row=next((x for x in csv.DictReader(f) if x['legacy_scope']=='SOKNA Center integration'),None)
if not row or row['completion_level']!='SUPERSEDED' or row['status']!='migrated': fail('migration matrix did not close Center as superseded')

m=json.loads((R/'docs/product/MASTER_CAPABILITY_MATRIX.json').read_text(encoding='utf-8'))
a32=next((x for x in m['rows'] if x['id']=='A32'),None)
if not a32 or a32['completion_level']!='SUPERSEDED' or a32['required_for_product']!='no' or a32['required_for_release']!='no': fail('A32 is not closed as superseded/non-required')

print('R1 SOKNA Center/Core hard-removal gate: OK')
