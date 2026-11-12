from pathlib import Path
import json,sys,os
from playwright.sync_api import sync_playwright

ROOT=Path(sys.argv[1]) if len(sys.argv)>1 else Path('/mnt/data/qa_sokna_current')
JS={"public":ROOT/'public/assets/scds/guest.js',"local":ROOT/'local/public/assets/scds/guest.js'}
ITEMS=[{"id":11,"name":"آیس موکا","price":210000,"category":"آیس","order_available":True,"available":True,"takeaway_allowed":True,"is_bundle":False}, {"id":12,"name":"آیس لته","price":150000,"category":"آیس","order_available":True,"available":True,"takeaway_allowed":True,"is_bundle":False}]
HEAD='''<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head><body class="sg-mode-table"><div data-sg-app data-installation-id="qa" data-table-token="table-test" data-session-token="s1" data-device-token="d1" data-ordering-enabled="1" data-order-intake-enabled="1" data-mutation-ready="1" data-connectivity-state="fresh" data-order-endpoint="/order" data-orders-endpoint="/orders" data-quote-endpoint="/quote" data-context-endpoint="/context" data-waiter-endpoint="https://qa.invalid/waiter"><script type="application/json" data-sg-items-json>''' + json.dumps(ITEMS,ensure_ascii=False)+'''</script><button data-sg-order-submit>ثبت سفارش</button><div data-sg-order-message></div><span data-sg-toast hidden></span><div data-sg-cart-drawer hidden><div></div></div><div data-sg-success-modal hidden><div data-sg-success-code></div></div></div></body></html>'''
STORAGE='''() => {const store=new Map();const mk=()=>({getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,String(v)),removeItem:k=>store.delete(k),clear:()=>store.clear()});Object.defineProperty(window,'localStorage',{configurable:true,value:mk()});Object.defineProperty(window,'sessionStorage',{configurable:true,value:mk()});}'''
MOCK='''(scenario) => {
window.__orders=[];window.__quotes=[];window.__scenario=scenario;window.__appends=[];
window.fetch=async function(url,options){
 const data=JSON.parse(options?.body||'{}');let body={success:true},status=200;
 if(String(url)==='/orders') {
   if(scenario==='append') body={success:true,orders:[{status:'pending_approval',can_edit:true,edit_signature:'abc',order_code:'OQA',client_token:'old'}]};
   else body={success:true,orders:[]};
 } else if(String(url)==='/context')body={success:true,session:{token:'s1'},mutation_ready:true,can_order:true,order_policy_ready:true,order_intake_enabled:true,model_stale:false,order_acceptance:{cafe:true,bar:true,kitchen:true}};
 else if(String(url)==='/order') {
   window.__orders.push(data);
   if(scenario==='timeout')throw new Error('network failure');
   let incorrect=data.items.find(l=>Number(l.expected_price)!==(Number(l.id)===11?220000:Number(l.id)===12?160000:0));
   if(scenario==='normal')incorrect=undefined;
   if(incorrect){status=409;body={success:false,code:'price_changed',message:'قیمت تغییر کرد',details:{item_id:incorrect.id,bundle_choice_item_id:0,current_price:Number(incorrect.id)===11?220000:160000}}}
   else body={success:true,terminal:true,order_code:'QA1',order_number:2,status:'pending_approval',client_token:data.client_token};
 }else if(String(url)==='/quote') {
   window.__quotes.push(data);
   const incorrect=data.items.find(l=>Number(l.expected_price)!==(Number(l.id)===11?220000:160000));
   if(incorrect){status=409;body={success:false,code:'price_changed',details:{item_id:incorrect.id,bundle_choice_item_id:0,current_price:Number(incorrect.id)===11?220000:160000},message:'قیمت تغییر کرد'}}
   else body={success:true,financial_preview:{subtotal:data.items.reduce((v,l)=>v+l.quantity*l.expected_price,0),tax:0,discount:0,total:data.items.reduce((v,l)=>v+l.quantity*l.expected_price,0)}};
 }else if(String(url)==='/appends') {window.__appends.push(data);status=409;body={success:false,code:'price_changed',details:{item_id:11,current_price:220000},message:'قیمت تغییر کرد'};}
 return {ok:status>=200&&status<300,status,json:async()=>body};
};
}'''

