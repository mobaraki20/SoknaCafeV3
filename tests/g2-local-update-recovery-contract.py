#!/usr/bin/env python3
from pathlib import Path
R=Path(__file__).resolve().parents[3]
def text(p):
    q=R/p
    if not q.is_file(): raise SystemExit(f'FAIL missing {p}')
    return q.read_text(encoding='utf-8')
def need(p,n,msg):
    if n not in text(p): raise SystemExit('FAIL '+msg)
svc='apps/local-web/src/Domain/Update/LocalUpdateService.php'; center='apps/local-web/src/Domain/Update/ComponentUpdateCenterService.php'; recovery='apps/local-web/src/Domain/Recovery/RecoveryWorkspaceService.php'; stable='apps/local-web/public/local-recovery.php'; api='apps/local-web/public/system/api.php'; page='apps/local-web/public/system/index.php'; upload='apps/local-web/public/system/update-upload.php'; js='apps/local-web/public/assets/system-diagnostics.js'; setup='apps/local-web/src/Setup/BrowserSetupService.php'
for p in [svc,center,recovery,stable,api,page,upload,js,setup,'apps/local-web/resources/component-registry-v1.json','apps/local-web/resources/compatibility-v1.json','apps/local-web/resources/update-trust-v1.json','tests/local-g2-update-lifecycle-pure-selftest.php','tests/local-g2-update-lifecycle-selftest.php']: text(p)
for token in ["sokna-component-package-v1","immutable_version_conflict","createRecoveryPoint","rollback_auto","verifySignature","bad_signature","same_version_requires_repair","stable_recovery"]: need(svc,token,'local updater missing '+token)
need(svc,"(string)($manifest['component']??'')!=='local'",'updater does not restrict package to Local owner')
need(stable,'Stable, self-contained Local recovery entrypoint','stable recovery marker missing')
if "bootstrap.php" in text(stable): raise SystemExit('FAIL stable recovery depends on application bootstrap')
need(stable,'password_verify','stable recovery code verification missing');need(stable,"lkg_recovery_id",'stable recovery does not use LKG pointer')
need(center,"G3-owned",'Public G3 boundary missing')
for token in ['update_activate','update_repair','update_rollback','recovery_code_rotate','backup_create','backup_inspect','backup_restore']: need(api,token,'system API missing '+token)
need(upload,'LocalPage::requireAdmin','update upload not admin-only');need(upload,'hash_equals($expected,$candidate)','update upload CSRF missing')
for token in ['Update Center','Verify + Stage','Repair همان نسخه','Rollback به LKG','Backup / Recovery','Machine takeover']: need(page,token,'system UI missing '+token)
need(js,"/system/update-upload.php",'update upload UI not wired');need(js,"/system/recovery-upload.php",'recovery upload UI not wired')
need(setup,"'zip'",'Browser Setup does not require PHP zip after updater introduction')
reg=text('apps/local-web/resources/component-registry-v1.json')
if 'windows_services_owner' not in reg or 'g3_pending' not in reg: raise SystemExit('FAIL bundled component registry lacks owner boundaries')
print('PASS G2.3 Local Update/Recovery contract')
