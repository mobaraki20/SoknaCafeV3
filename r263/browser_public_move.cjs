'use strict';
// One real Chromium click-through of the exact ZIP JS. HTTP results are controlled;
// HMAC/DB/Windows installation have independent R262 acceptance gates.
const {chromium}=require('playwright'),fs=require('fs'),assert=require('assert/strict');
if(process.env.GITHUB_ACTIONS!=='true')throw Error('DISPOSABLE_CI_ONLY');
const LOCAL='https://local.r263.test',PUBLIC='https://fresh-public.r263.test';
const localJs=fs.readFileSync(process.env.R263_LOCAL_JS,'utf8');
const publicJs=fs.readFileSync(process.env.R263_PUBLIC_JS,'utf8');
const trace=[],state={begun:false,approved:false,finish:0};
const localHTML=`<!doctype html><html dir=rtl lang=fa><meta charset=utf-8><div data-connections-workspace data-api="/integrations/api.php" data-csrf="qa">
<form data-public-move-form><input data-public-move-target value="${PUBLIC}"><button data-public-move-check type=submit>بررسی و انتقال</button></form>
<button data-public-move-resume hidden>ادامهٔ اتصال</button><button data-public-move-cancel hidden>لغو درخواست</button><div data-public-move-feedback></div>
<form data-public-pair-form></form><button data-public-sync></button><button data-public-probe></button><button data-public-reenroll></button>
<script>window.SoknaUI={busy:async(_e,fn)=>fn(),confirm:async()=>true,toast:()=>{}};
const root=document.querySelector('[data-connections-workspace]'),query=root.querySelector.bind(root);
root.querySelector=s=>query(s)||document.createElement(s.includes('form')?'form':'div');</script>
<script src="/assets/connections-workspace.js"></script></div></html>`;
const publicHTML=`<!doctype html><html lang=fa dir=rtl><meta charset=utf-8><body data-move-owner-page data-public-base="${PUBLIC}">
<div data-status></div><section data-login-section hidden><input data-password type=password><button data-owner-login>ورود مدیر</button></section>
<section data-approve-section hidden><button data-owner-approve>تأیید مالکیت مقصد</button></section>
<details data-owner-upgrade><button data-upgrade-check></button><div data-upgrade-form hidden><input data-upgrade-password><button data-upgrade-create></button></div></details>
<script src="/assets/sokna-public-move.js"></script></body></html>`;
const json=(r,v)=>r.fulfill({status:200,contentType:'application/json',body:JSON.stringify(v)});
async function dispatch(route){
 const req=route.request(),url=new URL(req.url());
 if(req.method()==='GET'){
  if(url.origin===LOCAL&&url.pathname==='/')return route.fulfill({status:200,contentType:'text/html',body:localHTML});
  if(url.origin===LOCAL&&url.pathname==='/assets/connections-workspace.js')return route.fulfill({status:200,contentType:'application/javascript',body:localJs});
  if(url.origin===PUBLIC&&url.pathname==='/move/approve')return route.fulfill({status:200,contentType:'text/html',body:publicHTML});
  if(url.origin===PUBLIC&&url.pathname==='/assets/sokna-public-move.js')return route.fulfill({status:200,contentType:'application/javascript',body:publicJs});
  return route.fulfill({status:404,body:'not found'});
 }
 const body=req.postDataJSON();
 if(url.origin===LOCAL){
  const action=body.action;trace.push('Local:'+action);
  if(action==='public_move_status')return json(route,{success:true,move:{status:'none'}});
  if(action==='public_move_inspect')return json(route,{success:true,public_move:{route:'awaiting_owner'}});
  if(action==='public_move_begin'){
   state.begun=true;const now=Math.trunc(Date.now()/1000);
   return json(route,{success:true,move:{operation_id:'move-browser-r263-001',challenge:{
    contract:'sokna-public-move-attestation-v1',operation_id:'move-browser-r263-001',
    local_installation_id:'local-r263-001',source_public_instance_id:'',
    source_base_url:'https://old-public-unreachable.r263.test',target_base_url:PUBLIC,
    nonce:'A'.repeat(48),issued_at:now,expires_at:now+300
   }}});
  }
  if(action==='public_move_confirm'){
   state.approved=body.pairing_code==='AAAAAAAA-BBBBBBBB-CCCCCCCC'&&!!body.receipt_envelope&&!!body.attestation;
   return json(route,state.approved?{success:true,move:{status:'sync_pending'}}:{success:false,message:'missing signed owner proof'});
  }
  if(action==='public_move_finish'){
   state.finish++;
   return json(route,state.finish===1?{success:true,move:{status:'sync_retry',auto_retry:true,media_remaining:1}}:{success:true,move:{status:'completed',completed:true}});
  }
  return json(route,{success:true,snapshot:{accommodation:{},public_edge:{},errors:{},print_agents:[],windows_runtime:{},windows_services:{},lan_staff:{},public_origin_transition:{}}});
 }
 if(url.origin===PUBLIC){
  const action=url.pathname.split('/').pop();trace.push('Public:'+action);
  if(action==='login')return json(route,{ok:true});
  if(action==='consent_prepare')return json(route,{ok:true,csrf:'B'.repeat(48)});
  if(action==='consent_sign')return json(route,{ok:true,proof:{attestation:{payload:{},signature_b64:'AAA'}},
   receipt_envelope:{receipt:{approval_receipt_id:'r263'}},pairing_code:'AAAAAAAA-BBBBBBBB-CCCCCCCC'});
 }
 return route.fulfill({status:404,body:'unknown'});
}
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const ctx=await browser.newContext({ignoreHTTPSErrors:true});await ctx.route('**/*',dispatch);
  const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push('Local '+e.message));
  await page.goto(LOCAL+'/',{waitUntil:'load'});
  const popupEvent=ctx.waitForEvent('page',{timeout:20000});await page.locator('[data-public-move-check]').click();
  const popup=await popupEvent;popup.on('pageerror',e=>errors.push('Public '+e.message));
  await popup.waitForURL(/fresh-public\.r263\.test\/move\/approve#/,{timeout:20000});
  await popup.locator('[data-password]').fill('qa-test-password');await popup.locator('[data-owner-login]').click();
  await popup.locator('[data-owner-approve]').waitFor({state:'visible',timeout:15000});
  await popup.locator('[data-owner-approve]').click();
  await page.waitForFunction(()=>document.querySelector('[data-public-move-feedback]')?.textContent.includes('متصل شد'),null,{timeout:20000});
  assert(state.begun&&state.approved,'owner approval did not reach Local');
  assert.equal(state.finish,2,'media sync auto retry missing');
  assert(!errors.length,JSON.stringify(errors));
  assert(trace.includes('Public:consent_sign'),'owner consent not sent');
  assert(!trace.some(x=>x.includes('old-public-unreachable')),'old public unexpectedly contacted');
  console.log('R263_REAL_R262_ZIP_JS_BROWSER_OWNER_AND_RETRY_PASS');console.log('FLOW',JSON.stringify(trace));
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});