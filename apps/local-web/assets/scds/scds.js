(()=>{
  'use strict';
  const q=(root,sel)=>Array.from(root.querySelectorAll(sel));
  q(document,'[data-scds-tabs]').forEach(group=>{
    const tabs=q(group,'[role="tab"]');
    const activate=tab=>{
      tabs.forEach(t=>{const on=t===tab;t.setAttribute('aria-selected',on?'true':'false');t.tabIndex=on?0:-1;const panel=document.getElementById(t.getAttribute('aria-controls')||'');if(panel)panel.hidden=!on;});
    };
    tabs.forEach((tab,index)=>{tab.addEventListener('click',()=>activate(tab));tab.addEventListener('keydown',event=>{if(!['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;event.preventDefault();let next=index;if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else next=(index+(event.key==='ArrowRight'?-1:1)+tabs.length)%tabs.length;tabs[next]?.focus();activate(tabs[next]);});});
  });
  q(document,'[data-scds-quantity]').forEach(root=>{
    const output=root.querySelector('output');const min=Number(root.dataset.min??0);const max=Number(root.dataset.max??999);const step=Math.max(1,Number(root.dataset.step??1));
    const set=v=>{const next=Math.min(max,Math.max(min,v));if(output){output.value=String(next);output.textContent=String(next);}root.dispatchEvent(new CustomEvent('scds:quantity',{bubbles:true,detail:{value:next}}));};
    q(root,'button[data-delta]').forEach(button=>button.addEventListener('click',()=>set(Number(output?.value??output?.textContent??0)+(Number(button.dataset.delta)||0)*step)));
  });
  q(document,'[data-scds-dialog-open]').forEach(button=>button.addEventListener('click',()=>{const dialog=document.getElementById(button.dataset.scdsDialogOpen||'');if(dialog instanceof HTMLDialogElement)dialog.showModal();}));
  q(document,'[data-scds-dialog-close]').forEach(button=>button.addEventListener('click',()=>{const dialog=button.closest('dialog');if(dialog instanceof HTMLDialogElement)dialog.close();}));
  q(document,'[data-sc-nav-group-toggle]').forEach(button=>button.addEventListener('click',()=>{const group=button.closest('[data-sc-nav-group]');if(!group)return;const panel=document.getElementById(button.getAttribute('aria-controls')||'');const open=button.getAttribute('aria-expanded')==='true';button.setAttribute('aria-expanded',open?'false':'true');group.classList.toggle('is-open',!open);if(panel)panel.hidden=open;}));
  const side=document.getElementById('sc-sidebar'),navButton=document.querySelector('[data-sc-nav-toggle]'),backdrop=document.querySelector('[data-sc-nav-backdrop]');
  const setNav=open=>{if(!side)return;side.classList.toggle('is-open',open);navButton?.setAttribute('aria-expanded',open?'true':'false');document.body.style.overflow=open?'hidden':'';};
  navButton?.addEventListener('click',()=>setNav(!side?.classList.contains('is-open')));
  backdrop?.addEventListener('click',()=>setNav(false));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&side?.classList.contains('is-open'))setNav(false);});
})();
