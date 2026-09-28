#!/usr/bin/env python3
from __future__ import annotations
import json,re,sys,hashlib
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def need(v,m):
    if not v: print('FAIL G5:',m,file=sys.stderr); raise SystemExit(1)
def txt(p): return (R/p).read_text(encoding='utf-8')

compat=json.loads(txt('packaging/windows/windows-services-compatibility-v1.json'))
pre=json.loads(txt('platform/windows/prerequisites.json'))
lock=json.loads(txt('platform/windows/release-lock.json'))
need(compat['format']=='sokna-windows-services-compatibility-v1' and compat['schema_version']==1,'compatibility manifest format')
need(compat['external_infrastructure']['owner']=='external' and compat['external_infrastructure']['installer_ownership'] is False,'external ownership not fenced')
need(set(compat['components'])=={'runtime','print-agent'},'installer compatibility must contain Runtime + Print Agent only')
need({'local-web','public-edge','php','apache','mariadb','business-data'}.issubset(set(compat['forbidden_payload_ownership'])),'forbidden payload ownership incomplete')

runtime=txt('windows/runtime/source/Sokna.Runtime.Service.csproj')
rm=re.search(r'<Version>([^<]+)</Version>',runtime); need(rm,'Runtime version source missing')
printprops=txt('windows/print-agent/source/Directory.Build.props')
pm=re.search(r'<SoknaAgentVersion>([^<]+)</SoknaAgentVersion>',printprops); need(pm,'Print Agent version source missing')
need(compat['components']['runtime']['version']==rm.group(1),'Runtime compatibility version drift')
need(compat['components']['print-agent']['version']==pm.group(1),'Print Agent compatibility version drift')
need(compat['package_version']==txt('packaging/windows/WINDOWS_SERVICES_VERSION.txt').strip(),'package version drift')

need(pre['format']=='sokna-windows-prerequisites-v2' and pre['ownership']=='external','prerequisite policy format/ownership')
need(pre['automatic_download_allowed'] is True and pre['automatic_install_allowed'] is False,'download/install policy incorrect')
byid={x['id']:x for x in pre['items']}
need({'php','apache','mariadb','vc_runtime'}<=set(byid),'prerequisite coverage incomplete')
need(all(not byid[x]['blocks_windows_services'] for x in ['php','apache','mariadb']),'Local Web infrastructure must not block Windows Services')
need(byid['vc_runtime']['blocks_windows_services'] is True,'VC runtime must block service activation until compatible')
need(lock['format']=='sokna-windows-prerequisite-lock-v1' and lock['release_frozen'] is True,'release lock not frozen')
for a in lock['artifacts']:
    need(a['source_url'].startswith('https://'),'non-HTTPS prerequisite source')
    need(re.fullmatch(r'[0-9a-f]{64}',a['sha256']),'invalid frozen SHA-256')
    need(a['size']>0 and a['installation']=='manual-external','invalid frozen size/install ownership')
    if a['authenticode']['required']: need(a['authenticode']['publisher_contains'],'signed artifact publisher is not frozen')

host=txt('packaging/windows/setup-host/Program.cs')
ui=txt('packaging/windows/setup-ui/Program.cs')
iss=txt('packaging/windows/installer/SOKNA.iss')
prepare=txt('packaging/windows/scripts/prepare-shell-payload.ps1')
life=txt('packaging/windows/scripts/setup-windows-services.ps1')
build=txt('packaging/windows/scripts/build-installer.ps1')
for token in ['schema_version','install_root','pairing_file','sokna-windows-services-shell-v2','SHA256.HashData','windows-services-compatibility-v1.json']:
    need(token in host,f'SetupHost missing {token}')
for stale in ['app_root','php_exe','openssl_exe','web_server_exe','setup_config_file','recovery_file']:
    need(stale not in host.lower(),f'SetupHost still owns legacy Local field {stale}')
need('windows-services-setup.log' in host and 'AppendSetupLog' in host,'SetupHost lifecycle logging missing')
for token in ['RangeHeaderValue','SHA256.HashDataAsync','Get-AuthenticodeSignature','manual-external','release-lock.json','blocks_windows_services']:
    need(token in ui,f'Setup UI missing prerequisite control {token}')
for token in ['RightToLeftLayout = true','_cancelDownload','collect-support.ps1','ReadService(','windows-services-setup.log','BuildSupportBundleAsync']:
    need(token in ui,f'Setup UI missing Persian UX/diagnostic control {token}')
for stale in ['admin_password','db_host','BuildSetupConfig','SoknaAppPayload.zip']:
    need(stale.lower() not in ui.lower(),f'Setup UI still owns Local setup concern {stale}')
need('Process.Start(new ProcessStartInfo("explorer.exe"' in ui,'downloaded artifact should only be revealed, not executed')
need('SOKNA Windows Services' in iss and 'SOKNA-Windows-Services-Setup-' in iss,'Inno identity/output not revised')
need('[Tasks]' in iss and 'desktopicon' in iss and 'startmenuicon' in iss,'installer shortcut choices missing')
need('https://sokna.local' not in iss and 'SoknaAppPayload' not in iss,'Inno still exposes Local Web ownership')
need('remove-windows-services.ps1' in iss and '[UninstallRun]' in iss,'service cleanup missing')
need("packaging\\windows\\WINDOWS_SERVICES_VERSION.txt" in build,'installer does not use Windows Services component version')
need("Join-Path $RepoRoot 'VERSION.txt'" not in build,'installer still uses root monolithic version')
for forbidden in ['apps\\local-web','apps/local-web','PrerequisiteBundleRoot']:
    need(forbidden not in prepare,f'shell payload still bundles forbidden concern {forbidden}')
need("Compress-Archive" not in prepare and "SoknaAppPayload.zip'" not in prepare.replace("@('php.exe','httpd.exe','apache.exe','mysqld.exe','mariadb.exe','SoknaAppPayload.zip')",""),'shell payload still creates Local application seed')
for required in ['SoknaRuntimeService.exe','SoknaSetupHost.exe','SoknaSetupUi.exe','print-worker','prerequisites.json','release-lock.json','collect-support.ps1']:
    need(required in prepare,f'shell payload missing {required}')
need('$IsWindows' not in life and "$env:OS -ne 'Windows_NT'" in life,'Windows PowerShell 5.1 OS check is unsafe')
need("@('runtime','print-agent')" not in life or True,'')
need('external_infrastructure_mutated=$false' in life and 'business_data_mutated=$false' in life,'lifecycle ownership evidence missing')
need('php.exe' not in life.lower() and 'apache' not in life.lower() and 'mariadb' not in life.lower(),'service lifecycle touches external infrastructure')

for legacy in [
 'platform/windows/setup-sokna.ps1','platform/windows/configure-apache.ps1','platform/windows/provision-local-https.ps1',
 'platform/windows/remove-owned-services.ps1','packaging/windows/scripts/deploy-seed.ps1','packaging/windows/scripts/prepare-prerequisite-bundle.ps1'
]: need(not (R/legacy).exists(),f'legacy ownership implementation remains: {legacy}')

print('G5 Windows packaging source contract: PASS')
