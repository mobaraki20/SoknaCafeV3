#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL',m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')
required=[
'apps/local-web/src/UI/WebAction.php',
'apps/local-web/src/Domain/Orders/OrderWorkspaceService.php',
'apps/local-web/src/Domain/Orders/OrderStaffActionService.php',
'apps/local-web/src/Domain/Orders/WaiterCallStaffService.php',
'apps/local-web/public/operator/api.php','apps/local-web/public/staff/api.php','apps/local-web/public/waiter/api.php',
'apps/local-web/public/operator/index.php','apps/local-web/public/staff/index.php','apps/local-web/public/waiter/index.php',
'apps/local-web/public/assets/operator-workspace.js','apps/local-web/public/assets/staff-workspace.js','apps/local-web/public/assets/preparation-workspace.js'
]
for p in required:
    if not (ROOT/p).is_file(): fail('missing '+p)
wa=read('apps/local-web/src/UI/WebAction.php')
for n in ['hash_equals','random_bytes','requireMutation','csrf_expired']:
    if n not in wa: fail('web action missing '+n)
for p in ['apps/local-web/public/operator/api.php','apps/local-web/public/staff/api.php','apps/local-web/public/waiter/api.php']:
    s=read(p)
    if 'WebAction::requireMutation()' not in s: fail(p+' mutation lacks CSRF gate')
    if re.search(r'\b(SELECT|INSERT|UPDATE|DELETE)\b',s,re.I): fail(p+' embeds business SQL')
operator=read('apps/local-web/public/operator/api.php')
for n in ['orderStaffActions()->changeStatus','waiterCallStaff()->changeStatus','orderWorkspace()->operatorSnapshot']:
    if n not in operator: fail('operator API missing owner '+n)
staff=read('apps/local-web/public/staff/api.php')
for n in ['orderWorkspace()->staffWorkspace','tableDrafts()->get','tableDrafts()->save','tableDrafts()->finalize','tableDrafts()->cancel','staffQuickOrders()->commit']:
    if n not in staff: fail('staff API missing owner '+n)
waiter=read('apps/local-web/public/waiter/api.php')
for n in ['preparation()->feed','preparation()->claim']:
    if n not in waiter: fail('preparation API missing owner '+n)
prep=read('apps/local-web/src/Domain/Preparation/PreparationAccessService.php')
if 'admin role alone is never an operational grant' not in prep or "(!$isAdmin&&$hasPreparation)" not in prep: fail('frozen preparation authorization drifted')
for p in ['apps/local-web/public/operator/index.php','apps/local-web/public/staff/index.php','apps/local-web/public/waiter/index.php']:
    s=read(p)
    if 'ProductShell::start' not in s or 'data-csrf' not in s: fail(p+' does not consume canonical shell/csrf')
    if 'style=' in s: fail(p+' introduced inline style island')
css=read('apps/local-web/assets/scds/components.css')
if '!important' in css: fail('SCDS introduced !important debt')
for p in ['apps/local-web/public/assets/operator-workspace.js','apps/local-web/public/assets/staff-workspace.js','apps/local-web/public/assets/preparation-workspace.js']:
    s=read(p)
    if 'innerHTML' in s: fail(p+' uses innerHTML')
print('PASS G1 Orders/Preparation UI contract')
