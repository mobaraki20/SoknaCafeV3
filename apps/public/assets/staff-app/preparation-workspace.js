(()=>{'use strict';
const root=document.querySelector('[data-preparation-workspace]');if(!root)return;
const runtime=window.SoknaStaffRuntime||null,adapter=runtime?.adapter?.('preparation')||null;
const appPath=v=>(runtime?.appPath?runtime.appPath(v):(window.SoknaURL?.path?window.SoknaURL.path(v):(window.SoknaAssets?.path?window.SoknaAssets.path(v):v)));
const api=root.dataset.api||appPath('/waiter/api.php');
const csrf=root.dataset.csrf||'',status=root.querySelector('[data-status]'),summary=root.querySelector('[data-preparation-summary]');
const L=window.SoknaLocale||{time:v=>String(v??''),integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),humanDigits:v=>String(v??''),localWallAgeMinutes:()=>null};
const make=(tag,value,cls)=>{const el=document.createElement(tag);if(value!==undefined)el.textContent=value;if(cls)el.className=cls;return el};
const toast=(m,tone='success',title='')=>window.SoknaUI?.toast?.(m,{tone,title});
const setStatus=(m,t='neutral')=>{if(!status)return;const text=String(m||'');status.textContent=text;status.hidden=!text;status.className='sc-alert'+(t==='danger'?' sc-alert--danger':t==='warning'?' sc-alert--warning':'')};
const actionError=(err,title='عملیات انجام نشد')=>{setStatus('');toast(err?.message||'عملیات انجام نشد.','danger',title)};
const ageMinutes=value=>{const n=typeof L.localWallAgeMinutes==='function'?L.localWallAgeMinutes(value):null;if(Number.isFinite(n))return Number(n);const raw=String(value||'').trim();if(!raw)return null;const d=new Date(raw.includes('T')?raw:raw.replace(' ','T')+'Z');if(Number.isNaN(d.getTime()))return null;return Math.max(0,Math.floor((Date.now()-d.getTime())/60000))};
const ageCopy=value=>{const mins=ageMinutes(value);if(mins===null)return '';if(mins<1)return 'همین حالا';if(mins<60)return `${L.integer(mins)} دقیقه انتظار`;const h=Math.floor(mins/60),m=mins%60;return `${L.integer(h)} ساعت${m?` و ${L.integer(m)} دقیقه`:''} انتظار`};
let stale=false,lastSyncAt='',lastData={orders:[],permissions:{}},preparationTab='pending';
const canMutate=()=>!adapter?.canMutate||adapter.canMutate()!==false;
async function fetchJson(url,opt={}){if(runtime?.json)return runtime.json(url,opt);const r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json',...(opt.headers||{})},...opt});let d={};try{d=await r.json()}catch{}if(!r.ok||d.success===false||d.ok===false)throw new Error(d.message||'دریافت صف انجام نشد.');return d}
async function loadData(){if(adapter?.load){const data=await adapter.load({root});stale=adapter.isStale?.()===true;lastSyncAt=String(adapter.lastSync?.()||'');root.dataset.stale=stale?'1':'0';const meta=adapter.meta?.()||{stale,last_sync_at:lastSyncAt,connectivity:null};runtime?.emitMeta?.('preparation',meta);return data||{orders:[],permissions:{}}}stale=false;root.dataset.stale='0';return fetchJson(api)}
async function mutate(payload){if(adapter?.mutate)return adapter.mutate(payload,{root});return fetchJson(api,{method:'POST',body:JSON.stringify({...payload,csrf_token:csrf})})}
async function claim(orderId,area,button){button.disabled=true;try{await mutate({action:adapter?.normalizeAction?.('claim')||'claim',order_id:orderId,area});await load()}catch(e){actionError(e)}finally{button.disabled=!canMutate()}}
async function acknowledge(adjustmentId,button){button.disabled=true;try{await mutate({action:'ack_adjustment',adjustment_id:adjustmentId});await load()}catch(e){actionError(e,'تأیید اصلاح انجام نشد')}finally{button.disabled=!canMutate()}}
function serviceLabel(v){return String(v||'dine_in')==='takeaway'?'بیرون‌بر':'داخل کافه'}
function adjustmentCopy(a){const delta=Number(a.delta_quantity||0),parts=[];if(delta!==0)parts.push(`${L.integer(a.from_quantity||0)} ← ${L.integer(a.to_quantity||0)}${delta>0?` · ${L.integer(delta)} عدد اضافه`:` · ${L.integer(Math.abs(delta))} عدد کم`}`);const fd=a.from_fulfillment_quantities||null,td=a.to_fulfillment_quantities||null,hasDistribution=fd&&td&&(Number(fd.dine_in||0)!==Number(td.dine_in||0)||Number(fd.takeaway||0)!==Number(td.takeaway||0));if(hasDistribution)parts.push(`داخل کافه ${L.integer(fd.dine_in||0)} ← ${L.integer(td.dine_in||0)} · بیرون‌بر ${L.integer(fd.takeaway||0)} ← ${L.integer(td.takeaway||0)}`);else if(a.from_fulfillment_mode&&a.to_fulfillment_mode&&a.from_fulfillment_mode!==a.to_fulfillment_mode)parts.push(`${serviceLabel(a.from_fulfillment_mode)} ← ${serviceLabel(a.to_fulfillment_mode)}`);if(String(a.from_item_note||'')!==String(a.to_item_note||''))parts.push('یادداشت آماده‌سازی تغییر کرد');return `${a.item_name}: ${parts.join(' · ')||'اصلاح سفارش'}`}
function adjustmentHint(a){if(a.inventory_effect==='preserve_consumption_after_claim')return 'کاهش بعد از دریافت سفارش ثبت شده؛ مصرف قبلی موجودی خودکار برنگشته است.';if(Number(a.delta_quantity||0)>0)return 'مقدار اضافه را آماده کن و سپس این اصلاح را تأیید کن.';if(a.adjustment_kind==='fulfillment'||a.adjustment_kind==='note'||a.adjustment_kind==='combined')return 'تغییر آماده‌سازی را بررسی کن و سپس اصلاح را تأیید کن.';return 'اصلاح تعداد را بررسی و تأیید کن.'}
const areaNeedsAction=area=>Boolean((area.adjustments||[]).length)||!Boolean(area.claim&&area.claim.current);
const orderNeedsAction=order=>(order.areas||[]).some(areaNeedsAction);
// The two state tabs own the only order counters; the previous summary duplicated them.
function renderSummary(_orders){if(!summary)return;summary.replaceChildren();summary.hidden=true}
function renderOrder(order,monitor){const card=document.createElement('article');card.className='sc-preparation-order'+(orderNeedsAction(order)?' is-attention':' is-handled');card.dataset.preparationState=orderNeedsAction(order)?'attention':'handled';const head=make('header',undefined,'sc-preparation-order__head'),copy=make('div',undefined,'sc-preparation-order__copy');copy.append(make('h3',`${L.humanDigits?L.humanDigits(order.table_name):order.table_name} · سفارش ${L.integer(order.order_number)}`));const meta=make('div',undefined,'sc-preparation-order__meta');meta.append(make('span',L.time?L.time(order.created_at):String(order.created_at||'')));const age=ageCopy(order.created_at);if(age)meta.append(make('span',age,'sc-preparation-age'));copy.append(meta);head.append(copy);card.append(head);const areas=make('div',undefined,'sc-preparation-order__areas');for(const area of order.areas||[]){const adjustments=Array.isArray(area.adjustments)?area.adjustments:[],box=make('section',undefined,'sc-preparation-area'+(areaNeedsAction(area)?' is-attention':' is-handled')),ah=make('div',undefined,'sc-preparation-area__head');ah.append(make('strong',area.label||({kitchen:'آشپزخانه',bar:'بار'}[area.area]||area.area||'بخش')));const current=Boolean(area.claim&&area.claim.current),claimState=adjustments.length?`${L.integer(adjustments.length)} اصلاح منتظر تأیید`:(current?(area.claim.claimed_by?`گرفته‌شده توسط ${area.claim.claimed_by}`:'دریافت‌شده'):'نیازمند دریافت');ah.append(make('span',claimState,adjustments.length?'sc-badge sc-badge--warning':(current?'sc-badge sc-badge--success':'sc-badge sc-badge--warning')));box.append(ah);for(const a of adjustments){const note=make('div',undefined,'sc-preparation-adjustment');note.append(make('strong',adjustmentCopy(a)));note.append(make('small',adjustmentHint(a)));if(area.actionable&&!monitor){const b=make('button','اصلاح را دیدم','sc-button sc-button--secondary');b.type='button';b.dataset.preparationAction='1';b.disabled=!canMutate();b.addEventListener('click',()=>acknowledge(Number(a.id),b));note.append(b)}box.append(note)}const items=make('div',undefined,'sc-preparation-items');const columns=make('div',undefined,'sc-preparation-items__head');columns.append(make('span','آیتم'),make('span','تعداد'),make('span','توضیح'));items.append(columns);for(const item of area.items||[]){const row=make('div',undefined,'sc-preparation-item'),name=make('strong',item.item_name||'—'),qty=make('b',L.integer(item.quantity||0),'sc-preparation-item__qty'),note=make('small',String(item.item_note||'').trim(),'sc-preparation-item__note');row.append(name,qty);if(note.textContent)row.append(note);items.append(row)}box.append(items);if(area.actionable&&!monitor&&!adjustments.length&&!current){const b=make('button','گرفتم','sc-button');b.type='button';b.dataset.preparationAction='1';b.disabled=!canMutate();b.addEventListener('click',()=>claim(order.id,area.area,b));box.append(b)}areas.append(box)}card.append(areas);return card}
function groupBlock(_title,_copy,orders,monitor,kind){
 const section=make('section',undefined,'sc-preparation-group sc-preparation-group--'+kind);
 const list=make('div',undefined,'sc-preparation-group__list');
 for(const order of orders)list.append(renderOrder(order,monitor));
 section.append(list);return section;
}
function render(data){
 const wrap=root.querySelector('[data-preparation-list]');if(!wrap)return;
 const activeTab=root.contains(document.activeElement)?document.activeElement?.closest?.('[role="tab"]')?.dataset.preparationTab:null;
 const scroll=wrap.scrollTop;wrap.replaceChildren();
 const orders=Array.isArray(data.orders)?data.orders:[],monitor=data.permissions?.monitor_only===true;
 renderSummary(orders);
 const chrono=(a,b)=>String(a.created_at||'').localeCompare(String(b.created_at||''))||Number(a.id||0)-Number(b.id||0);
 const pending=orders.filter(orderNeedsAction).sort(chrono),received=orders.filter(o=>!orderNeedsAction(o)).sort(chrono);
 const tabsBar=make('div',undefined,'sc-toolbar sc-local-nav sc-preparation-nav');
 const tabs=make('div',undefined,'sc-tabs sc-preparation-tabs');tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','وضعیت سفارش‌های آماده‌سازی');
 for(const [key,label,rows] of [['pending','در انتظار',pending],['received','دریافت‌شده',received]]){
   const button=make('button',undefined,'sc-tab sc-preparation-tab'+(preparationTab===key?' is-current':''));button.type='button';button.dataset.preparationTab=key;button.id='sc-preparation-tab-'+key;button.setAttribute('role','tab');button.setAttribute('aria-selected',preparationTab===key?'true':'false');button.setAttribute('tabindex',preparationTab===key?'0':'-1');button.setAttribute('aria-controls','sc-preparation-active-panel');button.append(make('span',label),make('b',L.integer(rows.length)));
   const pick=next=>{preparationTab=next;render(lastData);root.querySelector(`[data-preparation-tab="${next}"]`)?.focus({preventScroll:true})};
   button.addEventListener('click',()=>pick(key));
   button.addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();pick(e.key==='Home'?'pending':e.key==='End'?'received':preparationTab==='pending'?'received':'pending')});
   tabs.append(button);
 }
 tabsBar.append(tabs);wrap.append(tabsBar);
 const visible=preparationTab==='received'?received:pending;
 const panel=make('div',undefined,'sc-preparation-tab-panel');panel.id='sc-preparation-active-panel';panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby','sc-preparation-tab-'+preparationTab);
 if(visible.length)panel.append(groupBlock('', '',visible,monitor,preparationTab==='pending'?'active':'handled'));
 else panel.append(make('div',preparationTab==='pending'?'سفارش منتظر دریافت وجود ندارد.':'هنوز سفارشی دریافت نشده است.','sc-empty sc-preparation-empty'));
 wrap.append(panel);wrap.scrollTop=scroll;
 if(activeTab)root.querySelector(`[data-preparation-tab="${activeTab}"]`)?.focus({preventScroll:true});
 if(stale)setStatus(canMutate()?'اطلاعات نمایش‌داده‌شده ممکن است قدیمی باشد؛ هر اقدام هنگام ثبت دوباره توسط سیستم کافه بررسی می‌شود.':'اطلاعات راه‌دور قدیمی است و مسیر ثبت عملیات هنوز آماده نیست.','warning');
 else if(monitor)setStatus('حالت پایش: مشاهده مجاز است، اقدام عملیاتی برای این حساب فعال نیست.');else setStatus('');
}
let loading=null,loadAgain=false;
async function load(){if(loading){loadAgain=true;return loading}loading=(async()=>{root.setAttribute('aria-busy','true');try{lastData=await loadData();render(lastData)}catch(e){setStatus(e.message,'danger')}finally{root.removeAttribute('aria-busy')}})();try{await loading}finally{loading=null;if(loadAgain){loadAgain=false;void load()}}}
root.querySelector('[data-refresh]')?.addEventListener('click',()=>load());
document.addEventListener('sokna:staff-mutation-readiness',()=>render(lastData));
let projectionRefreshTimer=0;document.addEventListener('sokna:public-projection-changed',()=>{if(adapter?.host!=='public'||document.hidden||root.closest('[hidden]'))return;clearTimeout(projectionRefreshTimer);projectionRefreshTimer=setTimeout(()=>load(),40)});
const apiObject={load,activate:load,mode:()=>String(adapter?.host||document.body?.dataset.staffUiMode||'local'),isStale:()=>stale};window.SoknaStaffPreparation=apiObject;
if(root.dataset.autoLoad!=='0')load();
const timer=setInterval(()=>{const hiddenByContainer=Boolean(root.closest('[hidden]'));if(!document.hidden&&!hiddenByContainer)load()},4000);window.addEventListener('beforeunload',()=>clearInterval(timer));
})();
