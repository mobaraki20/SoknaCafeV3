#!/usr/bin/env python3
from pathlib import Path
import re
R=Path(__file__).resolve().parents[1]
local=['guest-content/index.php','marketing/index.php','reports/index.php','notifications/index.php','integrations/index.php']
for rel in local:
    s=(R/'apps/local-web/public'/rel).read_text(encoding='utf-8')
    if 'ProductShell::start' not in s: raise SystemExit('FAIL G4 Local surface bypasses ProductShell: '+rel)
    if 'sc-' not in s: raise SystemExit('FAIL G4 Local surface lacks SCDS components: '+rel)
    if re.search(r'\sstyle\s*=',s,re.I): raise SystemExit('FAIL G4 Local surface has inline style owner: '+rel)
for rel in ['assets/scds/guest.css','assets/scds/guest.js','assets/scds/remote-staff.css','assets/scds/remote-staff.js']:
    if not (R/'apps/public'/rel).is_file(): raise SystemExit('FAIL Public SCDS asset missing: '+rel)
guest=(R/'apps/public/src/Guest/GuestPageRenderer.php').read_text(encoding='utf-8');remote=(R/'apps/public/src/Remote/RemoteStaffPageRenderer.php').read_text(encoding='utf-8')
for name,s in [('guest',guest),('remote',remote)]:
    for token in ['lang="fa"','dir="rtl"','<meta name="viewport"']:
        if token not in s: raise SystemExit(f'FAIL Public {name} RTL/responsive contract missing {token}')
if 'aria-label=' not in guest or 'role="status"' not in guest: raise SystemExit('FAIL Guest accessibility semantics missing')
if 'aria-label=' not in remote: raise SystemExit('FAIL Remote accessibility semantics missing')
for rel in ['apps/public/assets/scds/guest.js','apps/public/assets/scds/remote-staff.js','apps/local-web/public/assets/integrations-workspace.js']:
    s=(R/rel).read_text(encoding='utf-8')
    if 'innerHTML' in s: raise SystemExit('FAIL unsafe innerHTML in '+rel)
css=(R/'apps/local-web/assets/scds/components.css').read_text(encoding='utf-8')
if '.sc-print-preview{' not in css: raise SystemExit('FAIL print preview styling bypasses canonical SCDS owner')
print('PASS G4.4 Public/G4 SCDS surface contract')
