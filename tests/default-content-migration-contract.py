#!/usr/bin/env python3
from __future__ import annotations
import hashlib, json, re
from pathlib import Path

R=Path(__file__).resolve().parents[1]
RES=R/'apps/local-web/resources/default-content/v1'
CAT=RES/'catalog.json'
ICONS=RES/'category-icons.json'
LOCAL_SPRITE=R/'apps/local-web/public/assets/category-icons.svg'
PUBLIC_SPRITE=R/'apps/public/assets/category-icons.svg'
LOCAL_UI_SPRITE=R/'apps/local-web/public/assets/ui-sprite.svg'
LOCAL_JALALI=R/'apps/local-web/public/assets/jalali-fields.js'

def need(ok: bool, msg: str):
    if not ok:
        raise SystemExit('DEFAULT CONTENT CONTRACT FAILED: '+msg)

need(CAT.is_file(),'catalog.json missing')
need(ICONS.is_file(),'category-icons.json missing')
doc=json.loads(CAT.read_text(encoding='utf-8'))
need(len(doc.get('menus',[]))==3,'expected 3 menus')
need(len(doc.get('categories',[]))==15,'expected 15 categories')
need(len(doc.get('items',[]))==135,'expected 135 items')
need(sum(int(x.get('active',1)) for x in doc['items'])==85,'expected 85 active items')
need(sum(int(x.get('available',1)) for x in doc['items'])==85,'expected 85 available items')
need(sum(int(x.get('staff_only',0)) for x in doc['items'])==3,'expected 3 staff-only items')
need(sum(int(x.get('featured',0)) for x in doc['items'])==8,'expected 8 featured items')
need(sum(len(set(x.get('menus',[]))) for x in doc['categories'])==33,'category membership baseline changed')
need(sum(len(set(x.get('menus',[]))) for x in doc['items'])==297,'item membership baseline changed')

media_refs=[]
for scope in ('categories','items'):
    for row in doc[scope]:
        p=str(row.get('image_path') or '').strip()
        if p: media_refs.append(p)
unique_media=sorted(set(media_refs))
need(len(unique_media)==24,'expected 24 unique referenced menu images')
need(len(media_refs)==82,'expected 82 category/item media references')
for p in unique_media:
    m=re.fullmatch(r'assets/menu/default/([A-Za-z0-9._-]+\.webp)',p)
    need(m is not None, f'unsupported legacy image path {p}')
    f=RES/'media'/m.group(1)
    need(f.is_file() and f.stat().st_size>0,f'missing seeded image {m.group(1)}')
need(len(list((RES/'media').glob('*.webp')))==24,'resource media directory must contain exactly the 24 referenced images')


