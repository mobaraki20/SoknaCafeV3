#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL',m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')
required=[
'apps/local-web/database/migrations/0017_g1_admin_controls.sql',
'apps/local-web/src/Domain/Admin/AdminControlException.php',
'apps/local-web/src/Domain/Admin/AdminControlService.php',
'apps/local-web/public/admin/api.php','apps/local-web/public/admin/index.php','apps/local-web/public/assets/admin-workspace.js'
]
for p in required:
    if not (ROOT/p).is_file(): fail('missing '+p)
mig=read(required[0])
for n in ['CREATE TABLE IF NOT EXISTS personnel','linked_user_id','zone_label','previous_access_token','qr_rotated_at','qr_rotated_by_user_id']:
    if n not in mig: fail('admin migration missing '+n)
svc=read('apps/local-web/src/Domain/Admin/AdminControlService.php')
for n in ['saveUser','savePersonnel','saveSettings','setModule','saveTable','bulkCreateTables','rotateQr','audit_log','self_deactivate','hasLiveSessionTx']:
    if n not in svc: fail('admin owner missing '+n)
if 'CenterIntegration' in svc or 'module.center' in svc: fail('cancelled Center integration leaked into admin owner')
api=read('apps/local-web/public/admin/api.php')
if 'WebAction::requireAny($core,[])' not in api or 'WebAction::requireMutation()' not in api: fail('admin API lacks admin/csrf gates')
if re.search(r'\b(SELECT|INSERT|UPDATE|DELETE)\b',api,re.I): fail('admin API embeds SQL instead of owner service')
for n in ['user_save','personnel_save','settings_save','module_toggle','table_save','tables_bulk_create','qr_rotate','qr_restore']:
    if n not in api: fail('admin API action missing '+n)
page=read('apps/local-web/public/admin/index.php')
for n in ['ProductShell::start','data-admin-workspace','پرسنل','میزها و QR','حساب ورود با هویت پرسنلی یکی نیست']:
    if n not in page: fail('admin page missing '+n)
if 'style=' in page or 'scds-' in page: fail('admin page introduced legacy/inline style debt')
js=read('apps/local-web/public/assets/admin-workspace.js')
if 'innerHTML' in js: fail('admin JS uses innerHTML')
for n in ['navigator.clipboard.writeText','module_toggle','personnel_save','qr_rotate']:
    if n not in js: fail('admin JS missing '+n)
boot=read('apps/local-web/src/Core/Bootstrap.php'); rootboot=read('apps/local-web/bootstrap.php')
if 'adminControls()' not in boot or 'AdminControlService' not in boot: fail('Bootstrap admin owner missing')
if 'AdminControlService.php' not in rootboot: fail('bootstrap require missing')
print('PASS G1.2b Admin Controls contract')
