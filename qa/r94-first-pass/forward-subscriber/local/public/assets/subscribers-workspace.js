(()=>{'use strict';
const root=document.querySelector('[data-subscriber-workspace]');if(!root)return;
const api=root.dataset.api,csrf=root.dataset.csrf,isAdmin=root.dataset.admin==='1',canPayment=root.dataset.canPayment!=='0';
const L=window.SoknaLocale||{integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),money:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0))+' تومان',phone:v=>String(v??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),humanDigits:v=>String(v??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),dateTime:v=>String(v??''),canonical:v=>String(v??''),numericValue:v=>Number(v||0)};
const $=(s,c=root)=>c.querySelector(s),money=n=>L.money(n),el=(t,c,txt)=>{const x=document.createElement(t);if(c)x.className=c;if(txt!==undefined)x.textContent=txt;return x};
const toast=(m,tone='success',title='')=>window.SoknaUI?.toast?.(m,{tone,title});
const status=(m,b=false)=>{const x=$('[data-status]');x.textContent=m||'';x.hidden=!m;x.className='sc-alert'+(b?' sc-alert--danger':'')};
const actionError=(err,title='عملیات انجام نشد')=>{status('');toast(err?.message||'عملیات انجام نشد.','danger',title)};
const requestId=prefix=>prefix+(globalThis.crypto?.randomUUID?.()||`${Date.now()}-${Math.random().toString(16).slice(2)}`);
async function req(url,opt={}){let r;try{r=await fetch(url,{cache:'no-store',...opt})}catch(e){if(e?.name==='AbortError')throw e;const err=new Error('ارتباط با سامانه برقرار نشد. نتیجه درخواست ممکن است نامعلوم باشد.');err.unknown_outcome=opt.method==='POST';throw err}let j={};try{j=await r.json()}catch{const err=new Error('پاسخ سامانه قابل خواندن نیست. نتیجه درخواست نامعلوم است.');err.unknown_outcome=opt.method==='POST';throw err}if(!r.ok||j.success===false){const err=new Error(j.message||'عملیات انجام نشد.');err.httpStatus=r.status;err.errorCode=j.code||j.error_code||'';err.unknown_outcome=opt.method==='POST'&&r.status>=500;throw err}if(j.staff_meta)window.SoknaStaffRuntime?.emitMeta?.('subscribers',j.staff_meta);return j}
async function post(p){return req(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...p,csrf_token:csrf})})}
let accountRange={from:'',to:'',offset:0,invoice_q:''};let current=null,currentId=0,rowsById=new Map(),searchSeq=0,searchController=null,detailSeq=0,selectedDetailId=0;
// A tab-scoped pending payment stores only the immutable payment request, never CSRF/auth secrets.
const paymentPendingStoreKey='sokna-subscriber-pending-payment-v1';
let paymentStorageBlocked=false;
function loadPendingPayment(){
 try{
  const raw=sessionStorage.getItem(paymentPendingStoreKey);
  if(raw===null)return null;
  const x=JSON.parse(raw);
  if(x?.action!=='payment'||!/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/.test(String(x.request_id||''))||!Number.isSafeInteger(x.subscriber_id)||x.subscriber_id<1||!Number.isSafeInteger(x.amount)||x.amount<1||typeof x.reference!=='string')throw new Error('invalid_pending_payment');
  return x;
 }catch{paymentStorageBlocked=true;return null}
}
let pendingPayment=loadPendingPayment(),paymentInFlight=false,paymentSubject=null,paymentRetryVerified=false;
function setPendingPayment(x){
 if(paymentStorageBlocked)throw new Error('اطلاعات پیگیری پرداخت قابل اعتماد نیست؛ برای حفظ ایمنی هیچ پرداخت تازه‌ای ارسال نمی‌شود.');
 try{
  if(x){
   const raw=JSON.stringify(x);
   sessionStorage.setItem(paymentPendingStoreKey,raw);
   if(sessionStorage.getItem(paymentPendingStoreKey)!==raw)throw new Error('not_persisted');
  }else{
   sessionStorage.removeItem(paymentPendingStoreKey);
   if(sessionStorage.getItem(paymentPendingStoreKey)!==null)throw new Error('not_removed');
  }
 }catch{
  paymentStorageBlocked=true;
  renderPendingPayment();
  throw new Error('ذخیره یا پاک‌سازی شناسه پیگیری پرداخت ممکن نیست؛ عملیات مالی تا بررسی وضعیت متوقف می‌ماند.');
 }
 pendingPayment=x;
 paymentRetryVerified=false;
 renderPendingPayment();
}
function samePayment(a,b){return a&&b&&a.action===b.action&&a.request_id===b.request_id&&a.subscriber_id===b.subscriber_id&&a.amount===b.amount&&a.reference===b.reference}
function paymentUncertain(err){return Boolean(err?.unknown_outcome||Number(err?.httpStatus||0)>=500||Number(err?.httpStatus||0)===409||!err?.httpStatus)}
function renderPendingPayment(){let note=$('[data-payment-pending-note]');if(!pendingPayment&&!paymentStorageBlocked){note?.remove();return}if(!note){note=el('div','sc-alert sc-alert--warning');note.dataset.paymentPendingNote='';note.setAttribute('role','status');const parent=$('[data-status]');parent?.insertAdjacentElement('afterend',note)}note.replaceChildren();if(paymentStorageBlocked){note.append(el('span','','حافظه پیگیری پرداخت این تب در دسترس نیست یا آسیب دیده است. برای جلوگیری از ثبت تکراری، ثبت پرداخت متوقف است؛ وضعیت دفتر مشتری را مستقل بررسی کنید.'));return}note.append(el('span','',`نتیجه پرداخت ${money(pendingPayment.amount)} برای مشتری شماره ${L.integer(pendingPayment.subscriber_id)} هنوز قطعی نیست. پرداخت تازه ثبت نکنید. `));const check=button('بررسی نتیجه',()=>checkPendingPayment(),'secondary'),retry=button('تلاش مجدد با همان شناسه',()=>runPayment(pendingPayment),'secondary');check.disabled=paymentInFlight;retry.disabled=paymentInFlight||!paymentRetryVerified;note.append(check);if(paymentRetryVerified)note.append(retry)}
async function checkPendingPayment(){
 if(!pendingPayment||paymentInFlight)return false;
 const saved=pendingPayment;paymentRetryVerified=false;paymentInFlight=true;renderPendingPayment();
 try{
  const j=await req(`${api}?action=payment_status&subscriber_id=${encodeURIComponent(saved.subscriber_id)}&request_id=${encodeURIComponent(saved.request_id)}`),r=j?.result||{};
  if(Number(r.subscriber_id)!==saved.subscriber_id)throw new Error('نتیجه استعلام متعلق به حساب دیگری است؛ ارسال مجدد ممنوع است.');
  if(r.state==='committed'){
   if(Number(r.amount)!==saved.amount||!Number.isSafeInteger(Number(r.entry_id))||Number(r.entry_id)<1)throw new Error('مبلغ یا شناسه رسید ثبت‌شده با پرداخت قبلی مغایر است؛ ارسال مجدد ممنوع است.');
   setPendingPayment(null);paymentDialog?.close();
   try{await detail(saved.subscriber_id);await search()}catch{paymentFeedback('پرداخت قطعی تأیید شد، اما تازه‌سازی فهرست کامل نشد. صفحه را دوباره باز کنید.');}
   toast('پرداخت قبلی در دفتر حساب تأیید شد.');return true;
  }
  if(r.state==='unconfirmed'){
   // Absence is NOT proof of a failed commit; same immutable ID only may be retried.
   paymentRetryVerified=true;
   paymentFeedback('هنوز رسید قطعی دیده نشده است. فقط همان درخواست، با همان شناسه قابل تلاش مجدد است.');
  }else{
   paymentFeedback('وضعیت درخواست نیازمند بررسی است؛ ارسال مجدد تا دریافت پاسخ معتبر ممنوع است.');
  }
  return false;
 }catch(e){paymentFeedback(e.message||'بررسی نتیجه پرداخت انجام نشد.');return false}
 finally{paymentInFlight=false;renderPendingPayment()}
}
async function runPayment(payload){
 if(paymentInFlight)return false;
 if(pendingPayment){
  if(!samePayment(pendingPayment,payload)){paymentFeedback('یک پرداخت با نتیجه نامعلوم باز است؛ ابتدا آن را بررسی کنید.');renderPendingPayment();return false}
  if(!paymentRetryVerified){paymentFeedback('قبل از ارسال مجدد، نتیجه همین شناسه را از سرور بررسی کنید.');renderPendingPayment();return false}
 }
 try{setPendingPayment(payload)}catch(e){paymentFeedback(e.message||'امکان حفظ شناسه پرداخت وجود ندارد؛ هیچ درخواستی ارسال نشد.');return false}
 paymentInFlight=true;
 try{
  const j=await post(payload),r=j?.result||{};
  if(String(r.entry_type)!=='payment'||Number(r.subscriber_id)!==payload.subscriber_id||Number(r.amount_delta)!==-payload.amount){const bad=new Error('پاسخ ثبت پرداخت رسید معتبر ندارد؛ نتیجه نامعلوم است.');bad.unknown_outcome=true;throw bad}
  setPendingPayment(null);paymentDialog?.close();
  try{await detail(payload.subscriber_id);await search()}catch{paymentFeedback('پرداخت ثبت شد، اما تازه‌سازی نمایش کامل نشد؛ پس از بازکردن دوباره صفحه، دفتر را بررسی کنید.');}
  toast('پرداخت ثبت شد.');return true;
 }catch(e){
  if(!paymentUncertain(e)){try{setPendingPayment(null)}catch(storageError){paymentFeedback(storageError.message);return false}}
  paymentFeedback(paymentUncertain(e)?'نتیجه پرداخت نامعلوم است. تا بررسی یا تلاش مجدد با همان شناسه، پرداخت جدید ثبت نکنید.':e.message||'ثبت پرداخت انجام نشد.');return false;
 }finally{paymentInFlight=false;renderPendingPayment()}
}

