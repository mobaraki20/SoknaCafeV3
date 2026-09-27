#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]

def read(rel): return (ROOT/rel).read_text(encoding='utf-8')
def check(cond,msg):
    if not cond:
        print('FAIL',msg,file=sys.stderr); raise SystemExit(1)

migration=read('apps/local-web/database/migrations/0016_g1_guest_waiter.sql')
for needle in ['CREATE TABLE IF NOT EXISTS table_session_clients','CREATE TABLE IF NOT EXISTS waiter_calls',"('orders_accepting.cafe','1')", "('public_waiter_call_enabled','0')",'UNIQUE KEY uq_g11_waiter_active_table']:
    check(needle in migration,'migration missing '+needle)

guest=read('apps/local-web/src/Domain/Orders/GuestOrderService.php')
for needle in ['pending_order_exists','itemized_settlement_active','settlement_pending','order_changed','prices_changed','table_session_clients','TaxService::calculateInvoiceLines','expected_signature','live_table_guard']:
    check(needle in guest,'guest behavior missing '+needle)
check("['pending_approval','new']" in guest,'guest mutable statuses drifted')
check("status='accounted'" in guest,'quote must use accounted base only')

waiter=read('apps/local-web/src/Domain/Orders/WaiterCallService.php')
for needle in ["public_waiter_call_enabled","waiter_call_enabled","INTERVAL 1 MINUTE","active_table_guard","client_token","status='cancelled'","registerSessionClient"]:
    check(needle in waiter,'waiter behavior missing '+needle)

gadapter=read('apps/local-web/src/Relay/GuestOrderRealtimeAdapter.php')
for kind in ['guest_order.submit','guest_order.quote','guest_order.list','guest_order.status','guest_table.context','order.edit','order.cancel']:
    check(kind in gadapter,'guest adapter missing '+kind)
wadapter=read('apps/local-web/src/Relay/WaiterCallRealtimeAdapter.php')
for kind in ['waiter_call.create','waiter_call.status','waiter_call.cancel']:
    check(kind in wadapter,'waiter adapter missing '+kind)

dispatch=read('apps/local-web/src/Relay/RealtimeDispatchService.php')
for token in ['GuestOrderRealtimeAdapter','WaiterCallRealtimeAdapter','SettlementRealtimeAdapter','PreparationRealtimeAdapter','TableDraftRealtimeAdapter']:
    check(token in dispatch,'realtime dispatcher missing '+token)

bootstrap=read('apps/local-web/src/Core/Bootstrap.php')
for method in ['guestOrders','waiterCalls','guestOrderRealtime','waiterCallRealtime','realtimeDispatch']:
    check('function '+method+'(' in bootstrap,'Bootstrap missing '+method)

# The current Public producer already emits quote although the historical shared
# wire inventory did not list it. G1 Local consumes it without mutating contracts/**.
public_rt=read('apps/public/src/Realtime/RealtimeService.php')
wire=read('contracts/local-public-realtime/wire-v1.json')
check("'guest_order.quote'" in public_rt,'current Public producer lost quote kind')
check('"guest_order.quote"' not in wire,'G1 must not silently rewrite shared historical contract')

print('PASS G1 guest/waiter Local owner contract')
