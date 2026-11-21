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

  // Observational Persian calendar. Does not alter the operational business-day cutoff.
  (()=>{
    const wrap=document.querySelector('.sc-shell__calendar-wrap');
    if(!wrap)return;
    const toggle=wrap.querySelector('[data-shell-calendar-toggle]'),panel=wrap.querySelector('[data-shell-calendar-panel]');
    const days=wrap.querySelector('[data-shell-calendar-days]'),label=wrap.querySelector('[data-shell-calendar-month]');
    const detail=wrap.querySelector('[data-shell-calendar-detail]'),source=wrap.querySelector('[data-shell-calendar-source]');
    const months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    const cal=new Intl.DateTimeFormat('en-US-u-ca-persian',{year:'numeric',month:'numeric',day:'numeric',timeZone:'Asia/Tehran'});
    const fd=n=>faDigits(n);
    const dayParts=d=>Object.fromEntries(cal.formatToParts(d).filter(x=>x.type!=='literal').map(x=>[x.type,Number(x.value)]));
    const utcDay=d=>new Date(Date.UTC(d.getUTCFullYear(),d.getUTCMonth(),d.getUTCDate(),12));
    const shift=(d,n)=>new Date(d.getTime()+n*86400000);
    const key=({year,month,day})=>[year,String(month).padStart(2,'0'),String(day).padStart(2,'0')].join('-');
    const firstDate=d=>{let first=utcDay(d);for(let n=0;n<33&&dayParts(first).day!==1;n++)first=shift(first,-1);return first};
    let first=null,events=null;
    const today=()=>dayParts(canonicalNow(wrap));
    const close=(focus=false)=>{if(panel.hidden)return;panel.hidden=true;toggle.setAttribute('aria-expanded','false');if(focus)toggle.focus({preventScroll:true})};
    const render=()=>{
      if(!first)return;
      const ym=dayParts(first);label.textContent=months[ym.month-1]+' '+fd(ym.year);
      days.replaceChildren();
      const blank=(first.getUTCDay()+1)%7;
      for(let i=0;i<blank;i++){const gap=document.createElement('span');gap.className='is-gap';gap.setAttribute('aria-hidden','true');days.append(gap)}
      const nowKey=key(today());
      for(let d=first,n=0;n<31;n++,d=shift(d,1)){
        const stamp=dayParts(d);if(stamp.month!==ym.month||stamp.year!==ym.year)break;
        const k=key(stamp),event=events?.year===stamp.year?events.events?.[k]:null;
        const b=document.createElement('button');b.type='button';b.textContent=fd(stamp.day);b.className='sc-shell__calendar-day';
        const friday=d.getUTCDay()===5;
        if(friday)b.classList.add('is-weekend');
        if(event?.holiday)b.classList.add('is-holiday');else if(event)b.classList.add('has-occasion');
        if(k===nowKey){b.classList.add('is-today');b.setAttribute('aria-current','date')}
        b.setAttribute('aria-label',`${fd(stamp.day)} ${months[stamp.month-1]} ${fd(stamp.year)}${event?'، '+event.label:''}${friday?'، جمعه':''}`);
        b.addEventListener('click',()=>{days.querySelectorAll('.is-selected').forEach(x=>x.classList.remove('is-selected'));b.classList.add('is-selected');detail.textContent=event?((event.holiday?'تعطیل رسمی: ':'مناسبت: ')+event.label):(friday?'جمعه — تعطیلی هفتگی':(events?.year===stamp.year?'مناسبت منتخبی برای این روز ثبت نشده است.':'اطلاعات مناسبت‌های این سال در دسترس نیست.'))});
        days.append(b);
      }
      source.textContent=events?.year===ym.year?'تعطیلات رسمی ۱۴۰۵ (۲۶ روز) + مناسبت‌های منتخب؛ منبع: مرکز تقویم دانشگاه تهران':'اطلاعات مناسبت‌های این سال در دسترس نیست';
    };
    const load=async()=>{
      try{
        const res=await fetch(appPath('/assets/iran-calendar-1405.json'),{credentials:'same-origin'});
        if(!res.ok)throw Error('offline');const data=await res.json();
        if(data.schema==='sokna-iran-calendar-v1'&&data.year===1405&&data.events&&typeof data.events==='object'){events=data}
      }catch(_){events=null}
      render();
    };
    toggle.addEventListener('click',()=>{if(!panel.hidden){close(true);return}first=firstDate(canonicalNow(wrap));panel.hidden=false;toggle.setAttribute('aria-expanded','true');render();load()});
    wrap.querySelector('[data-shell-calendar-prev]').addEventListener('click',()=>{first=firstDate(shift(first,-1));render()});
    wrap.querySelector('[data-shell-calendar-next]').addEventListener('click',()=>{first=firstDate(shift(first,35));render()});
    wrap.querySelector('[data-shell-calendar-close]').addEventListener('click',()=>close(true));
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden){e.preventDefault();close(true)}});
    document.addEventListener('pointerdown',e=>{if(!panel.hidden&&!wrap.contains(e.target))close()});
  })();

  // Local notification bell: read-only preview of the authenticated user's inbox.
  // Never acknowledge/delete notifications in a preview; the inbox remains authoritative.
  (()=>{
    const wrap=document.querySelector('[data-shell-notify-wrap]');
    if(!wrap)return;
    const toggle=wrap.querySelector('[data-shell-notify-toggle]');
    const panel=wrap.querySelector('[data-shell-notify-panel]');
    const items=wrap.querySelector('[data-shell-notify-items]');
    const closeButton=wrap.querySelector('[data-shell-notify-close]');
    let generation=0;
    const close=({focus=false}={})=>{
      if(panel.hidden)return;
      panel.hidden=true;
      toggle.setAttribute('aria-expanded','false');
      generation++;
      if(focus)toggle.focus({preventScroll:true});
    };
    const render=rows=>{
      items.replaceChildren();
      // The quick view is scoped to the current server business day. The full
      // inbox still contains history, including old unread and unresolved entries.
      const today=String(panel.dataset.businessDate||'');
      const cutoff=String(panel.dataset.businessCutoff||'');
      const matchesBusinessDay=row=>{
        if(!/^\d{4}-\d{2}-\d{2}$/.test(today)||!/^\d{2}:\d{2}$/.test(cutoff))return true;
        const wall=String(row.created_at||'').replace('T',' ');
        const m=/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/.exec(wall);
        if(!m)return false;
        const date=m[1],time=m[2];
        const assigned=time<cutoff?new Date(Date.parse(date+'T00:00:00Z')-86400000).toISOString().slice(0,10):date;
        return assigned===today;
      };
      const filtered=rows.filter(matchesBusinessDay);
      const sorted=[...filtered].sort((a,b)=>Boolean(a.read_at)-Boolean(b.read_at)||Number(b.id||0)-Number(a.id||0)).slice(0,6);
      if(!sorted.length){const empty=document.createElement('p');empty.textContent='برای امروز اعلانی وجود ندارد. اعلان‌های قدیمی در تاریخچه باقی می‌مانند.';items.append(empty);return}
      for(const item of sorted){
        const raw=String(item.target_url||'');
        const safePath=raw.startsWith('/')&&!raw.startsWith('//')&&!raw.includes('\\')?appPath(raw):'';
        const card=document.createElement(safePath?'a':'article');card.className='sc-shell__notify-item';
        if(safePath)card.setAttribute('href',safePath);
        if(!item.read_at)card.classList.add('is-unread');
        const title=document.createElement('strong');title.textContent=faDigits(item.title||'اعلان');
        const detail=document.createElement('small');detail.textContent=faDigits(item.body||'');
        card.append(title,detail);items.append(card);
      }
    };
    const open=async()=>{
      panel.hidden=false;toggle.setAttribute('aria-expanded','true');
      items.textContent='در حال دریافت اعلان‌ها…';const current=++generation;
      try{
        const res=await fetch(panel.dataset.api,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
        if(!res.ok)throw Error('HTTP '+res.status);
        const data=await res.json();
        if(data.success===false||!Array.isArray(data.snapshot?.items))throw Error('invalid snapshot');
        if(current===generation&&!panel.hidden)render(data.snapshot.items);
      }catch(_){if(current===generation&&!panel.hidden)items.textContent='دریافت اعلان‌ها ممکن نشد؛ از «مشاهده همه اعلان‌ها» استفاده کنید.'}
    };
    toggle.addEventListener('click',()=>{if(panel.hidden)open();else close({focus:true})});
    closeButton.addEventListener('click',()=>close({focus:true}));
    document.addEventListener('pointerdown',event=>{if(!panel.hidden&&!wrap.contains(event.target))close()});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!panel.hidden){event.preventDefault();close({focus:true})}});
  })();

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
    const saved=savedGroups();if(saved){applyingGroups=true;groups.forEach(g=>{g.open=saved.has(String(g.dataset.navGroupKey||''))||g.classList.contains('is-current')});applyingGroups=false}else if(matchMedia('(max-width:820px)').matches){applyingGroups=true;groups.forEach(g=>g.open=true);applyingGroups=false}
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
