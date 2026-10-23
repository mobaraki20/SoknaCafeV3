(()=>{'use strict';
const root=document.querySelector('[data-account]');if(!root)return;
const $=(s,r=root)=>r.querySelector(s),api=root.dataset.api,csrf=root.dataset.csrf,status=$('[data-status]');
const roleLabel=r=>({admin:'مدیر',operator:'اپراتور',waiter:'همکار سالن'}[r]||'کاربر');
const toast=(m,tone='success',title='')=>window.SoknaUI?.toast?.(m,{tone,title});
function message(text,bad=false){status.textContent=text||'';status.hidden=!text;status.classList.toggle('sc-alert--danger',bad);status.classList.toggle('sc-alert--info',!bad)}
function actionError(err,title='عملیات انجام نشد'){message('');toast(err?.message||'عملیات انجام نشد.','danger',title)}
async function request(url,options={}){let res;try{res=await fetch(url,{cache:'no-store',...options})}catch{throw new Error('ارتباط با سامانه برقرار نشد. دوباره تلاش کنید.')}let json;try{json=await res.json()}catch{throw new Error('پاسخ سامانه قابل خواندن نیست.')}if(!res.ok||json.success===false)throw new Error(json.message||'درخواست انجام نشد.');return json}
async function load(){root.setAttribute('aria-busy','true');message('');try{const j=await request(api),a=j.account,f=$('[data-profile-form]');f.elements.display_name.value=a.display_name||'';f.elements.username.value=a.username||'';f.elements.role_label.value=roleLabel(a.role)}catch(e){message(e.message,true)}finally{root.removeAttribute('aria-busy')}}
async function post(payload){return request(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...payload,csrf_token:csrf})})}
$('[data-profile-form]').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;await SoknaUI.busy(f,async()=>{try{await post({action:'profile_save',display_name:f.elements.display_name.value});message('');toast('نام نمایشی ذخیره شد.');window.setTimeout(()=>location.reload(),650)}catch(err){actionError(err)}})});
$('[data-password-form]').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;await SoknaUI.busy(f,async()=>{try{await post({action:'password_change',current_password:f.elements.current_password.value,new_password:f.elements.new_password.value,confirm_password:f.elements.confirm_password.value});f.reset();message('');toast('رمز عبور با موفقیت تغییر کرد.')}catch(err){actionError(err)}})});

const notifRoot=$('[data-notification-preferences]');
if(notifRoot){
  const notifApi=notifRoot.dataset.api,notifCsrf=notifRoot.dataset.csrf,form=notifRoot.querySelector('[data-notification-pref-form]'),pushState=notifRoot.querySelector('[data-notification-push-state]');let notificationSnapshot={};
  async function notifReq(opt={}){let r;try{r=await fetch(notifApi,{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json'},...opt})}catch{throw new Error('ارتباط با سامانه برقرار نشد. دوباره تلاش کنید.')}let j={};try{j=await r.json()}catch{throw new Error('پاسخ سامانه قابل خواندن نیست.')}if(!r.ok||j.success===false)throw new Error(j.message||'عملیات اعلان انجام نشد.');return j}
  const notifPost=d=>notifReq({method:'POST',body:JSON.stringify({...d,csrf_token:notifCsrf})});
  function renderNotificationPreferences(){const p=notificationSnapshot.preferences||{};form.elements.in_app_enabled.checked=Number(p.in_app_enabled)!==0;form.elements.push_enabled.checked=Number(p.push_enabled)===1;form.elements.push_operations_enabled.checked=Number(p.push_operations_enabled)!==0;form.elements.push_management_enabled.checked=Number(p.push_management_enabled)!==0;const enabled=form.elements.push_enabled.checked;form.elements.push_operations_enabled.disabled=!enabled;form.elements.push_management_enabled.disabled=!enabled;pushState.textContent=enabled?'دریافت Push برای حساب روشن است؛ ثبت یا قطع هر دستگاه از «اپ سکنا» روی همان دستگاه انجام می‌شود.':'دریافت Push برای این حساب خاموش است؛ اعلان داخل سامانه مستقل می‌ماند.'}
  async function loadNotificationPreferences(){try{notificationSnapshot=(await notifReq()).snapshot||{};renderNotificationPreferences()}catch(e){pushState.textContent=e.message||'وضعیت اعلان‌ها دریافت نشد.'}}
  form.addEventListener('submit',async e=>{e.preventDefault();await SoknaUI.busy(form,async()=>{try{await notifPost({action:'preferences',in_app_enabled:form.elements.in_app_enabled.checked,push_enabled:form.elements.push_enabled.checked,push_operations_enabled:form.elements.push_operations_enabled.checked,push_management_enabled:form.elements.push_management_enabled.checked});await loadNotificationPreferences();toast('تنظیم دریافت اعلان ذخیره شد.')}catch(err){actionError(err,'تنظیم اعلان ذخیره نشد')}})});
  form.elements.push_enabled.addEventListener('change',renderNotificationPreferences);
  loadNotificationPreferences();
  if(new URLSearchParams(location.search).get('section')==='notifications')requestAnimationFrame(()=>notifRoot.scrollIntoView({block:'start'}));
}

load();
})();
