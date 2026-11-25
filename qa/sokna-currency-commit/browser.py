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
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
    host=f['host'];ctx=browser.new_context(viewport={'width':390,'height':844});page=ctx.new_page();page.set_default_timeout(20000);login(ctx,host)
    def signal(name):
        (tmp/(name+'-request')).touch()
        deadline=time.monotonic()+70
        while not (tmp/(name+'-done')).exists():
            assert time.monotonic()<deadline,name+' not acknowledged';time.sleep(.2)
    def test():
        go(page,host,'/operator/' if host=='local' else '/staff/operator/')
        root=page.locator('[data-operator-workspace]')
        card=page.locator(f'[data-open-tables] [data-table-id="{f["table_id"]}"]')
        card.click();expect(page.locator('[data-account-settle]')).to_be_enabled(timeout=25000)
        def open_direct():
            page.locator('[data-account-settle]').click();page.locator('[data-settle-view="direct"]').click()
            return page.locator('.sui-settlement-dialog')
        dialog=open_direct();main=dialog.locator('.sui-settlement-account__main .amount strong')
        check_money(main,f['initial_total']*10,'ریال')
        for flag in [0,1]:
            before_unit='ریال' if flag==0 else 'تومان'
            before_value=f['initial_total']*(10 if flag==0 else 1)
            expect(dialog.locator('[data-settle-submit]')).to_be_enabled()
            signal(f'qa-currency-{flag}')
            expect(root).to_have_attribute('data-final-amount-rial',str(flag),timeout=60000)
            check_money(main,before_value,before_unit)
            submit=dialog.locator('[data-settle-submit]')
            if submit.is_enabled():submit.click()
            expect(submit).to_be_disabled()
            save(page,host+f'-drift-{flag}-blocked')
            dialog.locator('[data-settle-close]').first.click()
            dialog=open_direct();main=dialog.locator('.sui-settlement-account__main .amount strong')
            check_money(main,f['initial_total']*(10 if flag==1 else 1),'ریال' if flag==1 else 'تومان')
            print('PASS '+host+' drift blocked and reopened '+str(flag),flush=True)
        signal('qa-currency-blocked')
        expect(dialog.locator('[data-tender-type="pos"]')).to_have_attribute('aria-pressed','true')
        dialog.locator('[data-tender-mixed]').click()
        expect(dialog.locator('[data-tender-type][aria-pressed="true"]')).to_have_count(2)
        field=dialog.locator('[data-tender-amount="pos"]');expect(field).to_be_editable()
        field.fill('۱۱');expect(dialog.locator('[data-settle-submit]')).to_be_disabled()
        split=f['initial_total']//3;assert split>0
        field.fill(str(split*10).translate(str.maketrans('0123456789','۰۱۲۳۴۵۶۷۸۹')))
        expect(dialog.locator('[data-settle-submit]')).to_be_enabled()
        assert amount(dialog.locator('[data-tender-amount="cash"]').input_value())==(f['initial_total']-split)*10
        dialog.locator('[data-receipt-print]').uncheck()
        save(page,host+'-mixed-before-commit')
        dialog.locator('[data-settle-submit]').click()
        expect(dialog).not_to_be_visible(timeout=90000)
        expect(card).to_have_count(0,timeout=90000)
        save(page,host+'-released')
        page.reload(wait_until='domcontentloaded');expect(card).to_have_count(0,timeout=30000)
    check(host+'-actual-currency-commit',test,page);ctx.close();browser.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
assert all(r['status']=='PASS' for r in results)
