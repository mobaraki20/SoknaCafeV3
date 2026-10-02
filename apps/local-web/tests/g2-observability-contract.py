#!/usr/bin/env python3
from pathlib import Path

def text(p): return Path(p).read_text(encoding='utf-8')
def need(p,s,msg):
    if s not in text(p): raise SystemExit(msg)

svc='apps/local-web/src/Domain/System/SystemDiagnosticsService.php'
bundle='apps/local-web/src/Domain/System/SupportBundleWriter.php'
page='apps/local-web/public/system/index.php'
api='apps/local-web/public/system/api.php'
dl='apps/local-web/public/system/download.php'
js='apps/local-web/public/assets/system-diagnostics.js'
boot='apps/local-web/src/Core/Bootstrap.php'
entry='apps/local-web/bootstrap.php'
nav='apps/local-web/src/UI/ProductShell.php'
for p in [svc,bundle,page,api,dl,js,boot,entry,nav,'tests/local-g2-support-bundle-pure-selftest.php','tests/local-g2-observability-selftest.php']: text(p)
need(nav,"['id'=>'system','label'=>'وضعیت سیستم','href'=>'/system/']",'system diagnostics navigation missing')
need(page,'LocalPage::requireAdmin($core)','system page is not admin-only')
need(api,'WebAction::requireAny($core,[])','system API admin boundary missing')
need(dl,'LocalPage::requireAdmin($core)','support download is not admin-only')
need(bundle,"'format'=>self::FORMAT",'support bundle format missing')
need(bundle,"$this->observability->redact($bundleSnapshot)",'support bundle snapshot is not redacted')
need(bundle,'recentLogs(self::MAX_LOG_LINES)','support logs are not bounded')
need(bundle,'MAX_BUNDLES=5','support bundle retention is not bounded')
need(bundle,"preg_match('/^support-\\d{8}-\\d{6}-[a-f0-9]{8}$/D'",'support bundle path traversal guard missing')
need(svc,"'local_web'=>$local,'database'=>$database,'runtime'=>$runtime,'print_agent'=>$print,'public_edge'=>$public",'unified component snapshot missing')
need(svc,"'productization'=>'G3.3_LIVE_HEALTH_EMERGENCY'",'Public live-health projection phase missing')
need(svc,"'base_origin'=>$base",'Public status does not use origin-only projection')
need(svc,"$this->publicClient->diagnostics()",'G3.3 signed Public diagnostics projection missing')
need(svc,"PDO::getAvailableDrivers()",'PDO MySQL health check missing')
need(svc,"$this->migrations->appliedVersions()",'migration drift health check missing')
need(svc,'RuntimeEvidence::snapshot','Runtime receipt evidence owner missing')
need(svc,'RuntimeEvidence::combine','Runtime receipt/health evidence combination missing')
need(svc,'heartbeat_age_seconds','Print heartbeat health missing')
need(js,"action:'support_bundle'",'support bundle UI action missing')
need(entry,"/src/Domain/System/SupportBundleWriter.php",'entry bootstrap support bundle require missing')
need(entry,"/src/Domain/System/SystemDiagnosticsService.php",'entry bootstrap diagnostics require missing')
print('PASS G2.2 observability/support contract')
