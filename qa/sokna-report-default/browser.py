from pathlib import Path
from urllib.parse import urlsplit,parse_qs
import os,hashlib,json,sys,datetime
from playwright.sync_api import sync_playwright
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True)
results=[]
def login(b,host,width):
 c=b.new_context(viewport={'width':width,'height':844})
 data={'username':f['roles']['supervisor' if host=='local' else 'cashier'],'password':f['password']}
 if host=='public':data['installation_id']=f['installation_id']
 assert c.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0).status in (302,303)
 return c
def range_response(r):
 q=parse_qs(urlsplit(r.url).query,keep_blank_values=True)
 return r.status==200 and ('/reports/api.php' in r.url or '/staff/reports-adapter' in r.url) and q.get('action',['report'])==['report'] and 'from' in q and 'to' in q
def check(b,host,width,phase):
 c=login(b,host,width);p=c.new_page();form=p.locator('[data-report-form]')
 try:
  with p.expect_response(range_response,timeout=25000) as resp:p.goto(f[host]+('/reports/' if host=='local' else '/staff/reports/'),wait_until='domcontentloaded')
  data=resp.value.json()['report'];p.locator('[data-summary] [data-report-kpi]').first.wait_for(timeout=20000)
  actual={'from':form.locator('[name=from]').input_value(),'to':form.locator('[name=to]').input_value()}
  expected=data['range'];match=actual==expected and all(actual.values())
  p.screenshot(path=str(out/f'{phase}-{host}-{width}.png'),animations='disabled')
  results.append(dict(host=host,width=width,phase=phase,actual=actual,expected=expected,match=bool(match)))
  if phase=='original':assert not match,'original discrepancy not reproduced'
  else:
   assert match,(actual,expected)
   with p.expect_response(range_response,timeout=25000) as resp:form.locator('[data-range-days="7"]').click()
   data=resp.value.json()['report'];actual={'from':form.locator('[name=from]').input_value(),'to':form.locator('[name=to]').input_value()}
   assert actual==data['range'],(actual,data['range'])
   assert (datetime.date.fromisoformat(actual['to'])-datetime.date.fromisoformat(actual['from'])).days==6
   results.append(dict(host=host,width=width,phase='seven-day',match=True,actual=actual))
 finally:c.close()
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 try:
  check(b,'local',390,'original')
  path=Path(os.environ['RUNNER_TEMP'])/'local/public/reports/index.php'
  original=path.read_bytes();assert hashlib.sha256(original).hexdigest()=='8dca39c6a2ea3c3a10fef1ed9bafe25f685e00f65ba324f5614aab39af01fd6f'
  old=b"$from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');"
  new=b"$to=(string)(($_GET['to']??'')?:$core->time()->civilDate());$from=(string)(($_GET['from']??'')?:$to);"
  assert original.count(old)==1
  patched=original.replace(old,new);assert hashlib.sha256(patched).hexdigest()=='cd380dd37b0906aeabf3f6701fa19a6ad9382f33651b9343184ec329acf56cb6'
  path.write_bytes(patched)
  for host in ['local','public']:
   for width in [390,1280]:check(b,host,width,'candidate')
 finally:
  b.close();(out/'result.json').write_text(json.dumps(results,indent=2))
assert len(results)==9