const normalizedQuery=value=>L.canonical(String(value??'')).replace(/ي/g,'ی').replace(/ك/g,'ک').trim();
const normalizeMobile=value=>{let d=L.canonical(String(value??'')).replace(/\D+/g,'');if(d.startsWith('0098'))d='0'+d.slice(4);else if(d.startsWith('98'))d='0'+d.slice(2);else if(d.length===10&&d.startsWith('9'))d='0'+d;return d.slice(0,11)};const validMobile=value=>/^09\d{9}$/.test(normalizeMobile(value));
const formFeedback=(message='')=>{const x=$('[data-form-feedback]');if(!x)return;x.textContent=message;x.hidden=!message};
async function search(selectRequested=false){
  const list=$('[data-subscriber-list]'),count=$('[data-result-count]'),seq=++searchSeq;
  searchController?.abort?.();searchController=new AbortController();
  list?.setAttribute('aria-busy','true');if(count)count.textContent='در حال جست‌وجو…';status('');
  try{
    const q=normalizedQuery($('[data-search]').value),st=$('[data-status-filter]').value,debt=$('[data-debt-only]').checked?'1':'0';
    const j=await req(`${api}?action=search&q=${encodeURIComponent(q)}&status=${encodeURIComponent(st)}&debt=${debt}`,{signal:searchController.signal});
    if(seq!==searchSeq)return;
    renderList(Array.isArray(j.rows)?j.rows:[]);
    if(selectRequested){const requested=Number(new URLSearchParams(location.search).get('subscriber')||0);if(requested>0&&rowsById.has(requested))await detail(requested)}
  }catch(e){if(e?.name!=='AbortError'&&seq===searchSeq){status(e.message,true);if(count)count.textContent='خطا در جست‌وجو'}}
  finally{if(seq===searchSeq)list?.removeAttribute('aria-busy')}
}
function renderList(rows){const b=$('[data-subscriber-list]');rowsById=new Map((rows||[]).map(r=>[Number(r.id),r]));const count=$('[data-result-count]');if(count)count.textContent=`${L.integer(rows.length)} مشتری`;const P=window.SoknaStaffDataPanels;if(P){P.renderCustomerList(b,rows,{selectedId:currentId,onSelect:id=>detail(id),showMobile:true});return}b.replaceChildren();if(!rows.length){b.append(el('div','sc-empty','مشتری پیدا نشد.'));return}rows.forEach(r=>{const c=el('button','sc-card sc-subscriber-card'+(Number(r.id)===currentId?' is-selected':''));c.type='button';c.dataset.subscriberId=String(r.id);c.setAttribute('aria-pressed',Number(r.id)===currentId?'true':'false');const head=el('div','sc-subscriber-card__head');head.append(el('strong','',r.name),el('span','sc-money',Number(r.balance)===0?'بدون مانده':money(r.balance)));c.append(head,el('span','sc-muted sc-phone',L.phone(r.mobile)),el('small','sc-muted',r.last_activity?`آخرین فعالیت ${L.dateTime(r.last_activity)}`:'هنوز گردش حسابی ندارد'));c.addEventListener('click',()=>detail(Number(r.id)));b.append(c)})}
function syncSelection(){root.querySelectorAll('[data-subscriber-id]').forEach(c=>{const on=Number(c.dataset.subscriberId)===currentId;c.classList.toggle('is-selected',on);c.setAttribute('aria-pressed',on?'true':'false')})}
async function detail(id){id=Number(id);if(id!==selectedDetailId)accountRange={from:'',to:'',offset:0,invoice_q:''};selectedDetailId=id;const seq=++detailSeq;const range={...accountRange};try{const params=new URLSearchParams({action:'detail',id:String(id),...range});const j=await req(`${api}?${params}`);if(seq!==detailSeq||id!==selectedDetailId)return; if(Number(j.detail?.subscriber?.id)!==id)throw new Error('هویت پاسخ حساب با مشتری انتخاب‌شده مطابقت ندارد.');current=j.detail;currentId=id;renderDetail();syncSelection();status('')}catch(e){if(seq===detailSeq&&id===selectedDetailId)actionError(e)}}
function button(txt,fn,v='secondary'){const b=el('button','sc-button'+(v==='primary'?'':' sc-button--'+v),txt);b.type='button';b.addEventListener('click',async()=>{if(b.disabled)return;b.disabled=true;try{await fn()}finally{b.disabled=false}});return b}
function renderDetail(){const d=$('[data-subscriber-detail]');d.replaceChildren();d.classList.remove('sc-hidden');const s=current.subscriber;const body=el('div','sc-card__body sc-stack sc-subscriber-account');const head=el('header','sc-subscriber-account__head');const identity=el('div','sc-subscriber-account__identity');identity.append(el('small','sc-muted','حساب مشتری'),el('h2','',s.name),el('p','sc-muted sc-phone',L.phone(s.mobile)));const balance=el('div','sc-subscriber-account__balance');balance.append(el('span','',Number(s.balance)>0?'مانده قابل دریافت':Number(s.balance)<0?'اعتبار مشتری':'وضعیت حساب'),el('strong','sc-money',Number(s.balance)!==0?money(Math.abs(Number(s.balance))):'تسویه'));head.append(identity,balance);body.append(head);
const lastInvoice=(current.ledger||[]).find(x=>x.entry_type==='invoice');const meta=el('div','sc-subscriber-account__meta');meta.append(metaItem('وضعیت مشتری',Number(s.active)===1?'فعال':'غیرفعال'),metaItem('آخرین فعالیت',s.last_activity?L.dateTime(s.last_activity):'هنوز ثبت نشده'),metaItem('آخرین خرید',lastInvoice?.created_at?L.dateTime(lastInvoice.created_at):current.coverage?'در این بخش گردش فاکتوری نیست':'هنوز خریدی ثبت نشده'),metaItem('تعداد اسناد',L.integer(current.coverage?.ledger_total??(current.ledger||[]).length)));body.append(meta);
const acts=el('div','sc-actions sc-subscriber-account__actions');if(Number(s.balance)>0&&canPayment){acts.append(button('تسویه کامل مانده',()=>payFull(s),'primary'),button('پرداخت جزئی',()=>openPayment(s),'secondary'))}else if(Number(s.balance)>0){acts.append(el('span','sc-badge sc-badge--warning','پرداخت از راه دور در این نما فعال نیست'))}else acts.append(el('span','sc-badge sc-badge--success','مانده‌ای برای پرداخت نیست'));if(isAdmin)acts.append(button('ویرایش',()=>openForm(s),'secondary'));body.append(acts);
if(current.coverage)body.append(remoteAccountControls());const section=el('section','sc-subscriber-ledger');section.append(el('div','sc-section-head',''));const sh=section.firstElementChild;const shCopy=el('div');shCopy.append(el('h3','','گردش حساب'),el('p','','فاکتور، پرداخت و اسناد برگشت به‌ترتیب جدیدترین ثبت نمایش داده می‌شوند.'));sh.append(shCopy,el('span','sc-badge',`${L.integer(current.ledger.length)} ثبت`));const list=el('div','sc-list');if(!current.ledger.length)list.append(el('div','sc-empty',current.coverage?'در بخش همگام‌شده و بازه انتخابی، گردشی برای نمایش نیست.':'هنوز گردش حسابی برای این مشتری ثبت نشده است.'));current.ledger.forEach(l=>{const row=el('div','sc-list-item'),cp=el('div');cp.append(el('strong','',label(l.entry_type)),el('div','sc-list-item__meta',`${L.dateTime(l.created_at)} · مانده ${money(l.balance_after)}${l.reference?' · '+l.reference:''}`));row.append(cp,el('strong','sc-money',signed(l.amount_delta)));if(isAdmin&&l.entry_type==='payment'){row.append(button('برگشت',async()=>{const reason=await SoknaUI.prompt('برگشت پرداخت',{label:'دلیل برگشت'});if(!reason)return;try{await post({action:'reverse_payment',entry_id:Number(l.id),reason,request_id:requestId('subrev-')});await detail(Number(s.id));await search();toast('سند برگشت پرداخت ثبت شد.')}catch(e){actionError(e)}},'danger'))}list.append(row)});section.append(list);body.append(section);if(current.coverage)renderRemoteDocuments(body);else if(Array.isArray(current.invoices))renderLocalInvoiceDocuments(body);d.append(body)}
function metaItem(labelText,value){const item=el('div');item.append(el('span','',labelText),el('strong','',value));return item}
const label=t=>({invoice:'فاکتور',payment:'پرداخت',invoice_reversal:'برگشت فاکتور',payment_reversal:'برگشت پرداخت'})[t]||'ثبت حساب';const signed=n=>(Number(n)>0?'+':'')+money(n);
async function payFull(s){if(paymentInFlight||pendingPayment){renderPendingPayment();return}const subject={id:Number(s.id),amount:Number(s.balance||0)};if(subject.id<1||subject.amount<1)return;if(!await SoknaUI.confirm('تسویه کامل حساب',`کل مانده ${money(subject.amount)} به‌عنوان پرداخت ثبت شود؟`))return;if(paymentInFlight||pendingPayment)return;await runPayment({action:'payment',subscriber_id:subject.id,amount:subject.amount,reference:'',request_id:requestId('subpay-')})}
const paymentDialog=$('[data-payment-dialog]');function paymentFeedback(message=''){const x=$('[data-payment-feedback]');if(!x)return;x.textContent=message;x.hidden=!message}function updatePaymentPreview(){if(!paymentSubject)return;const balance=Number(paymentSubject.balance||0),amount=Math.max(0,L.numericValue($('[data-payment-amount]')?.value||'',{decimal:false})||0),after=Math.max(0,balance-amount);const a=$('[data-payment-preview-amount]'),n=$('[data-payment-preview-after]');if(a)a.textContent=amount>0?money(amount):'—';if(n)n.textContent=money(after)}
function openPayment(s){if(!paymentDialog||pendingPayment||paymentInFlight){renderPendingPayment();return}paymentSubject={id:Number(s.id),name:String(s.name||''),mobile:String(s.mobile||''),balance:Number(s.balance||0)};paymentFeedback();$('[data-payment-customer]').textContent=`${s.name} · ${L.phone(s.mobile)}`;$('[data-payment-balance]').textContent=`مانده ${money(s.balance)}`;$('[data-payment-amount]').value='';$('[data-payment-amount]').max=String(Math.max(0,Number(s.balance||0)));$('[data-payment-reference]').value='';updatePaymentPreview();if(!window.SoknaUI?.showDialog?.(paymentDialog,{initialFocus:'[data-payment-amount]'}))paymentDialog.showModal()}
$('[data-payment-cancel]')?.addEventListener('click',()=>paymentDialog?.close());$('[data-payment-amount]')?.addEventListener('input',updatePaymentPreview);$('[data-payment-form]')?.addEventListener('submit',async e=>{e.preventDefault();if(!paymentSubject||paymentInFlight||pendingPayment){renderPendingPayment();return}const s=paymentSubject,amount=L.numericValue($('[data-payment-amount]').value,{decimal:false}),balance=Number(s.balance||0);paymentFeedback();if(amount<1){paymentFeedback('مبلغ پرداخت را وارد کن.');return}if(amount>balance){paymentFeedback('مبلغ پرداخت نمی‌تواند از مانده حساب بیشتر باشد.');return}const payload={action:'payment',subscriber_id:Number(s.id),amount,reference:$('[data-payment-reference]').value.trim(),request_id:requestId('subpay-')};const submit=e.submitter;await SoknaUI.busy(submit,async()=>{await runPayment(payload)})});

