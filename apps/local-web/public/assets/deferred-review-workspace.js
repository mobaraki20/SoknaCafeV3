(()=>{
'use strict';
const root=document.querySelector('[data-operations-workspace]');
const body=document.querySelector('[data-deferred-reviews]');
if(!root||!body||root.dataset.admin!=='1')return;
const L=window.SoknaLocale||{humanDigits:v=>String(v??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),dateTime:v=>String(v??'')};
const api=root.dataset.api;
const csrf=root.dataset.csrf;
const status=document.querySelector('[data-status]');
const section=document.querySelector('[data-deferred-review-section]');
const countBadge=document.querySelector('[data-deferred-review-badge]');
function setStatus(message,bad=false){
  if(!status)return;
  status.textContent=message;
  status.classList.toggle('sc-alert--danger',bad);
}
function cell(row,label,value){
  const td=document.createElement('td');
  td.dataset.label=label;
  td.textContent=String(value??'—');
  row.append(td);
}
function actionButton(label,variant,handler){
  const button=document.createElement('button');
  button.type='button';
  button.className='sc-button'+(variant==='primary'?'':' sc-button--'+variant);
  button.textContent=label;
  button.addEventListener('click',handler);
  return button;
}
async function jsonRequest(url,options={}){
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
  let payload={};
  try{payload=await response.json()}catch(_){throw new Error('پاسخ سامانه قابل خواندن نیست.');}
  if(!response.ok||payload.success===false)throw new Error((payload.message||'عملیات انجام نشد.')+(payload.support_id?` · کد پیگیری: ${payload.support_id}`:''));
  return payload;
}
async function loadReviews(){
  const payload=await jsonRequest(`${api}?action=snapshot`);
  renderReviews(payload.snapshot?.deferred_reviews||[],payload.snapshot?.errors?.deferred_reviews||null);
}
function renderReviews(rows,error=null){
  body.replaceChildren();
  if(section)section.hidden=!error&&!rows.length;
  if(countBadge){countBadge.textContent=L.humanDigits(rows.length);countBadge.className='sc-badge '+(rows.length?'sc-badge--warning':'sc-badge--success');}
  if(error){
    const row=document.createElement('tr'),td=document.createElement('td');td.colSpan=5;td.className='sc-error';td.textContent=(error.message||'بررسی عملیات‌های معوق فعلاً در دسترس نیست.')+(error.support_id?` · کد پیگیری: ${error.support_id}`:'');row.append(td);body.append(row);return;
  }
  if(!rows.length){
    const row=document.createElement('tr');
    const td=document.createElement('td');
    td.colSpan=5;
    td.textContent='موردی برای بررسی وجود ندارد.';
    row.append(td);
    body.append(row);
    return;
  }
  for(const review of rows){
    const row=document.createElement('tr');
    cell(row,'زمان',L.dateTime(review.created_at));
    cell(row,'نوع','عملیات نیازمند بررسی');
    cell(row,'کاربر',review.actor_name||'—');
    cell(row,'پیام',review.message||'—');
    const actionsCell=document.createElement('td');
    actionsCell.dataset.label='عملیات';
    const actions=document.createElement('div');
    actions.className='sc-actions';
    actions.append(
      actionButton('تأیید','primary',()=>resolveReview(review,'approve')),
      actionButton('رد','danger',()=>resolveReview(review,'reject'))
    );
    actionsCell.append(actions);
    row.append(actionsCell);
    body.append(row);
  }
}
async function resolveReview(review,decision){
  const title=decision==='approve'?'تأیید مورد':'رد مورد';
  const reason=await SoknaUI.prompt(title,{label:'دلیل تصمیم',value:'بررسی مدیر'});
  if(!reason)return;
  try{
    setStatus('در حال ثبت تصمیم…');
    await jsonRequest(api,{
      method:'POST',
      headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({
        action:'deferred_resolve',
        review_id:Number(review.review_id),
        kind:review.kind,
        decision,
        reason,
        csrf_token:csrf
      })
    });
    await loadReviews();
    setStatus('تصمیم ثبت شد.');
  }catch(error){
    setStatus(error.message,true);
  }
}
loadReviews().catch(error=>setStatus(error.message,true));
})();
