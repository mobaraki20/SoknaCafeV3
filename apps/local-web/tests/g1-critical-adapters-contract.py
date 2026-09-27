#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[1]
def fail(m): print('FAIL',m,file=sys.stderr); raise SystemExit(1)
def need(cond,m):
    if not cond: fail(m)
sett=(ROOT/'src/Relay/SettlementRealtimeAdapter.php').read_text()
sub=(ROOT/'src/Relay/SubscriberPaymentDeferredAdapter.php').read_text()
disp=(ROOT/'src/Relay/DeferredDispatchService.php').read_text()
boot=(ROOT/'src/Core/Bootstrap.php').read_text()
svc=(ROOT/'src/Domain/Integrations/SubscriberService.php').read_text()
need("settlement.commit" in sett and "actor_projection_id" in sett and "$payload['request_id']=$requestId" in sett,'settlement relay adapter must bind actor and relay request id')
need("subscriber.payment" in sub and "expected_balance" in sub and "subscriber_balance_changed" in sub,'subscriber deferred conflict semantics missing')
need("closed_financial_period" in sub and "resolveReview" in sub and "deferred:subscriber-payment:" in sub,'subscriber deferred review/idempotency semantics missing')
for kind in ['supply.need.create','supply.status.prepare','supply.status.return','supply.receipt','inventory.waste','inventory.count_draft','subscriber.payment','expense.create']:
    need(kind in disp,f'deferred dispatcher missing {kind}')
need('public function balanceTx' in svc,'SubscriberService balanceTx must be reusable by deferred adapter')
for accessor in ['subscriberPaymentDeferred','deferredDispatch','settlementRealtime']:
    need(('function '+accessor) in boot,f'Bootstrap missing {accessor}')
print('PASS G1 critical adapters source contract')
