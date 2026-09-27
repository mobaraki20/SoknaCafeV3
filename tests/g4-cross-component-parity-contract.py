#!/usr/bin/env python3
from pathlib import Path

def read(p): return Path(p).read_text(encoding='utf-8')
def need(c,msg):
    if not c: raise SystemExit('FAIL '+msg)

kernel=read('apps/public/src/Http/PublicHttpKernel.php')
for route in ['/api/staff/realtime','/api/staff/realtime/result','/api/staff/deferred','/api/staff/deferred/result','/api/v1/local/realtime/claim','/api/v1/local/realtime/ack','/api/v1/local/deferred/claim','/api/v1/local/deferred/ack','/api/v1/local/deferred/reconcile']:
    need(route in kernel,'missing Public route '+route)
relay=read('apps/local-web/src/Domain/PublicEdge/PublicEdgeRelayService.php')
for x in ['RealtimeDispatchService','DeferredDispatchService','/api/v1/local/realtime/claim','/api/v1/local/realtime/ack','/api/v1/local/deferred/claim','/api/v1/local/deferred/ack','/api/v1/local/deferred/reconcile','public_reconcile_pending=0']:
    need(x in relay,'relay lifecycle missing '+x)
client=read('apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncClient.php')
need('function postRaw' in client and 'normalizeRawResponse' in client,'queue-aware signed client response missing')
bootloader=read('apps/local-web/bootstrap.php')
need("/src/Domain/PublicEdge/PublicEdgeRelayService.php" in bootloader,'Local bootstrap missing PublicEdgeRelayService wiring')
boot=read('apps/local-web/src/Core/Bootstrap.php')
need("'public.relay_sync'" in boot and 'publicEdgeRelay()->sync()' in boot,'runtime relay trigger missing')
for f in ['apps/local-web/src/Setup/BrowserSetupService.php','apps/local-web/tools/setup-machine.php','windows/runtime/runtime-config.example.json']:
    s=read(f);need('public.relay_sync' in s and 'public.projection_sync' in s,'runtime scheduling missing in '+f)
cap=read('apps/local-web/src/Core/Capabilities.php')
projection=read('apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php')
for local,public in [('remote_settlement','finance.settle'),('remote_supply','supply.need.defer'),('remote_subscriber_payments','subscriber.payment.defer')]:
    need(local in cap,'local remote capability missing '+local);need(local in projection and public in projection,'projection capability missing '+local+' -> '+public)
need('settlement_accounts' in projection and 'supply_groups' in projection and 'balance' in projection,'remote action context incomplete')
renderer=read('apps/public/src/Remote/RemoteStaffPageRenderer.php');js=read('apps/public/assets/scds/remote-staff.js')
for action in ['data-action="settlement"','data-action="supply"','data-action="subscriber"']:
    need(action in renderer,'remote staff form missing '+action)
for x in ['settlement.commit','supply.need.create','subscriber.payment','/api/staff/realtime','/api/staff/deferred','/api/staff/deferred/result']:
    need(x in js,'remote staff action flow missing '+x)
inv=read('apps/local-web/src/Relay/InventoryDeferredAdapter.php')
for x in ['DeferredReceiptService','replayTx','reviewTx','resolveReview','approveReviewTx','rejectReviewTx']:
    need(x in inv,'inventory deferred durable review missing '+x)
ddispatch=read('apps/local-web/src/Relay/DeferredDispatchService.php')
need("$this->inventory->dispatch($installationId,$envelope)" in ddispatch,'inventory deferred installation ownership not forwarded')
opsapi=read('apps/local-web/public/operations/api.php');opsjs=read('apps/local-web/public/assets/deferred-review-workspace.js');opsindex=read('apps/local-web/public/operations/index.php')
need('deferred_resolve' in opsapi and 'pendingReviews' in opsapi,'Local deferred review API missing')
need('renderReviews' in opsjs and 'deferred_resolve' in opsjs and 'deferred-review-workspace.js' in opsindex,'Local deferred review UI missing')
print('PASS G4.1 cross-component parity source contract')
