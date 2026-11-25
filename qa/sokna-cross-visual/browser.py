"""Real browser against paired disposable Local/Public hosts; never production."""
from pathlib import Path
import json, re, sys, time
from playwright.sync_api import sync_playwright, expect

fixture_path=Path(sys.argv[1]); f=json.loads(fixture_path.read_text()); out=Path(sys.argv[2]); out.mkdir(parents=True,exist_ok=True)
results=[]
report_headings={'overview':'ترکیب عملیات','operations':'عملیات و کیفیت سرویس','inventory':'انبار و بهای مصرف','margin':'حاشیه مشارکت سفارش‌ها','financial':'حساب مشتری و اقامتگاه'}
stock_headings={'attention':'نیازمند تصمیم مدیر','quality':'کیفیت داده انبار و خرید','purchase':'نیازها و خرید','receive':'تحویل و ورود کالا','consume':'مصرف، ضایعات و اصلاح','stock':'شمارش باز','history':'تاریخچه حرکت‌های انبار'}
page_headings={
 'catalog':{'items':'آیتم‌ها و خدمات','archive':'آرشیو کاتالوگ','categories':'دسته‌بندی‌ها','menus':'منوها','tags':'برچسب‌ها'},
 'system':{'health':'وضعیت کلی','updates':'به‌روزرسانی سامانه محلی','recovery':'ساخت نسخه پشتیبان'},
 'admin':{'team':'اعضای تیم','settings':'هویت و تنظیمات عملیاتی','modules':'امکانات قابل مدیریت','tables':'میزها و کدهای میز','notifications':'ارسال اعلان مدیریتی'},
 'print':{'devices':'مقصدهای چاپ','queue':'صف فعلی و موارد نیازمند اقدام','templates':'قالب‌های چاپ','history':'تاریخچه چاپ'}
}
shared={'dashboard':('/','/staff/'),'hall':('/operator/','/staff/operator/'),'preparation':('/waiter/','/staff/preparation/'),'inventory':('/operations/','/staff/operations/'),'reports':('/reports/','/staff/reports/'),'notifications':('/notifications/','/staff/notifications/'),'subscribers':('/subscribers/','/staff/subscribers/'),'account':('/account/','/staff/account/')}
localonly={'catalog':'/catalog/','print':'/printing/','system':'/system/','admin':'/admin/'}

def save(page,key):
    page.screenshot(path=str(out/(key+'.png')),animations='disabled',timeout=12000)

def check(key,fn,page):
    try:
        detail=fn() or {};results.append(dict(key=key,status='PASS',detail=detail));print('PASS',key,flush=True)
    except Exception as e:
        # Do not print fixture credentials or account form fields.
        message=str(e).replace(f['password'],'[test credential]').replace(f['new_password'],'[test credential]').replace(f['limited_password'],'[test credential]')
        results.append(dict(key=key,status='FAIL',error=message[:700]));print('FAIL',key,message[:350],flush=True)
        try:save(page,key+'-FAIL')
        except Exception:pass

def tabs(page,key,controls,attr,panels,headings=None):
    choices=[x.get_attribute(attr) for x in page.locator(controls).all()]
    assert len(choices)>=2,(key,'tabs missing')
    for value in choices:
        tab=page.locator(controls+f'[{attr}="{value}"]') if not controls.endswith(']') else page.locator(f'[{attr}="{value}"]')
        tab=tab.first
        if not tab.is_visible():
            d=tab.locator('xpath=ancestor::details[1]')
            if d.count() and not d.evaluate('(e)=>e.open'):d.locator('summary').click()
        tab.click(timeout=8000)
        name=value.removeprefix('#report-')
        panel=page.locator(f'[{panels}="{name}"]')
        expect(panel).to_be_visible(timeout=7000)
        assert page.locator(f'[{panels}]:visible').count()==1,(key,name,'overlapping panels')
        text=panel.inner_text().strip();assert len(text)>8,(key,name,'empty panel')
        if headings and name in headings:assert headings[name] in text,(key,name,'incorrect section content')
        save(page,key+'-'+name)
    return {'tabs':choices}

def login(ctx,host,username,password):
    path='/login.php' if host=='local' else '/staff/login'
    data={'username':username,'password':password}
    if host=='public':data['installation_id']=f['installation_id']
    r=ctx.request.post(f[host]+path,form=data,max_redirects=0,timeout=15000)
    assert r.status in (302,303),(host,'auth failed',r.status)

