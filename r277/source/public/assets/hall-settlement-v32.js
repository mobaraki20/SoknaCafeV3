(()=>{'use strict';
const root=document.querySelector('[data-operator-workspace]'); if(!root)return;
const mount=root.querySelector('[data-settlement-mount]'); if(!mount)return;
const appPath=v=>(window.SoknaURL?.path?window.SoknaURL.path(v):v);
const financeApi=root.dataset.financeApi||appPath('/finance/api.php');
const subscriberApi=root.dataset.subscriberApi||appPath('/subscribers/api.php');
const csrf=root.dataset.csrf||'';
const L=window.SoknaLocale||{integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),money:v=>`${new Intl.NumberFormat('fa-IR').format(Number(v||0))} تومان`,dateTime:v=>'—',humanDigits:v=>String(v??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)])};
const money=v=>L.money(v),finalMoney=v=>window.SoknaFinalAmountCurrency?.format?.(v,root.dataset.finalAmountRial==='1')||money(v),finalInput=v=>window.SoknaFinalAmountCurrency?.inputValue?.(v,root.dataset.finalAmountRial==='1')||String(v),parseFinal=v=>window.SoknaFinalAmountCurrency?.parseInput?.(v,root.dataset.finalAmountRial==='1')??null;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const T=window.SoknaSettlementTender;if(!T)return;
const svg=body=>`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${body}</svg>`;const icon=n=>({cash:svg('<path d="M4 7h16v10H4zM8 10h.01M16 14h.01M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z"/>'),split:svg('<path d="M12 3v18M4 7h4M4 12h4M4 17h4M16 7h4M16 12h4M16 17h4"/>'),user:svg('<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.7-4 3-6 7-6s6.3 2 7 6"/>'),home:svg('<path d="M3 11 12 4l9 7v9H3zM9 20v-6h6v6"/>'),more:svg('<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'),close:svg('<path d="m7 7 10 10M17 7 7 17"/>'),search:svg('<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>'),plus:svg('<path d="M12 5v14M5 12h14"/>'),minus:svg('<path d="M5 12h14"/>')}[n]||'');
let state={open:false,view:'entry',account:null,busy:false,error:'',recovery:null,lastSubmitKind:'',selection:new Map(),review:null,tender:T.fresh(),subscriberResults:[],subscriberQuery:'',subscriberSearchState:'idle',selectedSubscriber:null,reservationResults:[],reservationQuery:'',reservationSearchState:'idle',selectedReservation:null,more:false,receiptPrint:true,printPreferences:{direct:true,itemized:true,subscriber:true,accommodation:true},accommodationEnabled:true,canManageConnections:false,onComplete:null,onRecoveryState:null,onDetachFollowup:null,lastFocus:null,itemizedScrollTop:0,lateCatalog:[],lateSelection:new Map(),lateQuery:'',lateRequest:'',lateLoading:false,lateReturnView:'entry',lateSyncPending:false,lateExpectedSignature:'',lateExpectedOrderId:0,onLateComplete:null};
let viewportLock=false,settlementInertState=[];
// The exact payload must remain stable after a network-ambiguous settlement.
function unresolvedSettlement(){return Boolean(state.busy||state.pendingSettlement||state.pendingAccommodation)}
function settlementPossiblyCommitted(err){const n=Number(err?.httpStatus||err?.status||0);return Boolean(err?.unknown_outcome||n>=500||n===408||n===409||n===425||n===429||!n)}
function assertSettleReceipt(answer,payload){const item=answer?.result?.local||answer?.result||answer;const ok=Number(item?.settlement_id)>0&&Number(item?.session_id)===Number(payload.session_id)&&String(item?.destination||'')===String(payload.destination||'');if(!ok){const err=new Error('رسید قطعی تسویه معتبر نیست.');err.unknown_outcome=true;throw err}return answer}


