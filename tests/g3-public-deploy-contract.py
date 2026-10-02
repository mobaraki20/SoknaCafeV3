#!/usr/bin/env python3
from pathlib import Path

def fail(msg):
    raise SystemExit(msg)

def text(path):
    p=Path(path)
    if not p.is_file(): fail(f'missing {path}')
    return p.read_text(encoding='utf-8')

def need(path, token, msg):
    if token not in text(path): fail(msg)

root='apps/public'
kernel=f'{root}/src/Http/PublicHttpKernel.php'
index=f'{root}/public/index.php'
ht=f'{root}/public/.htaccess'
readme=f'{root}/README.md'
media=f'{root}/src/Guest/GuestMediaStore.php'
boot=f'{root}/bootstrap.php'
builder=f'{root}/tools/build-deploy-package.py'
setup_service=f'{root}/src/Setup/PublicSetupService.php'
setup_index=f'{root}/public/setup/index.php'
setup_api=f'{root}/public/setup/api.php'
setup_js=f'{root}/public/setup/setup.js'
for p in [kernel,index,ht,readme,media,boot,builder,setup_service,setup_index,setup_api,setup_js,f'{root}/config.example.php']:
    text(p)
need(index,"dirname(__DIR__)",'Public front controller does not keep component source outside document root')
need(index,"SOKNA_PUBLIC_CONFIG",'Public front controller lacks explicit config path override')
need(index,"SafeErrors::response(503, 'public_not_configured')",'missing safe unconfigured state')
need(index,"header('Location: /setup/'",'unconfigured browser request does not enter setup wizard')
need(ht,'RewriteRule ^ index.php [QSA,L]','Apache front-controller rewrite missing')
need(kernel,"$path === '/menu'",'canonical /menu route missing')
need(kernel,"'/api/guest/order'",'guest order route missing')
need(kernel,"'/api/guest/waiter'",'waiter route missing')
need(kernel,"$path === '/health'",'health route missing')
need(kernel,"'/assets/scds/guest.css'",'Guest SCDS CSS route missing')
need(kernel,"preg_match('#^/media/",'immutable media route missing')
need(kernel,'GuestCompatibilityHttpAdapter','guest writes bypass compatibility relay adapter')
need(kernel,"'media_base' => '/media'",'Guest renderer media endpoint missing')
need(kernel,"Content-Security-Policy",'Public response security headers missing')
need(media,'function publicFile(','Guest media owner lacks verified public read primitive')
need(media,"hash_file('sha256'",'Guest media public read is not hash verified')
need(readme,'apps/public/public/','hosting document-root contract missing')
need(readme,'/menu?table=<opaque-table-token>','table-token deploy flow missing')
need(readme,'/setup/','browser setup deployment flow missing')
need(setup_service,'class PublicSetupService','Public setup service missing')
for token in ['preflight(','testDatabase(','installNew(','resume(','sokna-public-install-lock-v1','initial_pairing_code','secret_encryption_key_base64']:
    need(setup_service,token,f'Public setup service missing {token}')
need(setup_index,'data-public-setup','Public setup page contract missing')
need(setup_api,"action==='install_new'",'Public setup API install owner missing')
need(setup_api,"SOKNA_PUBLIC_TRUST_PROXY_HEADERS",'Public setup proxy trust is not explicit')
need(setup_service,"$this->check('https',$secureTransport",'Public setup HTTPS preflight is not hard-required')
need(setup_js,"data-action=\"install\"",'Public setup browser flow missing install action')
if Path(f'{root}/public/config.php').exists(): fail('config.php must never live under Public document root')
if Path(f'{root}/public/storage').exists(): fail('storage must never live under Public document root')
need(boot,"/src/Http/PublicHttpKernel.php",'entry bootstrap does not load PublicHttpKernel')
need(boot,"/src/Setup/PublicSetupService.php",'entry bootstrap does not load Public setup owner')
need(boot,"/src/Http/HealthHttpAdapter.php",'entry bootstrap does not load HealthHttpAdapter')
need(builder,"'format':'sokna-public-deploy-v1'",'deploy package format missing')
need(builder,"EXCLUDED_NAMES={'.DS_Store','config.php'}",'deploy builder may include live config secret')
need(builder,"'document_root':'public'",'deploy manifest does not declare document root')
print('PASS G3.1 Public deploy/document-root contract')
