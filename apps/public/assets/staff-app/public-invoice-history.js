(()=>{'use strict';
const root=document.querySelector('[data-public-invoices]');if(!root)return;
const find=s=>root.querySelector(s),list=find('[data-invoice-list]'),detail=find('[data-invoice-detail]');
const form=find('[data-invoice-history-form]'),scope=find('[data-invoice-scope]'),error=find('[data-invoice-error]'),more=find('[data-invoice-more]');
const fmt=n=>new Intl.NumberFormat('fa-IR').format(Number(n)||0),money=n=>fmt(n)+' تومان';
const wrap=(tag,cls='',str='')=>{const n=document.createElement(tag);if(cls)n.className=cls;n.textContent=str;return n};
const date=s=>{const d=String(s||'');return d?d.replace(/-/g,'/').replace(/[0-9]/g,x=>'۰۱۲۳۴۵۶۷۸۹'[Number(x)]):'—'};
const fail=m=>{error.hidden=false;error.textContent=m};
const clear=()=>{error.textContent='';error.hidden=true};
let page=1,hasMore=false,seq=0;
const query=(action,other={})=>{const url=new URL(root.dataset.api,location.href);url.searchParams.set('action',action);Object.entries(other).forEach(([k,v])=>{if(v!==undefined&&v!==''&&v!==null)url.searchParams.set(k,String(v))});return url};
async function loadApi(action,args){const response=await fetch(query(action,args),{credentials:'same-origin',headers:{'Accept':'application/json'}});const j=await response.json();if(!response.ok||j.success===false)throw new Error(j.message||'دریافت اطلاعات مالی انجام نشد.');return j;}
const statusLabel=s=>s==='reversal'?'برگشت وجه':'تسویه‌شده';
function renderRow(r){const b=wrap('button','sc-invoice-history__row');b.type='button';b.dataset.invoiceId=String(r.id);const top=wrap('span','sc-invoice-history__head');top.append(wrap('strong','sc-canonical-id','فاکتور '+(r.invoice_number||r.id)),wrap('span','sc-invoice-history__badge',statusLabel(r.status)));b.append(top);const bottom=wrap('span','sc-invoice-history__body');bottom.append(wrap('small','',String(r.table_name||'میز نامشخص')+' · '+date(r.receipt_business_date)),wrap('strong','',money(r.total)));b.append(bottom);b.addEventListener('click',()=>showDetail(Number(r.id)));return b}
async function load(reset=true){const token=++seq;clear();if(reset){page=1;list.replaceChildren();detail.hidden=true}const args=Object.fromEntries(new FormData(form).entries());args.page=page;
 try{const j=await loadApi('invoice_history',args);if(token!==seq)return;const data=j.invoice_history||{},rows=data.rows||[],cov=data.coverage||{};scope.textContent=`پوشش همگام‌شده: ${date(cov.from)} تا ${date(cov.to)}${cov.truncated?' · فقط اسناد جدیدتر منتقل شده‌اند؛ فهرست کامل نیست.':' · این آرشیو فقط محدوده نمایش‌داده‌شده را پوشش می‌دهد.'}`;
 if(!rows.length&&reset)list.append(wrap('div','sc-empty','در اسناد همگام‌شده مطابق این فیلتر موردی پیدا نشد.'));
 rows.forEach(r=>list.append(renderRow(r)));hasMore=data.pagination?.has_more===true;more.hidden=!hasMore;
 }catch(e){if(token===seq){fail(e.message);if(reset)scope.textContent='تاریخچه فعلاً قابل تأیید نیست؛ برای آرشیو کامل از لوکال استفاده کنید.'}}
}
async function showDetail(id){clear();detail.hidden=false;detail.replaceChildren(wrap('p','sc-muted','در حال دریافت سند…'));detail.scrollIntoView({behavior:'smooth',block:'nearest'});
 try{const j=await loadApi('invoice_detail',{id});const r=j.invoice||{};if(Number(r.id)!==id)throw new Error('شناسه سند دریافت‌شده با درخواست مطابقت ندارد.');detail.replaceChildren();const box=wrap('div','sc-finance-document sc-card__body');
 const header=wrap('header','sc-finance-document__head'),head=wrap('div');head.append(wrap('small','sc-muted','سند مالی فقط‌خواندنی'),wrap('h2','sc-canonical-id','فاکتور '+String(r.invoice_number||r.id)),wrap('p','sc-muted',String(r.table_name||'میز نامشخص')));header.append(head,wrap('span','sc-invoice-history__badge',statusLabel(r.status)));box.append(header);
 const context=wrap('div','sc-finance-document__context');[['روز کاری فروش',date(r.sale_business_date)],['روز کاری دریافت',date(r.receipt_business_date)],['زمان دریافت',String(r.settled_at||'—')]].forEach(([k,v])=>{const x=wrap('div');x.append(wrap('span','',k),wrap('strong','',v));context.append(x)});box.append(context);
 if(r.status==='reversal')box.append(wrap('div','sc-finance-document__notice','این سند برگشت مالی است؛ مبلغ آن نباید دوباره به فروش اضافه شود.'));
 const lines=wrap('section','sc-finance-document__lines');lines.append(wrap('h3','','اقلام ثبت‌شده'));if(!r.item_lines_complete)lines.append(wrap('p','sc-muted','جزئیات اقلام این سند کامل نیست؛ جمع مالی از سند اصلی خوانده شده است.'));
 if(!Array.isArray(r.items)||!r.items.length)lines.append(wrap('p','sc-muted','ریز اقلام در مدل همگام‌شده موجود نیست.'));
 for(const item of r.items||[]){const line=wrap('div','sc-finance-document__line'),name=wrap('div');name.append(wrap('strong','',String(item.name||'آیتم')),wrap('small','sc-muted',fmt(item.quantity)+' × '+money(item.unit_price)));line.append(name,wrap('strong','',money(item.line_final)));lines.append(line)}box.append(lines);
 const totals=wrap('dl','sc-finance-document__totals');[['جمع اقلام',r.subtotal],['تخفیف',r.discount],['مالیات',r.tax_amount],['مبلغ نهایی',r.total]].forEach(([k,v],i)=>{const row=wrap('div',i===3?'is-total':'');row.append(wrap('dt','',k),wrap('dd','',money(v)));totals.append(row)});box.append(totals);
 const action=wrap('div','sc-actions'),close=wrap('button','sc-button sc-button--secondary','بستن جزئیات');close.type='button';close.addEventListener('click',()=>{detail.hidden=true;detail.replaceChildren()});action.append(close);box.append(action);detail.append(box);
 }catch(e){detail.replaceChildren(wrap('div','sc-alert sc-alert--warning',e.message+' برای سابقه کامل‌تر، سیستم اصلی کافه را بررسی کنید.'))}}
form.addEventListener('submit',e=>{e.preventDefault();load(true)});
more.addEventListener('click',()=>{if(hasMore){page++;load(false)}});
const invoiceId=new URL(location.href).searchParams.get('invoice_id');if(invoiceId&&/^[1-9][0-9]{0,12}$/.test(invoiceId)){const tab=document.querySelector('a[href="#report-invoices"]');tab?.click();showDetail(Number(invoiceId))}
load(true);
})();
