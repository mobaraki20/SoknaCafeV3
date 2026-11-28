const {chromium}=require(process.env.QA_WORK+'/pw/node_modules/playwright'),fs=require('fs');
const W=process.env.QA_WORK,base='http://127.0.0.1:18765',f=JSON.parse(fs.readFileSync(W+'/cp144-fixture.json'));
const accepted=[];let failures=0;
function ck(v,n){if(!v)throw Error(n);console.log('PASS '+n)}
async function group(n,fn){try{await fn();console.log('GROUP PASS '+n)}catch(e){failures++;console.log('GROUP FAIL '+n+' '+e.stack)}}
async function auth(p){await p.goto(base+'/login.php');const h=p.locator('[data-login-selection]');await h.waitFor({state:'attached'});if(await h.inputValue()!=='qa-target'){await p.locator('.sc-login-flow-change').click();await p.locator('[data-login-account][data-account-key="qa-target"]').click();await p.locator('.sc-login-stable-picker').waitFor({state:'hidden'});}await p.locator('input[name=password]').fill('synthetic-qa-admin-not-real');await Promise.all([p.waitForNavigation(),p.locator('form[data-login-form] button[type=submit]').click()]);ck(!p.url().includes('/login'),'real administrator login')}
async function ready(p,sel){await p.waitForFunction(s=>{const el=document.querySelector(s);return el&&!el.disabled&&el.offsetParent!==null},sel,{timeout:20000})}
async function putDate(p,selector,iso){
 const wrap=p.locator(selector+' + .sc-jalali-control');
 const date=wrap.locator('.sc-jalali-date'),time=wrap.locator('.sc-jalali-time');
 const j=iso?await p.evaluate(s=>window.SoknaJalali.gregorianToJalali(...s.slice(0,10).split('-').map(Number)).map((n,i)=>String(n).padStart(i?2:4,'0')).join('/'),iso):'';
 await date.fill(j);if(await time.count())await time.fill(iso?iso.slice(11,16):'');
 await ck(await p.locator(selector).inputValue()===iso,'visible date produces correct hidden value '+selector+' '+iso);
}
async function mutate(p,selector,action){
 const pending=p.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/finance/api.php')&&r.request().postDataJSON().action===action);
 await p.locator(selector).click();const r=await pending,j=await r.json();ck(r.ok()&&j.success,'UI saved '+action);
 const data=r.request().postDataJSON();await ready(p,selector);
 return {data,result:j.result}
}
async function checkTax(p,kind){
 const rate=kind==='tax_rate',field=rate?'[data-tax-effective]':'[data-tax-policy-effective]',save=rate?'[data-save-tax-rate]':'[data-save-tax-policy]';
 if(!rate){await ready(p,'[data-tax-item]');await p.locator('[data-tax-item]').selectOption(String(f.tax_item));await ready(p,save);await p.locator('[data-tax-policy]').selectOption('custom_rate')}
 for(const [i,iso] of ['2026-03-21T04:05','2030-01-02T09:15',''].entries()){
  await ready(p,save);
  await p.locator(rate?'[data-tax-rate]':'[data-tax-custom]').fill(String(7+i));
  await putDate(p,field,iso);
  const started=Date.now(),r=await mutate(p,save,kind);
  ck(r.data.effective_from===iso.replace('T',' '),'actual UI submitted exact intent '+kind+' '+(iso||'now'));
  if(iso)ck(r.result.effective_from.startsWith(iso+':00')&&r.result.effective_from.endsWith('+03:30'),'response confirms Iran local timestamp '+kind);
  else ck(Math.abs(Date.parse(r.result.effective_from)-started)<120000,'blank intent records now '+kind);
  accepted.push({kind,id:r.result.id,expected:r.result.effective_from.slice(0,19).replace('T',' '),rate_bps:(7+i)*100});
 }
 await p.screenshot({path:W+'/receipts/'+kind+'.png'});
}
(async()=>{const b=await chromium.launch({channel:'chrome',headless:true});const c=await b.newContext({viewport:{width:1280,height:900},locale:'fa-IR'});const p=await c.newPage();p.setDefaultTimeout(20000);
 try{await auth(p);await p.goto(base+'/finance/?tab=tax');await p.locator('[data-tab="settings"]').click();await ready(p,'[data-save-tax-rate]');
 await group('FIN-L220 UI tax rate explicit future now',()=>checkTax(p,'tax_rate'));
 await group('FIN-L220 UI item policy explicit future now',()=>checkTax(p,'tax_policy'));
 await group('FIN-L220 actual invoice filter dates',async()=>{
  await p.goto(base+'/finance/?tab=receipts');await p.locator('[data-invoice-from] + .sc-jalali-control .sc-jalali-date').waitFor({state:'visible'});
  await putDate(p,'[data-invoice-from]','2026-01-01');
  const pending=p.waitForResponse(r=>{const u=new URL(r.url());return u.pathname.endsWith('/finance/api.php')&&u.searchParams.get('action')==='invoices'&&u.searchParams.get('from')==='2026-01-01'&&u.searchParams.get('to')==='2030-12-31'});
  await putDate(p,'[data-invoice-to]','2030-12-31');const res=await pending;ck(res.ok()&&(await res.json()).success,'real invoice search preserved both dates');
  const req=[];const listener=r=>{if(r.url().includes('/finance/api.php')&&new URL(r.url()).searchParams.get('action')==='invoices')req.push(r.url())};p.on('request',listener);
  await p.locator('[data-invoice-from] + .sc-jalali-control .sc-jalali-date').fill('1405/99/99');await p.waitForTimeout(1000);
  ck(req.length===0,'invalid invoice date does not send an unfiltered request');p.off('request',listener);
  await putDate(p,'[data-invoice-from]','2026-01-01');await p.screenshot({path:W+'/receipts/invoice-filter.png'});
 });
 await group('FIN-L220 real expense form timestamp',async()=>{
  await p.goto(base+'/operations/?tab=expenses');await ready(p,'[data-open="expense-create"]');
  await p.locator('[data-open="expense-create"]').click();const form=p.locator('form[data-form="expense-create"]');await form.waitFor({state:'visible'});
  const cat=form.locator('select[name="category_key"]');await p.waitForFunction(()=>document.querySelector('form[data-form="expense-create"] select[name="category_key"]')?.options.length>0);
  if(!await cat.inputValue())await cat.selectOption({index:0});
  await form.locator('[name="amount"]').fill('12345');await form.locator('[name="description"]').fill('Synthetic CC48 dated expense');
  const sel='form[data-form="expense-create"] [name="occurred_at"]';
  const iso=await p.evaluate(()=>{const d=new Date(Date.now()-60000),a=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(d).map(x=>[x.type,x.value]));return a.year+'-'+a.month+'-'+a.day+'T'+a.hour+':'+a.minute});
  await putDate(p,sel,iso);
  const pending=p.waitForResponse(r=>r.url().includes('/operations/api.php')&&r.request().method()==='POST'&&r.request().postDataJSON().action==='expense_create');
  await form.locator('[data-ops-submit]').click();const res=await pending,j=await res.json();
  ck(res.ok()&&j.success&&Number(j.result.id)>0,'actual UI expense committed');
  ck(res.request().postDataJSON().occurred_at===iso.replace('T',' '),'expense intent preserved');
  accepted.push({kind:'expense_create',id:j.result.id,expected:iso.replace('T',' ')+':00',amount:12345});await form.waitFor({state:'hidden'});await p.screenshot({path:W+'/receipts/expense.png'});
 });
 }finally{fs.writeFileSync(W+'/receipts/accepted-ui.json',JSON.stringify(accepted));await c.close();await b.close()}
 if(failures)process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
