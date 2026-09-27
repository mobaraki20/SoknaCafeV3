#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL '+m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')
if (ROOT/'apps/local-web/src/Domain/Integrations/CenterIntegrationService.php').exists(): fail('CenterIntegrationService still exists')
active=[
'apps/local-web/bootstrap.php','apps/local-web/src/Core/Bootstrap.php','apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php',
'apps/local-web/public/integrations/index.php','apps/local-web/public/integrations/api.php','apps/local-web/public/assets/integrations-workspace.js',
'apps/local-web/tools/setup-machine.php']
for p in active:
    t=read(p).lower()
    for token in ['centerintegration','center_sync','center.user_projection','integrations.center','module.center','مرکز سکنا']:
        if token in t: fail(f'retired Center token {token!r} remains in active Local source {p}')
boot=read('apps/local-web/src/Core/Bootstrap.php')
if "'inventory.order_events'" not in boot or "'maintenance.health'" not in boot: fail('unrelated Runtime triggers were lost')
workspace=read('apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php')
if "'accommodation'" not in workspace: fail('Accommodation workspace was damaged')
setup=read('apps/local-web/tools/setup-machine.php')
if "'accommodation'=>['base_url'=>'','secret'=>'']" not in setup: fail('Accommodation setup config was damaged')
recovery=read('apps/local-web/src/Domain/Recovery/BusinessBackupService.php')
for token in ["RETIRED_TABLES=['center_projection_receipts','center_entitlement_cache']","retired_tables_skipped"]:
    if token not in recovery: fail('legacy Center backup compatibility missing '+token)
if 'center_machine_signing_secret' in recovery: fail('retired Center machine identity remains in new backup contract')
mig=read('apps/local-web/database/migrations/0018_g1_retire_sokna_center.sql')
for token in ['DROP TABLE IF EXISTS center_entitlement_cache','DROP TABLE IF EXISTS center_projection_receipts',"DELETE FROM settings WHERE setting_key='module.center.enabled'"]:
    if token not in mig: fail('retirement migration missing '+token)
print('PASS G1 Local SOKNA Center retirement contract')
