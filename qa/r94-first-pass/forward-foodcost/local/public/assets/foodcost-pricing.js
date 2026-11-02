(()=>{'use strict';
const root=document.querySelector('[data-reports] [data-foodcost-pricing]');if(!root)return;
const reports=document.querySelector('[data-reports]');
const api=String(reports?.dataset.api||'');const $=s=>root.querySelector(s);
const L=window.SoknaLocale||{integer:n=>new Intl.NumberFormat('fa-IR').format(n),money:n=>`${new Intl.NumberFormat('fa-IR').format(n)} تومان`};
let rows=[],loading=false;
const labels={known:'معتبر',estimated:'برآوردی',partial:'هزینه/رسپی ناقص',missing_recipe:'فاقد رسپی',bundle_needs_review:'ترکیب نیازمند بررسی'};
function cell(tr,label,value){const td=document.createElement('td');td.dataset.label=label;td.textContent=String(value??'—');tr.append(td);return td}
function status(msg){const el=$('[data-pricing-status]');if(!el)return;el.hidden=!msg;el.textContent=msg||''}
function filtered(){const q=String($('[data-pricing-search]')?.value||'').trim().toLocaleLowerCase('fa-IR'),filter=$('[data-pricing-filter]')?.value||'all',sort=$('[data-pricing-sort]')?.value||'ratio';
 const result=rows.filter(r=>{const text=`${r.name} ${r.sku} ${r.variant_label} ${r.category_name}`.toLocaleLowerCase('fa-IR');if(q&&!text.includes(q))return false;
 if(filter==='known'&&r.cost_status!=='known')return false;if(filter==='estimated'&&r.cost_status!=='estimated')return false;if(filter==='needs_review'&&['known','estimated'].includes(r.cost_status))return false;return true});
 result.sort((a,b)=>sort==='name'?String(a.name).localeCompare(String(b.name),'fa'):sort==='price'?Number(b.price||0)-Number(a.price||0):((b.food_cost_percent===null?-1:Number(b.food_cost_percent))-(a.food_cost_percent===null?-1:Number(a.food_cost_percent)))||String(a.name).localeCompare(String(b.name),'fa'));
 return result;
}
function money(n){return n===null||n===undefined?'—':L.money(n)}
function render(){const body=$('[data-pricing-rows]');if(!body)return;body.replaceChildren();const list=filtered();$('[data-pricing-count]').textContent=`${L.integer(list.length)} محصول از ${L.integer(rows.length)} محصول فعال`;
 if(!list.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=7;td.textContent='محصولی با این فیلتر پیدا نشد.';tr.append(td);body.append(tr);return}
 for(const item of list){const tr=document.createElement('tr');cell(tr,'محصول / سایز',[item.name,item.variant_label,item.sku].filter(Boolean).join(' · '));cell(tr,'قیمت فروش',money(item.price));cell(tr,'هزینه مواد',money(item.ingredient_cost));cell(tr,'فودکاست',item.food_cost_percent===null?'—':`${new Intl.NumberFormat('fa-IR',{maximumFractionDigits:1}).format(Number(item.food_cost_percent))}٪`);cell(tr,'فاصله',money(item.price_minus_ingredients));cell(tr,'کیفیت داده',labels[item.cost_status]||'نامشخص');cell(tr,'تاریخ مبنا',String(item.oldest_cost_updated_at||'—').slice(0,10));body.append(tr)}
}
async function refresh(){if(loading)return;loading=true;status('');try{const u=new URL(api,location.href);u.searchParams.set('action','pricing');const res=await fetch(u.toString(),{method:'GET',credentials:'same-origin',cache:'no-store'});if(!res.ok)throw Error(res.status===403?'برای گزارش قیمت‌گذاری دسترسی کافی ندارید.':'خطا در دریافت قیمت‌گذاری');const j=await res.json();if(j.success!==true||!Array.isArray(j.pricing?.items))throw Error('داده قیمت‌گذاری نامعتبر است.');rows=j.pricing.items;render();const btn=$('[data-pricing-export]');if(btn)btn.disabled=false}catch(e){status(e.message||'دریافت گزارش فعلاً ممکن نیست.');rows=[];render();const btn=$('[data-pricing-export]');if(btn)btn.disabled=true}finally{loading=false}}
function exportCsv(){const header=['کد','محصول','سایز','دسته','قیمت فروش','بهای مواد','درصد فودکاست','فاصله قیمت و مواد','وضعیت','مبنای هزینه'];const list=filtered();const f=v=>{let s=String(v??'');if(/^[=+\-@\t\r]/.test(s))s="'"+s;return '"'+s.replaceAll('"','""')+'"'};
 const lines=[header,...list.map(r=>[r.sku,r.name,r.variant_label,r.category_name,r.price,r.ingredient_cost,r.food_cost_percent,r.price_minus_ingredients,labels[r.cost_status]||r.cost_status,r.oldest_cost_updated_at])];
 const blob=new Blob(['\uFEFF',lines.map(row=>row.map(f).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'});const href=URL.createObjectURL(blob),a=document.createElement('a');a.href=href;a.download='sokna-current-catalog-food-cost.csv';a.hidden=true;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(href),3000);
}
for(const selector of ['[data-pricing-search]','[data-pricing-filter]','[data-pricing-sort]'])$(selector)?.addEventListener('input',render);
$('[data-pricing-export]')?.addEventListener('click',exportCsv);
$('[data-pricing-refresh]')?.addEventListener('click',refresh);
void refresh();
})();
