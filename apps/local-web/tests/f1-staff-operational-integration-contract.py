#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
def fail(m): raise SystemExit('F1.5 STAFF OPERATIONAL INTEGRATION CONTRACT FAILED: '+m)
def text(p):
    q=ROOT/p
    if not q.is_file(): fail('missing '+p)
    return q.read_text(encoding='utf-8')
def need(p,n,m):
    if n not in text(p): fail(m)
inv='apps/local-web/src/Domain/Inventory/InventoryOrderService.php'
posting='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionPostingService.php'
repo='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionRepository.php'
prep='apps/local-web/src/Domain/Preparation/PreparationService.php'
printing='apps/local-web/src/Domain/Printing/PrintService.php'
orders='apps/local-web/src/Domain/Orders/OrderCommitService.php'
boot='apps/local-web/src/Core/Bootstrap.php'
for p in [inv,posting,repo,prep,printing,orders,boot,'tests/local-f1-staff-operational-integration-selftest.php','tests/local-f1-staff-operational-cost-selftest.php']: text(p)
need(inv,'accountedRecipeSnapshotTx','exact accounted inventory snapshot is not reusable')
need(inv,"'known_cost_amount'=>$knownTotal",'known cost summary missing')
need(inv,"'estimated_cost_amount'=>$estimatedTotal",'estimated cost evidence missing')
need(posting,'accountedRecipeSnapshotTx','posting re-reads current recipe instead of durable event snapshot')
need(posting,"'known_cost_amount'=>$knownCost",'document known cost persistence missing')
need(posting,"'inventory_cost_snapshot'=>$costLine",'line recipe/cost snapshot persistence missing')
need(repo,"$data['known_cost_amount']",'repository still forces known cost to zero')
need(orders,"if($command['order_context']==='table_service')$this->printing->enqueueOrderTx",'staff printing is not deferred until staff authority exists')
need(posting,"$this->printing->enqueueOrderTx((int)$order['order_id'],$actorId)",'staff printing durable intent missing')
need(printing,"LEFT JOIN staff_consumptions sc ON sc.order_id=o.id",'printing does not resolve staff consumer context')
need(printing,"'badge'=>$context==='staff_consumption'?'مصرف پرسنل':'فیش آماده‌سازی'",'staff print label missing')
need(prep,'LEFT JOIN staff_consumptions sc ON sc.order_id=o.id','preparation feed still table-only')
need(prep,"'order_context'=>(string)($order['order_context']??'table_service')",'preparation context output missing')
need(prep,"return 'مصرف پرسنل'.($name!==''?' · '.$name:'');",'preparation staff display context missing')
need('tests/local-f1-staff-operational-integration-selftest.php',"array_key_exists('table_id',$found)&&$found['table_id']===null",'staff preparation null-table assertion is not null-safe')
if 'JOIN cafe_tables t ON t.id=o.table_id' in text(prep) and 'LEFT JOIN cafe_tables' not in text(prep): fail('preparation remains inner-table-only')
print('PASS F1.5 staff operational integration contract')
