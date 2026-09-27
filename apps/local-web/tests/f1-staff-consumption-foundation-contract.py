#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
LOCAL = ROOT / 'apps' / 'local-web'

def fail(msg: str) -> None:
    raise SystemExit('F1.1 STAFF CONSUMPTION FOUNDATION CONTRACT FAILED: ' + msg)

def text(path: str) -> str:
    p = ROOT / path
    if not p.is_file(): fail(f'missing {path}')
    return p.read_text(encoding='utf-8')

def need(path: str, needle: str, msg: str) -> None:
    if needle not in text(path): fail(msg)

migration = 'apps/local-web/database/migrations/0019_f1_staff_consumption_foundation.sql'
for table in [
    'staff_benefit_policies','staff_benefit_policy_rules','staff_benefit_profiles',
    'staff_benefit_overrides','staff_consumptions','staff_consumption_lines','staff_account_ledger'
]:
    need(migration, f'CREATE TABLE IF NOT EXISTS {table}', f'{table} authority missing')
need(migration, "order_context VARCHAR(32) NOT NULL DEFAULT 'table_service'", 'explicit order context missing')
need(migration, "order_context='staff_consumption' AND table_id IS NULL AND session_id IS NULL", 'non-table order invariant missing')
need(migration, 'consumer_personnel_id INT UNSIGNED NOT NULL', 'consumer personnel identity missing')
need(migration, 'recorded_by_user_id INT UNSIGNED NOT NULL', 'recorder user identity missing')
need(migration, "entry_type IN ('charge','payment','waiver','charge_reversal','payment_reversal','waiver_reversal','adjustment')", 'dedicated staff ledger semantics missing')
need(migration, 'benefit_amount+discount_amount<=menu_value_amount', 'benefit/payable accounting invariant missing')

caps = text('apps/local-web/src/Core/Capabilities.php')
for cap in ['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']:
    if f"'{cap}'" not in caps: fail(f'capability {cap} missing')

foundation = text('apps/local-web/src/Domain/StaffConsumption/StaffConsumptionFoundationService.php')
need('apps/local-web/src/Domain/StaffConsumption/StaffConsumptionFoundationService.php', "'staff_consumption_self'", 'self permission boundary missing')
need('apps/local-web/src/Domain/StaffConsumption/StaffConsumptionFoundationService.php', "'staff_consumption_proxy'", 'proxy permission boundary missing')
need('apps/local-web/src/Domain/StaffConsumption/StaffConsumptionFoundationService.php', 'findActiveByLinkedUserId', 'self flow is not linked through Personnel identity')
if 'cafe_tables' in foundation or 'table_sessions' in foundation:
    fail('foundation service must not create or depend on fake table/session identity')

personnel = text('apps/local-web/src/Domain/StaffConsumption/PersonnelRepository.php')
if 'linked_user_id=?' not in personnel or 'archived_at IS NULL' not in personnel:
    fail('Personnel repository does not preserve optional-login active identity semantics')

bootstrap = text('apps/local-web/src/Core/Bootstrap.php')
for accessor in ['personnel()', 'staffBenefits()', 'staffAccounts()', 'staffConsumptionFoundation()']:
    if accessor not in bootstrap: fail(f'Bootstrap accessor missing: {accessor}')

print('PASS F1.1 staff consumption foundation contract')
