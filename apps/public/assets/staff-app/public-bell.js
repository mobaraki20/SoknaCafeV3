(()=>{'use strict';
const root=document.querySelector('[data-public-bell]');if(!root)return;
const button=root.querySelector('[data-public-bell-toggle]'),panel=root.querySelector('[data-public-bell-panel]'),
 close=root.querySelector('[data-public-bell-close]'),status=root.querySelector('[data-public-bell-status]'),list=root.querySelector('[data-public-bell-items]');
const history=()=>panel.querySelector('.sc-shell__bell-all');
const digits=v=>String(v).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
let serial=0;
const showMessage=(msg,kind='info')=>{status.textContent=msg;status.dataset.kind=kind;status.hidden=false;};
const render=payload=>{
 list.replaceChildren();const snap=payload?.snapshot||{};
 if(!snap.today_available){showMessage(snap.today_message||'اطلاعات امروز هنوز معتبر نیست.','warning');return;}
 const rows=Array.isArray(snap.today_items)?snap.today_items:[];
 if(!rows.length){showMessage('برای روز کاری جاری اعلان تازه‌ای ثبت نشده است.');return;}
 status.hidden=true;
 const meta=document.createElement('p');meta.className='sc-shell__bell-count';meta.textContent=`${digits(rows.length)} اعلان در روز کاری جاری`;list.append(meta);
 rows.forEach(row=>{
   const item=document.createElement('article');item.className='sc-shell__bell-item';
   if(!row.read_at)item.classList.add('is-unread');
   const title=document.createElement('strong');title.textContent=String(row.title||'اعلان');
   const desc=document.createElement('p');desc.textContent=String(row.body||'');
   item.append(title,desc);list.append(item);
 });
};
async function load(){const s=++serial;showMessage('در حال دریافت اعلان‌های امروز…');list.replaceChildren();
 try{const response=await fetch(root.dataset.api,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const j=await response.json();if(s!==serial)return;
 if(!response.ok||j.success===false)throw new Error(j.message||'دریافت اعلان‌ها انجام نشد.');
 render(j);
 }catch(e){if(s===serial)showMessage(e.message||'ارتباط با سامانه برقرار نیست.','warning');}}
function open(){panel.hidden=false;button.setAttribute('aria-expanded','true');load();}
function hide(){panel.hidden=true;button.setAttribute('aria-expanded','false');serial++;button.focus();}
button.addEventListener('click',()=>panel.hidden?open():hide());close.addEventListener('click',hide);
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){e.preventDefault();hide()}});
document.addEventListener('pointerdown',e=>{if(!panel.hidden&&!root.contains(e.target)){panel.hidden=true;button.setAttribute('aria-expanded','false');serial++}});
})();
