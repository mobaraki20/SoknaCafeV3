#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]

def fail(m): raise SystemExit('F1.2 STAFF BENEFIT CONTRACT FAILED: '+m)
def text(p):
    q=ROOT/p
    if not q.is_file(): fail('missing '+p)
    return q.read_text(encoding='utf-8')
def need(p,n,m):
    if n not in text(p): fail(m)

calc='apps/local-web/src/Domain/StaffConsumption/StaffBenefitCalculator.php'
svc='apps/local-web/src/Domain/StaffConsumption/StaffBenefitCalculationService.php'
mgmt='apps/local-web/src/Domain/StaffConsumption/StaffBenefitManagementService.php'
repo='apps/local-web/src/Domain/StaffConsumption/StaffBenefitRepository.php'
for p in [calc,svc,mgmt,repo,'tests/local-f1-staff-benefit-calculation-selftest.php']:
    text(p)
need(calc,"public const ALGORITHM_VERSION = 'f1.2-v1'",'algorithm version missing')
need(calc,"'runtime_override'",'runtime override precedence missing')
need(calc,"'personnel_override'",'personnel override precedence missing')
need(calc,"'policy_rule'",'policy rule resolution missing')
need(calc,"'profile_explicit_no_benefit'",'explicit no-benefit profile semantics missing')
need(calc,"'fixed_basis'=>$type === 'fixed' ? 'per_unit'",'fixed benefit basis not frozen')
need(calc,"'snapshot_sha256'",'immutable calculation snapshot hash missing')
need(svc,"'staff_benefit_manage'",'runtime override authorization missing')
need(svc,'activeProfileForPersonnelAt','dated profile resolution missing')
need(svc,'activeOverridesForPersonnelAt','dated personnel override resolution missing')
need(repo,'ORDER BY priority,id LIMIT 1','deterministic default policy selection missing')
need(mgmt,"staff_benefit.policy_saved",'policy audit missing')
need(mgmt,"staff_benefit.rule_saved",'rule audit missing')
need(mgmt,"staff_benefit.profile_assigned",'profile audit missing')
need(mgmt,"staff_benefit.override_saved",'override audit missing')
for forbidden in ['staff_account_ledger','waiver']:
    if forbidden in text(calc).lower() or forbidden in text(svc).lower(): fail(f'pre-post calculator must not own {forbidden}')
boot=text('apps/local-web/src/Core/Bootstrap.php')
for accessor in ['staffBenefitCalculator()', 'staffBenefitCalculation()', 'staffBenefitManagement()']:
    if accessor not in boot: fail('Bootstrap accessor missing: '+accessor)
print('PASS F1.2 staff benefit calculation contract')
