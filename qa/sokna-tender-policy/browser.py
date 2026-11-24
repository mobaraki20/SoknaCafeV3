"""Real authenticated dashboard, Hall and settlement; exact frozen package."""
import json,sys,re,time
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
path=Path(sys.argv[1]);f=json.loads(path.read_text());out=Path(sys.argv[2]);out.mkdir(parents=True,exist_ok=True)
tmp=path.parent;results=[]
digits=str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789')
def amount(s):
    return int(''.join(c for c in s.translate(digits) if c.isdigit()))
def check_money(node,expected,unit):
    expect(node).to_be_visible();s=node.inner_text();assert unit in s and amount(s)==expected,(s,expected,unit)
def save(page,key):page.screenshot(path=str(out/(key+'.png')),animations='disabled',timeout=12000)
def login(ctx,host):
    data={'username':f['username'],'password':f['password']}
    if host=='public':data['installation_id']=f['installation_id']
    r=ctx.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0)
    assert r.status in (302,303),r.status
def go(page,host,route):
    r=page.goto(f[host]+route,wait_until='domcontentloaded');assert r and r.status==200,(route,r.status)
def check(key,fn,page):
    try:fn();results.append({'key':key,'status':'PASS'});print('PASS '+key,flush=True)
    except Exception as e:
        results.append({'key':key,'status':'FAIL','error':str(e)[:700]});print('FAIL '+key+' '+str(e)[:350],flush=True)
        try:save(page,key+'-FAIL')
        except Exception:pass
with sync_playwright() as p:
    b=p.chromium.launch(headless=True,args=['--no-sandbox'])
    for host in ['local','public']:
        ctx=b.new_context(viewport={'width':390,'height':844})
        page=ctx.new_page();page.set_default_timeout(15000)
        login(ctx,host)
        def dashboard():
            go(page,host,'/' if host=='local' else '/staff/')
            card=page.locator('[data-open-accounts-kpi]' if host=='local' else '[data-public-open-accounts-kpi]')
            check_money(card.locator('strong'),f['initial_total'],'تومان')
            check_money(page.locator('.sc-dashboard-kpi').filter(has_text='فروش خالص امروز').locator('strong'),0,'تومان')
            save(page,host+'-dashboard-toman')
        def hall():
            go(page,host,'/operator/' if host=='local' else '/staff/operator/')
            page.locator(f'[data-open-tables] [data-table-id="{f["table_id"]}"]').click()
            expect(page.locator('[data-account-settle]')).to_be_enabled(timeout=25000)
            check_money(page.locator('.sc-hall-payable-rial' if host=='local' else '.sc-hall-summary-row.is-payable strong'),f['initial_total']*10,'ریال')
            assert page.locator('[data-account-line]').count()>0
            save(page,host+'-hall-rial')
            page.locator('[data-account-settle]').click()
            page.locator('[data-settle-view="direct"]').click()
            dialog=page.locator('.sui-settlement-dialog')
            check_money(dialog.locator('.sui-settlement-account__main .amount strong'),f['initial_total']*10,'ریال')
            assert 'تومان' not in dialog.locator('[data-tender-box]').inner_text()
            save(page,host+'-settlement-rial')
            choices=dialog.locator('[data-tender-type][aria-pressed="true"]')
            if f['default_tender']=='pos':
                expect(choices).to_have_count(1)
                expect(choices.first).to_have_attribute('data-tender-type','pos')
                expect(dialog.locator('[data-settle-submit]')).to_be_enabled()
            else:
                expect(choices).to_have_count(0)
                expect(dialog.locator('[data-settle-submit]')).to_be_disabled()
            save(page,host+'-default-'+f['default_tender'])
            dialog.locator('[data-tender-type="cash"]').click()
            expect(choices).to_have_count(1)
            expect(choices.first).to_have_attribute('data-tender-type','cash')
            expect(dialog.locator('[data-tender-amount]')).to_have_count(0)
            expect(dialog.locator('[data-settle-submit]')).to_be_enabled()
            dialog.locator('[data-tender-mixed]').click()
            expect(choices).to_have_count(2)
            expect(dialog.locator('[data-tender-amount][data-currency-unit="IRR"]')).to_have_count(2)
            expect(dialog.locator('[data-settle-submit]')).to_be_enabled()
            save(page,host+'-explicit-mixed')
            dialog.locator('[data-tender-mixed]').click()
            expect(choices).to_have_count(1)
            expect(dialog.locator('[data-tender-amount]')).to_have_count(0)
            dialog.locator('[data-settle-close]').first.click()
            page.locator('[data-account-settle]').click()
            page.locator('[data-settle-view="direct"]').click()
            expect(choices).to_have_count(1 if f['default_tender']=='pos' else 0)
            if f['default_tender']=='pos':expect(choices.first).to_have_attribute('data-tender-type','pos')
            print('PASS '+host+' persistent tender default and explicit split',flush=True)

        check(host+'-dashboard-policy',dashboard,page)
        check(host+'-hall-policy',hall,page)
        ctx.close()
    # Explicitly stale the disposable Public heartbeat/model; do not replace app responses.
    ctx=b.new_context(viewport={'width':390,'height':844});page=ctx.new_page();page.set_default_timeout(15000);login(ctx,'public')
    def stale():
        (tmp/'qa-stale-request').touch()
        deadline=time.monotonic()+40
        while not (tmp/'qa-stale-done').exists():
            assert time.monotonic()<deadline,'stale fixture not acknowledged';time.sleep(.2)
        go(page,'public','/staff/')
        card=page.locator('[data-public-open-accounts-kpi]')
        expect(card.locator('strong')).to_have_text('—')
        assert 'نامشخص' in card.inner_text()
        expect(page.locator('.sc-dashboard-kpi').filter(has_text='فروش خالص امروز').locator('strong')).to_have_text('—')
        save(page,'public-stale-unknown')
    check('public-stale-is-not-zero',stale,page)
    ctx.close();b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
assert all(r['status']=='PASS' for r in results)