// Exactly one unresolved financial operation per browser tab. No automatic financial POST
// is made when a saved record is recovered after refresh/login; the operator must inspect it.
const SETTLEMENT_PENDING_KEY='sokna.hall.finance.pending.v1';
function readFinancialHold(){
 let text;
 try{text=window.sessionStorage.getItem(SETTLEMENT_PENDING_KEY)}catch{throw new Error('حافظه تب در دسترس نیست؛ بدون امکان پیگیری درخواست قبلی، تسویه جدید مجاز نیست.')}
 if(!text)return null;
 let hold;try{hold=JSON.parse(text)}catch{throw new Error('اطلاعات پیگیری مالی تب آسیب دیده است؛ ابتدا وضعیت حساب را در بخش مالی تعیین تکلیف کنید.')}
 if(hold?.version!==1||!Number.isSafeInteger(Number(hold.session_id))||Number(hold.session_id)<1||!['settlement','accommodation'].includes(hold.type))
  throw new Error('داده پیگیری مالی ناشناخته است؛ تا بررسی دفتر مالی، پرداخت دیگری ثبت نکنید.');
 if(hold.type==='settlement'&&(!['direct','itemized','subscriber'].includes(hold.kind)||!hold.payload||Number(hold.payload.session_id)!==Number(hold.session_id)||!/^[A-Za-z0-9._:-]{8,96}$/.test(String(hold.payload.request_id||''))))
  throw new Error('رسید ذخیره‌شده تسویه کامل نیست؛ قبل از ثبت جدید بررسی مالی لازم است.');
 if(hold.type==='accommodation'&&(hold.kind!=='accommodation'||!String(hold.reservation_code||'').trim()))
  throw new Error('اطلاعات انتقال اقامتگاه ناقص است؛ بررسی مالی لازم است.');
 return hold;
}
function saveFinancialHold(hold){
 try{
  if(window.sessionStorage.getItem(SETTLEMENT_PENDING_KEY)===null&&window.sessionStorage.getItem('sokna:finance:pending-settlement:v1')!==null)throw new Error('other_finance_hold');
  const raw=JSON.stringify({...hold,version:1});
  window.sessionStorage.setItem(SETTLEMENT_PENDING_KEY,raw);
  if(window.sessionStorage.getItem(SETTLEMENT_PENDING_KEY)!==raw)throw new Error('not_saved');
 }catch{throw new Error('درخواست مالی ارسال نشد؛ ذخیره امن شناسه پیگیری در این تب ممکن نیست.')}
}
function clearFinancialHold(){try{window.sessionStorage.removeItem(SETTLEMENT_PENDING_KEY)}catch{ /* fail closed on next open if inaccessible */ }}
function financialHoldPanel(){
 const accommodation=Boolean(state.pendingAccommodation),hold=state.pendingSettlement||state.pendingAccommodation;
 const code=accommodation?'شناسه انتقال':'شناسه درخواست',id=accommodation?(hold.transfer_id||'در حال بررسی'):hold.payload.request_id;
 const retry=accommodation?'':`<button class="sc-button sc-button--secondary" type="button" data-settle-retry>ارسال مجدد همان درخواست</button>`;
 const finalize=accommodation&&state.accommodationRecoveryState==='posted_local_pending'?`<button class="sc-button" type="button" data-settle-accommodation-finalize>تکمیل تسویه محلی (بدون ارسال مجدد به اقامتگاه)</button>`:'';
 return `<div class="sui-settlement-dialog is-compact" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('پیگیری نتیجه تسویه','تا تعیین تکلیف، پرداخت دیگری ثبت نشود')}<div class="sui-settlement-body"><div class="sui-settlement-warning"><strong>نتیجه عملیات مالی هنوز قطعی نیست.</strong><p>پس از بارگذاری مجدد صفحه، همین درخواست حفظ شده است. ${accommodation?'هزینه را دوباره به اقامتگاه نفرستید.':'اگر تکرار لازم شد، تنها همان شناسه و همان جزئیات ارسال می‌شوند.'}</p><small>${esc(code)}: ${esc(id)}</small></div>${state.error?`<div class="sui-settlement-error">${esc(state.error)}</div>`:''}<div class="sui-settlement-recovery__actions"><button class="sc-button" type="button" data-settle-check-status>بررسی نتیجه ثبت‌شده</button>${finalize}${retry}</div></div></div>`;
}
async function checkSettlementStatus(){
 const saved=state.pendingSettlement;
 if(!saved||state.busy)return false;
 state.busy=true;state.error='';render();
 try{
  const p=saved.payload,url=`${financeApi}?action=settlement_request_status&session_id=${encodeURIComponent(p.session_id)}&request_id=${encodeURIComponent(p.request_id)}`;
  const j=await request(url);const result=j?.result||{};
  if(String(result.state)==='committed'){
   const answer={result:result.result};assertSettleReceipt(answer,p);
   clearFinancialHold();state.pendingSettlement=null;state.busy=false;
   await complete(answer);return true;
  }
  state.error=String(result.state)==='requires_review'?'سند نیازمند بررسی مالی است؛ تسویه تازه ثبت نکنید.':'هنوز رسید قطعی در دفتر مالی دیده نمی‌شود؛ این به معنی ثبت‌نشدن درخواست قبلی نیست.';
 }catch(e){state.error=e.message||'استعلام نتیجه انجام نشد؛ درخواست قبلی همچنان محفوظ است.'}
 state.busy=false;render();return false;
}
async function checkAccommodationStatus(){
 const hold=state.pendingAccommodation;
 if(!hold||state.busy)return false;
 state.busy=true;state.error='';render();
 try{
  const j=await request(`${financeApi}?action=accommodation_transfer_status&session_id=${encodeURIComponent(hold.session_id)}`);
  const r=j?.result||{};
  if(Number(r.session_id)!==Number(hold.session_id)||r.reservation_code&&String(r.reservation_code)!==String(hold.reservation_code))throw new Error('نتیجه انتقال با حساب یا رزرو فعلی مطابقت ندارد.');
  if(String(r.state)==='committed'){
   const answer={result:r.result};assertSettleReceipt(answer,{session_id:hold.session_id,destination:'accommodation'});
   clearFinancialHold();state.pendingAccommodation=null;state.busy=false;
   await complete(answer);return true;
  }
  if(Number(r.transfer_id)>0){hold.transfer_id=Number(r.transfer_id);saveFinancialHold(hold)}
  state.accommodationRecoveryState=String(r.state||'unknown');
  state.error=({posted_local_pending:'هزینه در اقامتگاه ثبت شده ولی تسویه محلی تکمیل نشده است.',remote_result_unknown:'نتیجه عملیات قبلی اقامتگاه مبهم یا هنوز در جریان است؛ ارسال مجدد ممنوع است.',prepared:'انتقال آماده شده اما تأیید قطعی ارسال آن نداریم.',failed:'عملیات ثبت هزینه با خطا مواجه شده؛ وضعیت انتقال را در مالی بررسی کنید.',requires_review:'انتقال نیازمند بررسی مالی است.',unconfirmed:'درخواست در دفتر محلی تأیید نشده؛ ثبت دوباره خودکار مجاز نیست.'})[state.accommodationRecoveryState]||'نتیجه انتقال هنوز روشن نیست.';
 }catch(e){state.error=e.message||'استعلام انتقال ناموفق بود. انتقال را دوباره نفرستید.'}
 state.busy=false;render();return false;
}
async function finalizePostedAccommodation(){
 const hold=state.pendingAccommodation;
 if(!hold||state.busy||state.accommodationRecoveryState!=='posted_local_pending'||Number(hold.transfer_id)<1)return false;
 state.busy=true;state.error='';render();
 try{
  const answer=await post(financeApi,{action:'accommodation_finalize',transfer_id:Number(hold.transfer_id)});
  assertSettleReceipt(answer,{session_id:hold.session_id,destination:'accommodation'});
  clearFinancialHold();state.pendingAccommodation=null;state.busy=false;await complete(answer);return true;
 }catch(e){state.error=e.message||'تکمیل تسویه محلی انجام نشد؛ وضعیت قبلی محفوظ است.';state.busy=false;render();return false}
}

