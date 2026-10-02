(()=>{'use strict';
const root=document.querySelector('[data-account]');if(!root)return;
const $=(s,r=root)=>r.querySelector(s),api=root.dataset.api,csrf=root.dataset.csrf,status=$('[data-status]');
const roleLabel=r=>({admin:'مدیر',operator:'اپراتور',waiter:'همکار سالن'}[r]||'کاربر');
const toast=(m,tone='success',title='')=>window.SoknaUI?.toast?.(m,{tone,title});
function message(text,bad=false){status.textContent=text||'';status.hidden=!text;status.classList.toggle('sc-alert--danger',bad);status.classList.toggle('sc-alert--info',!bad)}
async function request(url,options={}){let res;try{res=await fetch(url,{cache:'no-store',...options})}catch{throw new Error('ارتباط با سامانه برقرار نشد. دوباره تلاش کنید.')}let json;try{json=await res.json()}catch{throw new Error('پاسخ سامانه قابل خواندن نیست.')}if(!res.ok||json.success===false)throw new Error(json.message||'درخواست انجام نشد.');return json}
async function load(){try{message('در حال دریافت اطلاعات حساب…');const j=await request(api),a=j.account,f=$('[data-profile-form]');f.elements.display_name.value=a.display_name||'';f.elements.username.value=a.username||'';f.elements.role_label.value=roleLabel(a.role);message('')}catch(e){message(e.message,true)}}
async function post(payload){return request(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...payload,csrf_token:csrf})})}
$('[data-profile-form]').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;await SoknaUI.busy(f,async()=>{try{await post({action:'profile_save',display_name:f.elements.display_name.value});message('');toast('نام نمایشی ذخیره شد.');window.setTimeout(()=>location.reload(),650)}catch(err){message(err.message,true)}})});
$('[data-password-form]').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;await SoknaUI.busy(f,async()=>{try{await post({action:'password_change',current_password:f.elements.current_password.value,new_password:f.elements.new_password.value,confirm_password:f.elements.confirm_password.value});f.reset();message('');toast('رمز عبور با موفقیت تغییر کرد.')}catch(err){message(err.message,true)}})});
load();
})();
