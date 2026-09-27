#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[3]
def fail(m): print('FAIL '+m,file=sys.stderr); raise SystemExit(1)
def read(p): return (ROOT/p).read_text(encoding='utf-8')

# Product/runtime owner must not exist.
if (ROOT/'apps/local-web/src/Domain/Integrations/CenterIntegrationService.php').exists():
    fail('CenterIntegrationService still exists')

# Fresh schema must never create Center tables/settings.
m13=read('apps/local-web/database/migrations/0013_m5_integrations.sql').lower()
for token in ['center_projection_receipts','center_entitlement_cache','module.center.enabled','sokna center']:
    if token in m13: fail(f'Center schema/config token remains in fresh migration: {token}')
if (ROOT/'apps/local-web/database/migrations/0018_g1_retire_sokna_center.sql').exists():
    fail('retirement migration must not exist in pre-operational clean schema')

# Active Local source/config has no Center integration.
active=[
'apps/local-web/bootstrap.php','apps/local-web/src/Core/Bootstrap.php',
'apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php',
'apps/local-web/public/integrations/index.php','apps/local-web/public/integrations/api.php',
'apps/local-web/public/assets/integrations-workspace.js','apps/local-web/tools/setup-machine.php',
'apps/local-web/src/Domain/Recovery/BusinessBackupService.php'
]
for p in active:
    t=read(p).lower()
    for token in ['centerintegration','center_sync','center.user_projection','integrations.center','module.center','center_projection_receipts','center_entitlement_cache','مرکز سکنا']:
        if token in t: fail(f'Center token {token!r} remains in active Local source {p}')

# Unrelated integrations/runtime behavior must remain.
boot=read('apps/local-web/src/Core/Bootstrap.php')
if "'inventory.order_events'" not in boot or "'maintenance.health'" not in boot:
    fail('unrelated Runtime triggers were lost')
workspace=read('apps/local-web/src/Domain/Integrations/IntegrationWorkspaceService.php')
if "'accommodation'" not in workspace:
    fail('Accommodation workspace was damaged')
setup=read('apps/local-web/tools/setup-machine.php')
if "'accommodation'=>['base_url'=>'','secret'=>'']" not in setup:
    fail('Accommodation setup config was damaged')

print('PASS G1 Local SOKNA Center hard-removal contract')