def check_guest(browser,scope,scenario):
 page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content(HEAD);page.evaluate(STORAGE);page.evaluate(MOCK,scenario)
 src=JS[scope].read_text()
 marker='  bindMediaFallbacks();renderCart();renderWaiter();';assert marker in src
 src=src.replace(marker,"  globalThis.__QA={setCartQty,submitOrder,cart,payloadLines};\n"+marker)
 page.add_script_tag(content=src)
 assert page.evaluate('Boolean(window.__QA)'), (scope,errors)
 page.evaluate('window.__QA.setCartQty(11,1)');assert page.evaluate('window.__QA.cart.size')==1
 if scenario=='two':page.evaluate('window.__QA.setCartQty(12,1)');assert page.evaluate('window.__QA.cart.size')==2
 page.evaluate('() => {window.__QA.submitOrder();return true;}')
 if scenario in ['approve','decline','two']:
  page.locator('[data-sg-confirm-ok]').wait_for(timeout=4500)
  assert 'تأیید مبلغ جدید سفارش' in page.locator('[data-sg-confirm-title]').inner_text()
  if scenario=='decline':page.locator('[data-sg-confirm-modal] [data-sg-close-layer]').click(timeout=1000)
  else:page.locator('[data-sg-confirm-ok]').click(timeout=1000)
 if scenario in ['approve','two']:page.wait_for_function('window.__orders.length>=2',timeout=4500)
 else:page.wait_for_timeout(180)
 calls=page.evaluate('window.__orders.map(p=>p.items.map(i=>({id:i.id,price:i.expected_price,qty:i.quantity})))')
 remaining=page.evaluate('Array.from(window.__QA.cart.values()).map(i=>i.unit_price)')
 quotes=page.evaluate('window.__quotes.length')
 assert not errors,(scope,scenario,errors)
 if scenario=='approve':assert calls==[[{'id':11,'price':210000,'qty':1}],[{'id':11,'price':220000,'qty':1}]],calls;assert quotes>=1
 if scenario=='two': assert calls[0]==[{'id':11,'price':210000,'qty':1},{'id':12,'price':150000,'qty':1}],calls;assert calls[-1]==[{'id':11,'price':220000,'qty':1},{'id':12,'price':160000,'qty':1}];assert quotes>=2
 if scenario=='decline':assert len(calls)==1 and remaining==[210000],(calls,remaining)
 if scenario=='timeout': assert len(calls)==1 and quotes==0,(calls,quotes)
 if scenario=='normal': assert len(calls)==1 and quotes==0,(calls,quotes)
 page.close()
 return f'PASS {scope} {scenario}: orders={len(calls)} quotes={quotes}'

LOGIN_JS=(ROOT/'local/public/assets/login.js').read_text()
def login_html(n):
 cards=[]
 for i in range(n):
  name=f'همکار {i+1}'
  cards.append(f'<label class="sc-login-account" data-login-account data-account-key="staff{i+1}" data-theme-preference="light"><input type="radio" name="account_picker" value="staff{i+1}" {"checked" if i==0 else ""}><span class="sc-login-account__copy"><strong>{name}</strong><small>اپراتور</small></span></label>')
 return '<!doctype html><html lang="fa"><head><meta charset="utf-8"></head><body><section data-login-root data-login-storage-key="qa" data-selected-explicit="0"><form data-login-form><fieldset data-login-accounts><div class="sc-login-account-grid">'+''.join(cards)+'</div></fieldset><input data-login-selection type="hidden" name="username" value="staff1"><input type="password" name="password"><button type="submit">ورود</button></form></section></body></html>'

def check_login(browser,n):
 page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content(login_html(n));page.evaluate(STORAGE);page.add_script_tag(content=LOGIN_JS)
 if n<9:
  assert page.locator('.sc-login-picker-toggle').count()==0
  page.locator('[data-login-account]').nth(3).click()
  assert page.locator('[data-login-selection]').input_value()=='staff4'
  result='PASS login small retains cards'
 else:
  assert page.locator('.sc-login-picker-toggle').count()==1
  assert not page.locator('.sc-login-picker-panel').is_visible()
  page.locator('.sc-login-picker-toggle').click()
  assert page.locator('.sc-login-picker-panel').is_visible()
  page.locator('.sc-login-picker-search').fill('staff17')
  assert page.locator('[data-login-account]:visible').count()==1
  page.locator('[data-login-account]:visible').click()
  assert page.locator('[data-login-selection]').input_value()=='staff17'
  assert not page.locator('.sc-login-picker-panel').is_visible()
  assert 'همکار 17' in page.locator('.sc-login-picker-toggle').inner_text()
  assert page.locator('input[name="password"]').evaluate('el=>document.activeElement===el')
  page.locator('.sc-login-picker-toggle').click()
  page.locator('.sc-login-picker-search').fill('ناموجود')
  assert page.locator('[data-login-account]:visible').count()==0
  assert 'پیدا نشد' in page.locator('.sc-login-picker-count').inner_text()
  page.locator('.sc-login-picker-search').press('Escape')
  assert not page.locator('.sc-login-picker-panel').is_visible()
  result='PASS login 20 search, select, focus, escape, empty search'
 assert not errors,errors
 page.close();return result

if __name__=='__main__':
 with sync_playwright() as pl:
  browser=pl.chromium.launch(headless=True,executable_path=os.getenv('CHROMIUM_PATH') or '/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
  for scope in ['public','local']:
   for scenario in ['approve','decline','two','timeout','normal']:
    print(check_guest(browser,scope,scenario),flush=True)
  for n in [4,20]: print(check_login(browser,n),flush=True)
  browser.close()
