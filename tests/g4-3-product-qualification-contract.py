#!/usr/bin/env python3
from pathlib import Path
s=Path('tests/run-g4-3-product-qualification.sh').read_text(encoding='utf-8')
for x in ['run-g4-2-product-qualification.sh','g4-3-pure-selftest.php','g4-3-business-extensions-selftest.php','g4-3-business-extensions-contract.py','MariaDB 11.4.x','pdo_mysql','notifications.outbox','G4.3 Product Qualification: PASS']:
    if x not in s: raise SystemExit('FAIL G4.3 qualification runner missing '+x)
t=Path('tests/g4-3-business-extensions-selftest.php').read_text(encoding='utf-8')
for x in ['0024_g4_business_extensions','saveCampaign','saveEvent','publicFeed','report(','range_too_large','compose','processPending','registerPush','remoteModels']:
    if x not in t: raise SystemExit('FAIL G4.3 selftest missing '+x)
print('PASS G4.3 qualification runner contract')
