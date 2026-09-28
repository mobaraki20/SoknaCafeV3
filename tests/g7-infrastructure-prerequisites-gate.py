#!/usr/bin/env python3
import json,re,sys
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def need(v,m):
    if not v:
        print("FAIL G7 infrastructure prerequisites:",m,file=sys.stderr)
        raise SystemExit(1)

win=json.loads((R/'platform/windows/prerequisites.json').read_text(encoding='utf-8'))
need([x['id'] for x in win['items']]==['vc_runtime'],'Windows Services must not expose Local Web infrastructure prerequisites')

p=json.loads((R/'platform/windows/infrastructure-prerequisites.json').read_text(encoding='utf-8'))
need(p['format']=='sokna-infrastructure-prerequisites-v1' and p['schema_version']==1,'bad infrastructure policy')
need(p['automatic_download_allowed'] and p['automatic_install_allowed'],'helper must own automated preparation')
need(not p['local_web_payload_allowed'] and not p['database_application_provisioning_allowed'],'Local Web/database ownership leaked into prerequisites helper')
need({x['id'] for x in p['items']}=={'php','apache','mariadb'},'unexpected infrastructure dependency set')
need(p['recovery']['never_initialize_existing_mariadb_data'],'MariaDB preservation guard missing')
need(p['recovery']['reregister_windows_services_after_os_reinstall'],'Windows recovery service registration missing')
need(p['recovery']['cross_root_existing_installation_requires_explicit_resolution'],'cross-root ownership resolution guard missing')
need(p['recovery']['automatic_cross_root_migration'] is False,'cross-root migration must never be automatic')
need(p['recovery']['preflight_before_mutation'],'ownership preflight must run before mutation')
need(p['offline_artifact_import_allowed'] is True and p['offline_kit_folder_allowed'] is True,'offline artifact handoff contract missing')
need(p['download_behavior']['header_timeout_seconds'] <= 10,'download header timeout is too slow for unreachable hosts')
need(p['download_behavior']['show_actual_bytes'] and p['download_behavior']['show_transfer_rate'] and p['download_behavior']['show_eta'],'real-time download telemetry contract missing')
need(p['download_behavior']['resume_partial_downloads'],'partial download resume must remain enabled')
need(p['ports']['apache']==18080,'Apache default port drift')
need(p['ports']['apache_fallback_candidates']==[18081,18082,18083,8080,8081,8088,8000,8888],'Apache fallback port contract drift')

src=(R/'packaging/prerequisites/setup-ui/Program.cs').read_text(encoding='utf-8')
for token in [
    'OperationMode.Recover','SoknaApache','SoknaMariaDB','MariaDataInitialized()',
    'existing_mariadb_data_reinitialized = false','DownloadVerifiedAsync',
    'SOKNA-Prerequisites-Support','Local Web payload is explicitly out of scope',
    'ADDLOCAL=MYSQLSERVER,Client,SharedLibraries','REMOVE=DBInstance,DEVEL,HeidiSQL',
    'RightToLeftLayout = false','AutoScaleMode = AutoScaleMode.Dpi','Tahoma',
    'SOKNA", "Prerequisites", "Logs"','ShowNotice(',
    'PhpReady(','ApacheReady()','StopApacheForMaintenance()','Application.ProductVersion',
    'BuildArtifactSourcesBox()','SelectManualArtifactAsync(','SelectOfflineFolderAsync()',
    'DownloadArtifactResumableAsync(','ContentLength','FormatSpeed(','FormatEta(',
    'WaitAsync(TimeSpan.FromSeconds(20)','CancelAfter(TimeSpan.FromSeconds(7))',
    'EnsureApachePortAvailableWithFallback()','CanBindLoopback(','DescribePortConflict(',
    'ServerName 127.0.0.1:{ApachePort()}','LocalWebUrl()','apache_document_root','base_url = LocalWebUrl()',
    'InfrastructureOwnershipDetector.Detect(','--self-test-infrastructure-ownership','--probe-infrastructure-ownership',
    'cross_root_existing_installation_detected = false','WindowsInstaller:','ReadServiceImagePath(',
    'stale registration; executable is missing and may be safely rebound','delete SoknaApache',
    'LoadModule rewrite_module modules/mod_rewrite.so','Apache mod_rewrite پیدا نشد',
    'ApacheConfigurationReady(','Local Web configuration is incomplete; repair is required',
    'apache-error.log','ApacheLocalWebConfigReady=',
    'extension_dir = \"{extDir}\"','php_zip.dll','ValidateApachePhpRuntimeAsync(',
    'required_extensions=OK','DetectExistingInfrastructureRoot()','حالت «تعمیر نصب موجود» به‌صورت خودکار انتخاب شده است'
]:
    need(token in src,f'missing implementation guard: {token}')
