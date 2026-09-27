#!/usr/bin/env python3
from pathlib import Path
s=Path('tests/run-g4-4-product-qualification.sh').read_text(encoding='utf-8')
for x in ['run-g4-3-product-qualification.sh','g4-4-print-template-pure-selftest.php','g4-4-print-template-selftest.php','g4-4-print-template-contract.py','g4-4-scds-surface-contract.py','scds-m6-gate.py','MariaDB 11.4.x','pdo_mysql','G4.4 Product Qualification: PASS']:
    if x not in s: raise SystemExit('FAIL G4.4 qualification runner missing '+x)
t=Path('tests/g4-4-print-template-selftest.php').read_text(encoding='utf-8')
for x in ['0025_g4_print_template_packages','printTemplates()->import','printTemplates()->activate','enqueueTest','print.preview','print_template_version_conflict','bridge_pairing_id']:
    if x not in t: raise SystemExit('FAIL G4.4 DB selftest missing '+x)
print('PASS G4.4 qualification runner contract')
