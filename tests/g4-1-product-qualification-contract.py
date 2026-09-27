#!/usr/bin/env python3
from pathlib import Path
p=Path('tests/run-g4-1-product-qualification.sh');s=p.read_text()
def need(x,m):
    if x not in s: raise SystemExit('FAIL '+m)
for x in ['run-g2-product-qualification.sh','public-g3-deploy-selftest.php','g3-remote-staff-publisher-selftest.php','public-g3-emergency-update-takeover-selftest.php','g4-cross-component-parity-selftest.php','g4-cross-component-parity-contract.py','G4.1 Product Qualification: PASS']:
    need(x,'qualification runner missing '+x)
print('PASS G4.1 qualification runner contract')
