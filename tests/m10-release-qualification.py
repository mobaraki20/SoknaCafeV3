#!/usr/bin/env python3
from __future__ import annotations
import json, subprocess, sys
from pathlib import Path

R=Path(__file__).resolve().parents[1]

def need(v,m):
    if not v:
        print(m,file=sys.stderr); raise SystemExit(1)

def run_py(path,*args):
    p=subprocess.run([sys.executable,str(R/path),*args],cwd=R,text=True,capture_output=True)
    if p.returncode:
        print(p.stdout,p.stderr,file=sys.stderr); raise SystemExit(p.returncode)
    return p.stdout

# M10 is a final-architecture source/preflight gate. Real DB/Windows evidence is produced by G6 workflow.
for gate in [
    'tests/m9-packaging-gate.py',
    'tests/g5-windows-packaging-contract.py',
    'tests/g5-prerequisite-lock-selftest.py',
    'tests/g6-component-release-contract.py',
    'tests/g6-release-preflight.py',
    'tests/component-registry-gate.py',
]:
    run_py(gate)
run_py('tests/product-parity-gate.py','--mode','inventory')

# Compatibility-v2 must bind independent component-owned versions.
compat=json.loads((R/'release/compatibility-v2.json').read_text(encoding='utf-8'))
need(compat.get('format')=='sokna-release-compatibility-v2','compatibility-v2 format missing')
need(set(compat['components'])=={'local-web','public-edge','windows-runtime','print-agent','windows-services-packaging','shared-contracts'},'independent component compatibility set incomplete')
need('monolithic' in ' '.join(compat.get('rules',[])).lower(),'compatibility rules do not fence monolithic version ownership')

# Final qualification workflow must aggregate Linux/MariaDB and Windows evidence on one commit.
workflow=(R/'.github/workflows/g6-final-qualification.yml').read_text(encoding='utf-8')
for token in ['mariadb:11.4','windows-latest','run-g4-4-product-qualification.sh','run-g5-windows-packaging-qualification.sh','run-g6-component-artifact-qualification.sh','run-g6-final-qualification.sh']:
    need(token in workflow,f'G6 final workflow missing {token}')

# Deferred register must cover every PRODUCT_OPEN capability exactly once before real qualification.
reg=json.loads((R/'release/deferred-qualification-v1.json').read_text(encoding='utf-8'))
need(reg.get('format')=='sokna-deferred-qualification-v1','deferred qualification registry missing')
rows=json.loads((R/'docs/product/MASTER_CAPABILITY_MATRIX.json').read_text(encoding='utf-8'))['rows']
open_ids={r['id'] for r in rows if r.get('completion_level')=='PRODUCT_OPEN'}
covered=[]
for e in reg.get('entries',[]): covered.extend(e.get('capabilities',[]))
need(set(covered)==open_ids,'deferred qualification coverage does not equal PRODUCT_OPEN capability set')
need(len(covered)==len(set(covered)),'a PRODUCT_OPEN capability is covered by more than one deferred qualification entry')

# Automated qualification must not pretend manual/device checks passed.
uat=json.loads((R/'release/manual-uat-status.json').read_text(encoding='utf-8'))
need(uat.get('format')=='sokna-manual-uat-v1','manual UAT status contract missing')
required={'windows_clean_install','physical_thermal_printer','responsive_touch_persian_ime'}
seen={x.get('id') for x in uat.get('checks',[])}
need(required.issubset(seen),'required manual UAT checks are not declared')
for x in uat['checks']:
    need(x.get('status') in {'pending','passed','failed'},'manual UAT has invalid status')

# ADR-0004 ownership removals must remain hard-removed.
for legacy in [
    'platform/windows/setup-sokna.ps1','platform/windows/configure-apache.ps1','platform/windows/provision-local-https.ps1',
    'platform/windows/remove-owned-services.ps1','packaging/windows/scripts/deploy-seed.ps1','packaging/windows/scripts/prepare-prerequisite-bundle.ps1'
]:
    need(not (R/legacy).exists(),f'legacy deployment ownership implementation remains: {legacy}')

print('M10 revised automated release qualification: OK')
