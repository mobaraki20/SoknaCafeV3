(()=>{'use strict';
const card=document.querySelector('[data-open-accounts-kpi][data-url]');if(!card)return;
const amount=card.querySelector('strong'),hint=card.querySelector('div>span:not(.sc-dashboard-kpi__delta)');if(!amount)return;
const url=card.getAttribute('data-url');if(!url)return;
let busy=false,request=0;
const fa=(n)=>new Intl.NumberFormat('fa-IR',{maximumFractionDigits:0}).format(n);
const unknown=()=>{amount.textContent='—';if(hint)hint.textContent='مبلغ فعلاً نامشخص است';};
async function read(){
 if(busy||document.hidden)return;busy=true;const current=++request;
 try{
  const resp=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
  if(!resp.ok)throw new Error('dashboard_open_accounts_status');
  const result=await resp.json();const n=result?.amount_toman,accounts=result?.account_count;
  if(result?.ok!==true||!Number.isSafeInteger(n)||n<0||!Number.isSafeInteger(accounts)||accounts<0)throw new Error('dashboard_unreliable_amount');
  if(current!==request)return;
  // Dashboard reports operational sales only in toman, regardless of checkout setting.
  amount.textContent=fa(n)+' تومان';
  if(hint)hint.textContent='مانده تسویه‌نشده '+fa(accounts)+' حساب';
 }catch{if(current===request)unknown();}
 finally{busy=false;}
}
void read();const timer=setInterval(read,20000);
window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
})();
