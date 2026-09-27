#!/usr/bin/env python3
from pathlib import Path
p=Path('tests/run-g4-2-product-qualification.sh')
s=p.read_text(encoding='utf-8')
def need(x,m):
    if x not in s: raise SystemExit('FAIL '+m)
for x in [
    'run-g4-1-product-qualification.sh',
    'g4-2-guest-content-platform-selftest.php',
    'g4-2-guest-content-platform-contract.py',
    'g4-2-theme-package-selftest.php',
    'MariaDB 11.4.x',
    'pdo_mysql',
    'fileinfo',
    'G4.2 Product Qualification: PASS',
]: need(x,'qualification runner missing '+x)
selftest=Path('tests/g4-2-guest-content-platform-selftest.php').read_text(encoding='utf-8')
for x in [
    '0023_g4_guest_content_platform',
    'saveThemeDraft', 'saveCopyDraft', 'publishDraft',
    'importUpload', 'assignMediaToItem', 'archiveMedia', 'garbageCollect',
    'PublicEdgePublisherService', '/media/', '/theme/', '/menu',
    'Public media store accepted SVG replica',
]:
    if x not in selftest: raise SystemExit('FAIL G4.2 real-env selftest missing '+x)
print('PASS G4.2 qualification runner contract')
