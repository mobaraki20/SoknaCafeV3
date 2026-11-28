"""Real HTTP browser checks on isolated fixture. No product writes or order submission."""
import json, os, pathlib, sys, traceback
from playwright.sync_api import sync_playwright
W=pathlib.Path(sys.argv[1]).resolve(); F=json.loads((W/'cp144-fixture.json').read_text()); Q=json.loads((W/'gap-catalog.json').read_text()) if (W/'gap-catalog.json').exists() else None
LOCAL='http://127.0.0.1:18765'; PUBLIC='http://127.0.0.1:18766/public'; OUT=W/'screenshots';OUT.mkdir(exist_ok=True)
results=[]
def ck(x,n):
    if not x:raise AssertionError(n)
    print('PASS',n,flush=True)
def auth(p,base):
    p.goto(base+('/login.php' if base==LOCAL else '/staff/login'))
    h=p.locator('[data-login-selection]');h.wait_for(state='attached');key='qa-target' if base==LOCAL else 'user:'+str(next(u['id'] for u in F['users'] if u['name']=='qa-target'))
    if h.input_value()!=key:
        p.locator('.sc-login-flow-change').click();p.locator(f'[data-login-account][data-account-key="{key}"]').click();p.locator('.sc-login-stable-picker').wait_for(state='hidden')
    p.locator('input[name=password]').fill('synthetic-qa-admin-not-real')
    with p.expect_navigation():p.locator('form[data-login-form] button[type=submit]').click()
    ck('/login' not in p.url,'authenticated test admin '+base)
def no_overflow(p):ck(p.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'no horizontal overflow')
def catalog(p,w):
    p.goto(LOCAL+'/catalog/');p.locator('[data-catalog-workspace]').wait_for();p.locator('[data-items] tr').first.wait_for();
    if Q:
        p.locator('[data-item-search]').fill('QA A21 Single');p.locator('[data-items] tr').filter(has_text='QA A21 Single').wait_for();ck('QA A21 Medium' not in p.locator('[data-items]').inner_text(),'actual filter excludes other item');p.locator('[data-clear-item-filters]').click();ck(p.locator('[data-item-search]').input_value()=='','filter clear')
    for tab in ['items','categories','menus','tags','archive']:
        p.locator(f'[data-tab="{tab}"]').click();panel=p.locator(f'[data-panel="{tab}"]');panel.wait_for();no_overflow(p)
        help=panel.locator('.sc-catalog-section-help');help.locator('summary').click();ck(help.locator('p').is_visible(),tab+' help visible');help.locator('summary').click();p.screenshot(path=str(OUT/f'catalog-{tab}-{w}.png'),full_page=True)
def order(p,w):
    if Q is None:raise RuntimeError('BLOCKED: signed order fixture unavailable')
    ids=Q['ids'];one_id,small,medium,large=ids;table=Q['table'];r=p.request.get(PUBLIC+'/api/staff/read?model=table_draft_context');j=r.json();ck(r.status==200 and j.get('stale') is False,'fresh authenticated real order read')
    ck(set(ids).issubset({int(a['id']) for a in j['payload']['catalog']}),'signed catalog members');ck(any(int(a['id'])==table for a in j['payload']['tables']),'signed free table')
    def enter():
        p.goto(PUBLIC+'/staff/operator/');p.locator('[data-global-order]:visible,[data-staff-order]:visible,[data-mobile-new-order]:visible').first.click();p.locator(f'[data-pick-table="{table}"]').click();p.wait_for_function("!document.querySelector('[data-order-workspace-host]')?.hasAttribute('aria-busy')");g=p.locator(f'[data-order-group="{Q["menu"]}"]');g.wait_for();g.click();p.locator(f'[data-order-add="{one_id}"]').wait_for()
    def qty():return {int(k):int(v) for k,v in p.evaluate("id=>{let k=Object.keys(localStorage).find(k=>k.startsWith('sokna:staff-order-draft:')&&k.endsWith(':'+id));return Object.fromEntries((k?JSON.parse(localStorage.getItem(k)).items:[]).map(r=>[r.id,r.quantity]))}",table).items()}
    def counts(expected):ck(qty()==expected,'draft quantities '+str(expected))
    enter();one=p.locator(f'[data-order-add="{one_id}"]');family=p.locator('.sc-order-card-wrap.is-family .sc-order-product').filter(has=p.locator(f'[data-order-add="{small}"]'));height=lambda l:l.bounding_box()['height'];before=[height(one),height(family)];p.screenshot(path=str(OUT/f'order-before-{w}.png'))
    one.click();p.locator(f'[data-order-add="{small}"]').click();p.locator(f'[data-order-add="{large}"]').click();p.locator(f'[data-order-add="{small}"]').click();one.click();counts({one_id:2,small:2,large:1});ck(all(a<=b+1 for a,b in zip([height(one),height(family)],before)),'stable card height');p.screenshot(path=str(OUT/f'order-selected-{w}.png'))
    if w<820:
        dec=p.locator(f'[data-order-decrease="{small},{medium},{large}"]');dec.click();counts({one_id:2,small:1,large:1});dec.click();counts({one_id:2,small:1});dec.click();counts({one_id:2});ck(dec.count()==0,'family decrement disappears');d=p.locator(f'[data-order-decrease="{one_id}"]');d.click();d.click();counts({});ck(d.count()==0,'single decrement disappears');one.click();p.locator(f'[data-order-add="{large}"]').click()
    expected=qty();enter();counts(expected);ck('is-selected' in one.get_attribute('class'),'restored selection');no_overflow(p);p.screenshot(path=str(OUT/f'order-restored-{w}.png'))
with sync_playwright() as pw:
    b=pw.chromium.launch(executable_path=os.environ.get('SOKNA_CHROMIUM') or None,headless=True)
    for w in (390,1280):
        for name,base,fn in [('catalog',LOCAL,catalog),('order',PUBLIC,order)]:
            ctx=b.new_context(viewport={'width':w,'height':900},locale='fa-IR',service_workers='block');p=ctx.new_page();p.set_default_timeout(16000)
            # Never navigate or transmit test data to external endpoints.
            from urllib.parse import urlparse
            p.route('**/*',lambda route:route.continue_() if urlparse(route.request.url).hostname=='127.0.0.1' else route.abort())
            try:
                auth(p,base);fn(p,w);status='PASS';error=''
            except Exception as e:
                status='BLOCKED' if str(e).startswith('BLOCKED:') else 'FAIL';error=str(e);print(name,w,status,error,flush=True)
                try:p.screenshot(path=str(OUT/f'{name}-failure-{w}.png'),full_page=True)
                except Exception:pass
            finally:ctx.close()
            results.append({'group':name,'width':w,'status':status,'error':error})
    b.close()
(W/'browser-gap-results.json').write_text(json.dumps(results,indent=2));sys.exit(0 if all(x['status']=='PASS' for x in results) else 1)
