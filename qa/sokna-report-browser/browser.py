"""Authenticated reports, true date filter and tab content, no intercepted API."""
from pathlib import Path
import json,sys,re
from playwright.sync_api import sync_playwright,expect
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(parents=True,exist_ok=True)
results=[]
digits=str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789')
headings={'overview':'ترکیب عملیات','operations':'عملیات و کیفیت سرویس','inventory':'انبار و بهای مصرف','margin':'حاشیه مشارکت','financial':'حساب مشتری','tenders':'روش‌های پرداخت','trends':'روند','item-sales':'فروش','invoices':'فاکتور','activity':'فعالیت'}
def compact(s):return re.sub(r'[,٬\s]','',s.translate(digits))
def save(page,key):page.screenshot(path=str(out/(key+'.png')),animations='disabled',timeout=12000)
def login(ctx,host):
    data={'username':f['username'],'password':f['password']}
    if host=='public':data['installation_id']=f['installation_id']
    r=ctx.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0);assert r.status in (302,303)
def check(key,fn,page):
    try:fn();results.append({'key':key,'status':'PASS'});print('PASS '+key,flush=True)
    except Exception as e:
        results.append({'key':key,'status':'FAIL','error':str(e)[:700]});print('FAIL '+key+' '+str(e)[:350],flush=True)
        try:save(page,key+'-FAIL')
        except Exception:pass
with sync_playwright() as p:
    b=p.chromium.launch(headless=True,args=['--no-sandbox'])
    for host in ['local','public']:
        for width in [390,1280]:
            ctx=b.new_context(viewport={'width':width,'height':844})
            page=ctx.new_page();page.set_default_timeout(15000);login(ctx,host)
            key=host+'-'+str(width)
            def change_range(start,end):
                form=page.locator('[data-report-form]');form.locator('[name=from]').fill(start);form.locator('[name=to]').fill(end)
                with page.expect_response(lambda r:'from='+start in r.url and 'to='+end in r.url and r.request.resource_type in ('fetch','xhr'),timeout=25000) as res:
                    form.locator('button[type=submit]').click()
                r=res.value;assert r.ok,(r.status,r.url);body=r.json()
                expect(page.locator('[data-reports]')).not_to_have_attribute('aria-busy','true',timeout=25000)
                return body['report']
            def tab(name):
                page.locator('[data-report-section-nav] [href="#report-'+name+'"]').click()
                panel=page.locator('[data-report-section="'+name+'"]');expect(panel).to_be_visible()
                assert page.locator('[data-report-section]:visible').count()==1
                return panel
            def test():
                r=page.goto(f[host]+('/reports/' if host=='local' else '/staff/reports/'),wait_until='domcontentloaded');assert r.ok
                expect(page.locator('[data-summary] [data-report-kpi]').first).to_be_visible(timeout=25000)
                # Full range: every visible tab must display its own heading/content.
                change_range(f['base_range']['from'],f['base_range']['to'])
                for name in headings:
                    link=page.locator('[data-report-section-nav] [href="#report-'+name+'"]')
                    if not link.count():continue
                    panel=tab(name);assert headings[name] in panel.inner_text(),(name,panel.inner_text()[:180])
                    assert not panel.locator('[data-report-unavailable]').is_visible(),name
                    save(page,key+'-full-'+name)
                # Historical range: exact gross and discount in data and visible trend.
                report=change_range(f['historical_from'],f['historical_to'])
                for k,v in f['expected_summary'].items():assert report['summary'][k]==v,(k,report['summary'][k],v)
                panel=tab('overview');assert str(f['expected_summary']['net_sales']) in compact(panel.inner_text())
                save(page,key+'-archive-overview')
                panel=tab('trends');s=compact(panel.inner_text())
                for k in ('gross_sales','discount_total'):
                    assert str(f['expected_summary'][k]) in s,(k,s[:400])
                assert 'تخفیف' in panel.inner_text() and 'جمع اقلام' in panel.inner_text()
                save(page,key+'-archive-trends')
                if host=='public':
                    expect(page.locator('[data-remote-report-coverage]')).to_be_visible()
                    assert 'تحلیل' in page.locator('[data-remote-report-coverage]').inner_text()
                    for name in ['operations','inventory','margin','financial','tenders']:
                        panel=tab(name);expect(panel.locator('[data-report-unavailable]')).to_be_visible()
                        assert all(c.is_hidden() for c in panel.locator(':scope > :not([data-report-unavailable])').all()),name
                        save(page,key+'-archive-unavailable-'+name)
                # Full range again: hidden detailed content must recover.
                change_range(f['base_range']['from'],f['base_range']['to'])
                panel=tab('inventory');assert 'انبار و بهای مصرف' in panel.inner_text()
                assert not panel.locator('[data-report-unavailable]').is_visible()
                save(page,key+'-full-restored')
            check(key,test,page);ctx.close()
    b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
assert all(r['status']=='PASS' for r in results)
