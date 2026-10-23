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
  const clockAnchors=new WeakMap();
  const canonicalNow=clock=>{
    let anchor=clockAnchors.get(clock);
    if(!anchor){
      const raw=clock.dataset.serverNow||document.body?.dataset?.serverNow||'';
      const parsed=Date.parse(raw);
      anchor={epoch:Number.isFinite(parsed)?parsed:Date.now(),mono:performance.now()};
      clockAnchors.set(clock,anchor);
    }
    return new Date(anchor.epoch+(performance.now()-anchor.mono));
  };
  const updateClock=clock=>{
    const dateEl=clock.querySelector('[data-shell-date]'),timeEl=clock.querySelector('[data-shell-time]');
    if(!dateEl||!timeEl)return;
    const timezone='Asia/Tehran',now=canonicalNow(clock);
    try{
      const parts=new Intl.DateTimeFormat('fa-IR-u-ca-persian',{weekday:'long',year:'numeric',month:'long',day:'numeric',timeZone:timezone}).formatToParts(now);
      const map=Object.fromEntries(parts.filter(x=>x.type!=='literal').map(x=>[x.type,x.value]));
      dateEl.textContent=`${map.weekday||''} ${map.day||''} ${map.month||''} ${map.year||''}`.replace(/\s+/g,' ').trim();
      timeEl.textContent=new Intl.DateTimeFormat('fa-IR',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:timezone}).format(now);
    }catch(_){
      dateEl.textContent='—';timeEl.textContent='—';
    }
  };
  const clocks=[...document.querySelectorAll('[data-shell-clock]')];
  const syncClocks=()=>clocks.forEach(updateClock);
  if(clocks.length){syncClocks();window.setInterval(syncClocks,30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)syncClocks()})}

  const pageHeaderActions=document.querySelector('[data-page-header-actions]');
  const pageContent=document.querySelector('.sc-shell__content');
  const uiIcon=name=>{
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.classList.add('sc-ui-icon');svg.setAttribute('aria-hidden','true');svg.setAttribute('focusable','false');
    const use=document.createElementNS('http://www.w3.org/2000/svg','use');const sprite=window.SoknaAssets?.path?window.SoknaAssets.path('/assets/ui-sprite.svg'):'/assets/ui-sprite.svg';use.setAttribute('href',sprite+'#icon-'+name);svg.append(use);return svg;
  };
  const proxyAction=(source,{refresh=false}={})=>{
    if(!pageHeaderActions||!source||source.dataset.pageActionProxied==='1')return null;
    source.dataset.pageActionProxied='1';source.hidden=true;
    const proxy=document.createElement(source.tagName==='A'?'a':'button');
    if(proxy.tagName==='BUTTON')proxy.type='button';
    if(source.tagName==='A'&&source.getAttribute('href'))proxy.href=source.getAttribute('href');
    const label=source.getAttribute('aria-label')||source.textContent.trim()||(refresh?'تازه‌سازی':'عملیات');
    proxy.setAttribute('aria-label',label);proxy.title=label;
    if(refresh){proxy.className='sc-icon-button sc-page-refresh';proxy.append(uiIcon('refresh'));proxy.dataset.pageRefreshProxy='1'}
    else{proxy.className=source.classList.contains('sc-button')?source.className:'sc-button sc-button--secondary';proxy.textContent=label;proxy.dataset.pageActionProxy='1'}
    if(source.tagName!=='A')proxy.addEventListener('click',()=>source.click());
    pageHeaderActions.append(proxy);return proxy;
  };
  if(pageContent&&pageHeaderActions){
    const refresh=pageContent.querySelector('[data-refresh]');
    const hallOwnsRefresh=Boolean(refresh&&document.body?.dataset?.page==='operator'&&refresh.closest('[data-operator-workspace]'));
    // Hall owns its refresh action inside the operational toolbar. Proxying it into the
    // generic page header hides the real control on desktop because operator suppresses
    // that generic header. Keep the Hall action in place and proxy refresh everywhere else.
    if(refresh&&!hallOwnsRefresh)proxyAction(refresh,{refresh:true});
    pageContent.querySelectorAll('[data-page-action]').forEach(source=>{if(source!==refresh)proxyAction(source)});
  }

  const nav=document.querySelector('[data-product-nav]');
  const backdrop=document.querySelector('[data-product-nav-backdrop]');
  const toggles=[...document.querySelectorAll('[data-product-nav-toggle]')];
  if(!nav||!backdrop||!toggles.length)return;
  const drawerMq=matchMedia('(max-width:1180px)');
  const groups=[...nav.querySelectorAll('[data-shell-nav-group]')];
  const compactToggle=nav.querySelector('[data-shell-nav-compact]');
  const groupStoreKey='sokna.nav.groups.v1',compactStoreKey='sokna.nav.compact.v1';
  let returnFocus=null,applyingGroups=false,compactPreferred=false;
  try{compactPreferred=localStorage.getItem(compactStoreKey)==='1'}catch(_){}
  const savedGroups=()=>{try{const v=JSON.parse(localStorage.getItem(groupStoreKey)||'null');return Array.isArray(v)?new Set(v.map(String)):null}catch(_){return null}};
  const syncGroupAria=()=>groups.forEach(g=>g.querySelector('[data-shell-nav-group-toggle]')?.setAttribute('aria-expanded',g.open?'true':'false'));
  const restoreGroups=()=>{
    if(document.body.classList.contains('sc-nav-compact')){applyingGroups=true;groups.forEach(g=>g.open=true);applyingGroups=false;syncGroupAria();return}
    const saved=savedGroups();if(saved){applyingGroups=true;groups.forEach(g=>{g.open=saved.has(String(g.dataset.navGroupKey||''))||g.classList.contains('is-current')});applyingGroups=false}
    groups.filter(g=>g.classList.contains('is-current')).forEach(g=>g.open=true);syncGroupAria();
  };
  groups.forEach(g=>g.addEventListener('toggle',()=>{syncGroupAria();if(applyingGroups||document.body.classList.contains('sc-nav-compact'))return;const openGroups=groups.filter(x=>x.open).map(x=>String(x.dataset.navGroupKey||''));try{localStorage.setItem(groupStoreKey,JSON.stringify(openGroups))}catch(_){}document.dispatchEvent(new CustomEvent('sokna:ui-preference-intent',{detail:{key:'nav_groups',value:openGroups}}))}));
  const applyCompact=()=>{
    const compact=compactPreferred&&!drawerMq.matches;document.body.classList.toggle('sc-nav-compact',compact);
    if(compactToggle){compactToggle.setAttribute('aria-pressed',compact?'true':'false');compactToggle.setAttribute('aria-label',compact?'بازکردن نوار کناری':'جمع‌کردن نوار کناری');compactToggle.title=compact?'بازکردن نوار کناری':'جمع‌کردن نوار کناری'}
    restoreGroups();
  };
  compactToggle?.addEventListener('click',()=>{compactPreferred=!compactPreferred;try{localStorage.setItem(compactStoreKey,compactPreferred?'1':'0')}catch(_){}document.dispatchEvent(new CustomEvent('sokna:ui-preference-intent',{detail:{key:'nav_compact',value:compactPreferred}}));applyCompact()});
  const focusables=()=>[...nav.querySelectorAll('a[href],button:not([disabled]),summary,[tabindex]:not([tabindex="-1"])')].filter(el=>!el.hidden&&getComputedStyle(el).display!=='none'&&getComputedStyle(el).visibility!=='hidden');
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
      if(nav.classList.contains('is-open')){event.preventDefault();setOpen(false)}return
    }
    if(event.key!=='Tab'||!drawerMq.matches||!nav.classList.contains('is-open'))return;
    const list=focusables();if(!list.length)return;const first=list[0],last=list[list.length-1];
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
  });
  nav.addEventListener('click',event=>{if(event.target.closest('a')&&drawerMq.matches)setOpen(false,{restore:false,moveFocus:false})});
  const sync=()=>{setOpen(false,{restore:false,moveFocus:false});syncA11y(false);applyCompact()};
  if(typeof drawerMq.addEventListener==='function')drawerMq.addEventListener('change',sync);else drawerMq.addListener(sync);
  sync();
})();
