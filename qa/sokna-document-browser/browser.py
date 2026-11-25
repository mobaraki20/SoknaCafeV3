from pathlib import Path
import json,sys
from playwright.sync_api import sync_playwright
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True)
records=f['canonical_receipts'];results=[]
def login(b,host,role,width):
 c=b.new_context(viewport={'width':width,'height':844})
 data={'username':f['roles'][role],'password':f['password']}
 if host=='public':data['installation_id']=f['installation_id']
 r=c.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0)
 assert r.status in (302,303)
 return c
def compare(doc,row):
 assert int(doc['id'])==int(row['id'])
 for k in ['subtotal','discount','total','tax_amount']:assert int(doc[k])==int(row[k]),k
 assert doc['receipt_business_date']==row['business_date']
 assert doc['sale_business_date']==row['sale_date']
 assert doc['status']==row['status'] and doc['items']
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 for width in [390,1280]:
  c=login(b,'public','cashier',width);page=c.new_page()
  try:
   page.goto(f['public']+'/staff/reports/',wait_until='domcontentloaded')
   page.locator('a[href="#report-invoices"]').click()
   for row in records:
    i=int(row['id']);button=page.locator('[data-invoice-id="'+str(i)+'"]')
    button.wait_for(state='visible',timeout=20000)
    with page.expect_response(lambda r:'action=invoice_detail' in r.url and 'id='+str(i) in r.url) as response:button.click()
    r=response.value;assert r.status==200,r.status
    compare(r.json()['invoice'],row)
    detail=page.locator('[data-invoice-detail] .sc-finance-document')
    detail.wait_for(state='visible')
    assert detail.locator('.sc-finance-document__line').count()>0
    if row['status']=='reversal':assert 'برگشت کامل فاکتور' in detail.inner_text()
    page.screenshot(path=str(out/f'public-{width}-receipt-{i}.png'),animations='disabled')
   results.append({'host':'public','width':width,'case':'real invoices','status':'PASS'})
  except Exception as e:
   results.append({'host':'public','width':width,'case':'real invoices','status':'FAIL','error':str(e)[:600]})
   page.screenshot(path=str(out/f'public-{width}-FAIL.png'))
  finally:c.close()
  c=login(b,'local','supervisor',width);page=c.new_page()
  try:
   d=f['event_day'];page.goto(f['local']+'/reports/?from='+d+'&to='+d,wait_until='domcontentloaded')
   page.locator('a[href="#report-activity"]').click()
   form=page.locator('[data-activity-form]')
   form.locator('[name=family]').select_option('settlement')
   form.locator('[name=q]').fill(str(f['receipt_ids'][0]))
   with page.expect_response(lambda r:'action=activity' in r.url and 'family=settlement' in r.url) as response:form.locator('button[type=submit]').click()
   r=response.value;assert r.status==200
   rows=r.json()['activity']['rows']
   target=next(x for x in rows if x['action']=='settlement.completed' and int(x['entity_id'])==int(f['receipt_ids'][0]))
   page.locator('[data-activity-list] .sc-report-activity-row').nth(rows.index(target)).get_by_role('button',name='مشاهده سند اصلی').click()
   page.locator('[data-activity-detail]').wait_for(state='visible')
   page.get_by_text('نمایش فقط‌خواندنی و از داده اصلی سامانه؛ هیچ تغییری در سند انجام نمی‌شود.',exact=True).wait_for(state='visible',timeout=15000)
   page.screenshot(path=str(out/f'local-{width}-activity.png'),animations='disabled')
   results.append({'host':'local','width':width,'case':'original activity document','status':'PASS'})
  except Exception as e:
   results.append({'host':'local','width':width,'case':'original activity document','status':'FAIL','error':str(e)[:600]})
   page.screenshot(path=str(out/f'local-{width}-FAIL.png'))
  finally:c.close()
 b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
print(json.dumps(results,ensure_ascii=False))
assert len(results)==4 and all(x['status']=='PASS' for x in results)
