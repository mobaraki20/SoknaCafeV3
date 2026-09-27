#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL '+m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')
required=[
'apps/local-web/src/Domain/Operations/OperationsWorkspaceService.php',
'apps/local-web/public/operations/index.php','apps/local-web/public/operations/api.php','apps/local-web/public/assets/operations-workspace.js',
'apps/local-web/src/Domain/Inventory/InventoryService.php','apps/local-web/src/Domain/Inventory/InventoryCountService.php',
'apps/local-web/src/Domain/Supply/SupplyService.php','apps/local-web/src/Domain/Expenses/ExpenseService.php']
for p in required:
    if not (ROOT/p).is_file(): fail('missing '+p)
for p in ['apps/local-web/public/operations/index.php','apps/local-web/public/operations/api.php']:
    t=read(p)
    if re.search(r"['\"]\s*(SELECT|INSERT|UPDATE|DELETE)\s+",t,re.I): fail('public route contains direct SQL: '+p)
api=read('apps/local-web/public/operations/api.php')
for n in ['WebAction::requireAny','WebAction::requireMutation','inventory()->createItem','inventory()->recordManualAdjustment','inventoryCounts()->start','inventoryCounts()->updateLine','inventoryCounts()->finalize','inventoryCounts()->cancel','supply()->upsertNeed','supply()->prepare','supply()->receive','expenses()->create','expenses()->reverse']:
    if n not in api: fail('operations API missing '+n)
svc=read('apps/local-web/src/Domain/Inventory/InventoryService.php')
for n in ["majorToBase($data['quantity_major']", "['waste','count_adjustment']", "['add','remove']", 'inventory_not_ready', 'request_id_required', "'source_type'=>'manual_ui'", "'idempotency_key'=>'inventory:manual:'"]:
    if n not in svc: fail('manual adjustment semantics missing '+n)
workspace=read('apps/local-web/src/Domain/Operations/OperationsWorkspaceService.php')
if "'expenses'=>$isAdmin?" not in workspace: fail('expense snapshot is not admin-filtered')
js=read('apps/local-web/public/assets/operations-workspace.js')
if 'innerHTML' in js: fail('operations UI uses innerHTML')
for n in ['X-CSRF-Token','csrf_token','crypto.randomUUID','count_update','supply_receive','expense_reverse']:
    if n not in js: fail('operations JS missing '+n)
index=read('apps/local-web/public/operations/index.php')
for n in ['ProductShell::start','data-can-ops','data-can-manage','data-can-finalize','data-can-buy']:
    if n not in index: fail('operations page missing '+n)
if 'style=' in index: fail('operations page introduced inline style island')
nav=read('apps/local-web/src/UI/ProductShell.php')
if "'/operations/'" not in nav: fail('navigation missing operations workspace')
css=read('apps/local-web/assets/scds/components.css')
if '!important' in css: fail('SCDS introduced !important debt')
print('PASS G1 Inventory/Supply/Expenses UI contract')