const settlementFocusable='a[href],button:not([disabled]),input:not([type="hidden"]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
function setSettlementBackgroundInert(active){if(active){if(settlementInertState.length)return;let current=mount;while(current&&current!==document.body){const parent=current.parentElement;if(!parent)break;for(const sibling of parent.children){if(sibling===current)continue;const had=sibling.hasAttribute('inert');settlementInertState.push([sibling,had]);if(!had)sibling.setAttribute('inert','')}current=parent}}else{for(const [node,had] of settlementInertState){if(node?.isConnected&&!had)node.removeAttribute('inert')}settlementInertState=[]}}
function settlementFocusables(){const dialog=mount.querySelector('.sui-settlement-dialog');if(!dialog)return[];return [...dialog.querySelectorAll(settlementFocusable)].filter(node=>!node.hidden&&node.getAttribute('aria-hidden')!=='true'&&node.getClientRects().length>0)}
function lockViewport(){if(viewportLock)return;viewportLock=true;setSettlementBackgroundInert(true);document.documentElement.classList.add('sui-settlement-open');document.body?.classList.add('sui-settlement-open')}
function unlockViewport(){viewportLock=false;setSettlementBackgroundInert(false);document.documentElement.classList.remove('sui-settlement-open');document.body?.classList.remove('sui-settlement-open')}
const asciiDigits=v=>String(v??'').replace(/[۰-۹]/g,d=>'0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(d)]).replace(/[٠-٩]/g,d=>'0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(d)]);
function subscriberQueryMeta(v){const raw=String(v??'').trim(),ascii=asciiDigits(raw),compact=ascii.replace(/[\s()+\-._]+/g,''),numeric=/^\+?\d+$/.test(compact),digits=compact.replace(/\D+/g,'');return {raw,numeric,valid:numeric?digits.length>=3:[...raw].length>=2}}
function reservationQueryMeta(v){const raw=String(v??'').trim(),query=asciiDigits(raw).replace(/\s+/g,' ');return {raw,query,valid:[...query].length>=2}}
const FT=()=>window.SoknaFinanceTransport||null;
async function request(url,opt={}){const t=FT();if(t?.request)return t.request(url,opt);const r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json',...(opt.headers||{})},...opt});let j={};try{j=await r.json()}catch{}if(!r.ok||j.success===false){const e=new Error(j.message||'عملیات انجام نشد.');e.data=j;e.httpStatus=r.status;throw e}return j}
const post=(url,p)=>{const t=FT();return t?.post?t.post(url,{...p,csrf_token:csrf}):request(url,{method:'POST',body:JSON.stringify({...p,csrf_token:csrf})})};
const rid=p=>{const c=globalThis.crypto;return `${p}-${c?.randomUUID?c.randomUUID():Date.now().toString(36)+Math.random().toString(36).slice(2)}`};
const expected=()=>({session_id:Number(state.account?.session?.id||0),expected_session_id:Number(state.account?.session?.id||0),expected_remaining_total:Number(state.account?.remaining_total||0),expected_signature:String(state.account?.signature||'')});
function accountBox(compact=false){const a=state.account||{},discount=Number(a.remaining_discount||0),gross=Number(a.remaining_subtotal||0),tax=Number(a.remaining_tax||0),paid=Number(a.paid_total||0),total=Number(a.remaining_total||0),table=L.humanDigits?L.humanDigits(a.session?.table_name||''):String(a.session?.table_name||''),facts=[];if(discount>0)facts.push(`<span><small>تخفیف</small><b>− ${finalMoney(discount)}</b></span>`);if(tax>0)facts.push(`<span><small>مالیات</small><b>+ ${finalMoney(tax)}</b></span>`);if(paid>0)facts.push(`<span><small>پرداخت‌شده</small><b>${finalMoney(paid)}</b></span>`);return `<div class="sui-settlement-account${compact?' is-compact-context':''}"><div class="sui-settlement-account__main"><div><span>حساب جاری</span><strong>${esc(table||'—')}</strong>${gross>0?`<small>جمع اقلام ${finalMoney(gross)}</small>`:''}</div><div class="amount"><span>${compact?'مانده حساب':'قابل پرداخت'}</span>${compact?`<b>${finalMoney(total)}</b>`:`<strong>${finalMoney(total)}</strong>`}</div></div>${facts.length?`<div class="sui-settlement-account__facts">${facts.join('')}</div>`:''}</div>`}
function itemizedContext(){const a=state.account||{},paid=Number(a.paid_total||0),remaining=Number(a.remaining_total||0);return `<div class="sui-itemized-context"><div class="sui-itemized-balance"><div class="is-paid"><span>پرداخت‌شده تا این لحظه</span><b>${finalMoney(paid)}</b></div><div class="is-remaining"><span>مانده حساب</span><strong>${finalMoney(remaining)}</strong></div></div></div>`}
function recoveryPanel(){if(!state.recovery)return '';return `<div class="sui-settlement-recovery"><div><strong>تسویه کامل نشد</strong><small>${esc(state.recovery.message||'این حساب نیازمند پیگیری است.')}</small></div><div class="sui-settlement-recovery__actions"><button type="button" class="sc-button" data-settle-retry>تلاش مجدد</button><button type="button" class="sc-button sc-button--secondary" data-settle-change-method>تغییر روش تسویه</button><button type="button" class="sc-button sc-button--secondary" data-settle-detach>آزاد کردن میز و پیگیری حساب</button></div></div>`}
function messages(){return `${state.error?`<div class="sui-settlement-error">${esc(state.error)}</div>`:''}${state.recovery?recoveryPanel():''}${state.review?.warning?`<div class="sui-settlement-warning">${esc(state.review.warning)}</div>`:''}`}
function recoveryInfo(error,kind){const raw=String(error?.message||''),message=window.SoknaUI?.errorText?.(error,'تسویه کامل نشد. دوباره تلاش کنید.')||raw||'تسویه کامل نشد. دوباره تلاش کنید.',code=String(error?.data?.error_code||error?.data?.code||error?.code||''),status=Number(error?.httpStatus||error?.status||0),text=`${code} ${raw}`.toLowerCase(),network=/failed to fetch|networkerror|network request failed|load failed|fetch failed|offline|timeout|timed out|request_pending/.test(text),external=/accommodation|transfer|finalize_pending|reversal_pending|handler_failed|upstream|connection|relay/.test(text),server=status>=500; if(!network&&!external&&!server)return null;const destination=kind==='accommodation'?'accommodation':kind==='subscriber'?'subscriber':'direct';return {kind,destination,code:code||'network_failed',message,status}}
function head(title,sub=''){return `<header class="sui-settlement-head"><div class="sui-settlement-title"><strong id="sui-settlement-title">${esc(title)}</strong>${sub?`<small>${esc(sub)}</small>`:''}</div><button class="sui-settlement-close" type="button" data-settle-close aria-label="بستن">${icon('close')}</button></header>`}
function receiptToggle(){return `<label class="sui-settlement-receipt"><span><input type="checkbox" data-receipt-print ${state.receiptPrint?'checked':''}><span><strong>فیش مشتری</strong><small>چاپ پس از ثبت</small></span></span></label>`}
function foot(primary,label='ثبت تسویه',disabled=false){return `<footer class="sui-settlement-foot"><button class="sui-settlement-cta" type="button" data-settle-submit="${primary}" ${disabled||state.busy?'disabled':''}>${state.busy?'<span class="sui-settlement-spinner"></span>':esc(label)}</button><button class="sui-settlement-secondary" type="button" data-settle-entry ${state.busy?'disabled':''}>بازگشت</button></footer>`}
function settleFeature(key,def=true){const attr=root.dataset['settlement'+key[0].toUpperCase()+key.slice(1)];return attr===undefined||attr===''?def:attr==='1'}
function renderEntry(){const itemized=settleFeature('itemized'),subscriber=settleFeature('subscriber'),accommodation=settleFeature('accommodation');const primary=`<button class="sui-settlement-method is-primary" type="button" data-settle-view="direct"><span class="sui-settlement-method-icon">${icon('cash')}</span><span><strong>پرداخت مستقیم</strong><small>ثبت کل مانده و آزاد کردن میز</small></span></button>`;const item=itemized?`<button class="sui-settlement-method" type="button" data-settle-view="itemized"><span class="sui-settlement-method-icon">${icon('split')}</span><span><strong>تسویه آیتمی</strong><small>پرداخت بخشی از اقلام و ادامه حساب میز</small></span></button>`:'';const extra=[];if(subscriber)extra.push(`<button class="sui-settlement-method" type="button" data-settle-view="subscriber"><span class="sui-settlement-method-icon">${icon('user')}</span><span><strong>حساب مشتری</strong><small>ثبت کل مانده در حساب مشتری</small></span></button>`);if(accommodation)extra.push(`<button class="sui-settlement-method" type="button" data-settle-view="accommodation"><span class="sui-settlement-method-icon">${icon('home')}</span><span><strong>حساب اقامتگاه</strong><small>جست‌وجوی رزرو و انتقال کل مانده</small></span></button>`);return `<div class="sui-settlement-dialog is-compact" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('تسویه حساب','روش تسویه را انتخاب کنید')}<div class="sui-settlement-body">${messages()}${accountBox()}<button type="button" class="sc-button sc-button--secondary sui-settlement-late-entry" data-settle-late-open ${state.busy?'disabled':''}>+ ثبت قلم تحویل‌شدهٔ جاافتاده</button><div class="sui-settlement-grid">${primary}${item}</div>${extra.length?`<button class="sui-settlement-more" type="button" data-settle-more>${icon('more')} روش‌های دیگر</button>${state.more?`<div class="sui-settlement-more-panel"><div class="sui-settlement-grid">${extra.join('')}</div></div>`:''}`:''}</div></div>`}
function tenderBox(total,enabled=true){T.sync(state.tender,total);if(enabled&&Number(total)===0)return '<section class="sui-tender" data-tender-box><div class="sui-tender-head"><strong>روش پرداخت</strong><small>مبلغ نهایی صفر است؛ نیازی به انتخاب روش پرداخت نیست.</small></div></section>';const split=state.tender.selected.length>1;const buttons=T.TYPES.map(x=>{const selected=state.tender.selected.includes(x.type);return `<button type="button" class="sui-tender-choice${selected?' is-selected':''}" data-tender-type="${x.type}" aria-pressed="${selected?'true':'false'}" ${enabled?'':'disabled'}><strong>${esc(x.label)}</strong><small>${selected?'انتخاب‌شده':'انتخاب'}</small></button>`}).join('');const autoType=split?T.autoType(state.tender):'';const fields=split&&enabled?`<div class="sui-tender-split">${state.tender.selected.map(type=>{const auto=type===autoType;return `<label${auto?' class="is-auto"':''}><span>${esc(T.label(type))}${auto?'<small>باقیمانده</small>':''}</span><input type="text" inputmode="numeric" data-money-input="1" autocomplete="off" data-tender-amount="${type}" value="${L.humanDigits(finalInput(state.tender.amounts[type]||0))}" data-currency-unit="${root.dataset.finalAmountRial==='1'?'IRR':'IRT'}" aria-label="مبلغ ${esc(T.label(type))}" ${auto?'readonly aria-readonly="true"':''}></label>`}).join('')}</div>`:'';const delta=T.delta(state.tender);const summary=split&&enabled?`<div class="sui-tender-summary${T.valid(state.tender)?' is-valid':' is-pending'}"><span>${T.valid(state.tender)?'جمع روش‌های پرداخت':'مانده تخصیص'}</span><strong>${finalMoney(T.valid(state.tender)?T.sum(state.tender):Math.abs(delta))}</strong>${delta<0?'<small>جمع واردشده بیشتر از مبلغ تسویه است.</small>':delta>0?'<small>مبلغ روش‌های قبلی را وارد کنید؛ باقیمانده خودکار محاسبه می‌شود.</small>':'<small>آماده ثبت</small>'}</div>`:'';return `<section class="sui-tender${enabled?'':' is-disabled'}" data-tender-box><div class="sui-tender-head"><strong>روش پرداخت</strong><small>${enabled?'یک روش را انتخاب کنید؛ برای پرداخت ترکیبی دو روش یا بیشتر را فعال کنید.':'پس از انتخاب اقلام، روش پرداخت فعال می‌شود.'}</small></div><div class="sui-tender-choices">${buttons}</div>${fields}${summary}</section>`}
function receiptDefault(view){const key=view==='subscriber'?'subscriber':view==='accommodation'?'accommodation':view==='itemized'?'itemized':'direct';return state.printPreferences[key]!==false}
function renderDirect(){const total=Number(state.account?.remaining_total||0);return `<div class="sui-settlement-dialog is-compact" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('پرداخت مستقیم','تأیید نهایی')}<div class="sui-settlement-body">${messages()}${accountBox()}<div class="sui-settlement-confirm"><h3>ثبت پرداخت و بستن میز</h3><p>کل مانده حساب تسویه می‌شود و میز آزاد خواهد شد.</p></div>${tenderBox(total)}${receiptToggle()}</div>${foot('direct',`ثبت پرداخت · ${finalMoney(total)}`,!T.valid(state.tender))}</div>`}
function selectedQty(id){return Number(state.selection.get(Number(id))||0)}
function itemSelection(){return [...state.selection.entries()].filter(([,q])=>q>0).map(([order_item_id,quantity])=>({order_item_id:Number(order_item_id),quantity:Number(quantity)}))}
function renderItemized(){const items=(state.account?.items||[]).filter(x=>Number(x.remaining_quantity)>0);const rows=items.map(x=>{const q=selectedQty(x.id);return `<div class="sui-settlement-line${q>0?' is-selected':''}"><div class="sui-settlement-line-main"><strong>${esc(x.item_name)}</strong><span class="sui-settlement-remaining"><b>${L.integer(x.remaining_quantity)} مانده</b><small>قیمت واحد ${money(x.unit_price)}</small></span></div><div class="sui-settlement-stepper"><button type="button" data-settle-minus="${Number(x.id)}" aria-label="کم کردن" ${q<=0?'disabled':''}>${icon('minus')}</button><output>${L.integer(q)}</output><button type="button" data-settle-plus="${Number(x.id)}" data-max="${Number(x.remaining_quantity)}" aria-label="اضافه کردن" ${q>=Number(x.remaining_quantity)?'disabled':''}>${icon('plus')}</button></div></div>`}).join('');const review=state.review,total=review&&!review.loading?Number(review.total||0):Number(review?.total||0);if(total>0)T.sync(state.tender,total);const table=esc(L.humanDigits?L.humanDigits(state.account?.session?.table_name||'میز'):String(state.account?.session?.table_name||'میز'));return `<div class="sui-settlement-dialog is-itemized${items.length>6?' is-long':''}" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('تسویه آیتمی','میز '+table+' | اقلام را انتخاب کنید')}<div class="sui-settlement-body"><div class="sui-itemized-messages">${messages()}</div>${itemizedContext()}<div class="sui-settlement-lines" data-itemized-lines>${rows||'<div class="sui-settlement-info">قلم پرداخت‌نشده‌ای باقی نمانده است.</div>'}</div><div class="sui-itemized-payment"><div class="sui-settlement-review${review?.loading?' is-loading':''}"><span>مبلغ این پرداخت${review?.loading?' · در حال محاسبه':''}</span><strong>${finalMoney(total)}</strong></div>${tenderBox(total,total>0)}${receiptToggle()}</div></div>${foot('itemized',total>0?`ثبت پرداخت · ${finalMoney(total)}`:'ثبت پرداخت',!review||review.loading||!itemSelection().length||!T.valid(state.tender))}</div>`}
function renderSubscriber(){const searching=state.subscriberSearchState==='loading',selected=state.selectedSubscriber;if(selected){return `<div class="sui-settlement-dialog" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('ثبت در حساب مشتری','مشتری انتخاب‌شده')}<div class="sui-settlement-body">${messages()}${accountBox()}<div class="sui-settlement-selected is-final"><div><strong>${esc(selected.name)}</strong><small>${esc(L.humanDigits?L.humanDigits(selected.mobile||'بدون شماره'):(selected.mobile||'بدون شماره'))} · مانده فعلی ${finalMoney(selected.balance||0)}</small></div><button type="button" class="sui-settlement-change" data-subscriber-clear>تغییر مشتری</button></div>${receiptToggle()}</div>${foot('subscriber',`ثبت ${finalMoney(Number(state.account?.remaining_total||0))} در حساب مشتری`,false)}</div>`}const results=state.subscriberResults.map((r,i)=>`<button class="sui-settlement-result" type="button" data-subscriber-id="${Number(r.id)}" data-subscriber-result="${i}"><span><strong>${esc(r.name)}</strong><small>${esc(L.humanDigits?L.humanDigits(r.mobile||'بدون شماره'):(r.mobile||'بدون شماره'))}${r.last_activity?` · آخرین فعالیت ${esc(L.dateTime(r.last_activity))}`:''}</small></span><b>${finalMoney(r.balance||0)}</b></button>`).join(''),empty=searching?'<div class="sui-settlement-info">در حال جست‌وجوی مشتری…</div>':state.subscriberSearchState==='done'&&!results?'<div class="sui-settlement-info">مشتری مطابق این جست‌وجو پیدا نشد.</div>':'';return `<div class="sui-settlement-dialog" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('ثبت در حساب مشتری','جست‌وجو و انتخاب مشتری')}<div class="sui-settlement-body">${messages()}${accountBox()}<div class="sui-settlement-search is-late"><input type="search" data-subscriber-search autocomplete="off" value="${esc(state.subscriberQuery)}" aria-controls="sui-subscriber-results" placeholder="نام یا موبایل مشتری"><button type="button" data-subscriber-search-button aria-label="جست‌وجوی مشتری" ${searching?'disabled':''}>${searching?'<span class="sui-settlement-spinner"></span>':icon('search')}</button></div><div class="sui-settlement-results" id="sui-subscriber-results" aria-live="polite" aria-busy="${searching?'true':'false'}">${results||empty}</div></div>${foot('subscriber','ثبت در حساب مشتری',true)}</div>`}
function renderAccommodation(){if(!state.accommodationEnabled)return `<div class="sui-settlement-dialog is-compact" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('ثبت در حساب اقامتگاه','اتصال اقامتگاه فعال نیست')}<div class="sui-settlement-body">${accountBox()}<div class="sui-settlement-callout"><strong>اتصال اقامتگاه فعال نیست</strong><p>برای جست‌وجوی مهمان و انتقال حساب، ابتدا اتصال اقامتگاه را در بخش «اتصال‌ها» راه‌اندازی کنید.</p>${state.canManageConnections?`<a class="sc-button sc-button--secondary" href="${esc(appPath('/integrations/'))}">رفتن به اتصال‌ها</a>`:''}</div></div>${foot('accommodation','ثبت در حساب اقامتگاه',true)}</div>`;const searching=state.reservationSearchState==='loading',results=state.reservationResults.map((r,i)=>`<button class="sui-settlement-result${state.selectedReservation===r?' is-selected':''}" type="button" data-reservation-index="${i}" data-reservation-result="${i}"><span><strong>${esc(r.guest_name)}</strong><small>${esc(r.room_name)}${r.phone_hint?` · ${esc(L.humanDigits?L.humanDigits(r.phone_hint):r.phone_hint)}`:''}</small></span><b>${esc(L.humanDigits?L.humanDigits(r.reservation_code):r.reservation_code)}</b></button>`).join(''),empty=searching?'<div class="sui-settlement-info">در حال جست‌وجوی رزرو…</div>':state.reservationSearchState==='done'&&!results?'<div class="sui-settlement-info">رزروی مطابق این جست‌وجو پیدا نشد.</div>':'';return `<div class="sui-settlement-dialog" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('ثبت در حساب اقامتگاه','نام، موبایل یا کد رزرو')}<div class="sui-settlement-body">${messages()}${accountBox()}<div class="sui-settlement-search is-late"><input type="search" data-reservation-search autocomplete="off" value="${esc(state.reservationQuery)}" aria-controls="sui-reservation-results" placeholder="نام مهمان، موبایل یا کد رزرو"><button type="button" data-reservation-search-button aria-label="جست‌وجوی رزرو" ${searching?'disabled':''}>${searching?'<span class="sui-settlement-spinner"></span>':icon('search')}</button></div><div class="sui-settlement-results" id="sui-reservation-results" aria-live="polite" aria-busy="${searching?'true':'false'}">${results||empty}</div>${state.selectedReservation?`<div class="sui-settlement-selected"><strong>${esc(state.selectedReservation.guest_name)} · ${esc(state.selectedReservation.room_name)}</strong><small>رزرو ${esc(L.humanDigits?L.humanDigits(state.selectedReservation.reservation_code):state.selectedReservation.reservation_code)}</small></div>`:''}</div>${foot('accommodation',state.selectedReservation?`ثبت ${finalMoney(Number(state.account?.remaining_total||0))} در حساب اقامتگاه`:'ثبت در حساب اقامتگاه',!state.selectedReservation)}</div>`}
// One correction flow for every open-account settlement method.
function renderLateServed(){
 if(state.lateSyncPending)return `<div class="sui-settlement-dialog is-compact" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('قلم به حساب اضافه شد','به‌روزرسانی ماندهٔ میز')}
 <div class="sui-settlement-body">${messages()}<div class="sui-settlement-info">قلم ثبت شد. برای ادامهٔ تسویه، ابتدا حساب میز را به‌روز کنید.</div></div>
 <footer class="sui-settlement-foot"><button type="button" class="sui-settlement-cta sc-refresh-action" data-late-refresh ${state.busy?'disabled':''} aria-label="بازخوانی حساب میز" title="بازخوانی حساب میز"><svg class="sc-ui-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 11a8 8 0 0 0-14.1-5.2L4 8m0-5v5h5M4 13a8 8 0 0 0 14.1 5.2L20 16m0 5v-5h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></footer></div>`;
 const query=String(state.lateQuery||'').trim().toLocaleLowerCase('fa');
 const rows=(state.lateCatalog||[]).filter(x=>!query||String(x.name||'').toLocaleLowerCase('fa').includes(query)).slice(0,query?9:5);
 const selected=[...state.lateSelection.entries()].filter(([,q])=>q>0);
 const selectedTotal=selected.reduce((sum,[id,q])=>sum+Number((state.lateCatalog||[]).find(x=>Number(x.id)===Number(id))?.price||0)*q,0);
 const list=state.lateLoading?'<p>در حال دریافت منو…</p>':rows.length?rows.map(x=>{
   const id=Number(x.id),qty=Number(state.lateSelection.get(id)||0);
   return `<div class="sui-settlement-line"><div class="sui-settlement-line-main"><strong>${esc(x.name)}</strong><small>${money(x.price)}</small></div><div class="sui-settlement-stepper"><button type="button" data-late-minus="${id}" ${qty<=0?'disabled':''} aria-label="کم کردن">${icon('minus')}</button><output>${L.integer(qty)}</output><button type="button" data-late-plus="${id}" ${qty>=50?'disabled':''} aria-label="اضافه کردن">${icon('plus')}</button></div></div>`;
 }).join(''):'<p>قلمی با این نام پیدا نشد.</p>';
 return `<div class="sui-settlement-dialog" role="dialog" aria-modal="true" aria-labelledby="sui-settlement-title" tabindex="-1">${head('ثبت قلم تحویل‌شدهٔ جاافتاده','فقط برای اقلامی که مهمان قبلاً دریافت کرده است')}
 <div class="sui-settlement-body">${messages()}${accountBox(true)}
 <div class="sui-settlement-warning" role="note">فقط قلمی را ثبت کنید که قبلاً تحویل شده است. مبلغ به حساب میز ثبت می‌شود و مصرف موجودی هم محاسبه می‌شود؛ فیش آماده‌سازی جدید صادر نمی‌شود. برای سفارش تازه، «افزودن سفارش» را بزنید.</div>
 <label class="sui-settlement-search is-late"><span>جستجوی قلم</span><input class="sc-control" data-late-search type="search" autocomplete="off" value="${esc(state.lateQuery)}" placeholder="نام نوشیدنی یا غذا" /></label>
 <div class="sui-settlement-lines" data-late-items>${list}</div>
 <div class="sui-itemized-balance"><div class="is-remaining"><span>مبلغ اقلام جاافتاده</span><strong>${finalMoney(selectedTotal)}</strong></div></div>
 </div><footer class="sui-settlement-foot"><button class="sui-settlement-cta" type="button" data-late-commit ${!selected.length||state.busy||state.lateLoading?'disabled':''}>${state.busy?'در حال ثبت…':'ثبت قلم تحویل‌شده'}</button><button class="sui-settlement-secondary" type="button" data-late-back ${state.busy?'disabled':''}>بازگشت به تسویه</button></footer></div>`;
}
async function showLate(){
 if(state.busy)return;
 const lookupState=state;const isCurrent=()=>state===lookupState&&state.open&&state.view==='late';
 if(state.pendingRequestId){state.error='نتیجهٔ تسویهٔ قبلی هنوز مشخص نیست؛ ابتدا وضعیت آن را بررسی کن.';render();return}
 state.lateReturnView=state.view;state.view='late';state.error='';state.lateLoading=true;state.lateSelection=new Map();state.lateQuery='';state.lateRequest='';render();
 try{const j=await post(financeApi,{action:'late_served_catalog'});if(!isCurrent())return;state.lateCatalog=Array.isArray(j.items)?j.items:[];}
 catch(e){if(!isCurrent())return;state.lateCatalog=[];state.error=e.message||'دریافت منو انجام نشد.';}
 finally{if(isCurrent()){state.lateLoading=false;render()}}
}
function lateAccountVisible(){
 return !!state.account&&((String(state.lateExpectedSignature||'').length===64&&String(state.account.signature||'')===String(state.lateExpectedSignature||'')) ||
   (Number(state.lateExpectedOrderId)>0&&(state.account.orders||[]).some(o=>Number(o.id||o.order_id)===Number(state.lateExpectedOrderId))));
}
async function lateRefresh(){
 if(state.busy)return;state.busy=true;state.error='';render();
 try{
   await refreshAccount();
   if(!lateAccountVisible()){state.error='ثبت انجام شده، اما نمایش Public هنوز ماندهٔ جدید را دریافت نکرده است. کمی بعد دوباره بررسی کنید.';return}
   state.lateSyncPending=false;state.view=state.lateReturnView||'entry';state.tender=T.fresh();state.review=null;state.selection=new Map();
   try{if(typeof state.onLateComplete==='function')await state.onLateComplete()}catch{}
   window.SoknaUI?.toast?.('حساب با قلم جاافتاده تازه شد.',{tone:'success'});
 }catch(e){state.error=e?.message||'دریافت حساب تازه انجام نشد.'}
 finally{state.busy=false;render()}
}
async function commitLate(){
 if(state.busy||state.lateLoading||state.lateSyncPending)return;
 const rows=[...state.lateSelection.entries()].filter(([,q])=>q>0).map(([id,quantity])=>{
   const x=state.lateCatalog.find(x=>Number(x.id)===Number(id));
   return {id:Number(id),quantity:Number(quantity),expected_price:Number(x?.price||0)};
 });
 if(!rows.length)return;
 if(!state.lateRequest)state.lateRequest=rid('late-served');
 state.busy=true;state.error='';render();
 try{
   const answer=await post(financeApi,{action:'late_served_commit',...expected(),request_id:state.lateRequest,items:rows});
   const committed=answer?.result||answer||{};
   // Command acknowledgement is durable even when Public projection has not caught up.
   state.lateRequest='';state.lateSelection=new Map();
   state.lateExpectedSignature=String(committed.account_signature||'');
   state.lateExpectedOrderId=Number(committed.order_id||0);
   state.lateSyncPending=true;
   try{await refreshAccount()}catch{}
   if(!lateAccountVisible()){
     state.error='قلم در سامانهٔ کافه ثبت شد؛ نمایش حساب هنوز در حال همگام‌سازی است.';
   }else{
     state.lateSyncPending=false;state.view=state.lateReturnView||'entry';state.tender=T.fresh();state.review=null;state.selection=new Map();
     try{if(typeof state.onLateComplete==='function')await state.onLateComplete()}catch{}
     window.SoknaUI?.toast?.('قلم تحویل‌شده در حساب ثبت شد؛ فیش آماده‌سازی صادر نشد.',{tone:'success'});
   }
 }catch(e){
   state.error=e?.message||'نتیجهٔ ثبت مشخص نیست؛ وضعیت همان درخواست را بررسی کن.';
   // Deterministic, server-confirmed rejections are safe to correct with a new ID;
   // network timeouts, 5xx, pending relay, and unrecognized 409 preserve the same ID.
   const code=String(e?.data?.code||e?.data?.error_code||e?.code||'');
   if(['price_changed','account_changed','expected_state_required','session_closed','forbidden',
       'invalid_items','item_unavailable','bundle_not_supported','idempotency_conflict',
       'unconfirmed_orders','accommodation_transfer_open','partial_discount_reallocation'].includes(code)&&!e?.unknown_outcome)state.lateRequest='';
   // No new request ID after an uncertain outcome.
 }finally{state.busy=false;render()}
}
function render(){const oldBody=mount.querySelector('.sui-settlement-dialog.is-itemized>.sui-settlement-body');if(oldBody)state.itemizedScrollTop=oldBody.scrollTop;if(!state.open){mount.innerHTML='';unlockViewport();return}let body=state.pendingSettlement||state.pendingAccommodation?financialHoldPanel():state.view==='direct'?renderDirect():state.view==='itemized'?renderItemized():state.view==='subscriber'?renderSubscriber():state.view==='accommodation'?renderAccommodation():state.view==='late'?renderLateServed():renderEntry();mount.innerHTML=`<div class="sui-settlement-overlay">${body}</div>`;lockViewport();const scrollBody=mount.querySelector('.sui-settlement-dialog.is-itemized>.sui-settlement-body');if(scrollBody)scrollBody.scrollTop=Number(state.itemizedScrollTop||0);mount.querySelector('.sui-settlement-dialog')?.focus({preventScroll:true})}
function open(account,{onComplete,onLateComplete,onRecoveryState,onDetachFollowup,printPreferences={},accommodationEnabled=true,canManageConnections=false}={}){
 if(!account?.session?.id||state.open&&unresolvedSettlement())return false;
 let hold=null;try{hold=readFinancialHold()}catch(e){window.SoknaUI?.toast?.(e.message,{tone:'error'});return false}
 if(hold&&Number(hold.session_id)!==Number(account.session.id)){window.SoknaUI?.toast?.('این تب درخواست مالی حل‌نشده میز دیگری دارد. ابتدا همان حساب را باز و تعیین تکلیف کنید.',{tone:'error'});return false}
 const prefs={direct:true,itemized:true,subscriber:true,accommodation:true,...printPreferences};state={open:true,view:'entry',account,busy:false,error:'',recovery:null,lastSubmitKind:'',selection:new Map(),review:null,tender:T.fresh(),subscriberResults:[],subscriberQuery:'',subscriberSearchState:'idle',selectedSubscriber:null,reservationResults:[],reservationQuery:'',reservationSearchState:'idle',selectedReservation:null,more:false,receiptPrint:prefs.direct!==false,printPreferences:prefs,accommodationEnabled:Boolean(accommodationEnabled),canManageConnections:Boolean(canManageConnections),onComplete:onComplete||null,onRecoveryState:onRecoveryState||null,onDetachFollowup:onDetachFollowup||null,lastFocus:document.activeElement,itemizedScrollTop:0,lateCatalog:[],lateSelection:new Map(),lateQuery:'',lateRequest:'',lateLoading:false,lateReturnView:'entry',lateSyncPending:false,lateExpectedSignature:'',lateExpectedOrderId:0,onLateComplete:onLateComplete||null,pendingSettlement:hold?.type==='settlement'?{kind:hold.kind,payload:hold.payload}:null,pendingAccommodation:hold?.type==='accommodation'?hold:null,accommodationRecoveryState:''};if(hold)state.lastSubmitKind=hold.kind;render();return true}
function close(){if(state.open&&unresolvedSettlement()){state.error=state.busy?'تسویه در حال ارسال است؛ تا مشخص‌شدن نتیجه پنجره را نبند.':'نتیجهٔ تسویه نامعلوم است؛ فقط همان درخواست را پیگیری کنید، پرداخت تازه ممنوع است.';render();return}if(state.open&&state.view==='late'&&(state.lateRequest||state.lateSyncPending)){state.error='نتیجهٔ ثبت قلم هنوز قطعی یا در حساب قابل مشاهده نیست؛ همین درخواست را پیگیری کن و پنجره را نبند.';render();return}const f=state.lastFocus;state.open=false;render();if(f&&typeof f.focus==='function')f.focus({preventScroll:true})}
async function refreshAccount(){const j=await request(`${financeApi}?action=account&session_id=${encodeURIComponent(state.account.session.id)}`);state.account=j.account;return state.account}
async function complete(j){const outer=j?.result??j??{};const settled=outer?.local&&typeof outer.local==='object'?outer.local:outer;const invoice=String(settled?.invoice_number||'');const closes=Boolean(settled?.closes_session);state.open=false;render();if(typeof state.onComplete==='function')await state.onComplete({invoice,closes,result:settled});window.SoknaUI?.toast(invoice?`تسویه ${L.humanDigits?L.humanDigits(invoice):invoice} ثبت شد.`:'تسویه ثبت شد.',{tone:'success'})}
async function submit(kind){
 if(state.busy)return;
 if(state.pendingAccommodation){if(kind==='accommodation')return checkAccommodationStatus();state.error='انتقال اقامتگاه هنوز تعیین تکلیف نشده؛ ثبت تسویه دیگر مجاز نیست.';render();return;}
 // A retry must keep BOTH the original request id and all financial fields unchanged.
 if(state.pendingSettlement&&state.pendingSettlement.kind!==kind){state.error='نتیجه درخواست قبلی نامعلوم است؛ تغییر روش تسویه مجاز نیست.';render();return}
 state.lastSubmitKind=String(kind||state.lastSubmitKind||'direct');state.busy=true;state.error='';state.recovery=null;
 if(typeof state.onRecoveryState==='function')state.onRecoveryState(null);render();
 try{
  if(kind==='direct'||kind==='itemized'||kind==='subscriber'){
   let saved=state.pendingSettlement;
   if(!saved){
    const base={action:'settle',...expected(),request_id:rid('hall-settle'),print_receipt:Boolean(state.receiptPrint)};
    let payload;
    if(kind==='direct'){
     const total=Number(state.account?.remaining_total||0);T.sync(state.tender,total);
     if(!T.valid(state.tender))throw new Error('روش پرداخت و مبلغ آن را کامل کن.');
     payload={...base,destination:'direct',mode:'full',tenders:T.rows(state.tender)};
    }else if(kind==='itemized'){
     const selection=itemSelection();if(!selection.length)throw new Error('حداقل یک قلم را انتخاب کنید.');
     const total=Number(state.review?.total||0);T.sync(state.tender,total);
     if(!T.valid(state.tender))throw new Error('روش پرداخت و مبلغ آن را کامل کن.');
     payload={...base,destination:'direct',mode:'itemized',selection,tenders:T.rows(state.tender)};
    }else{
     if(!state.selectedSubscriber)throw new Error('مشتری انتخاب نشده است.');
     payload={...base,destination:'subscriber',mode:'full',subscriber_id:Number(state.selectedSubscriber.id)};
    }
    // A copy prevents later edits to selected tender/selection from changing a retry.
    saved={kind,payload:JSON.parse(JSON.stringify(payload))};
   }
   saveFinancialHold({type:'settlement',session_id:Number(saved.payload.session_id),kind:saved.kind,payload:saved.payload});
   state.pendingSettlement=saved;
   const response=await post(financeApi,saved.payload);
   assertSettleReceipt(response,saved.payload);
   clearFinancialHold();state.pendingSettlement=null;
   return await complete(response);
  }
  if(kind==='accommodation'){
    if(state.pendingAccommodation){state.busy=false;return await checkAccommodationStatus()}
    if(!state.selectedReservation)throw new Error('رزرو انتخاب نشده است.');
    const hold={type:'accommodation',kind:'accommodation',session_id:Number(state.account.session.id),reservation_code:String(state.selectedReservation.reservation_code),transfer_id:0,phase:'preparing'};
    // Persist BEFORE prepare: even a lost prepare reply must not lead to a blind second charge.
    saveFinancialHold(hold);state.pendingAccommodation=hold;
    const prepared=await post(financeApi,{action:'accommodation_prepare',...expected(),reservation_code:hold.reservation_code,guest_name:String(state.selectedReservation.guest_name),room_name:String(state.selectedReservation.room_name),phone_hint:String(state.selectedReservation.phone_hint||'')});
    const transferId=Number(prepared.result?.id||prepared.result?.transfer_id||0);if(transferId<1)throw new Error('شناسه انتقال اقامتگاه دریافت نشد؛ عملیات قبلی را بررسی کنید.');
    hold.transfer_id=transferId;hold.phase='charging';saveFinancialHold(hold);
    const response=await post(financeApi,{action:'accommodation_charge',transfer_id:transferId});
    assertSettleReceipt(response,{session_id:hold.session_id,destination:'accommodation'});
    clearFinancialHold();state.pendingAccommodation=null;
    return await complete(response)
  }
 }catch(e){
  state.error=window.SoknaUI?.errorText?.(e,'عملیات انجام نشد.')||e.message||'عملیات انجام نشد.';
  // When a response may have committed, the UI may only retry the exact payload.
  if(!settlementPossiblyCommitted(e))state.pendingSettlement=null;
  if(state.pendingSettlement)state.error='پاسخ تسویه نامعلوم است؛ برای پیگیری تنها همان درخواست قبلی مجاز است.';
  if(state.pendingAccommodation)state.error='نتیجه انتقال اقامتگاه روشن نیست؛ ابتدا وضعیت همان انتقال را استعلام کنید. ارسال مجدد ممنوع است.';
  state.recovery=recoveryInfo(e,kind)||(state.pendingSettlement?{kind,code:'unknown_outcome',message:'نتیجه ثبت نامعلوم است؛ تنها همان درخواست قبلی قابل تکرار است.'}:null);
  if(typeof state.onRecoveryState==='function')state.onRecoveryState(state.recovery);
  try{if(!state.pendingSettlement)await refreshAccount()}catch{}
  state.busy=false;render()
 }
}

let reviewTimer=0,reviewSeq=0;
function scheduleReview(){clearTimeout(reviewTimer);const selection=itemSelection();if(!selection.length){state.review=null;render();return}const seq=++reviewSeq;state.review={loading:true,total:state.review?.total||0};render();reviewTimer=setTimeout(async()=>{try{const j=await post(financeApi,{action:'review',...expected(),mode:'itemized',selection});if(seq!==reviewSeq)return;state.review={...(j.review||{}),loading:false};state.error=''}catch(e){if(seq!==reviewSeq)return;state.review=null;state.error=e.message||'محاسبه مبلغ انجام نشد.'}render()},160)}
async function searchSubscriber(){const lookupState=state,lookupToken=(state.subscriberLookupToken||0)+1;state.subscriberLookupToken=lookupToken;const isCurrent=()=>state===lookupState&&state.open&&state.view==='subscriber'&&state.subscriberLookupToken===lookupToken;const input=mount.querySelector('[data-subscriber-search]'),meta=subscriberQueryMeta(input?.value||'');state.subscriberQuery=meta.raw;state.selectedSubscriber=null;if(!meta.valid){state.subscriberResults=[];state.subscriberSearchState='idle';state.error=meta.numeric?'حداقل سه رقم از موبایل مشتری را وارد کنید.':'حداقل دو حرف از نام مشتری را وارد کنید.';render();mount.querySelector('[data-subscriber-search]')?.focus({preventScroll:true});return}state.subscriberSearchState='loading';state.error='';render();try{const j=await request(`${subscriberApi}?action=settlement_search&q=${encodeURIComponent(meta.raw)}`);if(!isCurrent())return;state.subscriberResults=Array.isArray(j.rows)?j.rows:[];state.subscriberSearchState='done'}catch(e){if(!isCurrent())return;state.subscriberResults=[];state.subscriberSearchState='error';state.error=e.message||'جست‌وجوی مشتری انجام نشد.'}if(!isCurrent())return;render();const next=mount.querySelector('[data-subscriber-search]');if(next){next.focus({preventScroll:true});next.setSelectionRange?.(next.value.length,next.value.length)}}
async function searchAccommodation(){const lookupState=state,lookupToken=(state.reservationLookupToken||0)+1;state.reservationLookupToken=lookupToken;const isCurrent=()=>state===lookupState&&state.open&&state.view==='accommodation'&&state.reservationLookupToken===lookupToken;const input=mount.querySelector('[data-reservation-search]'),meta=reservationQueryMeta(input?.value||'');state.reservationQuery=meta.raw;state.selectedReservation=null;if(!meta.valid){state.reservationResults=[];state.reservationSearchState='idle';state.error='حداقل دو نویسه از نام، موبایل یا کد رزرو را وارد کنید.';render();mount.querySelector('[data-reservation-search]')?.focus({preventScroll:true});return}state.reservationSearchState='loading';state.error='';render();try{const j=await request(`${financeApi}?action=reservation_search&q=${encodeURIComponent(meta.query)}`);if(!isCurrent())return;state.reservationResults=Array.isArray(j.rows)?j.rows:[];state.reservationSearchState='done'}catch(e){if(!isCurrent())return;state.reservationResults=[];state.reservationSearchState='error';state.error=e.message||'جست‌وجوی اقامتگاه انجام نشد.'}if(!isCurrent())return;render();const next=mount.querySelector('[data-reservation-search]');if(next){next.focus({preventScroll:true});next.setSelectionRange?.(next.value.length,next.value.length)}}
mount.addEventListener('click',e=>{
 if(e.target.closest('[data-settle-late-open]')){void showLate();return}
 if(e.target.closest('[data-late-back]')){if(state.lateRequest){state.error='نتیجه ثبت قبلی نامشخص است؛ با همان شناسه دوباره تلاش کن.';render();return}state.view=state.lateReturnView||'entry';state.error='';render();return}
 if(e.target.closest('[data-late-refresh]')){void lateRefresh();return}
 if(e.target.closest('[data-late-commit]')){void commitLate();return}
 const inc=e.target.closest('[data-late-plus],[data-late-minus]');if(inc){if(state.lateRequest)return;const id=Number(inc.dataset.latePlus||inc.dataset.lateMinus||0),before=Number(state.lateSelection.get(id)||0),next=Math.max(0,Math.min(50,before+(inc.hasAttribute('data-late-plus')?1:-1)));state.lateSelection.set(id,next);render();return}
 if(e.target.closest('[data-settle-close]')||e.target.classList.contains('sui-settlement-overlay'))return close();if(e.target.closest('[data-settle-check-status]')){if(state.pendingAccommodation)return void checkAccommodationStatus();if(state.pendingSettlement)return void checkSettlementStatus();return}if(e.target.closest('[data-settle-accommodation-finalize]'))return void finalizePostedAccommodation();if(e.target.closest('[data-settle-retry]')){if(state.pendingAccommodation)return void checkAccommodationStatus();if(state.lastSubmitKind)return submit(state.lastSubmitKind);return}if(e.target.closest('[data-settle-change-method]')){if(unresolvedSettlement())return;state.view='entry';state.error='';state.recovery=null;if(typeof state.onRecoveryState==='function')state.onRecoveryState(null);render();return}if(e.target.closest('[data-settle-detach]')){if(unresolvedSettlement())return;if(state.recovery&&typeof state.onDetachFollowup==='function')return state.onDetachFollowup(state.recovery);return}const v=e.target.closest('[data-settle-view]');if(v){if(unresolvedSettlement())return;state.view=v.dataset.settleView;state.error='';state.recovery=null;if(typeof state.onRecoveryState==='function')state.onRecoveryState(null);state.review=null;state.receiptPrint=receiptDefault(state.view);render();return}if(e.target.closest('[data-settle-entry]')){if(unresolvedSettlement())return;state.view='entry';state.error='';render();return}if(e.target.closest('[data-settle-more]')){state.more=!state.more;render();return}const tender=e.target.closest('[data-tender-type]');if(tender){const total=state.view==='itemized'?Number(state.review?.total||0):Number(state.account?.remaining_total||0);T.toggle(state.tender,String(tender.dataset.tenderType||''),total);render();return}const plus=e.target.closest('[data-settle-plus]');if(plus){const id=Number(plus.dataset.settlePlus),max=Number(plus.dataset.max);state.selection.set(id,Math.min(max,selectedQty(id)+1));scheduleReview();return}const minus=e.target.closest('[data-settle-minus]');if(minus){const id=Number(minus.dataset.settleMinus);state.selection.set(id,Math.max(0,selectedQty(id)-1));scheduleReview();return}if(e.target.closest('[data-subscriber-clear]')){state.selectedSubscriber=null;state.subscriberResults=[];state.subscriberSearchState='idle';state.subscriberQuery='';render();return}const receipt=e.target.closest('[data-receipt-print]');if(receipt){state.receiptPrint=Boolean(receipt.checked);return}const sub=e.target.closest('[data-subscriber-id]');if(sub){state.selectedSubscriber=state.subscriberResults.find(x=>Number(x.id)===Number(sub.dataset.subscriberId))||null;render();return}const res=e.target.closest('[data-reservation-index]');if(res){state.selectedReservation=state.reservationResults[Number(res.dataset.reservationIndex)]||null;render();return}if(e.target.closest('[data-subscriber-search-button]'))return searchSubscriber();if(e.target.closest('[data-reservation-search-button]'))return searchAccommodation();const submit=e.target.closest('[data-settle-submit]');if(submit)return submit.disabled?null:submit.dataset.settleSubmit&&window.SoknaHallSettlement.submit(submit.dataset.settleSubmit)});
mount.addEventListener('input',e=>{if(e.target.matches?.('[data-late-search]')){state.lateQuery=e.target.value;const pos=e.target.selectionStart;render();const input=mount.querySelector('[data-late-search]');input?.focus({preventScroll:true});try{input?.setSelectionRange(pos,pos)}catch{}return}const input=e.target.closest?.('[data-tender-amount]');if(!input)return;const converted=parseFinal(input.value);if(converted===null){input.setAttribute('aria-invalid','true');mount.querySelector('[data-settle-submit]')?.setAttribute('disabled','');return}T.setAmount(state.tender,String(input.dataset.tenderAmount||''),converted);const box=mount.querySelector('[data-tender-box]');if(box){const active=document.activeElement;render();const next=mount.querySelector(`[data-tender-amount="${CSS.escape(String(input.dataset.tenderAmount||''))}"]`);if(next){next.focus({preventScroll:true});next.setSelectionRange?.(next.value.length,next.value.length)}}});
mount.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();return}if(e.key==='Tab'&&state.open){const dialog=mount.querySelector('.sui-settlement-dialog'),nodes=settlementFocusables();if(!dialog)return;if(!nodes.length){e.preventDefault();dialog.focus({preventScroll:true});return}const first=nodes[0],last=nodes[nodes.length-1],active=document.activeElement;if(e.shiftKey&&(active===first||!dialog.contains(active))){e.preventDefault();last.focus({preventScroll:true});return}if(!e.shiftKey&&(active===last||!dialog.contains(active))){e.preventDefault();first.focus({preventScroll:true});return}}const subscriberInput=e.target.matches?.('[data-subscriber-search]'),subscriberResult=e.target.closest?.('[data-subscriber-result]');if(subscriberInput&&e.key==='Enter'){e.preventDefault();searchSubscriber();return}if(subscriberInput&&e.key==='ArrowDown'){const first=mount.querySelector('[data-subscriber-result]');if(first){e.preventDefault();first.focus()}return}if(subscriberResult&&(e.key==='ArrowDown'||e.key==='ArrowUp'||e.key==='Home'||e.key==='End')){const rows=[...mount.querySelectorAll('[data-subscriber-result]')],i=rows.indexOf(subscriberResult);if(i>=0&&rows.length){e.preventDefault();const n=e.key==='Home'?0:e.key==='End'?rows.length-1:e.key==='ArrowDown'?(i+1)%rows.length:(i-1+rows.length)%rows.length;rows[n].focus()}return}const reservationInput=e.target.matches?.('[data-reservation-search]'),reservationResult=e.target.closest?.('[data-reservation-result]');if(reservationInput&&e.key==='Enter'){e.preventDefault();searchAccommodation();return}if(reservationInput&&e.key==='ArrowDown'){const first=mount.querySelector('[data-reservation-result]');if(first){e.preventDefault();first.focus()}return}if(reservationResult&&(e.key==='ArrowDown'||e.key==='ArrowUp'||e.key==='Home'||e.key==='End')){const rows=[...mount.querySelectorAll('[data-reservation-result]')],i=rows.indexOf(reservationResult);if(i>=0&&rows.length){e.preventDefault();const n=e.key==='Home'?0:e.key==='End'?rows.length-1:e.key==='ArrowDown'?(i+1)%rows.length:(i-1+rows.length)%rows.length;rows[n].focus()}return}});
window.SoknaHallSettlement={open,close,submit,openLate:(account,options={})=>{if(!open(account,options))return false;void showLate();return true}};
// Recover a tab-local pending operation even if its table disappeared after a successful server commit.
try{const hold=readFinancialHold();if(hold)open({session:{id:Number(hold.session_id),table_name:'حساب نیازمند پیگیری'},remaining_total:0,signature:''})}catch(e){window.SoknaUI?.toast?.(e.message,{tone:'error'})}
})();