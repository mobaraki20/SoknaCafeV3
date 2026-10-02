from pathlib import Path
import json,re,sys
ROOT=Path(__file__).resolve().parents[1]
def fail(m): print(m,file=sys.stderr); raise SystemExit(1)
def need(path,*terms):
    text=(ROOT/path).read_text(encoding='utf-8')
    for term in terms:
        if term not in text: fail(f'{path}: missing {term}')
    return text
mig=need('apps/local-web/database/migrations/0023_g4_guest_content_platform.sql','guest_content_config','guest_media_assets','guest_media_derivatives','guest_media_references','published_revision')
service=need('apps/local-web/src/Domain/GuestContent/GuestContentService.php','saveThemeDraft','saveCopyDraft','publishDraft','importUpload','assignMediaToItem','garbageCollect','publicMediaForSource','mediaPayloads','gd-center-crop-640-webp84',"'extension'=>'webp'","'width'=>$target","'height'=>$target")
manager=need('apps/local-web/src/Domain/GuestContent/ThemePackageManager.php','sokna-guest-theme-v1','theme_package_forbidden_file','editable_tokens','fingerprint')
need('apps/local-web/public/guest-content/index.php','data-theme-form','data-copy-form','data-upload-form','data-media-list','data-item-media')
need('apps/local-web/public/guest-content/api.php',"theme_save","copy_save","media_assign","media_gc","publish")
need('apps/local-web/public/guest-content/upload.php','importUpload','is_uploaded_file','csrf_expired')
need('apps/local-web/public/assets/guest-content-workspace.js','data-theme-token','media_archive','media_gc','theme_save','copy_save')
builder=need('apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php','publishedPresentation','publicMediaForSource','media_manifest','guestMediaPayloads')
publisher=need('apps/local-web/src/Domain/PublicEdge/PublicEdgePublisherService.php',"/api/v1/local/guest/media",'guest_media','guest_publish')
kernel=need('apps/public/src/Http/PublicHttpKernel.php',"/api/v1/local/guest/media","/theme/",'theme_css')
if 'gif|svg' in kernel or "=== 'svg'" in kernel: fail('Public media route must not serve SVG')
renderer=need('apps/public/src/Guest/GuestPageRenderer.php','presentation','themeCssUrl',"ready_title","degraded_title","search_placeholder","submit_order","call_waiter")
if 'gif|svg' in renderer: fail('Guest renderer must not render SVG media')
theme_css=need('apps/public/src/Guest/GuestThemeCssService.php','--sg-color-primary','--sg-radius-md',"preg_match('/^#[0-9A-Fa-f]{6}$/D'")
for manifest in [ROOT/'apps/local-web/resources/guest-themes/sokna-house/theme.json',ROOT/'apps/local-web/resources/guest-themes/sokna-classic/theme.json']:
    d=json.loads(manifest.read_text(encoding='utf-8'))
    if d.get('format')!='sokna-guest-theme-v1': fail(f'{manifest}: bad format')
    if any(p.suffix.lower() in {'.php','.js','.css'} for p in manifest.parent.iterdir() if p.is_file()): fail(f'{manifest.parent}: executable/style payload forbidden')
if "'image/svg+xml'" in service: fail('Local Media Library must not accept SVG uploads')
public_media=(ROOT/'apps/public/src/Guest/GuestMediaStore.php').read_text(encoding='utf-8')
if "'image/svg+xml'" in public_media or 'gif|svg' in public_media: fail('Public Media Store must not accept SVG replicas')
if 'eval(' in manager or 'include ' in manager or 'require ' in manager: fail('Theme manager may not execute package code')
if '<style' in renderer or ' style=' in renderer: fail('Guest renderer introduced inline style ownership')
if "image_path'=>'" not in builder and "'image_path'=>$source" not in builder: fail('Guest publish does not project media source')
print('G4.2 Guest Content Platform contract: PASS')

setup=need('apps/local-web/src/Setup/BrowserSetupService.php',"'gd'",'GD WebP support')
prereq=need('packaging/prerequisites/setup-ui/Program.cs','php_gd.dll','"gd"')
