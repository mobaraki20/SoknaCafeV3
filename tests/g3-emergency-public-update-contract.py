#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')
def need(p,t,m):
    if t not in text(p): raise SystemExit('FAIL: '+m)
files=[
'apps/public/database/migrations/0003_g3_emergency_update_takeover.sql','apps/public/public/emergency.php',
'apps/public/src/Emergency/EmergencyAccessService.php','apps/public/src/Emergency/PublicUpdateService.php',
'apps/public/src/Emergency/PublicTakeoverService.php','apps/public/src/Emergency/PairingSecretStore.php',
'apps/public/src/Http/EmergencyLocalHttpAdapter.php','apps/public/src/Security/SignedLocalRequestVerifier.php',
'apps/public/tools/build-update-package.py','apps/local-web/src/Domain/PublicEdge/PublicReenrollmentService.php',
'apps/local-web/public/system/public-update-upload.php']
for f in files:
    if not (ROOT/f).is_file(): raise SystemExit('FAIL missing '+f)
need('apps/public/public/emergency.php',"require_once $root.'/src/Emergency/PublicUpdateService.php'",'Emergency console is not bootstrap-independent')
if "require_once $root.'/bootstrap.php'" in text('apps/public/public/emergency.php'): raise SystemExit('FAIL Emergency console depends on normal Public bootstrap')
for token in ["'public/emergency.php'","'src/Emergency/'","'resources/update-trust-v1.json'"]: need('apps/public/src/Emergency/PublicUpdateService.php',token,'stable emergency path not protected: '+token)
need('apps/public/src/Emergency/PublicUpdateService.php',"'component']??'')!=='public-edge'",'Public updater package component boundary missing')
need('apps/public/src/Emergency/PublicUpdateService.php',"'local_public_contract'",'Public update compatibility contract missing')
need('apps/public/src/Emergency/PublicTakeoverService.php',"revoked_at=UTC_TIMESTAMP()",'old installation is not atomically revoked')
need('apps/public/src/Emergency/PublicTakeoverService.php',"$this->secrets->put($newId,$newSecret)",'new pairing secret is not provisioned')
need('apps/public/src/Security/SignedLocalRequestVerifier.php',"'installation_revoked'",'revoked installation signatures are not rejected')
need('apps/public/src/Emergency/PairingSecretStore.php','sodium_crypto_secretbox','pairing secret is not encrypted at rest')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/v1/local/diagnostics'",'signed Public diagnostics route missing')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/v1/local/update/stage'",'Local->Public update owner route missing')
need('apps/local-web/src/Domain/System/SystemDiagnosticsService.php',"$this->publicClient->diagnostics()",'Local does not project live Public diagnostics')
need('apps/local-web/public/system/api.php',"$action==='public_emergency_code_rotate'",'Emergency code rotation missing from Local control plane')
need('apps/local-web/public/system/api.php',"$action==='public_reenroll'",'Local re-enrollment action missing')
need('apps/local-web/public/system/public-update-upload.php','stageUpdate','Local Public update upload orchestration missing')
need('apps/local-web/src/Domain/PublicEdge/PublicReenrollmentService.php',"$raw['public']['shared_secret']=$secret",'new pairing secret is not persisted locally')
need('apps/public/tools/build-update-package.py',"STABLE=('public/emergency.php','src/Emergency/'",'Public update builder does not exclude stable emergency owner')
need('apps/public/database/migrations/0003_g3_emergency_update_takeover.sql','installation_pairing_secrets','pairing secret table missing')
need('apps/public/database/migrations/0003_g3_emergency_update_takeover.sql','installation_takeovers','takeover table missing')
print('PASS G3.3 emergency/public updater contract')
