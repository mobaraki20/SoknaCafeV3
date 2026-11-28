const {chromium}=require(process.env.QA_WORK+'/pw/node_modules/playwright'),fs=require('fs');
const W=process.env.QA_WORK,f=JSON.parse(fs.readFileSync(W+'/cp144-fixture.json'));
const L='http://127.0.0.1:18765',P='http://127.0.0.1:18766/public';
function ck(x,m){if(!x)throw Error(m);console.log('PASS '+m)}
async function auth(p,base,user){
 await p.goto(base+(base===L?'/login.php':'/staff/login'));
 const h=p.locator('[data-login-selection]');await h.waitFor({state:'attached'});
 const key=base===L?user:'user:'+f.users.find(u=>u.name===user).id;
 if(await h.inputValue()!==key){await p.locator('.sc-login-flow-change').click();await p.locator('[data-login-account][data-account-key="'+key+'"]').click();await p.locator('.sc-login-stable-picker').waitFor({state:'hidden'});}
 await p.locator('input[name=password]').fill(user==='qa-target'?'synthetic-qa-admin-not-real':'synthetic-member-only');
 await Promise.all([p.waitForNavigation(),p.locator('form[data-login-form] button[type=submit]').click()]);
 ck(!p.url().includes('/login'),'authenticated '+user+' '+base);
}
(async()=>{
 const b=await chromium.launch({channel:'chrome',headless:true});let failed=0;
 try{
 for(const base of [L,P])for(const [user,allowed,width] of [['qa-supervisor',false,390],['qa-cashier',false,1280],['qa-cost-supervisor',true,390],['qa-target',true,1280]]){
  const label=(base===L?'local':'public')+'-'+user+'-'+width;
  const ctx=await b.newContext({viewport:{width,height:900},locale:'fa-IR'});
  const p=await ctx.newPage();p.setDefaultTimeout(16000);
  try{
   await auth(p,base,user);
   await p.goto(base+(base===L?'/reports/':'/staff/reports/'));
   await p.locator('[data-summary] [data-report-kpi="net_sales"]').waitFor({state:'visible'});
   for(const k of ['inventory','margin']){
    await p.locator('#report-tab-'+k).click();
    const s=p.locator('[data-report-section="'+k+'"]');await s.waitFor({state:'visible'});
    const m=s.locator('[data-report-unavailable]');
    if(allowed){
     ck(!await m.isVisible(),'authorized '+k+' '+label);
     const metric=s.locator(k==='inventory'?'[data-inventory-metrics]':'[data-margin-coverage]');
     await metric.waitFor({state:'visible'});
     ck((await metric.innerText()).trim().length>0,'real contents '+k+' '+label);
    }else{
     await m.waitFor({state:'visible'});
     ck((await m.innerText()).includes('مجوز مشاهده بهای مواد'),'permission message '+k+' '+label);
     ck(await s.evaluate(el=>[...el.children].filter(c=>!c.hasAttribute('data-report-unavailable')).every(c=>c.hidden)),'restricted children hidden '+k+' '+label);
    }
    await p.screenshot({path:W+'/receipts/'+label+'-'+k+'.png',animations:'disabled'});
   }
   await p.locator('#report-tab-overview').click();
   ck(await p.locator('[data-summary] [data-report-kpi="net_sales"]').isVisible(),'sales still visible '+label);
   if(base===L){
    const analytics=p.locator('[data-export-analytical]');
    ck((await analytics.count()>0)===allowed,'export control permission '+label);
   }
   ck(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'viewport fits '+label);
   console.log('GROUP PASS REP-L281 '+label);
  }catch(e){failed++;console.log('GROUP FAIL REP-L281 '+label+' '+e.stack);await p.screenshot({path:W+'/receipts/'+label+'-failure.png',animations:'disabled'}).catch(()=>{});}
  finally{await ctx.close();}
 }
 }finally{await b.close();}
 if(failed)process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
