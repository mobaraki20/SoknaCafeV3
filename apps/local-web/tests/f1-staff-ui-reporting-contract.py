#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
def fail(m): raise SystemExit('F1.6 STAFF UI/REPORTING CONTRACT FAILED: '+m)
def text(p):
    q=ROOT/p
    if not q.is_file(): fail('missing '+p)
    return q.read_text(encoding='utf-8')
def need(p,n,m):
    if n not in text(p): fail(m)
page='apps/local-web/public/staff-consumption/index.php'
api='apps/local-web/public/staff-consumption/api.php'
js='apps/local-web/public/assets/staff-consumption-workspace.js'
workspace='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionWorkspaceService.php'
report='apps/local-web/src/Domain/StaffConsumption/StaffConsumptionReportService.php'
nav='apps/local-web/src/UI/ProductShell.php'
boot='apps/local-web/src/Core/Bootstrap.php'
entry_boot='apps/local-web/bootstrap.php'
migration='apps/local-web/database/migrations/0021_f1_staff_consumption_reporting.sql'
for p in [page,api,js,workspace,report,nav,boot,entry_boot,migration,'tests/local-f1-staff-ui-reporting-selftest.php']: text(p)
need(nav,"'staff-consumption','label'=>'مصرف پرسنل','href'=>'/staff-consumption/'",'staff consumption is not a distinct navigation entry')
for token in ['مصرف من','ثبت برای پرسنل','سوابق','مزایا','حساب پرسنل','گزارش']:
    need(page,token,'missing UI entry point: '+token)
for action in ['quote_self','quote_proxy','post_self','post_proxy','policy_save','rule_save','profile_save','override_save','account_payment','account_waiver','account_reverse']:
    need(api,"$action==='"+action+"'",'missing API action '+action)
need(api,"$action==='report'",'report API missing')
need(workspace,'consumer_name_snapshot','history does not preserve consumer identity')
need(workspace,'recorder_name','history does not preserve recorder identity')
need(workspace,"'benefits'=>$access['staff_benefit_manage']",'benefit management is not permission gated')
need(report,'staff_consumption_reports','report capability boundary missing')
for col in ['menu_value_amount','benefit_amount','payable_amount','known_cost_amount']:
    need(report,col,'report missing '+col)
need(report,"entry_type IN('waiver','waiver_reversal')",'waiver is not sourced from Staff Account ledger')
need(report,'recorder_name','recorder breakdown missing')
need(report,'consumer_name','consumer breakdown missing')
need(report,"if($days>365)",'report range is not bounded to 366 days')
need(migration,'idx_f16_staff_consumption_report','consumption reporting index missing')
need(migration,'idx_f16_staff_account_report','staff account reporting index missing')
need(js,"'post_self':'post_proxy'",'self/proxy consumption UI not wired')
need(js,"'post_self':'post_proxy'",'self/proxy consumption UI not wired')
need(js,"'account_payment':'account_waiver'",'waiver UI not wired separately')
need(js,'data-report-metric','report metric renderer missing')
need(js,'initialTab','initial permission-driven tab activation missing')
need(entry_boot,"/src/Domain/StaffConsumption/StaffConsumptionWorkspaceService.php",'entry bootstrap does not load StaffConsumptionWorkspaceService')
need(entry_boot,"/src/Domain/StaffConsumption/StaffConsumptionReportService.php",'entry bootstrap does not load StaffConsumptionReportService')
if 'subscriber_ledger' in text(report) or 'subscriber_ledger' in text(workspace): fail('Staff Consumption UI/reporting depends on Subscriber Ledger')
print('PASS F1.6 staff UI/reporting contract')
