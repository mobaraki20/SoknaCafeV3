from pathlib import Path
import json,sys
from playwright.sync_api import sync_playwright,expect
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True)
receipt=int(f['receipt_ids'][0]);results=[]
def login(b,role):
 c=b.new_context(viewport={'width':390,'height':844})
 r=c.request.post(f['public']+'/staff/login',form={'username':f['roles'][role],'password':f['password'],'installation_id':f['installation_id']},max_redirects=0)
 assert r.status in (302,303),r.status
 return c
def req(c,path,expected):
 r=c.request.get(f['public']+path,max_redirects=0)
 assert r.status==expected,(path,r.status,expected)
 return r.json() if expected==200 else {}
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 for role in ('cashier','supervisor','staff-report'):
  c=login(b,role)
  try:
   raw=req(c,'/api/staff/read?model=reports',200)['payload']
   assert isinstance(raw.get('dashboard_activity'),list),'dashboard projection absent'
   if role!='supervisor':
    assert raw.get('activity') is None or raw.get('activity')=={},'unexpected detailed activity'
    req(c,'/staff/reports-adapter?action=activity',403)
   if role=='cashier':
    req(c,'/staff/reports-adapter?action=invoice_history',200)
    doc=req(c,'/staff/reports-adapter?action=invoice_detail&id='+str(receipt),200)['invoice']
    assert int(doc['id'])==receipt and len(doc['items'])>0
    assert isinstance(raw.get('invoice_history_archive'),dict),'positive receipt projection missing'
    page=c.new_page()
    page.goto(f['public']+'/staff/reports/',wait_until='domcontentloaded')
    page.locator('a[href="#report-invoices"]').click()
    button=page.locator('[data-invoice-id="'+str(receipt)+'"]');button.wait_for(state='visible',timeout=20000)
    button.click()
    detail=page.locator('[data-invoice-detail] .sc-finance-document')
    expect(detail).to_be_visible(timeout=15000)
    detail.scroll_into_view_if_needed();detail.screenshot(path=str(out/'receipt-full.png'),animations='disabled')
   else:
    assert 'invoice_history_archive' not in raw,'unexpected invoice archive'
    req(c,'/staff/reports-adapter?action=invoice_history',403)
    req(c,'/staff/reports-adapter?action=invoice_detail&id='+str(receipt),403)
   results.append({'role':role,'status':'PASS'})
  except Exception as e:results.append({'role':role,'status':'FAIL','error':str(e)[:500]})
  finally:c.close()
 b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
print(json.dumps(results,ensure_ascii=False))
assert len(results)==3 and all(x['status']=='PASS' for x in results)
