const {chromium}=require(process.env.RUNNER_TEMP+'/pw/node_modules/playwright'),fs=require('fs');
const f=JSON.parse(fs.readFileSync(process.env.RUNNER_TEMP+'/cp144-fixture.json')), local='http://127.0.0.1:18765',pub='http://127.0.0.1:18766/public',out=process.env.RUNNER_TEMP+'/screenshots';
fs.mkdirSync(out,{recursive:true}); let failures=0;
function ck(v,n){if(!v)throw Error(n);console.log('PASS '+n)}
async function group(n,fn){try{await fn();console.log('GROUP PASS '+n)}catch(e){failures++;console.log('GROUP FAIL '+n+' '+e.stack)}}
async function auth(p,base,user='qa-target'){await p.goto(base+(base===local?'/login.php':'/staff/login'));const h=p.locator('[data-login-selection]');await h.waitFor({state:'attached'});const key=base===local?user:'user:'+f.users.find(u=>u.name===user).id;if(await h.inputValue()!==key){await p.locator('.sc-login-flow-change').click();await p.locator('[data-login-account][data-account-key="'+key+'"]').click();await p.locator('.sc-login-stable-picker').waitFor({state:'hidden'});}await p.locator('input[name=password]').fill(user==='qa-target'?'synthetic-qa-admin-not-real':'synthetic-member-only');await Promise.all([p.waitForNavigation(),p.locator('form[data-login-form] button[type=submit]').click()]);ck(!p.url().includes('/login'),'real login '+base)}
async function overflow(p){ck(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'no overall horizontal overflow')}
(async()=>{const b=await chromium.launch({channel:'chrome',headless:true});async function withPage(base,w,fn){const ctx=await b.newContext({viewport:{width:w,height:900},locale:'fa-IR'});const p=await ctx.newPage();p.setDefaultTimeout(16000);try{p.on('pageerror',e=>console.log('PAGE ERROR '+e.message));await auth(p,base);await fn(p)}finally{await ctx.close()}}


const cp=require('child_process');
const fixture=mode=>console.log(cp.execFileSync('php',[process.env.QA_REPO+'/qa/post-release-a10/a14-fixture.php',process.env.RUNNER_TEMP+'/local',mode],{encoding:'utf8'}).trim());
async function shot(p,n){await p.screenshot({path:out+'/'+n+'.png',fullPage:true})}
await group('Public real DB cached dashboard and signed reconnect',()=>withPage(pub,390,async p=>{
 fixture('sync');await p.goto(pub+'/staff/');const dash=p.locator('[data-public-dashboard]');await dash.waitFor();
 const values=()=>p.locator('.sc-dashboard-kpi div>strong').allTextContents();
 const read=async()=>{const r=await p.request.get(pub+'/api/staff/read?model=reports');ck(r.status()===200,'authorized reports projection');return await r.json()};
 const initial=await read();ck(initial.stale===false,'fresh signed reports before disconnect');const fresh=await values();ck(fresh.length===5&&fresh.every(x=>x!=='نامشخص'&&x!=='—'),'five known live values');await shot(p,'dashboard-live');
 fixture('age');const aged=await read();ck(aged.stale===true&&aged.freshness?.reason==='local_heartbeat_stale','real DB freshness stale after simulated heartbeat loss');
 await p.waitForFunction(()=>document.querySelector('[data-public-dashboard]')?.dataset.dashboardCached==='1',{},{timeout:35000});const cached=await values();ck(JSON.stringify(cached)===JSON.stringify(fresh),'cached KPI values preserved');ck((await dash.innerText()).includes('غیرزنده'),'cached data labeled');await shot(p,'dashboard-stale');
 fixture('sync');await p.waitForFunction(()=>document.querySelector('[data-public-dashboard]')?.dataset.dashboardCached==='0',{},{timeout:35000});ck(JSON.stringify(await values())===JSON.stringify(fresh),'same values after signed recovery');await shot(p,'dashboard-reconnected');
}));
await b.close();console.log('A14_BROWSER_GROUP_FAILURES='+failures);process.exitCode=failures?1:0})().catch(e=>{console.error(e);process.exitCode=1});