prov=json.loads((RES/'PROVENANCE.json').read_text(encoding='utf-8'))
need(prov.get('format')=='sokna-default-content-provenance-v1','content provenance format mismatch')
need(prov['legacy_source']['default_menu_sha256']=='647967a71cc0c47ea148e9cbfff9fbe92cd7b8642bb56ce560156e0f91e2beb9','legacy default menu provenance changed')
need(prov['legacy_source']['legacy_ui_sprite_sha256']=='6b7eec7a2f6fa85253585090040597e5bb21e44174947b27bc7327a81e7d3883','legacy icon sprite provenance changed')
need(prov.get('current_menu_source',{}).get('sha256')=='dc71b72c6dd5a57eb8352c61c1c90a66ca9dc394bfb4dc384872b53177191d09','current menu spreadsheet provenance changed')
need(prov.get('current_menu_source',{}).get('rows')==131,'current menu source row count changed')
canonical_items=[{'item_code':x['item_code'],'name':x['name'],'price':x['price'],'category':x['category'],'active':x['active']} for x in doc['items']]
canonical_digest=hashlib.sha256(json.dumps(canonical_items,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()).hexdigest()
need(prov['current_menu_source']['canonical_catalog_digest_sha256']=='cd800ff566d65fc5591f2a6effc602059d3fe1cea2de61857191058940d64c60','legacy spreadsheet provenance changed')
need(canonical_digest=='5b614edc1a52dbc5a5ccd1f744bafe109894e062ef2cd44eada367154914f0ff','current reconciled catalog content changed without approval')
need(prov['business_reconciliation_2026_10_14']['product_model_revision']==4,'audited business reconciliation revision missing')
need(prov['business_reconciliation_2026_10_14']['latest_items_export_sha256']=='cb15ceb59b35a45f883b26406aae41f27c1e3654b09b1c10ce1f45412bb0fcd4','approved latest menu export provenance changed')
need(len(prov.get('media',[]))==24 and all(x.get('sha256')==x.get('legacy_sha256') for x in prov['media']),'media provenance does not prove byte-identical legacy copies')

icon_doc=json.loads(ICONS.read_text(encoding='utf-8'))
need(icon_doc.get('format')=='sokna-category-icon-library-v1','icon library format mismatch')
icons=[i for g in icon_doc.get('groups',[]) for i in g.get('icons',[])]
keys=[i.get('key') for i in icons]
need(len(keys)==56 and len(set(keys))==56,'expected 56 unique category icons')
need([len(g.get('icons',[])) for g in icon_doc['groups']]==[5,13,6,22,4,6],'icon group baseline changed')
need('list' in keys,'fallback list icon missing')
seed_icons=[]
for c in doc['categories']:
    k=str(c.get('icon_key') or '')
    need(k in keys,f'default category icon {k!r} missing from icon library')
    seed_icons.append(k)
need(len(seed_icons)==15 and len(set(seed_icons))==14,'reconciled category/icon mapping changed')
expected_category_icons={'قهوه':'bean','غذا و میان‌وعده':'sharing','نوشیدنی‌های سرد':'cold-drink','چای و دمنوش':'tea','شیک و اسموتی':'shake','شربت‌ها':'sharbat','کیک و دسر':'cake','نوشیدنی‌های آماده':'water','سرویس‌ها':'service','دمنوش ها':'herbal','اسموتی':'smoothie','فینگرفود':'sharing','خوراک':'food','غذای اصلی':'iranian-food','ترشیجات':'sauce'}
need({c['name']:c['icon_key'] for c in doc['categories']}==expected_category_icons,'seeded category/icon assignment drifted')
for sprite in (LOCAL_SPRITE,PUBLIC_SPRITE):
    need(sprite.is_file(),f'{sprite.relative_to(R)} missing')
    text=sprite.read_text(encoding='utf-8')
    ids=set(re.findall(r'<symbol\s+id="icon-([a-z0-9-]+)"',text))
    need(ids==set(keys),f'{sprite.relative_to(R)} symbol set does not exactly match 56-icon registry')
need(LOCAL_SPRITE.read_bytes()==PUBLIC_SPRITE.read_bytes(),'Local/Public category sprite bytes differ')

# Only assets actually consumed by V3 are carried forward.
# Local ui-sprite is now an active product UI dependency: Jalali date/time controls
# resolve calendar/clock icons from it. Public Edge still must not carry this Local-only asset.
need(LOCAL_UI_SPRITE.is_file() and LOCAL_UI_SPRITE.stat().st_size>0,'Local UI sprite required by Jalali controls is missing')
jalali=LOCAL_JALALI.read_text(encoding='utf-8')
need("assetPath('/assets/ui-sprite.svg')" in jalali,'Jalali controls no longer bind the Local UI sprite')
ui_ids=set(re.findall(r'<symbol\s+id="icon-([a-z0-9-]+)"',LOCAL_UI_SPRITE.read_text(encoding='utf-8')))
need({'calendar','clock'} <= ui_ids,'Local UI sprite lacks calendar/clock symbols required by Jalali controls')
for banned in [
    R/'apps/public/assets/ui-sprite.svg',
    R/'apps/local-web/public/assets/staff-192.png', R/'apps/local-web/public/assets/staff-512.png',
    R/'apps/public/assets/staff-192.png', R/'apps/public/assets/staff-512.png',
]:
    need(not banned.exists(),f'unused legacy asset was imported: {banned.relative_to(R)}')
for base in [R/'apps/local-web/public/assets',R/'apps/public/assets']:
    for n in ('favicon-32.png','favicon-180.png'):
        need((base/n).is_file() and (base/n).stat().st_size>0,f'{(base/n).relative_to(R)} missing')
    # Local PWA owns its 192px/512px icons. Public uses 32px/180px favicons only.
local_manifest=(R/'apps/local-web/public/manifest.webmanifest').read_text(encoding='utf-8')
need('favicon-192.png' in local_manifest and 'favicon-512.png' in local_manifest,'Local PWA icon manifest missing active icons')
need((R/'apps/local-web/public/assets/favicon-192.png').is_file(),'Local PWA favicon-192.png missing')
need((R/'apps/local-web/public/assets/favicon-512.png').is_file(),'Local PWA favicon-512.png missing')
need(not (R/'apps/public/assets/favicon-192.png').exists(),'Public must not import unused favicon-192.png')
need(not (R/'apps/public/assets/favicon-512.png').exists(),'Public must not import unused favicon-512.png')

seeder=(R/'apps/local-web/src/Domain/Sellables/DefaultContentSeeder.php').read_text(encoding='utf-8')
need("private const MARKER_KEY = 'default_content.v2'" in seeder,'idempotent seed marker missing')
need("count($doc['menus']) !== 3" in seeder and "count($doc['categories']) !== 15" in seeder and "count($doc['items']) !== 135" in seeder,'audited baseline fence missing')
need("count($map)!==24" in seeder,'24-image baseline fence missing')
need("'media:'" in seeder and 'guest_media_references' in seeder,'Media Library reference integration missing')
need("$v==='other'?'cold_bar'" in seeder,'legacy preparation station mapping missing')
# Regression fence for a bug found during this audit: exactly six bound parameters are used in derivative INSERT.
derivative_line=next((x for x in seeder.splitlines() if 'guest_media_derivatives' in x and 'VALUES' in x),'')
need(derivative_line.count('?')==6,'guest media derivative INSERT placeholder count must be exactly six')
item_line=next((x for x in seeder.splitlines() if '$itemUp=' in x),'')
need(item_line.count('?')==12,'item upsert placeholder count must be exactly twelve')

setup=(R/'apps/local-web/src/Setup/BrowserSetupService.php').read_text(encoding='utf-8')
machine=(R/'apps/local-web/tools/setup-machine.php').read_text(encoding='utf-8')
need(setup.count('defaultContentSeeder()->seed(')>=2,'browser setup install+resume seed hooks missing')
need("default_content.v2" in setup and "'menus'" in setup and "'media'" in setup,'browser final-health default content fence missing')
need('defaultContentSeeder()->seed(' in machine,'setup-machine seed hook missing')

projection=(R/'apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php').read_text(encoding='utf-8')
need("c.audience<>'staff_only'" in projection,'Public category staff-only filter is not V3-safe')
need("'icon_key'" in projection and "'image_path'" in projection and 'publicMediaForSource' in projection,'category icon/media Public projection missing')
renderer=(R/'apps/public/src/Guest/GuestPageRenderer.php').read_text(encoding='utf-8')
need('/assets/category-icons.svg#icon-' in renderer,'Public category icon renderer missing')
need('sg-category-media' in renderer,'Public category image renderer missing')
need('/assets/favicon-32.png' in renderer and '/assets/favicon-180.png' in renderer,'Public favicon wiring missing')
shell=(R/'apps/local-web/src/UI/ProductShell.php').read_text(encoding='utf-8')
need('/assets/favicon-32.png' in shell and '/assets/favicon-180.png' in shell,'Local favicon wiring missing')

# Exact legacy edge-case mapping remains intentional and visible in the source data.
other=[x for x in doc['items'] if x.get('preparation_station')=='other']
need(len(other)==1 and other[0].get('item_code')=='1211','legacy other-station baseline changed')
need(len({str(x.get('item_code')) for x in doc['items']})==135,'item codes must be unique')
need(all(str(x.get('item_code','')).isdigit() for x in doc['items']),'current POS item codes must remain numeric')
for code,name in [('1219','سس سزار'),('1317','سالاد حمص'),('1220','چیزکیک سن سباستین')]:
    need(any(str(x.get('item_code'))==code and x.get('name')==name for x in doc['items']),f'new menu item missing: {code} {name}')
print('PASS default content contract: approved reconciliation v4 / 3 menus / 15 categories / 135 items / 24 images')
