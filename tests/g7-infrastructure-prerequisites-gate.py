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

src=(R/'packaging/prerequisites/setup-ui/Program.cs').read_text(encoding='utf-8')
for token in [
    'OperationMode.Recover','SoknaApache','SoknaMariaDB','MariaDataInitialized()',
    'existing_mariadb_data_reinitialized = false','DownloadVerifiedAsync',
    'SOKNA-Prerequisites-Support','Local Web payload is explicitly out of scope',
    'ADDLOCAL=MYSQLSERVER,Client,SharedLibraries','REMOVE=DBInstance,DEVEL,HeidiSQL',
    'RightToLeftLayout = false','AutoScaleMode = AutoScaleMode.Dpi','Tahoma',
    'SOKNA", "Prerequisites", "Logs"','ShowNotice(',
    'PhpReady(','ApacheReady()','StopApacheForMaintenance()','Application.ProductVersion'
]:
    need(token in src,f'missing implementation guard: {token}')
need('password=<redacted>' in src,'MariaDB root password is not redacted in command log')
need('apps/local-web' not in src,'prerequisites helper must not copy Local Web payload')

iss=(R/'packaging/prerequisites/installer/SOKNA-Prerequisites.iss').read_text(encoding='utf-8')
need('SOKNA-Prerequisites-Setup-{#ProductVersion}' in iss,'installer output contract missing')
need('SoknaPrerequisitesSetup.exe' in iss,'installer does not launch prerequisites UI')
need('runascurrentuser' in iss,'post-install prerequisites UI launch must keep elevated token')

print('G7 infrastructure prerequisites gate: PASS')

lock=json.loads((R/'platform/windows/release-lock.json').read_text(encoding='utf-8'))
maria=next(x for x in lock['artifacts'] if x['dependency']=='mariadb')
need(maria['source_url']=='https://downloads.mariadb.org/rest-api/mariadb/11.4.12/mariadb-11.4.12-winx64.msi','MariaDB must use official REST download endpoint')
need(maria['sha256']=='4d92fb5f16c0ec8d5a9fc1efdb33a377eaa712d6bce97451e151465c3041ccac','MariaDB frozen hash drift')
version=(R/'packaging/prerequisites/VERSION.txt').read_text(encoding='utf-8').strip()
csproj=(R/'packaging/prerequisites/setup-ui/Sokna.Prerequisites.Setup.csproj').read_text(encoding='utf-8')
need(f'<Version>{version}</Version>' in csproj,'setup binary version must match package VERSION.txt')
