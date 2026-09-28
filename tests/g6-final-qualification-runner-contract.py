#!/usr/bin/env python3
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def need(v,m):
 if not v: raise SystemExit('G6 FINAL RUNNER CONTRACT FAILED: '+m)
for f in ['tests/run-g6-component-artifact-qualification.sh','tests/run-g6-final-qualification.sh','tests/capture-qualification-evidence.py','tests/g6-evidence-aggregate.py','.github/workflows/g6-final-qualification.yml']:
 need((R/f).is_file(),f+' missing')
s=(R/'tests/g6-evidence-aggregate.py').read_text();need('G6 Final Product Qualification: PASS' in s,'final terminal missing');need("e.get('source_head')!=a.source_head" in s,'same-head evidence fence missing')
r=(R/'release/deferred-qualification-v1.json').read_text();need('DQ-G4.1' in r and 'DQ-G5' in r and 'DQ-G6' in r,'deferred qualification chain incomplete')
print('PASS G6 final qualification runner contract')
