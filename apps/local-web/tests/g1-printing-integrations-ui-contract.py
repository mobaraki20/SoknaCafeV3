#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL '+m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')
required=[
'apps/local-web/src/Domain/Printing/PrintManagementService.php','apps/local-web/src/Domain/Printing/PrintService.php',
'apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php','apps/local-web/src/Domain/Integrations/AccommodationService.php',
'apps/local-web/public/integrations/index.php','apps/local-web/public/integrations/api.php','apps/local-web/public/assets/integrations-workspace.js']
for p in required:
    if not (ROOT/p).is_file(): fail('missing '+p)
for p in ['apps/local-web/public/integrations/index.php','apps/local-web/public/integrations/api.php']:
    t=read(p)
    if re.search(r"['\"]\s*(SELECT|INSERT|UPDATE|DELETE)\s+",t,re.I): fail('public route contains direct SQL: '+p)
api=read('apps/local-web/public/integrations/api.php')
for n in ['WebAction::requireAny','WebAction::requireMutation','accommodation()->searchReservations','accommodation()->prepare','accommodation()->attemptCharge','accommodation()->attemptVoid','printing()->createAgent','printing()->configureDestination','printing()->enqueueTest','printing()->resolveAmbiguous']:
    if n not in api: fail('integration API missing '+n)
printing=read('apps/local-web/src/Domain/Printing/PrintService.php')
for n in ['configureDestination','enqueueTest','print:test:','sokna-print-document-v2','destination_not_ready']:
    if n not in printing: fail('printing management semantics missing '+n)
for forbidden in ['Winspool','System.Printing','ServiceController','powershell','pwsh.exe']:
    if forbidden.lower() in printing.lower(): fail('PHP PrintService crossed OS/spooler boundary: '+forbidden)
accommodation=read('apps/local-web/src/Domain/Integrations/AccommodationService.php')
for n in ['searchReservations','assertCashier','reservation_code','guest_name','room_name']:
    if n not in accommodation: fail('accommodation search adapter missing '+n)
workspace=read('apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php')
if 'api_key' in workspace or "integrations.center.secret" in workspace: fail('workspace leaks integration secret configuration')
js=read('apps/local-web/public/assets/integrations-workspace.js')
if 'innerHTML' in js: fail('integrations UI uses innerHTML')
for n in ['X-CSRF-Token','csrf_token','reservation_search','print_destination_configure','print_test']:
    if n not in js: fail('integrations JS missing '+n)
nav=read('apps/local-web/src/UI/ProductShell.php')
if "'/integrations/'" not in nav: fail('navigation missing integrations workspace')
index=read('apps/local-web/public/integrations/index.php')
if 'ProductShell::start' not in index or 'data-csrf' not in index: fail('integrations page lacks canonical shell/csrf')
if 'style=' in index: fail('integrations page introduced inline style island')
print('PASS G1 Printing/Integrations UI contract')

for p in ['apps/local-web/public/integrations/index.php','apps/local-web/public/integrations/api.php','apps/local-web/public/assets/integrations-workspace.js','apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php']:
    t=read(p).lower()
    if 'center_sync' in t or 'centerintegration' in t or 'مرکز سکنا' in t: fail('retired Center integration leaked into '+p)
