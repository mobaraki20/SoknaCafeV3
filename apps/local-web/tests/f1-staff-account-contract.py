#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
def fail(m): raise SystemExit('F1.4 STAFF ACCOUNT CONTRACT FAILED: '+m)
def text(p):
    q=ROOT/p
    if not q.is_file(): fail('missing '+p)
    return q.read_text(encoding='utf-8')
def need(p,n,m):
    if n not in text(p): fail(m)

migration='apps/local-web/database/migrations/0020_f1_staff_account.sql'
repo='apps/local-web/src/Domain/StaffConsumption/StaffAccountRepository.php'
service='apps/local-web/src/Domain/StaffConsumption/StaffAccountService.php'
posting='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionPostingService.php'
boot='apps/local-web/src/Core/Bootstrap.php'
for p in [migration,repo,service,posting,boot,'tests/local-f1-staff-account-selftest.php']:
    text(p)
need(migration,'occurred_at DATETIME','ledger occurrence timestamp missing')
need(migration,'uq_f14_staff_account_consumption_type (consumption_id,entry_type)','one-entry-type-per-consumption uniqueness missing')
need(repo,"['charge','payment','waiver','charge_reversal','payment_reversal','waiver_reversal']",'ledger type boundary missing')
need(repo,'staff_account_balance_underflow','balance underflow guard missing')
need(repo,'findByIdempotencyForUpdate','ledger idempotency missing')
need(repo,'findReversalForUpdate','single reversal guard missing')
need(repo,'historyForPersonnel','account history query missing')
need(service,"public const ACCOUNT_VERSION='f1.4-v1'",'account version missing')
need(service,'chargeConsumptionTx','automatic consumption charge missing')
need(service,"'staff_account_manage'",'account management capability boundary missing')
need(service,'public function payment','payment workflow missing')
need(service,'public function waiver','waiver workflow missing')
need(service,'public function reverse','reversal workflow missing')
need(service,'staff_account_waiver_reason_required','waiver reason boundary missing')
need(service,"'staff_account.waiver_recorded'",'waiver audit missing')
need(service,"'staff_account.entry_reversed'",'reversal audit missing')
need(service,'assertAccepts','Financial Period open-period guard missing')
if 'UPDATE staff_consumptions' in text(service): fail('Staff Account must not rewrite posted consumption/benefit snapshots')
need(posting,'chargeConsumptionTx($consumptionId,$actorId,$occurredAt)','payable charge not integrated in posting transaction')
need(posting,"'staff_account_charge_id'",'posting result lacks account charge evidence')
need(boot,'staffAccountService()','Bootstrap Staff Account service accessor missing')
print('PASS F1.4 staff account contract')
