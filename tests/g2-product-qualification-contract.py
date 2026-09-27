#!/usr/bin/env python3
from pathlib import Path
import sys
p=Path('tests/run-g2-product-qualification.sh')
if not p.is_file(): raise SystemExit('FAIL missing G2 qualification runner')
s=p.read_text(encoding='utf-8')
def need(x,msg):
    if x not in s: raise SystemExit('FAIL '+msg)
need('PDO::getAvailableDrivers()', 'pdo_mysql prerequisite not enforced')
need('ZipArchive', 'PHP zip prerequisite not enforced')
need("extension_loaded(\"sodium\")", 'sodium prerequisite not enforced')
need('./tests/run-f1-real-db-qualification.sh', 'G1/F1 real DB regression is not chained')
for t in ['tests/local-g2-browser-setup-selftest.php','tests/local-g2-observability-selftest.php','tests/local-g2-update-lifecycle-selftest.php']:
    need(t,'missing G2 DB/lifecycle scenario '+t)
need('reset_db', 'clean DB isolation missing')
need('product-parity-gate.py --mode inventory', 'product parity gate missing')
need("G2 Product Qualification: PASS", 'terminal PASS marker missing')
print('PASS G2 product qualification contract')
