(()=>{'use strict';
const root=document.querySelector('[data-move-owner-page]');if(!root)return;
const base=String(root.dataset.publicBase||'').replace(/\/$/,'');
const el=selector=>root.querySelector(selector);
const status=(message,error=false)=>{const item=el('[data-status]');item.textContent=message;item.classList.toggle('error',error)};
const sections=(login,approve)=>{el('[data-login-section]').hidden=!login;el('[data-approve-section]').hidden=!approve;};
let challenge=null,returnOrigin='',upgradeCsrf='';
const canonical=value=>{try{return new URL(value).href.replace(/\/$/,'')}catch{return ''}};
function parseRequest(){
 const hash=new URLSearchParams(location.hash.slice(1));
 const encoded=hash.get('challenge')||'';
 const target=hash.get('return_origin')||'';
 if(encoded.length>2600||!/^[a-zA-Z0-9_-]{20,2600}$/.test(encoded))throw Error('درخواست انتقال معتبر نیست.');
 let json='';
 try{
   const raw=atob(encoded.replace(/-/g,'+').replace(/_/g,'/'));
   const bytes=Uint8Array.from(raw,c=>c.charCodeAt(0));
   json=new TextDecoder('utf-8',{fatal:true}).decode(bytes);
 }catch{throw Error('درخواست انتقال قابل خواندن نیست.');}
 const parsed=JSON.parse(json);
 if(!parsed||parsed.contract!=='sokna-public-move-attestation-v1'||
    canonical(parsed.target_base_url)!==canonical(base)||
    typeof parsed.operation_id!=='string'||
    typeof parsed.local_installation_id!=='string'||
    !Number.isInteger(parsed.expires_at)||parsed.expires_at*1000<=Date.now()||
    parsed.expires_at*1000>Date.now()+310000)throw Error('درخواست انتقال مربوط به این وب عمومی نیست یا منقضی شده است.');
 const origin=new URL(target);
 if(!['https:','http:'].includes(origin.protocol)||origin.origin!==target)
   throw Error('نشانی بازگشت معتبر نیست.');
 if(!window.opener||window.opener.closed)throw Error('این صفحه باید از بخش انتقال سامانه باز شود.');
 return {parsed,origin:origin.origin};
}
async function action(name,body={}){
 const reply=await fetch(base+'/api/v1/owner/'+name,{
   method:'POST',credentials:'same-origin',cache:'no-store',
   headers:{'Content-Type':'application/json','Accept':'application/json'},
   body:JSON.stringify(body)
 });
 let result={};try{result=await reply.json()}catch{}
 if(!reply.ok||result.ok!==true)throw Error('تأیید انجام نشد؛ ورود مدیر یا اعتبار درخواست را بررسی کنید.');
 return result;
}
async function withBusy(button,fn){button.disabled=true;try{await fn()}catch(e){status(e?.message||'خطا در تأیید مدیر',true)}finally{button.disabled=false}}
try{
 const parsed=parseRequest();challenge=parsed.parsed;returnOrigin=parsed.origin;
 // URL fragment never goes to the web server or external assets.
 history.replaceState(null,'',location.pathname+location.search);
 sections(true,false);
 status('درخواست انتقال دریافت شد؛ لطفاً با حساب مدیر این وب عمومی تأیید کنید.');
}catch(e){sections(false,false);el('[data-owner-upgrade]').hidden=true;status(e.message||'درخواست معتبر نیست.',true);return;}
el('[data-owner-login]').addEventListener('click',e=>withBusy(e.currentTarget,async()=>{
 const password=el('[data-password]').value;
 if(!password)throw Error('رمز مدیریت را وارد کنید.');
 await action('login',{password});
 el('[data-password]').value='';
 sections(false,true);status('ورود مدیر موفق بود. برای تأیید مقصد، دکمهٔ زیر را بزنید.');
}));
el('[data-owner-approve]').addEventListener('click',e=>withBusy(e.currentTarget,async()=>{
 const prep=await action('consent_prepare',{challenge});
 const signed=await action('consent_sign',{challenge,csrf:prep.csrf});
 const attestation=signed.proof?.attestation;
 if(!attestation||!signed.receipt_envelope)throw Error('تأیید امضاشده از وب عمومی دریافت نشد.');
 window.opener.postMessage({
   type:'sokna-public-move-owner-approved-v1',
   operation_id:challenge.operation_id,
   target_base_url:base,
   attestation,
   receipt_envelope:signed.receipt_envelope
 },returnOrigin);
 sections(false,false);
 el('[data-owner-upgrade]').hidden=true;
 status('مالکیت این وب عمومی تأیید شد. به پنجرهٔ سامانهٔ مدیریت برگردید.');
}));
el('[data-upgrade-check]').addEventListener('click',e=>withBusy(e.currentTarget,async()=>{
 const result=await action('upgrade_preflight',{});
 if(result.result?.owner_initialized===true){
   status('حساب مدیر از قبل آماده است. با رمز مدیر وب عمومی وارد شوید.');
   sections(true,false);
   return;
 }
 if(!result.result?.csrf)throw Error('حساب مدیر این نصب هنوز آماده نیست.');
 upgradeCsrf=result.result.csrf;
 el('[data-upgrade-form]').hidden=false;
 status('حساب مدیر کارکنان تأیید شد. یک رمز مدیریت وب عمومی تعیین کنید.');
}));
el('[data-upgrade-create]').addEventListener('click',e=>withBusy(e.currentTarget,async()=>{
 const password=el('[data-upgrade-password]').value;
 if(password.length<12||password.length>256)throw Error('رمز مدیریت باید بین ۱۲ تا ۲۵۶ نویسه باشد.');
 await action('upgrade_activate',{csrf:upgradeCsrf,new_owner_password:password});
 el('[data-upgrade-password]').value='';
 el('[data-upgrade-form]').hidden=true;
 el('[data-owner-upgrade]').open=false;
 sections(true,false);
 status('رمز مدیریت ساخته شد. با همین رمز وارد شوید و مقصد را تأیید کنید.');
}));
})();
