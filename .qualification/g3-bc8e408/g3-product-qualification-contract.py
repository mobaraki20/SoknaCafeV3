#!/usr/bin/env python3
from pathlib import Path

p=Path('tests/run-g3-product-qualification.sh')
if not p.is_file(): raise SystemExit('FAIL missing G3 qualification runner')
s=p.read_text(encoding='utf-8')
def need(x,msg):
    if x not in s: raise SystemExit('FAIL '+msg)
need('PDO::getAvailableDrivers()', 'pdo_mysql prerequisite not enforced')
need('ZipArchive', 'PHP zip prerequisite not enforced')
need('extension_loaded("sodium")', 'sodium prerequisite not enforced')
need('./tests/run-g2-product-qualification.sh', 'G2 real-environment regression is not chained')
need('tests/public-mysql-migration-selftest.php', 'Public MariaDB migration qualification missing')
for t in [
    'tests/public-g3-deploy-selftest.php',
    'tests/g3-remote-staff-publisher-selftest.php',
    'tests/public-g3-emergency-update-takeover-selftest.php',
]:
    need(t, 'missing G3 real-environment scenario '+t)
for t in [
    'tests/g3-public-deploy-contract.py',
    'tests/g3-remote-staff-publisher-contract.py',
    'tests/g3-emergency-public-update-contract.py',
    'tests/public-g3-package-selftest.py',
    'tests/public-g3-update-package-selftest.py',
]:
    need(t, 'missing G3 source/package regression '+t)
need('build-deploy-package.py', 'final deploy artifact build missing')
need('build-update-package.py', 'final update artifact build missing')
need('protected=', 'stable/protected update artifact assertion missing')
need('product-parity-gate.py --mode inventory', 'product parity gate missing')
need('G3 Product Qualification: PASS', 'terminal PASS marker missing')
print('PASS G3 product qualification contract')
