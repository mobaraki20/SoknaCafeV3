(()=>{'use strict';
const root=document.querySelector('[data-move-owner-page]');if(!root)return;
const base=String(root.dataset.publicBase||'').replace(/\/$/,'');
const el=selector=>root.querySelector(selector);
const status=(message,error=false)=>{const item=el('[data-status]');item.textContent=message;item.classList.toggle('error',error)};
const sections=(login,approve)=>{el('[data-login-section]').hidden=!login;el('[data-approve-section]').hidden=!approve;};
let challenge=null,returnOrigin='',upgradeCsrf='',minimumNextEpoch=0;
const canonical=value=>{try{return new URL(value).href.replace(/\/$/,'')}catch{return ''}};
function parseRequest(){
 const hash=new URLSearchParams(location.hash.slice(1));
 const encoded=hash.get('challenge')||'';
 const target=hash.get('return_origin')||'';
 const requestedEpoch=Number(hash.get('minimum_next_epoch')||0);
 if(!Number.isSafeInteger(requestedEpoch)||requestedEpoch<2||requestedEpoch>2147483600)
    throw Error('نسل اتصال مقصد معتبر نیست. بررسی را از Local دوباره آغاز کنید.');
 minimumNextEpoch=requestedEpoch;
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
 if(!reply.ok||result.ok!==true){
   const code=String(result.code||result.error||'');
   const messages={invalid_credentials:'رمز مدیر این وب عمومی پذیرفته نشد.',
     public_owner_not_initialized:'رمز مستقل مدیر هنوز ساخته نشده است؛ بخش آماده‌سازی رمز را باز کنید.',
     existing_move_other_installation:'این وب عمومی به نصب محلی دیگری متصل است؛ اتصال بدون بررسی مالکیت آن مجاز نیست.',
     existing_move_pending_or_unavailable:'در مقصد سفارش یا عملیات بررسی‌نشده وجود دارد، یا بازیابی امن آماده نیست. اتصال فعلی حفظ شد.',
     owner_action_denied:'درخواست تأیید معتبر نیست یا اعتبار آن تمام شده است. از Local دوباره بررسی را شروع کنید.',
     owner_action_failed:'تأیید مالک در سرور کامل نشد؛ اتصال فعلی حفظ شد.'};
   throw Error(messages[code]||('تأیید کامل نشد ('+String(reply.status)+'). کد پیگیری را در گزارش پشتیبانی بررسی کنید.'));
 }
 return result;
}
async function withBusy(button,fn){button.disabled=true;try{await fn()}catch(e){status(e?.message||'خطا در تأیید مدیر',true)}finally{button.disabled=false}}
try{
 const parsed=parseRequest();challenge=parsed.parsed;returnOrigin=parsed.origin;
 // URL fragment never goes to the web server or external assets.
 // Keep the short-lived fragment so refreshing the owner screen can resume.
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
 const kind=String(signed.move_kind||'fresh_unpaired');
 const pairingCode=String(signed.pairing_code||'');
 const ownerAuthorityCode=String(signed.owner_authority_code||'');
 const code=kind==='existing_paired'?ownerAuthorityCode:pairingCode;
 if(!attestation||!signed.receipt_envelope ||
    !['fresh_unpaired','existing_paired'].includes(kind) ||
    (kind==='fresh_unpaired' && !/^[A-F0-9]{8}(?:-[A-F0-9]{8}){2}$/.test(code)))
   throw Error('تأیید مالک معتبر نیست. اتصال فعلی دست‌نخورده باقی ماند.');
 window.opener.postMessage({
   type:'sokna-public-move-owner-approved-v1',
   operation_id:challenge.operation_id,
   target_base_url:base,
   attestation,
   receipt_envelope:signed.receipt_envelope,
   pairing_code:kind==='fresh_unpaired'?code:'',
   owner_authority_code:kind==='existing_paired'?code:'',
   owner_next_epoch:Number(signed.owner_next_epoch)||0,
   move_kind:kind
 },returnOrigin);
 sections(false,false);
 el('[data-owner-upgrade]').hidden=true;
 status(kind==='existing_paired'?'مالکیت تأیید شد؛ منتظر تأیید مدیر سامانه محلی هستیم.':
   'مالکیت این وب عمومی تأیید شد. به پنجرهٔ سامانهٔ مدیریت برگردید.');
}));
window.addEventListener('message',async event=>{
 if(!challenge||event.source!==window.opener||event.origin!==returnOrigin)return;
 const v=event.data||{};
 if(v.type!=='sokna-public-move-local-confirmed-v1'||
    v.operation_id!==challenge.operation_id)return;
 try{
   // Local admin approval preceded this request. The remote owner session is
   // still required. No Emergency password or technical recovery UI is shown.
   const csrf=(await action('consent_prepare',{challenge})).csrf;
   const offer=await action('offer_existing',{challenge,csrf,
      minimum_next_epoch:Math.max(minimumNextEpoch,2,Number(v.minimum_next_epoch)||0)});
   const code=String(offer.owner_authority_code||'');
   if(!/^[A-F0-9]{8}(?:-[A-F0-9]{8}){2}$/.test(code))
     throw Error('مجوز یک‌بارمصرف مقصد آماده نشد.');
   window.opener.postMessage({
     type:'sokna-public-move-owner-offer-v1',
     operation_id:challenge.operation_id,
     target_base_url:base,
     owner_authority_code:code,
     owner_next_epoch:Number(offer.owner_next_epoch)||0,
     move_kind:'existing_paired',
   },returnOrigin);
   status('اجازه مالک صادر شد. ادامهٔ انتقال در Local انجام می‌شود.');
 }catch(e){status(e?.message||'صدور مجوز اتصال کامل نشد.',true)}
});
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