function remoteAccountControls(){const box=el('section','sc-stack');box.append(el('p','sc-alert sc-alert--info',`مانده و اسناد قطعی تا آخرین همگام‌سازی: ${(L.instantDateTime||L.dateTime)(current.as_of||'—')}. پرداخت‌های منتظر انتقال در مانده منظور نشده‌اند.`));const c=current.coverage;if(!c.ledger_complete||!c.invoices_complete)box.append(el('p','sc-alert sc-alert--warning',c.available===false?'پوشش تاریخچه این حساب هنوز مشخص نیست؛ برای سابقه کامل از Local بررسی کن.':`این نما حداکثر ${L.integer(c.ledger_limit||200)} گردش و ${L.integer(c.invoice_limit||50)} فاکتور اخیر هر مشتری را نگه می‌دارد. تاریخچه قدیمی‌تر در Local موجود است؛ خالی بودن این بخش به معنی نبودن سابقه نیست.`));const form=el('form','sc-form-grid'),from=document.createElement('input'),to=document.createElement('input');for(const [input,key,title] of [[from,'from','از تاریخ'],[to,'to','تا تاریخ']]){input.type='date';input.className='sc-control';input.name=key;input.value=accountRange[key];input.setAttribute('aria-label',title);const label=el('label','sc-field');label.append(el('span','sc-field__label',title),input);form.append(label)}const submit=el('button','sc-button','نمایش گردش و فاکتورها');submit.type='submit';form.append(submit,button('پاک‌کردن بازه',async()=>{accountRange={from:'',to:'',offset:0};await detail(currentId)}));form.addEventListener('submit',e=>{e.preventDefault();accountRange={from:from.value,to:to.value,offset:0};void detail(currentId)});box.append(form);for(const p of current.pending_payments||[])box.append(el('p','sc-alert sc-alert--warning',`${p.state==='needs_review'?'پرداخت نیازمند بررسی':p.state==='committed'?'پرداخت همگام شد؛ منتظر تازه‌شدن مانده':'پرداخت منتظر همگام‌سازی'} · ${money(p.amount)} · ${(L.instantDateTime||L.dateTime)(p.occurred_at)}`));return box;}
function renderInvoiceCards(body){
 const section=el('section','sc-stack sc-subscriber-invoices');section.append(el('h3','','فاکتورهای مرتبط'));
 const docs=Array.isArray(current.invoices)?current.invoices:[];
 if(!docs.length)section.append(el('p','sc-empty','فاکتوری در بازه و فیلتر انتخاب‌شده موجود نیست.'));
 for(const invoice of docs){
  const details=el('details','sc-card'),number=String(invoice.invoice_number||'—'),date=invoice.created_at?L.dateTime(invoice.created_at):'تاریخ نامشخص',summary=el('summary','sc-card__body',`شماره ${L.humanDigits(number)} · ${date} · ${money(invoice.total)}${invoice.status==='reversal'?' · برگشت فاکتور':''}`),lines=el('div','sc-card__body sc-stack');
  lines.append(el('p','sc-muted',`جمع قبل از تخفیف ${money(invoice.subtotal)} · تخفیف ${money(invoice.discount)} · مالیات ${money(invoice.tax_amount)}`));
  const items=Array.isArray(invoice.lines)?invoice.lines:[];
  if(!items.length)lines.append(el('p','sc-alert sc-alert--warning','ریز اقلام این فاکتور در اطلاعات دریافت‌شده موجود نیست؛ مبلغ را از روی اقلام تخمین نزنید.'));
  for(const item of items){const row=el('p','',`${item.item_name_snapshot||'قلم بدون نام'} · ${L.integer(item.quantity)} عدد · مبلغ نهایی ${money(item.final_amount)}`);lines.append(row)}
  details.append(summary,lines);section.append(details);
 }
 body.append(section);
}
function renderRemoteDocuments(body){
 const pager=el('div','sc-actions'),page=current.pagination||{};
 if(page.offset>0)pager.append(button('گردش‌های جدیدتر',async()=>{accountRange.offset=Math.max(0,page.offset-page.limit);await detail(currentId)}));
 if(page.has_more)pager.append(button('گردش‌های قدیمی‌تر',async()=>{accountRange.offset=page.offset+page.limit;await detail(currentId)}));
 body.append(pager);renderInvoiceCards(body);
}
function renderLocalInvoiceDocuments(body){
 const form=el('form','sc-form-grid sc-subscriber-invoice-filters');
 const query=el('input','sc-control');query.type='search';query.name='invoice_q';query.value=accountRange.invoice_q||'';query.placeholder='جست‌وجوی شماره فاکتور';query.maxLength=60;query.setAttribute('aria-label','جست‌وجوی شماره فاکتور');
 const labelQuery=el('label','sc-field');labelQuery.append(el('span','sc-field__label','شماره فاکتور'),query);form.append(labelQuery);
 const dateInputs=[];
 for(const [name,title] of [['from','از تاریخ'],['to','تا تاریخ']]){const input=el('input','sc-control');input.type='date';input.name=name;input.value=accountRange[name]||'';input.setAttribute('aria-label',title);const labelDate=el('label','sc-field');labelDate.append(el('span','sc-field__label',title),input);form.append(labelDate);dateInputs.push(input)}
 const submit=el('button','sc-button','نمایش فاکتورها');submit.type='submit';form.append(submit);
 form.append(button('حذف فیلتر',async()=>{accountRange={from:'',to:'',offset:0,invoice_q:''};await detail(currentId)}));
 form.addEventListener('submit',e=>{e.preventDefault();if(dateInputs[0].value&&dateInputs[1].value&&dateInputs[0].value>dateInputs[1].value){status('بازه تاریخ فاکتورها معتبر نیست.',true);return;}accountRange={invoice_q:query.value.trim(),from:dateInputs[0].value,to:dateInputs[1].value,offset:0};void detail(currentId)});
 body.append(form);
 const page=current.invoice_pagination||{},pager=el('div','sc-actions');
 if(Number(page.total)>0)body.append(el('p','sc-muted',`${L.integer(page.total)} فاکتور مطابق فیلتر · نمایش ${L.integer((current.invoices||[]).length)} مورد`));
 if(page.offset>0)pager.append(button('فاکتورهای جدیدتر',async()=>{accountRange.offset=Math.max(0,Number(page.offset)-Number(page.limit||30));await detail(currentId)}));
 if(page.has_more)pager.append(button('فاکتورهای قدیمی‌تر',async()=>{accountRange.offset=Number(page.offset||0)+Number(page.limit||30);await detail(currentId)}));
 if(pager.childElementCount)body.append(pager);
 renderInvoiceCards(body);
}

