#!/usr/bin/env python3
from pathlib import Path
import sys

def text(path):
    p=Path(path)
    if not p.exists():
        print('FAIL missing',path);sys.exit(1)
    return p.read_text(encoding='utf-8')
def need(path,needle,msg):
    if needle not in text(path): print('FAIL',msg);sys.exit(1)

service='apps/local-web/src/Setup/BrowserSetupService.php'
api='apps/local-web/public/setup/api.php'
page='apps/local-web/public/setup/index.php'
js='apps/local-web/public/assets/setup-wizard.js'
app='apps/local-web/public/_app.php'
for p in [service,api,page,js,app,'apps/local-web/src/Setup/SetupException.php','tests/local-g2-browser-setup-selftest.php']: text(p)
for token in ['preflight','testDatabase','installNew','resume','finalHealth','sokna-install-lock-v3','MariaDB 11.4.x','installation.id','setup.status']:
    need(service,token,'setup service missing '+token)
need(service,"$this->writePrivate($this->lockPath()",'install lock is not explicitly persisted')
if text(service).find('finalHealth($appConfig)') > text(service).find("$this->writePrivate($this->lockPath()"):
    print('FAIL install.lock must be written only after final health');sys.exit(1)
need(api,"session_name('sokna_setup')",'setup-specific session missing')
need(api,'hash_equals','CSRF comparison missing')
need(api,"header('Cache-Control: no-store')",'setup API no-store missing')
need(page,'Content-Security-Policy','setup CSP missing')
need(page,'Local Web Setup','browser setup surface missing')
need(js,"api('test_database'",'DB test UI not wired')
need(js,"api('install_new'",'install UI not wired')
need(js,"api('resume'",'resume UI not wired')
need(app,"if(!$setup->status()['installed'])",'normal app does not require valid install lock')
for forbidden in ['shell_exec(','passthru(','system(','proc_open(','setup-machine.php']:
    if forbidden in text(api)+text(js)+text(page): print('FAIL browser setup invokes shell/legacy installer:',forbidden);sys.exit(1)
print('PASS G2.1 browser setup contract')
