/* SOKNA R94 QA candidate: read-only recovery for stale server-rendered Public dashboard.
   Does not modify orders, metrics, accounting data or mutation readiness. */
(()=>{'use strict';
  const isStale=()=>Boolean(document.querySelector('[data-projection-stale-banner]'));
  if(!isStale())return;
  let timer=null,inFlight=false,attempt=0,stopped=false;
  const schedule=(delay)=>{if(stopped)return;if(timer!==null)clearTimeout(timer);timer=setTimeout(check,delay)};
  async function check(){
    timer=null;
    if(stopped||inFlight||!isStale())return;
    if(document.hidden){schedule(10000);return;}
    inFlight=true;
    const controller=new AbortController();
    const abortTimer=setTimeout(()=>controller.abort(),8000);
    try{
      const response=await fetch(location.href,{
        method:'GET',credentials:'same-origin',cache:'no-store',redirect:'follow',
        headers:{Accept:'text/html'},signal:controller.signal
      });
      if(response.ok&&(response.headers.get('content-type')||'').includes('text/html')){
        const html=await response.text();
        const page=new DOMParser().parseFromString(html,'text/html');
        if(page.querySelector('.sc-dashboard')&&!page.querySelector('[data-projection-stale-banner]')){
          stopped=true;
          window.location.reload();
          return;
        }
      }
    }catch(_){/* Keep visible stale state; never present missing data as zero. */}
    finally{
      clearTimeout(abortTimer);inFlight=false;
      if(!stopped){attempt++;schedule(Math.min(60000,5000*Math.pow(2,Math.min(attempt,4))))}
    }
  }
  document.addEventListener('visibilitychange',()=>{if(!document.hidden&&isStale())schedule(250)});
  window.addEventListener('online',()=>{if(isStale())schedule(500)});
  schedule(3500);
})();
