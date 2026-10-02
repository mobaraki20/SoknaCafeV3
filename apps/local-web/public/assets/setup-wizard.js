(()=>{
  'use strict';
  const root=document.querySelector('[data-setup-root]');
  if(!root)return;
  const appBase=String(document.body?.dataset?.appBase||'').replace(/\/$/,'');
  const appPath=value=>{const raw=String(value??'').trim();if(!raw||/^(?:https?:)?\/\//i.test(raw))return raw;return appBase+'/'+raw.replace(/^\/+/, '')};
  let csrf='';
  const $=selector=>root.querySelector(selector);
  const msg=$('[data-message]');
  const existingCard=$('[data-existing-install]');
  const installFlow=[...root.querySelectorAll('[data-install-flow]')];
  const show=(text,type='info')=>{
    msg.hidden=false;
    msg.className='sc-alert sc-alert--'+type;
    msg.textContent=text;
  };
  const esc=value=>String(value).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

  async function resetLegacyBrowserState(){
    let changed=false;
    let mustReload=Boolean(navigator.serviceWorker?.controller);
    try{
      if('serviceWorker' in navigator){
        const registrations=await navigator.serviceWorker.getRegistrations();
        if(registrations.length>0)mustReload=true;
        for(const registration of registrations){
          if(!registration.scope || registration.scope.startsWith(location.origin+(appBase||'')+'/')){
            changed=(await registration.unregister())||changed;
          }
        }
      }
    }catch(_){/* Setup must remain usable when SW APIs are unavailable. */}
    try{
      if('caches' in window){
        const keys=await caches.keys();
        for(const key of keys)changed=(await caches.delete(key))||changed;
      }
    }catch(_){/* Cache Storage cleanup is best-effort. */}

    const version=root.dataset.setupVersion||'current';
    const resetKey='sokna.setup.browser-state-reset';
    if((changed||mustReload) && sessionStorage.getItem(resetKey)!==version){
      sessionStorage.setItem(resetKey,version);
      const target=location.origin+appPath('/setup/?browser_reset=1&v='+encodeURIComponent(version));
      location.replace(target);
      return true;
    }
    return false;
  }

  async function confirmFreshStart(){
    const dialog=document.querySelector('[data-start-fresh-dialog]');
    if(!(dialog instanceof HTMLDialogElement)||typeof dialog.showModal!=='function')return window.confirm('وضعیت اتصال نصب قبلی آرشیو می‌شود و Setup تازه باز خواهد شد. دیتابیس و پوشه داده حذف نمی‌شوند. ادامه می‌دهی؟');
    if(dialog.open)dialog.close('cancel');
    return new Promise(resolve=>{
      dialog.addEventListener('close',()=>resolve(dialog.returnValue==='confirm'),{once:true});
      dialog.querySelector('[data-start-fresh-cancel]')?.addEventListener('click',()=>dialog.close('cancel'),{once:true});
      dialog.showModal();
    });
  }

  const api=async(action,body={})=>{
    if(!/^https?:$/.test(location.protocol)||!location.host)throw new Error('آدرس سامانه در مرورگر معتبر نیست.');
    const endpoint=location.origin+appPath('/setup/api.php?action='+encodeURIComponent(action));
    let response;
    try{
      response=await fetch(endpoint,{
        method:action==='status'?'GET':'POST',
        credentials:'same-origin',
        cache:'no-store',
        redirect:'error',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-Sokna-Setup':'1'},
        body:action==='status'?undefined:JSON.stringify({...body,csrf}),
      });
    }catch(error){
      console.error('setup request failed',error);throw new Error('ارتباط با سرویس راه‌اندازی برقرار نشد. صفحه را تازه کن و دوباره تلاش کن.');
    }
    let json;
    try{json=await response.json();}
    catch(_){console.error('setup response invalid',response.status);throw new Error('پاسخ سرویس راه‌اندازی قابل خواندن نیست.');}
    if(!response.ok||json.success===false)throw new Error(json.message||'عملیات کامل نشد.');
    return json;
  };

  const db=()=>Object.fromEntries([...root.querySelectorAll('[data-db]')].map(input=>[input.dataset.db,input.value]));
  const renderChecks=preflight=>{
    $('[data-preflight]').innerHTML=(preflight.checks||[]).map(check=>`<div class="sc-setup__check ${check.ok?'is-ok':'is-bad'}"><strong>${check.ok?'✓':'×'} ${esc(check.label)}</strong><small>${esc(check.current||'')}</small></div>`).join('');
  };

  const applySetupState=setup=>{
    const hasExisting=setup?.state==='existing';
    if(existingCard)existingCard.hidden=!hasExisting;
    installFlow.forEach(section=>{section.hidden=hasExisting;});
  };

  async function start(){
    try{
      if(await resetLegacyBrowserState())return;
      const result=await api('status');
      const canonical=new URL(result.local_base_url);
      if(location.origin.toLowerCase()!==canonical.origin.toLowerCase()){
        location.replace(canonical.origin+appPath('/setup/'));
        return;
      }
      sessionStorage.removeItem('sokna.setup.browser-state-reset');
      csrf=result.csrf;
      renderChecks(result.preflight);
      applySetupState(result.setup);
      if(result.setup.state==='installed'){
        show('سامانه قبلاً راه‌اندازی شده است. در حال انتقال به صفحه ورود…','success');
        setTimeout(()=>{location.href=appPath('/login.php');},450);
        return;
      }
      if(result.setup.state==='existing'){
        show('یک نصب کامل قبلی روی دستگاه پیدا شد. قبل از ادامه، یکی از دو گزینه زیر را انتخاب کن.','warning');
        return;
      }
      if(result.setup.state==='partial'){
        $('[data-action="resume"]').hidden=false;
        show('یک راه‌اندازی نیمه‌تمام پیدا شد. می‌توانی آن را ادامه دهی.','warning');
      }
      if(result.setup.state==='inconsistent')show('اطلاعات راه‌اندازی قبلی ناقص است؛ برای ادامه از بخش پشتیبانی کمک بگیر.','danger');
    }catch(error){show(error.message,'danger');}
  }

  root.addEventListener('click',async event=>{
    const button=event.target.closest('[data-action]');
    if(!button)return;
    event.preventDefault();
    button.disabled=true;
    try{
      if(button.dataset.action==='preflight'){
        const result=await api('preflight',{data_dir:$('[data-data-dir]').value});
        renderChecks(result.preflight);
        show(result.preflight.ok?'پیش‌نیازها آماده‌اند.':'بعضی پیش‌نیازها کامل نیستند.',result.preflight.ok?'success':'warning');
      }
      if(button.dataset.action==='test-db'){
        const result=await api('test_database',{db:db(),create_database:$('[data-create-db]').checked});
        $('[data-db-result]').textContent='اتصال با موفقیت برقرار شد.';
        show('اتصال دیتابیس تأیید شد.','success');
      }
      if(button.dataset.action==='install'){
        const body={
          data_dir:$('[data-data-dir]').value,
          db:db(),
          create_database:$('[data-create-db]').checked,
          admin_user:$('[data-admin="user"]').value,
          admin_password:$('[data-admin="password"]').value,
          cafe_name:$('[data-field="cafe_name"]').value,
          table_count:Number($('[data-field="table_count"]').value||10),
          timezone:$('[data-field="timezone"]').value,
        };
        const result=await api('install_new',body);
        show('راه‌اندازی کامل شد. در حال انتقال به صفحه ورود…','success');
        $('[data-final]').textContent='نصب کامل و قفل شد.';
        setTimeout(()=>{location.href=appPath(result.next||'/login.php');},600);
      }
      if(button.dataset.action==='resume'){
        const result=await api('resume');
        show('راه‌اندازی نیمه‌تمام با موفقیت کامل شد.','success');
        setTimeout(()=>{location.href=appPath(result.next||'/login.php');},600);
      }
      if(button.dataset.action==='continue-existing'){
        const result=await api('continue_existing');
        show('نصب موجود تأیید شد. هیچ اطلاعات کسب‌وکار یا دیتابیسی تغییر نکرد.','success');
        setTimeout(()=>{location.href=appPath(result.next||'/login.php');},600);
      }
      if(button.dataset.action==='start-fresh'){
        const confirmed=await confirmFreshStart();
        if(!confirmed)return;
        const result=await api('start_fresh',{confirmation:'archive-and-start-new'});
        show('وضعیت نصب قبلی با موفقیت آرشیو شد. در حال بازکردن Setup تازه…','success');
        setTimeout(()=>{location.href=appPath(result.next||'/setup/')+'?fresh=1';},500);
      }
    }catch(error){show(error.message,'danger');}
    finally{button.disabled=false;}
  });

  start();
})();
