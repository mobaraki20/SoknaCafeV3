#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]

def fail(m): raise SystemExit('F1.3 STAFF CONSUMPTION POSTING CONTRACT FAILED: '+m)
def text(p):
    q=ROOT/p
    if not q.is_file(): fail('missing '+p)
    return q.read_text(encoding='utf-8')
def need(p,n,m):
    if n not in text(p): fail(m)

posting='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionPostingService.php'
repo='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionRepository.php'
foundation='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionFoundationService.php'
orders='apps/local-web/src/Domain/Orders/OrderCommitService.php'
catalog='apps/local-web/src/Domain/Orders/OrderCatalogService.php'
for p in [posting,repo,foundation,orders,catalog,'tests/local-f1-staff-consumption-posting-selftest.php']:
    text(p)
need(posting,"public const POSTING_VERSION='f1.3-v1'",'posting version missing')
need(posting,'selfPostingIdentityTx','self posting identity boundary missing')
need(posting,'proxyPostingIdentityTx','proxy posting identity boundary missing')
need(posting,"'order_context'=>'staff_consumption'",'non-table canonical order projection missing')
need(posting,"'table_id'=>null,'session_id'=>null",'fake table/session guard missing')
need(posting,'findByClientTokenForUpdate','idempotency lookup missing')
need(posting,"'idempotency_conflict'",'idempotency ownership conflict missing')
need(posting,"'staff_consumption.posted'",'posting audit missing')
need(posting,"'consumer_personnel_id'",'consumer identity snapshot missing')
need(posting,"'recorded_by_user_id'",'recorder identity snapshot missing')
need(posting,"'zero_payable'=>$payable===0",'zero payable result semantics missing')
need(repo,"VALUES(?,?,?,?,?,?,?,?,'posted',?,?,?,?,?,?,?,?,?,?)",'staff consumption document insert placeholder count drifted')
if 'INSERT INTO staff_account_ledger' in text(posting): fail('F1.3 must not post Staff Account ledger before F1.4')
need(orders,"'table_service','staff_consumption'",'Order owner is not context aware')
need(orders,"$table===null?null:(int)$table['id']",'Order persistence cannot write non-table context')
need(orders,"(string)$existing['order_context']!==$command['order_context']",'Order idempotency does not fence context')
need(catalog,"'category_id'=>(int)$item['category_id']",'catalog snapshot lacks benefit category identity')
need(foundation,'lockActiveByLinkedUserIdTx','self consumer is not locked in posting transaction')
need(foundation,'lockActiveByIdTx','proxy consumer is not locked in posting transaction')
boot=text('apps/local-web/src/Core/Bootstrap.php')
for accessor in ['staffConsumptionRepository()', 'staffConsumptionPosting()']:
    if accessor not in boot: fail('Bootstrap accessor missing: '+accessor)
print('PASS F1.3 staff consumption posting contract')