def goto(page,host,path):
    r=page.goto(f[host]+path,wait_until='domcontentloaded',timeout=20000)
    assert r and r.status==200,(host,path,r.status if r else None)
    assert 'public_internal_error' not in page.content()
    assert not ('/login' in page.url or '/setup/' in page.url),(host,'unexpected auth/setup redirect')
    if '/operator/' not in path:expect(page.locator('h1').first).to_be_visible(timeout=8000)
    page.wait_for_timeout(200)

with sync_playwright() as p:
    b=p.chromium.launch(headless=True,args=['--no-sandbox'])
    for host in ('local','public'):
        for width in (390,1280):
            ctx=b.new_context(viewport={'width':width,'height':844},device_scale_factor=1)
            page=ctx.new_page();page.set_default_timeout(8000)
            try:login(ctx,host,f['username'],f['password'])
            except Exception as e:
                check(f'{host}-{width}-login',lambda:(_ for _ in ()).throw(e),page);ctx.close();continue
            routes={name:pair[0 if host=='local' else 1] for name,pair in shared.items()}
            routes.update(localonly if host=='local' else {'health':'/staff/health/'})
            for name,path in routes.items():
                if name in ('hall','reports','dashboard'):continue # existing dedicated gates and evidence
                key=f'{host}-{width}-{name}'
                def test():
                    errors=[];handler=lambda e:errors.append(str(e));page.on('pageerror',handler)
                    try:
                        goto(page,host,path);detail={'title':page.locator('h1').first.inner_text()}
                        save(page,key+'-initial')
                        if name=='reports':detail.update(tabs(page,key,'[data-report-section-nav] [role=tab]','href','data-report-section',report_headings))
                        elif name=='inventory':
                            expect(page.locator('[data-operations-workspace]')).to_be_visible()
                            detail.update(tabs(page,key,'[data-ops-workflow]','data-ops-workflow','data-ops-workflow-view',stock_headings))
                            assert page.locator('[data-ops-workflow-view="history"] [data-movement-load-more]').count()==1
                            assert page.locator('[data-ops-workflow-view="receive"] [data-movement-load-more]').count()==0
                        elif name=='preparation':
                            for state,order in [('pending',f['pending_order']),('received',f['received_order'])]:
                                page.locator(f'[data-preparation-tab="{state}"]').click(timeout=10000)
                                card=page.locator(f'[data-preparation-order="{order}"]');expect(card).to_be_visible(timeout=10000)
                                assert f['product'] in card.inner_text();save(page,key+'-'+state)
                            detail['real_order_content']=True
                        elif name=='hall':
                            card=page.locator(f'[data-open-tables] [data-table-id="{f["table_id"]}"]');expect(card).to_be_visible(timeout=10000)
                            for density in ('normal','compact','overview'):
                                page.locator(f'[data-hall-density="{density}"]').click();save(page,key+'-'+density)
                            card.click();expect(page.locator('[data-account-discount]')).to_be_enabled(timeout=12000)
                            assert f['product'] in page.locator('.sc-hall-bill-lines').inner_text();save(page,key+'-account')
                            detail['actual_account_content']=True
                        elif name in ('catalog','system','admin'):
                            detail.update(tabs(page,key,'[role=tab][data-tab]','data-tab','data-panel',page_headings[name]))
                        elif name=='print':detail.update(tabs(page,key,'[data-print-tab]','data-print-tab','data-print-panel',page_headings[name]))
                        elif name=='health':detail.update(tabs(page,key,'[data-health-tab]','data-health-tab','data-health-panel'))
                        elif name=='account' and host=='public':expect(page.locator('[data-public-password-form]')).to_be_visible()
                        save(page,key)
                        detail['document_width']=page.evaluate('document.documentElement.scrollWidth')
                        assert detail['document_width']<=width+4,('document overflow',detail['document_width'],width)
                        assert not errors,('JS errors',errors[:3]);return detail
                    finally:page.remove_listener('pageerror',handler)
                check(key,test,page)
            ctx.close()
    b.close()
(out/'result.json').write_text(json.dumps({'cases':results,'pass':sum(x['status']=='PASS' for x in results),'fail':sum(x['status']=='FAIL' for x in results),'scope':'real authenticated desktop and mobile; manual screenshot review separate'},ensure_ascii=False,indent=2))
if any(x['status']=='FAIL' for x in results):sys.exit(1)
