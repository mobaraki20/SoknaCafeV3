(()=>{'use strict';
const root=document.querySelector('[data-subscriber-workspace]');if(!root)return;
const api=root.dataset.api,csrf=root.dataset.csrf,isAdmin=root.dataset.admin==='1',canPayment=root.dataset.canPayment!=='0';
const L=window.SoknaLocale||{integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),money:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0))+' تومان',phone:v=>String(v??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),humanDigits:v=>String(v??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),dateTime:v=>String(v??''),canonical:v=>String(v??''),numericValue:v=>Number(v||0)};
const $=(s,c=root)=>c.querySelector(s),money=n=>L.money(n),el=(t,c,txt)=>{const x=document.createElement(t);if(c)x.className=c;if(txt!==undefined)x.textContent=txt;return x};
const toast=(m,tone='success',title='')=>window.SoknaUI?.toast?.(m,{tone,title});
const status=(m,b=false)=>{const x=$('[data-status]');x.textContent=m||'';x.hidden=!m;x.className='sc-alert'+(b?' sc-alert--danger':'')};
const actionError=(err,title='عملیات انجام نشد')=>{status('');toast(err?.message||'عملیات انجام نشد.','danger',title)};
window.SoknaPublicRealtime?.mountDeferred?.(root);
const requestId=prefix=>prefix+(globalThis.crypto?.randomUUID?.()||`${Date.now()}-${Math.random().toString(16).slice(2)}`);
async function req(url,opt={}){let r;try{r=await fetch(url,{cache:'no-store',...opt})}catch(e){if(e?.name==='AbortError')throw e;throw new Error('ارتباط با سامانه برقرار نشد. دوباره تلاش کنید.')}let j={};try{j=await r.json()}catch{throw new Error('پاسخ سامانه قابل خواندن نیست.')}if(!r.ok||j.success===false)throw new Error(j.message||'عملیات انجام نشد.');if(j.staff_meta)window.SoknaStaffRuntime?.emitMeta?.('subscribers',j.staff_meta);return j}
async function post(p){
 if(!canPayment||p.action!=='payment')throw new Error('ثبت پرداخت برای این حساب فعال نیست.');
 const subscriber=current?.subscriber;
 if(!subscriber||Number(subscriber.id)!==Number(p.subscriber_id))throw new Error('حساب مشتری را دوباره باز کنید.');
 const payload={subscriber_id:Number(p.subscriber_id),amount:Number(p.amount),expected_balance:Number(subscriber.balance),reference:String(p.reference||''),reason:'پرداخت از فضای کاری پابلیک'};
 const result=await window.SoknaPublicRealtime.deferred('subscriber.payment',payload);return {success:true,result};
}
let current=null,currentId=0,rowsById=new Map(),searchSeq=0,searchController=null;
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
async function detail(id){try{const j=await req(`${api}?action=detail&id=${id}`);current=j.detail;currentId=Number(id);renderDetail();syncSelection();status('')}catch(e){actionError(e)}}
function button(txt,fn,v='secondary'){const b=el('button','sc-button'+(v==='primary'?'':' sc-button--'+v),txt);b.type='button';b.addEventListener('click',async()=>{if(b.disabled)return;b.disabled=true;try{await fn()}finally{b.disabled=false}});return b}
function renderDetail(){const d=$('[data-subscriber-detail]');d.replaceChildren();d.classList.remove('sc-hidden');const s=current.subscriber;const body=el('div','sc-card__body sc-stack sc-subscriber-account');const head=el('header','sc-subscriber-account__head');const identity=el('div','sc-subscriber-account__identity');identity.append(el('small','sc-muted','حساب مشتری'),el('h2','',s.name),el('p','sc-muted sc-phone',L.phone(s.mobile)));const balance=el('div','sc-subscriber-account__balance');balance.append(el('span','',Number(s.balance)>0?'مانده قابل دریافت':'وضعیت حساب'),el('strong','sc-money',Number(s.balance)>0?money(s.balance):'تسویه'));head.append(identity,balance);body.append(head);
const lastInvoice=(current.ledger||[]).find(x=>x.entry_type==='invoice');const meta=el('div','sc-subscriber-account__meta');meta.append(metaItem('وضعیت مشتری',Number(s.active)===1?'فعال':'غیرفعال'),metaItem('آخرین فعالیت',s.last_activity?L.dateTime(s.last_activity):'هنوز ثبت نشده'),metaItem('آخرین خرید',lastInvoice?.created_at?L.dateTime(lastInvoice.created_at):'هنوز خریدی ثبت نشده'),metaItem('تعداد اسناد',L.integer((current.ledger||[]).length)));body.append(meta);
const acts=el('div','sc-actions sc-subscriber-account__actions');if(Number(s.balance)>0&&canPayment){acts.append(button('تسویه کامل مانده',()=>payFull(s),'primary'),button('پرداخت جزئی',()=>openPayment(s),'secondary'))}else if(Number(s.balance)>0){acts.append(el('span','sc-badge sc-badge--warning','پرداخت از راه دور در این نما فعال نیست'))}else acts.append(el('span','sc-badge sc-badge--success','مانده‌ای برای پرداخت نیست'));if(isAdmin)acts.append(button('ویرایش',()=>openForm(s),'secondary'));body.append(acts);
const section=el('section','sc-subscriber-ledger');section.append(el('div','sc-section-head',''));const sh=section.firstElementChild;const shCopy=el('div');shCopy.append(el('h3','','گردش حساب'),el('p','','فاکتور، پرداخت و اسناد برگشت به‌ترتیب جدیدترین ثبت نمایش داده می‌شوند.'));sh.append(shCopy,el('span','sc-badge',`${L.integer(current.ledger.length)} ثبت`));const list=el('div','sc-list');if(!current.ledger.length)list.append(el('div','sc-empty','هنوز گردش حسابی برای این مشتری ثبت نشده است.'));current.ledger.forEach(l=>{const row=el('div','sc-list-item'),cp=el('div');cp.append(el('strong','',label(l.entry_type)),el('div','sc-list-item__meta',`${L.dateTime(l.created_at)} · مانده ${money(l.balance_after)}${l.reference?' · '+l.reference:''}`));row.append(cp,el('strong','sc-money',signed(l.amount_delta)));if(isAdmin&&l.entry_type==='payment'){row.append(button('برگشت',async()=>{const reason=await SoknaUI.prompt('برگشت پرداخت',{label:'دلیل برگشت'});if(!reason)return;try{await post({action:'reverse_payment',entry_id:Number(l.id),reason,request_id:requestId('subrev-')});await detail(Number(s.id));await search();toast('سند برگشت پرداخت ثبت شد.')}catch(e){actionError(e)}},'danger'))}list.append(row)});section.append(list);body.append(section);if(Array.isArray(current.invoices))renderPublicInvoiceDocuments(body);d.append(body)}
function renderPublicInvoiceDocuments(body){
 const section=el('section','sc-stack sc-subscriber-invoices');
 section.append(el('h3','','فاکتورهای مرتبط'));
 const coverage=current.coverage||{},docs=Array.isArray(current.invoices)?current.invoices:[];
 const asOf=current.as_of?(L.instantDateTime||L.dateTime)(current.as_of):'نامشخص';
 section.append(el('p','sc-muted','اطلاعات قطعی همگام‌شده تا '+asOf));
 if(current.projection_stale===true)section.append(el('p','sc-alert sc-alert--warning','مدل مشتریان قدیمی است؛ نتیجه جست‌وجو ممکن است کامل نباشد.'));
 if(coverage.available===false||coverage.invoices_complete!==true){
  section.append(el('p','sc-alert sc-alert--warning','نمای پابلیک فقط فاکتورهای اخیر همگام‌شده را جست‌وجو می‌کند؛ برای تاریخچه کامل، لوکال را بررسی کنید.'));
 }
 const form=el('form','sc-form-grid'),query=el('input','sc-control');
 query.type='search';query.maxLength=60;query.placeholder='شماره فاکتور';query.setAttribute('aria-label','جست‌وجوی شماره فاکتور');
 const queryLabel=el('label','sc-field');queryLabel.append(el('span','sc-field__label','شماره فاکتور'),query);form.append(queryLabel);
 const dates=[];
 for(const [name,title] of [['from','از تاریخ'],['to','تا تاریخ']]){
  const input=el('input','sc-control');input.type='date';input.name=name;input.setAttribute('aria-label',title);dates.push(input);
  const label=el('label','sc-field');label.append(el('span','sc-field__label',title),input);form.append(label);
 }
 const apply=el('button','sc-button','جست‌وجو در فاکتورهای همگام‌شده');apply.type='submit';form.append(apply);
 const clear=el('button','sc-button sc-button--secondary','پاک‌کردن');clear.type='button';form.append(clear);
 section.append(form);
 const list=el('div','sc-stack');
 const refresh=()=>{
  list.replaceChildren();
  const fold=v=>L.canonical(String(v??'')).toLowerCase();
  const q=fold(query.value.trim()),from=dates[0].value,to=dates[1].value;
  if(from&&to&&from>to){list.append(el('p','sc-alert sc-alert--warning','تاریخ شروع بعد از تاریخ پایان است.'));return;}
  const shown=docs.filter(d=>{const number=fold(d.invoice_number),date=String(d.created_at||'').slice(0,10);
   return (!q||number.includes(q))&&(!from||date>=from)&&(!to||date<=to);
  });
  if(!shown.length)list.append(el('p','sc-empty','در بخش همگام‌شده، فاکتوری مطابق این فیلتر دیده نشد؛ این نتیجه اثبات نبود سابقه در لوکال نیست.'));
  for(const d of shown){
   const detail=el('details','sc-card'),summary=el('summary','sc-card__body','شماره '+L.humanDigits(d.invoice_number||'—')+' · '+L.dateTime(d.created_at)+' · '+money(d.total)+(d.status==='reversal'?' · برگشت':''));
   const lines=el('div','sc-card__body sc-stack');
   lines.append(el('p','sc-muted','جمع '+money(d.subtotal)+' · تخفیف '+money(d.discount)+' · مالیات '+money(d.tax_amount)));
   const items=Array.isArray(d.lines)?d.lines:[];
   if(!items.length)lines.append(el('p','sc-alert sc-alert--warning','ریز اقلام این سند در مدل همگام‌شده موجود نیست.'));
   for(const item of items)lines.append(el('p','',''+(item.item_name_snapshot||'قلم بدون نام')+' · '+L.integer(item.quantity)+' عدد · مبلغ ردیف '+money(item.final_amount)));
   detail.append(summary,lines);list.append(detail);
  }
 };
 form.addEventListener('submit',e=>{e.preventDefault();refresh()});
 clear.addEventListener('click',()=>{query.value='';dates.forEach(d=>d.value='');refresh()});
 refresh();section.append(list);body.append(section);
}
function metaItem(labelText,value){const item=el('div');item.append(el('span','',labelText),el('strong','',value));return item}
const label=t=>({invoice:'فاکتور',payment:'پرداخت',invoice_reversal:'برگشت فاکتور',payment_reversal:'برگشت پرداخت'})[t]||'ثبت حساب';const signed=n=>(Number(n)>0?'+':'')+money(n);
async function payFull(s){const balance=Number(s.balance||0);if(balance<1)return;if(!await SoknaUI.confirm('تسویه کامل حساب',`کل مانده ${money(balance)} به‌عنوان پرداخت ثبت شود؟`))return;try{await post({action:'payment',subscriber_id:Number(s.id),amount:balance,reference:'',request_id:requestId('subpay-')});await detail(Number(s.id));await search();toast('کل مانده حساب تسویه شد.')}catch(e){actionError(e,'ثبت تسویه کامل انجام نشد')}}
const paymentDialog=$('[data-payment-dialog]');function paymentFeedback(message=''){const x=$('[data-payment-feedback]');if(!x)return;x.textContent=message;x.hidden=!message}function updatePaymentPreview(){if(!current?.subscriber)return;const balance=Number(current.subscriber.balance||0),amount=Math.max(0,L.numericValue($('[data-payment-amount]')?.value||'',{decimal:false})||0),after=Math.max(0,balance-amount);const a=$('[data-payment-preview-amount]'),n=$('[data-payment-preview-after]');if(a)a.textContent=amount>0?money(amount):'—';if(n)n.textContent=money(after)}
function openPayment(s){if(!paymentDialog)return;paymentFeedback();$('[data-payment-customer]').textContent=`${s.name} · ${L.phone(s.mobile)}`;$('[data-payment-balance]').textContent=`مانده ${money(s.balance)}`;$('[data-payment-amount]').value='';$('[data-payment-amount]').max=String(Math.max(0,Number(s.balance||0)));$('[data-payment-reference]').value='';updatePaymentPreview();if(!window.SoknaUI?.showDialog?.(paymentDialog,{initialFocus:'[data-payment-amount]'}))paymentDialog.showModal()}
$('[data-payment-cancel]')?.addEventListener('click',()=>paymentDialog?.close());$('[data-payment-amount]')?.addEventListener('input',updatePaymentPreview);$('[data-payment-form]')?.addEventListener('submit',async e=>{e.preventDefault();if(!current?.subscriber)return;const s=current.subscriber,amount=L.numericValue($('[data-payment-amount]').value,{decimal:false}),balance=Number(s.balance||0);paymentFeedback();if(amount<1){paymentFeedback('مبلغ پرداخت را وارد کن.');return}if(amount>balance){paymentFeedback('مبلغ پرداخت نمی‌تواند از مانده حساب بیشتر باشد.');return}const submit=e.submitter;await SoknaUI.busy(submit,async()=>{try{await post({action:'payment',subscriber_id:Number(s.id),amount,reference:$('[data-payment-reference]').value.trim(),request_id:requestId('subpay-')});paymentDialog.close();await detail(Number(s.id));await search();toast('پرداخت ثبت شد.')}catch(err){paymentFeedback(err.message||'ثبت پرداخت انجام نشد.')}})});
const dialog=$('[data-subscriber-dialog]');function openForm(s=null){if(!isAdmin||!dialog)return;formFeedback();$('[data-form-id]').value=s?.id||'';$('[data-form-name]').value=s?.name||'';$('[data-form-mobile]').value=s?.mobile?L.humanDigits(normalizeMobile(s.mobile)):'';$('[data-form-active]').checked=s?Number(s.active)===1:true;$('[data-form-title]').textContent=s?'ویرایش مشتری':'مشتری جدید';if(!window.SoknaUI?.showDialog?.(dialog,{initialFocus:'[data-form-name]'}))dialog.showModal()}
if(isAdmin){$('[data-new-subscriber]').addEventListener('click',()=>openForm());$('[data-close-dialog]').addEventListener('click',()=>dialog.close());$('[data-subscriber-form]').addEventListener('submit',async e=>{e.preventDefault();const busy=e.currentTarget;await SoknaUI.busy(busy,async()=>{const id=Number($('[data-form-id]').value||0);try{const mobile=normalizeMobile($('[data-form-mobile]').value),name=$('[data-form-name]').value.trim();formFeedback();if(!name){formFeedback('نام یا عنوان مشتری را وارد کنید.');$('[data-form-name]')?.focus();return}if(!validMobile(mobile)){formFeedback('شماره موبایل باید به شکل 09xxxxxxxxx باشد.');$('[data-form-mobile]')?.focus();return}const payload={action:id?'update':'create',subscriber_id:id,name,mobile,active:$('[data-form-active]').checked};const j=await post(payload);dialog.close();await search();await detail(Number(j.result.id));toast('اطلاعات مشتری ذخیره شد.')}catch(err){actionError(err)}})})}

$('[data-form-mobile]')?.addEventListener('input',e=>{const input=e.currentTarget,normalized=normalizeMobile(input.value);input.value=L.humanDigits(normalized);formFeedback()});
let timer;$('[data-search]').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>search(),180)});$('[data-status-filter]').addEventListener('change',()=>search());$('[data-debt-only]').addEventListener('change',()=>search());$('[data-refresh]').addEventListener('click',()=>search());const params=new URLSearchParams(location.search),initialQ=params.get('q');if(initialQ)$('[data-search]').value=initialQ;search(true);
})();