const dialog=$('[data-subscriber-dialog]');function openForm(s=null){if(!isAdmin||!dialog)return;formFeedback();$('[data-form-id]').value=s?.id||'';$('[data-form-name]').value=s?.name||'';$('[data-form-mobile]').value=s?.mobile?L.humanDigits(normalizeMobile(s.mobile)):'';$('[data-form-active]').checked=s?Number(s.active)===1:true;$('[data-form-title]').textContent=s?'ویرایش مشتری':'مشتری جدید';if(!window.SoknaUI?.showDialog?.(dialog,{initialFocus:'[data-form-name]'}))dialog.showModal()}
if(isAdmin){$('[data-new-subscriber]').addEventListener('click',()=>openForm());$('[data-close-dialog]').addEventListener('click',()=>dialog.close());$('[data-subscriber-form]').addEventListener('submit',async e=>{e.preventDefault();const busy=e.currentTarget;await SoknaUI.busy(busy,async()=>{const id=Number($('[data-form-id]').value||0);try{const mobile=normalizeMobile($('[data-form-mobile]').value),name=$('[data-form-name]').value.trim();formFeedback();if(!name){formFeedback('نام یا عنوان مشتری را وارد کنید.');$('[data-form-name]')?.focus();return}if(!validMobile(mobile)){formFeedback('شماره موبایل باید به شکل 09xxxxxxxxx باشد.');$('[data-form-mobile]')?.focus();return}const payload={action:id?'update':'create',subscriber_id:id,name,mobile,active:$('[data-form-active]').checked};const j=await post(payload);dialog.close();await search();await detail(Number(j.result.id));toast('اطلاعات مشتری ذخیره شد.')}catch(err){actionError(err)}})})}

$('[data-form-mobile]')?.addEventListener('input',e=>{const input=e.currentTarget,normalized=normalizeMobile(input.value);input.value=L.humanDigits(normalized);formFeedback()});
let timer;$('[data-search]').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>search(),180)});$('[data-status-filter]').addEventListener('change',()=>search());$('[data-debt-only]').addEventListener('change',()=>search());$('[data-refresh]').addEventListener('click',()=>search());const params=new URLSearchParams(location.search),initialQ=params.get('q');if(initialQ)$('[data-search]').value=initialQ;search(true);renderPendingPayment();
})();
