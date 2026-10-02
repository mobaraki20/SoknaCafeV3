(()=>{
  'use strict';
  const KEY='sokna.theme';
  const root=document.documentElement;
  const media=matchMedia('(prefers-color-scheme: dark)');
  const valid=new Set(['light','dark','system']);
  const read=()=>{try{const value=localStorage.getItem(KEY)||'system';return valid.has(value)?value:'system'}catch{return 'system'}};
  const effective=value=>value==='system'?(media.matches?'dark':'light'):value;
  const label=value=>value==='light'?'روشن':value==='dark'?'تیره':'سیستم';
  const save=value=>{try{localStorage.setItem(KEY,value)}catch{}};
  const apply=value=>{
    const preference=valid.has(value)?value:'system';
    const theme=effective(preference);
    root.dataset.theme=theme;
    root.dataset.themePreference=preference;
    const meta=document.querySelector('meta[name="theme-color"]');if(meta)meta.setAttribute('content',theme==='dark'?'#101512':'#0f6b66');
    document.querySelectorAll('[data-theme-label]').forEach(el=>el.textContent=theme==='dark'?'حالت روشن':'حالت تیره');
    document.querySelectorAll('[data-theme-toggle]').forEach(el=>{
      el.setAttribute('aria-pressed',theme==='dark'?'true':'false');
      el.setAttribute('aria-label',theme==='dark'?'فعال کردن حالت روشن':'فعال کردن حالت تیره');
      el.dataset.themeState=theme;
    });
    document.querySelectorAll('[data-theme-option]').forEach(el=>{
      const checked=el.dataset.themeOption===preference;
      el.setAttribute('aria-checked',checked?'true':'false');
      el.classList.toggle('is-active',checked);
    });
    document.querySelectorAll('[data-theme-menu-toggle]').forEach(el=>{
      const suffix=preference==='system'?` (${theme==='dark'?'تیره':'روشن'})`:'';
      el.setAttribute('aria-label',`پوسته: ${label(preference)}${suffix}`);
      el.title=`پوسته: ${label(preference)}${suffix}`;
    });
    document.dispatchEvent(new CustomEvent('sokna:theme-change',{detail:{preference,theme}}));
  };
  const closeMenus=except=>document.querySelectorAll('[data-theme-picker]').forEach(picker=>{
    if(except&&picker===except)return;
    const menu=picker.querySelector('[data-theme-menu]'),button=picker.querySelector('[data-theme-menu-toggle]');
    if(menu)menu.hidden=true;if(button)button.setAttribute('aria-expanded','false');
  });
  const setMenu=(picker,open)=>{
    const menu=picker?.querySelector('[data-theme-menu]'),button=picker?.querySelector('[data-theme-menu-toggle]');
    if(!menu||!button)return;
    if(open)closeMenus(picker);
    menu.hidden=!open;button.setAttribute('aria-expanded',open?'true':'false');
    if(open){const selected=menu.querySelector('[data-theme-option][aria-checked="true"]')||menu.querySelector('[data-theme-option]');selected?.focus()}
  };
  apply(read());
  media.addEventListener?.('change',()=>{if(read()==='system')apply('system')});
  document.addEventListener('click',event=>{
    const option=event.target.closest('[data-theme-option]');
    if(option){const value=option.dataset.themeOption;if(valid.has(value)){save(value);apply(value);setMenu(option.closest('[data-theme-picker]'),false)}return}
    const menuButton=event.target.closest('[data-theme-menu-toggle]');
    if(menuButton){const picker=menuButton.closest('[data-theme-picker]'),menu=picker?.querySelector('[data-theme-menu]');setMenu(picker,!!menu?.hidden);return}
    const toggle=event.target.closest('[data-theme-toggle]');
    if(toggle){const next=root.dataset.theme==='dark'?'light':'dark';save(next);apply(next);return}
    if(!event.target.closest('[data-theme-picker]'))closeMenus();
  });
  document.addEventListener('keydown',event=>{
    const picker=event.target.closest?.('[data-theme-picker]');
    if(event.key==='Escape'&&picker){setMenu(picker,false);picker.querySelector('[data-theme-menu-toggle]')?.focus();return}
    if(!picker)return;
    const options=[...picker.querySelectorAll('[data-theme-option]')];
    const index=options.indexOf(document.activeElement);
    if(index<0)return;
    if(event.key==='ArrowDown'||event.key==='ArrowLeft'){event.preventDefault();options[(index+1)%options.length].focus()}
    if(event.key==='ArrowUp'||event.key==='ArrowRight'){event.preventDefault();options[(index-1+options.length)%options.length].focus()}
  });
})();
