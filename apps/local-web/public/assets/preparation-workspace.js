(()=>{'use strict';
const root=document.querySelector('[data-preparation-workspace]');if(!root)return;
const appPath=v=>(window.SoknaURL?.path?window.SoknaURL.path(v):v),api=root.dataset.api||appPath('/waiter/api.php'),csrf=root.dataset.csrf||'',status=root.querySelector('[data-status]'),summary=root.querySelector('[data-preparation-summary]');
const L=window.SoknaLocale||{time:v=>String(v??''),integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),localWallAgeMinutes:()=>null};
const make=(tag,value,cls)=>{const el=document.createElement(tag);if(value!==undefined)el.textContent=value;if(cls)el.className=cls;return el};
const toast=(m,tone='success',title='')=>window.SoknaUI?.toast?.(m,{tone,title});
const setStatus=(m,t='neutral')=>{const text=String(m||'');status.textContent=text;status.hidden=!text;status.className='sc-alert'+(t==='danger'?' sc-alert--danger':'')};
const actionError=(err,title='عملیات انجام نشد')=>{setStatus('');toast(err?.message||'عملیات انجام نشد.','danger',title)};
const ageMinutes=value=>{const n=typeof L.localWallAgeMinutes==='function'?L.localWallAgeMinutes(value):null;return Number.isFinite(n)?Number(n):null};
const ageCopy=value=>{const mins=ageMinutes(value);if(mins===null)return '';if(mins<1)return 'همین حالا';if(mins<60)return `${L.integer(mins)} دقیقه انتظار`;const h=Math.floor(mins/60),m=mins%60;return `${L.integer(h)} ساعت${m?` و ${L.integer(m)} دقیقه`:''} انتظار`};
async function request(opts={}){const r=await fetch(api,{credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json'},...opts});let d={};try{d=await r.json()}catch{}if(!r.ok||d.success===false)throw new Error(d.message||'دریافت صف انجام نشد.');return d}
async function claim(orderId,area,button){button.disabled=true;try{await request({method:'POST',body:JSON.stringify({action:'claim',order_id:orderId,area,csrf_token:csrf})});toast('بخش به نام شما ثبت شد.');await load()}catch(e){actionError(e)}finally{button.disabled=false}}
async function acknowledge(adjustmentId,button){button.disabled=true;try{await request({method:'POST',body:JSON.stringify({action:'ack_adjustment',adjustment_id:adjustmentId,csrf_token:csrf})});toast('اصلاح سفارش تأیید شد.');await load()}catch(e){actionError(e,'تأیید اصلاح انجام نشد')}finally{button.disabled=false}}
function serviceLabel(v){return String(v||'dine_in')==='takeaway'?'بیرون‌بر':'داخل کافه'}
function adjustmentCopy(a){const delta=Number(a.delta_quantity||0),parts=[];if(delta!==0)parts.push(`${L.integer(a.from_quantity||0)} ← ${L.integer(a.to_quantity||0)}${delta>0?` · ${L.integer(delta)} عدد اضافه`:` · ${L.integer(Math.abs(delta))} عدد کم`}`);const fd=a.from_fulfillment_quantities||null,td=a.to_fulfillment_quantities||null,hasDistribution=fd&&td&&(Number(fd.dine_in||0)!==Number(td.dine_in||0)||Number(fd.takeaway||0)!==Number(td.takeaway||0));if(hasDistribution)parts.push(`داخل کافه ${L.integer(fd.dine_in||0)} ← ${L.integer(td.dine_in||0)} · بیرون‌بر ${L.integer(fd.takeaway||0)} ← ${L.integer(td.takeaway||0)}`);else if(a.from_fulfillment_mode&&a.to_fulfillment_mode&&a.from_fulfillment_mode!==a.to_fulfillment_mode)parts.push(`${serviceLabel(a.from_fulfillment_mode)} ← ${serviceLabel(a.to_fulfillment_mode)}`);if(String(a.from_item_note||'')!==String(a.to_item_note||''))parts.push('یادداشت آماده‌سازی تغییر کرد');return `${a.item_name}: ${parts.join(' · ')||'اصلاح سفارش'}`}
function adjustmentHint(a){if(a.inventory_effect==='preserve_consumption_after_claim')return 'کاهش بعد از دریافت سفارش ثبت شده؛ مصرف قبلی موجودی خودکار برنگشته است.';if(Number(a.delta_quantity||0)>0)return 'مقدار اضافه را آماده کن و سپس این اصلاح را تأیید کن.';if(a.adjustment_kind==='fulfillment'||a.adjustment_kind==='note'||a.adjustment_kind==='combined')return 'تغییر آماده‌سازی را بررسی کن و سپس اصلاح را تأیید کن.';return 'اصلاح تعداد را بررسی و تأیید کن.'}
const areaNeedsAction=area=>Boolean((area.adjustments||[]).length)||!Boolean(area.claim&&area.claim.current);
const orderNeedsAction=order=>(order.areas||[]).some(areaNeedsAction);
function renderSummary(orders){
  if(!summary)return;
  const active=orders.filter(orderNeedsAction).length,handled=orders.length-active,adjustments=orders.reduce((n,o)=>n+(o.areas||[]).reduce((a,x)=>a+(x.adjustments||[]).length,0),0);
  summary.replaceChildren();
  const label=make('div',undefined,'sc-preparation-summary__copy');label.append(make('strong','صف امروز'),make('small',`${L.integer(orders.length)} سفارش قابل مشاهده`));summary.append(label);
  const metrics=make('div',undefined,'sc-preparation-summary__metrics');
  const chip=(text,value,cls='')=>{const el=make('span',undefined,'sc-preparation-chip'+(cls?' '+cls:''));el.append(make('b',L.integer(value)),make('span',text));return el};
  metrics.append(chip('نیازمند اقدام',active,active?'is-attention':''),chip('دریافت‌شده',handled,'is-done'));
  if(adjustments)metrics.append(chip('اصلاح منتظر',adjustments,'is-adjustment'));
  summary.append(metrics);
}
function renderOrder(order,monitor){
  const card=document.createElement('article');card.className='sc-preparation-order'+(orderNeedsAction(order)?' is-attention':' is-handled');card.dataset.preparationState=orderNeedsAction(order)?'attention':'handled';
  const head=make('header',undefined,'sc-preparation-order__head'),copy=make('div',undefined,'sc-preparation-order__copy');
  copy.append(make('h3',`${L.humanDigits?L.humanDigits(order.table_name):order.table_name} · سفارش ${L.integer(order.order_number)}`));
  const meta=make('div',undefined,'sc-preparation-order__meta');meta.append(make('span',L.time(order.created_at)));const age=ageCopy(order.created_at);if(age)meta.append(make('span',age,'sc-preparation-age'));copy.append(meta);head.append(copy);
  const state=make('span',orderNeedsAction(order)?'نیازمند اقدام':'دریافت‌شده','sc-badge '+(orderNeedsAction(order)?'sc-badge--warning':'sc-badge--success'));head.append(state);card.append(head);
  const areas=make('div',undefined,'sc-preparation-order__areas');
  for(const area of order.areas||[]){
    const adjustments=Array.isArray(area.adjustments)?area.adjustments:[],box=make('section',undefined,'sc-preparation-area'+(areaNeedsAction(area)?' is-attention':' is-handled')),ah=make('div',undefined,'sc-preparation-area__head');
    ah.append(make('strong',area.label));
    const current=Boolean(area.claim&&area.claim.current),claimState=adjustments.length?`${L.integer(adjustments.length)} اصلاح منتظر تأیید`:(current?(area.claim.claimed_by?`گرفته‌شده توسط ${area.claim.claimed_by}`:'دریافت‌شده'):'نیازمند دریافت');
    ah.append(make('span',claimState,adjustments.length?'sc-badge sc-badge--warning':(current?'sc-badge sc-badge--success':'sc-badge sc-badge--warning')));box.append(ah);
    for(const a of adjustments){const note=make('div',undefined,'sc-preparation-adjustment');note.append(make('strong',adjustmentCopy(a)));note.append(make('small',adjustmentHint(a)));if(area.actionable&&!monitor){const b=make('button','اصلاح را دیدم','sc-button sc-button--secondary');b.type='button';b.addEventListener('click',()=>acknowledge(Number(a.id),b));note.append(b)}box.append(note)}
    const items=make('div',undefined,'sc-preparation-items');
    const columns=make('div',undefined,'sc-preparation-items__head');columns.append(make('span','آیتم'),make('span','تعداد'),make('span','توضیح'));items.append(columns);
    for(const item of area.items||[]){
      const row=make('div',undefined,'sc-preparation-item'),name=make('strong',item.item_name||'—'),qty=make('b',L.integer(item.quantity||0),'sc-preparation-item__qty'),note=make('small',item.item_note||'—','sc-preparation-item__note');
      row.append(name,qty,note);items.append(row);
    }
    box.append(items);
    if(area.actionable&&!monitor&&!adjustments.length&&!current){const b=make('button','گرفتم','sc-button');b.type='button';b.addEventListener('click',()=>claim(order.id,area.area,b));box.append(b)}areas.append(box)
  }
  card.append(areas);return card;
}
function groupBlock(title,copy,orders,monitor,kind){
  const section=make('section',undefined,'sc-preparation-group sc-preparation-group--'+kind),head=make('div',undefined,'sc-preparation-group__head');head.append(make('div',undefined));head.firstElementChild.append(make('h2',title),make('p',copy));head.append(make('span',L.integer(orders.length),'sc-preparation-group__count'));section.append(head);
  const list=make('div',undefined,'sc-preparation-group__list');orders.forEach(order=>list.append(renderOrder(order,monitor)));section.append(list);return section;
}
function render(data){
 const wrap=root.querySelector('[data-preparation-list]');wrap.replaceChildren();const orders=Array.isArray(data.orders)?data.orders:[],monitor=data.permissions?.monitor_only===true;renderSummary(orders);
 if(!orders.length){wrap.append(make('div','سفارشی برای آماده‌سازی وجود ندارد.','sc-empty sc-preparation-empty'));setStatus(monitor?'حالت پایش: مشاهده مجاز است، اقدام عملیاتی برای این حساب فعال نیست.':'');return}
 const active=orders.filter(orderNeedsAction),handled=orders.filter(o=>!orderNeedsAction(o));
 if(active.length)wrap.append(groupBlock('نیازمند اقدام','بخش‌هایی که هنوز دریافت نشده‌اند یا اصلاح تازه دارند.',active,monitor,'active'));
 if(handled.length)wrap.append(groupBlock('دریافت‌شده امروز','سفارش‌هایی که همهٔ بخش‌های قابل مشاهدهٔ آن‌ها دریافت شده‌اند.',handled,monitor,'handled'));
 if(monitor)setStatus('حالت پایش: مشاهده مجاز است، اقدام عملیاتی برای این حساب فعال نیست.');else setStatus('')
}
async function load(){root.setAttribute('aria-busy','true');try{render(await request())}catch(e){setStatus(e.message,'danger')}finally{root.removeAttribute('aria-busy')}}
root.querySelector('[data-refresh]').addEventListener('click',()=>load());load();const timer=setInterval(()=>{if(!document.hidden)load()},8000);window.addEventListener('beforeunload',()=>clearInterval(timer));
})();
