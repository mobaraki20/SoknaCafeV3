from pathlib import Path
import json,sys,re
from playwright.sync_api import sync_playwright,expect
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True)
origin=f['origin'];api=origin+'/printing/api.php';patched=f['mode']=='patched';result={}
def save(page,key):page.screenshot(path=str(out/(key+'.png')),animations='disabled')
def login(ctx,name):
 r=ctx.request.post(origin+'/login.php',form={'username':name,'password':f['password']},max_redirects=0);assert r.status in (302,303),r.status
def get(ctx,id):return ctx.request.get(api+'?action=print_template_details&package_id='+str(id)).json()['result']
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 for kind,width in [('preparation',390),('customer',1280)]:
  ctx=b.new_context(viewport={'width':width,'height':844});login(ctx,f['username']);page=ctx.new_page();page.set_default_timeout(15000)
  assert page.goto(origin+'/printing/?tab=templates',wait_until='domcontentloaded').ok
  panel=page.locator('[data-print-panel="templates"]');expect(panel).to_be_visible()
  base=f['base_ids'][kind];old=get(ctx,base);expect(page.locator('.sc-print-template-version.is-active')).to_have_count(2)
  card=page.locator('.sc-print-template-version.is-active').filter(has_text='آماده‌سازی' if kind=='preparation' else 'فیش مشتری')
  card.get_by_role('button',name='ویرایش به نسخه جدید').click()
  d=page.locator('[data-dialog="template-editor"]');expect(d).to_be_visible()
  form=d.locator('[data-template-editor-form]');area=d.locator('[data-template-live-preview]')
  form.locator('[name=header_title]').fill('QA '+kind+' title')
  form.locator('[name=header_subtitle]').fill('QA subtitle')
  form.locator('[name=footer]').fill('QA footer')
  form.locator('details:has([name=base_font_size]) > summary').click()
  expect(form.locator('[name=base_font_size]')).to_be_visible()
  font_fields={'base_font_size':30,'title_font_size':40,'header_subtitle_font_size':34,'table_font_size':42,'notes_font_size':38,'footer_font_size':44,'totals_font_size':46,'settlement_font_size':36,'meta_font_size':32,'line_spacing':8,'margin':10}
  for key,value in font_fields.items():
   persian=str(value).translate(str.maketrans('0123456789','۰۱۲۳۴۵۶۷۸۹'))
   form.locator('[name='+key+']').fill(persian)
  form.locator('[name=show_section_titles]').select_option('1')
  form.locator('[name=show_order_number]').select_option('1')
  if kind=='customer':form.locator('[name=show_settlement_label]').select_option('1')
  form.locator('[name=item_layout]').select_option('columnar-notes' if kind=='preparation' else 'single-line')
  if kind=='customer':form.locator('[name=merchant_card_number]').fill('۶۰۳۷۹۹۱۲۳۴۵۶۷۸۹۰')
  for paper in ['80','58']:
   d.locator('[data-template-preview-paper]').select_option(paper)
   title=area.locator('.sc-print-r318-title');expect(title).to_have_text('QA '+kind+' title')
   actual=title.evaluate('(e)=>e.style.fontSize')
   if patched:
    assert actual=='20px',(paper,actual)
    assert area.locator('.sc-print-r318-subtitle').evaluate('(e)=>e.style.fontSize')=='17px'
    assert area.locator('.sc-template-live-footer').last.evaluate('(e)=>e.style.fontSize')=='22px'
    assert area.locator('.sc-print-r318-item').first.evaluate('(e)=>e.style.fontSize')=='21px'
    assert area.locator('.sc-template-live-meta').first.evaluate('(e)=>e.style.fontSize')=='16px'
   else:assert actual!='20px',('original unexpectedly fixed',actual)
   assert area.locator('.sc-print-r318-title').count()==1
   assert area.locator('.sc-print-r318-item').count()==(3 if kind=='preparation' else 2)
   if kind=='preparation':assert area.locator('.sc-print-r318-columns.is-three').count()==(4 if paper=='80' else 0)
   save(page,kind+'-'+paper+'-'+f['mode'])
  area.locator('[data-preview-edit="title"]').click()
  expect(form.locator('[name=header_title]')).to_be_focused()
  # Draft is an actual authenticated API request, not a synthetic response.
  with page.expect_response(lambda r:r.url==api and r.request.method=='POST' and 'print_template_draft_preview' in (r.request.post_data or ''),timeout=15000) as pr:
   d.locator('[data-template-draft-preview]').click()
  response=pr.value;submitted=response.request.post_data_json['package']['template'];body=response.json()
  if patched:
   assert response.ok and body['success'],body.get('code')
   payload=json.loads(body['result']['preview']['payload_json'])
   for key,value in font_fields.items():assert submitted[key]==value and payload['template'][key]==value,(key,submitted.get(key),payload['template'].get(key))
   assert payload['template']['footer']=='QA footer' and body['result']['draft']
   assert get(ctx,base)['package']==old['package']
   (out/(kind+'-native-payload.json')).write_text(json.dumps(payload,ensure_ascii=False))
  else:
   assert submitted['title_font_size'] is None and not response.ok,(response.status,submitted)
   print('PASS original defect reproduced: Persian title font NaN and invalid exact preview '+kind,flush=True)
  page.locator('[data-preview-close]:visible').first.click();expect(d).to_be_visible()
  # Save actual browser form; capture server response and independent readback.
  with page.expect_response(lambda r:r.url==api and r.request.method=='POST' and 'print_template_version_save' in (r.request.post_data or '')) as sr:
   form.locator('button[type=submit][value=submit]').click()
  body=sr.value.json();assert sr.value.ok and body['success'],body.get('code');new=int(body['result']['package_id'])
  expect(d).not_to_be_visible();saved=get(ctx,new);assert saved['active'] is False and get(ctx,base)['active']
  for key,value in font_fields.items():assert saved['template'][key]==value,(key,saved['template'].get(key))
  assert get(ctx,base)['package']==old['package']
  page.locator('.sc-print-template-version.is-pending').filter(has_text=saved['package']['version']).get_by_role('button',name='فعال‌سازی این نسخه').click()
  expect(page.locator('.sc-print-template-version.is-active').filter(has_text=saved['package']['version'])).to_be_visible()
  assert get(ctx,new)['active'] and not get(ctx,base)['active']
  csrf=page.locator('[data-printing-workspace]').get_attribute('data-csrf')
  def post(action,**kw):return ctx.request.post(api,data={'action':action,'csrf_token':csrf,**kw},headers={'X-CSRF-Token':csrf})
  r=post('print_template_delete',package_id=new);assert r.status==409
  r=post('print_template_rollback',package_id=base);assert r.ok and get(ctx,base)['active']
  r=post('print_template_delete',package_id=new);assert r.ok
  raw=json.dumps(saved['package'],ensure_ascii=False)
  r=post('print_template_import',package_json=raw);assert r.ok
  imported=int(r.json()['result']['package_id']);assert get(ctx,imported)['package']==saved['package']
  r=post('print_template_import',package_json=raw);assert r.ok and r.json()['result']['deduplicated']
  changed=dict(saved['package']);changed['description']='QA conflicting version'
  assert post('print_template_import',package_json=json.dumps(changed,ensure_ascii=False)).status==409
  result[kind]={'base':base,'imported':imported,'template':saved['template']}
  print('PASS real edit/save/activate/rollback/delete/import '+kind,flush=True)
  ctx.close()
 c=b.new_context();login(c,f['operator']);assert c.request.get(api).status==403;c.close();b.close()
(out/'result.json').write_text(json.dumps(result,ensure_ascii=False))