need('password=<redacted>' in src,'MariaDB root password is not redacted in command log')
preflight=src.find('InfrastructureOwnershipDetector.Detect(RootPath(),Artifact("mariadb").Version)')
layout=src.find('PreparePersistentLayout();',preflight)
need(preflight>=0 and layout>preflight,'cross-root ownership detection must happen before persistent layout mutation')
need('automatic' not in src[src.find('ToUserMessage(OperationMode mode)'):src.find('internal static class Program')].lower() or 'هیچ Data' in src,'cross-root message must make non-destructive behavior explicit')
need('apps/local-web' not in src,'prerequisites helper must not copy Local Web payload')

iss=(R/'packaging/prerequisites/installer/SOKNA-Prerequisites.iss').read_text(encoding='utf-8')
need('SOKNA-Prerequisites-Setup-{#ProductVersion}' in iss,'installer output contract missing')
need('SoknaPrerequisitesSetup.exe' in iss,'installer does not launch prerequisites UI')
need('runascurrentuser' in iss,'post-install prerequisites UI launch must keep elevated token')
need('CloseApplications=yes' in iss and 'CloseApplicationsFilter=SoknaPrerequisitesSetup.exe' in iss,'installer upgrade must close the running prerequisites UI safely')
need('GetInstalledVersion' in iss and 'DisplayVersion' in iss and 'InitializeSetup' in iss,'installer upgrade detection/prompt missing')
need('ارتقا داده می‌شود' in iss,'installer upgrade UX must explicitly describe upgrade')

lock=json.loads((R/'platform/windows/release-lock.json').read_text(encoding='utf-8'))
maria=next(x for x in lock['artifacts'] if x['dependency']=='mariadb')
need(maria['source_url']=='https://downloads.mariadb.org/rest-api/mariadb/11.4.12/mariadb-11.4.12-winx64.msi','MariaDB must use official REST download endpoint')
need(maria['sha256']=='4d92fb5f16c0ec8d5a9fc1efdb33a377eaa712d6bce97451e151465c3041ccac','MariaDB frozen hash drift')
version=(R/'packaging/prerequisites/VERSION.txt').read_text(encoding='utf-8').strip()
csproj=(R/'packaging/prerequisites/setup-ui/Sokna.Prerequisites.Setup.csproj').read_text(encoding='utf-8')
need(f'<Version>{version}</Version>' in csproj,'setup binary version must match package VERSION.txt')

windows_src=(R/'packaging/windows/setup-ui/Program.cs').read_text(encoding='utf-8')
need('انتخاب فایل از کامپیوتر' in windows_src and 'SelectLocalPrerequisiteAsync()' in windows_src,'Windows Services offline VC runtime handoff missing')
need('VerifyArtifactAsync(dialog.FileName, artifact)' in windows_src,'Windows Services local prerequisite verification missing')

offline_readme=R/'packaging/offline/README_FA.md'
offline_verify=R/'packaging/offline/verify-offline-kit.ps1'
need(offline_readme.exists() and offline_verify.exists(),'Offline Kit documentation/verifier missing')
verify=offline_verify.read_text(encoding='utf-8')
for dep in ['php','apache','mariadb','vc_runtime']:
    need(dep in verify,f'Offline Kit verifier missing {dep}')
need('Get-FileHash' in verify and 'Get-AuthenticodeSignature' in verify,'Offline Kit verifier must validate hash/signature')

print('G7 infrastructure prerequisites gate: PASS')

need('ApacheReady()' in src and 'ApacheConfigurationReady(conf)' in src,'Apache Ready check does not validate Local Web configuration')
