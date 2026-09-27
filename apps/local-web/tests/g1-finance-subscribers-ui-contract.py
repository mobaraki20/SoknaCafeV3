#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL '+m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')
required=[
'apps/local-web/src/Domain/Finance/FinanceWorkspaceService.php',
'apps/local-web/src/Domain/Integrations/SubscriberAccountService.php',
'apps/local-web/public/finance/index.php','apps/local-web/public/finance/api.php','apps/local-web/public/assets/finance-workspace.js',
'apps/local-web/public/subscribers/index.php','apps/local-web/public/subscribers/api.php','apps/local-web/public/assets/subscribers-workspace.js']
for p in required:
    if not (ROOT/p).is_file(): fail('missing '+p)
for p in ['apps/local-web/public/finance/index.php','apps/local-web/public/finance/api.php','apps/local-web/public/subscribers/index.php','apps/local-web/public/subscribers/api.php']:
    t=read(p)
    if re.search(r"['\"]\s*(SELECT|INSERT|UPDATE|DELETE)\s+",t): fail('public route contains direct SQL: '+p)
api=read('apps/local-web/public/finance/api.php')
for n in ['WebAction::requireAny','WebAction::requireMutation','settlements()->setDiscount','settlements()->settle','settlements()->reverse','tax()->setEnabled','tax()->createRateVersion','tax()->createItemPolicyVersion']:
    if n not in api: fail('finance API missing '+n)
sub=read('apps/local-web/public/subscribers/api.php')
for n in ['WebAction::requireAny','WebAction::requireMutation','subscriberAccounts()->payment','subscribers()->create','subscribers()->update','subscriberAccounts()->reversePayment']:
    if n not in sub: fail('subscriber API missing '+n)
for p in ['apps/local-web/public/assets/finance-workspace.js','apps/local-web/public/assets/subscribers-workspace.js']:
    t=read(p)
    if 'innerHTML' in t: fail('server data renderer must not use innerHTML: '+p)
    if 'csrf_token' not in t or 'X-CSRF-Token' not in t: fail('mutation CSRF missing: '+p)
nav=read('apps/local-web/src/UI/ProductShell.php')
for href in ["'/finance/'","'/subscribers/'"]:
    if href not in nav: fail('navigation missing '+href)
svc=read('apps/local-web/src/Domain/Integrations/SubscriberAccountService.php')
for n in ['beginTransaction','insertLedgerTx','reverseEntryTx','subscriber:payment:','subscriber:payment-reversal:','business_date']:
    if n not in svc: fail('subscriber account semantics missing '+n)
boot=read('apps/local-web/src/Core/Bootstrap.php')
if 'financialPeriodIdentity(),$this->businessClock(),$this->subscribers()' not in boot.replace(' ',''): fail('subscriber account bootstrap must receive BusinessClock')
print('PASS G1 Finance/Subscribers UI contract')
