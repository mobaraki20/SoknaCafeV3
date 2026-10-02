(()=>{
  'use strict';

  const appBase=String(document.body?.dataset?.appBase||'').replace(/\/$/,'');
  const appPath=value=>{
    const raw=String(value??'').trim();
    if(!raw||raw.startsWith('#')||/^(?:https?:)?\/\//i.test(raw)||/^(?:mailto|tel|data):/i.test(raw))return raw;
    if(raw.startsWith(appBase+'/')&&appBase)return raw;
    return appBase+'/'+raw.replace(/^\/+/, '');
  };
  window.SoknaURL={base:appBase,path:appPath};

  const faDigits=value=>String(value??'').replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
  const updateClock=clock=>{
    const dateEl=clock.querySelector('[data-shell-date]'),timeEl=clock.querySelector('[data-shell-time]');
    if(!dateEl||!timeEl)return;
    const timezone=clock.dataset.timezone||'Asia/Tehran',now=new Date();
    try{
      const parts=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{weekday:'long',year:'numeric',month:'long',day:'numeric',timeZone:timezone}).formatToParts(now);
      const map=Object.fromEntries(parts.filter(x=>x.type!=='literal').map(x=>[x.type,x.value]));
      dateEl.textContent=`${map.weekday||''} ${map.day||''} ${map.month||''} ${map.year||''}`.replace(/\s+/g,' ').trim();
      timeEl.textContent=new Intl.DateTimeFormat('fa-IR',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:timezone}).format(now);
    }catch(_){
      dateEl.textContent=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{weekday:'long',year:'numeric',month:'long',day:'numeric'}).format(now);
      timeEl.textContent=faDigits(`${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`);
    }
  };
  const clocks=[...document.querySelectorAll('[data-shell-clock]')];
  const syncClocks=()=>clocks.forEach(updateClock);
  if(clocks.length){syncClocks();window.setInterval(syncClocks,30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)syncClocks()})}

  const nav=document.querySelector('[data-product-nav]');
  const backdrop=document.querySelector('[data-product-nav-backdrop]');
  const toggles=[...document.querySelectorAll('[data-product-nav-toggle]')];
  const moreNav=nav?.querySelector('[data-shell-more-nav]')||null;
  if(!nav||!backdrop||!toggles.length)return;
  const drawerMq=matchMedia('(max-width:1180px)');
  let returnFocus=null;
  const focusables=()=>[...nav.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>!el.hidden&&getComputedStyle(el).display!=='none'&&getComputedStyle(el).visibility!=='hidden');
  const syncA11y=open=>{
    const drawer=drawerMq.matches;
    if(drawer){
      nav.toggleAttribute('inert',!open);
      nav.setAttribute('aria-hidden',open?'false':'true');
      backdrop.hidden=!open;
    }else{
      nav.removeAttribute('inert');nav.removeAttribute('aria-hidden');backdrop.hidden=true;
    }
  };
  const setOpen=(open,{restore=true,moveFocus=true}={})=>{
    open=Boolean(open&&drawerMq.matches);
    if(open&&!nav.classList.contains('is-open'))returnFocus=document.activeElement instanceof HTMLElement?document.activeElement:null;
    nav.classList.toggle('is-open',open);
    backdrop.classList.toggle('is-open',open);
    document.body.classList.toggle('sc-nav-open',open);
    toggles.forEach(button=>button.setAttribute('aria-expanded',open?'true':'false'));
    syncA11y(open);
    if(open&&moveFocus){requestAnimationFrame(()=>{const current=nav.querySelector('[aria-current="page"]');(current||nav.querySelector('.sc-shell__brand')||focusables()[0])?.focus?.()})}
    if(!open&&restore&&returnFocus instanceof HTMLElement&&document.contains(returnFocus)){requestAnimationFrame(()=>returnFocus.focus())}
  };
  toggles.forEach(button=>button.addEventListener('click',()=>setOpen(!nav.classList.contains('is-open'))));
  backdrop.addEventListener('click',()=>setOpen(false));
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&!event.target.closest?.('[data-theme-picker]')){
      if(moreNav?.open){event.preventDefault();moreNav.open=false;moreNav.querySelector('summary')?.focus();return}
      if(nav.classList.contains('is-open')){event.preventDefault();setOpen(false)}return
    }
    if(event.key!=='Tab'||!drawerMq.matches||!nav.classList.contains('is-open'))return;
    const list=focusables();if(!list.length)return;const first=list[0],last=list[list.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
  });
  nav.addEventListener('click',event=>{if(event.target.closest('a')&&drawerMq.matches)setOpen(false,{restore:false,moveFocus:false})});
  document.addEventListener('click',event=>{if(moreNav?.open&&!moreNav.contains(event.target))moreNav.open=false});
  const sync=()=>{setOpen(false,{restore:false,moveFocus:false});syncA11y(false)};
  if(typeof drawerMq.addEventListener==='function')drawerMq.addEventListener('change',sync);else drawerMq.addListener(sync);
  sync();
})();
