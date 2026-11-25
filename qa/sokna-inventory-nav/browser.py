from pathlib import Path
import json,sys
from playwright.sync_api import sync_playwright,expect
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True);results=[]
names=['attention','purchase','receive','consume','stock','quality','history']
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 for host in ['local','public']:
  for width in [390,1280]:
   c=b.new_context(viewport={'width':width,'height':844});page=c.new_page();page.set_default_timeout(12000)
   try:
    data={'username':f['username'],'password':f['password']}
    if host=='public':data['installation_id']=f['installation_id']
    r=c.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0);assert r.status in [302,303]
    r=page.goto(f[host]+('/operations/' if host=='local' else '/staff/operations/'),wait_until='domcontentloaded');assert r.status==200
    nav=page.locator('.sc-ops-workflow-nav');expect(nav).to_be_visible();more=nav.locator('details.sc-ops-more')
    expect(page.locator('[data-metric="inventory-items"]')).not_to_have_text('—')
    assert nav.locator('[data-ops-workflow]').count()==7
    if width==390:
     assert more.evaluate('(e)=>e.open')
     expect(nav.locator('[data-ops-workflow]:visible')).to_have_count(7)
     assert nav.locator('summary').evaluate('(e)=>getComputedStyle(e).display')=='none'
     assert len(nav.evaluate("(e)=>getComputedStyle(e).gridTemplateColumns.split(' ')"))==2
    else:
     assert not more.evaluate('(e)=>e.open')
     expect(nav.locator('summary')).to_be_visible()
     nav.locator('summary').click()
    page.screenshot(path=str(out/f'{host}-{width}-navigation.png'),animations='disabled')
    for key in names:
     button=nav.locator('[data-ops-workflow="'+key+'"]');expect(button).to_be_visible();button.click()
     panel=page.locator('[data-ops-workflow-view="'+key+'"]');expect(panel).to_be_visible()
     assert page.locator('[data-ops-workflow-view]:visible').count()==1
     assert panel.inner_text().strip()
    assert page.evaluate('document.documentElement.scrollWidth')<=width+4
    results.append({'host':host,'width':width,'status':'PASS'});print('PASS seven navigation steps',host,width,flush=True)
   except Exception as e:
    results.append({'host':host,'width':width,'status':'FAIL','error':str(e)[:600]});print('FAIL',host,width,str(e)[:500],flush=True)
    page.screenshot(path=str(out/f'{host}-{width}-FAIL.png'))
   finally:c.close()
 b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
assert all(x['status']=='PASS' for x in results)
