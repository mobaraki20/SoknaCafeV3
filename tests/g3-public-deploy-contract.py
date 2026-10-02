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
setup=f'{root}/public/setup.php'
setup_pair=f'{root}/public/setup-pair.php'
setup_service=f'{root}/src/Setup/PublicSetupService.php'
pair_service=f'{root}/src/Setup/PublicInitialPairingService.php'
for p in [kernel,index,ht,readme,media,boot,builder,setup,setup_pair,setup_service,pair_service,f'{root}/config.example.php']:
    text(p)
need(index,"dirname(__DIR__)",'Public front controller does not keep component source outside document root')
need(index,"SOKNA_PUBLIC_CONFIG",'Public front controller lacks explicit config path override')
need(index,"SafeErrors::response(503, 'public_not_configured')",'missing safe unconfigured state')
need(index,"header('Location: /setup.php')",'unconfigured Public root does not route to browser setup')
need(setup,"PublicSetupService",'Public browser setup surface missing')
need(setup,"pairing_code",'Public setup does not display one-time pairing handoff')
need(setup_pair,"PublicInitialPairingService",'Public initial pairing endpoint missing')
need(pair_service,"password_hash($code",'initial pairing code is not stored as a hash')
need(pair_service,"MAX_ATTEMPTS = 8",'initial pairing brute-force bound missing')
need(pair_service,"expires_at",'initial pairing expiry missing')
need(pair_service,"PairingSecretStore",'initial pairing does not use encrypted pairing-secret owner')
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
if Path(f'{root}/public/config.php').exists(): fail('config.php must never live under Public document root')
if Path(f'{root}/public/storage').exists(): fail('storage must never live under Public document root')
need(boot,"/src/Http/PublicHttpKernel.php",'entry bootstrap does not load PublicHttpKernel')
need(boot,"/src/Http/HealthHttpAdapter.php",'entry bootstrap does not load HealthHttpAdapter')
need(boot,"/src/Setup/PublicSetupService.php",'entry bootstrap does not load PublicSetupService')
need(boot,"/src/Setup/PublicInitialPairingService.php",'entry bootstrap does not load PublicInitialPairingService')
need(builder,"'format':'sokna-public-deploy-v1'",'deploy package format missing')
need(builder,"EXCLUDED_NAMES={'.DS_Store','config.php'}",'deploy builder may include live config secret')
need(builder,"'document_root':'public'",'deploy manifest does not declare document root')
print('PASS G3.1 Public deploy/document-root contract')
