(()=>{
  'use strict';
  const $$=(root,selector)=>Array.from(root.querySelectorAll(selector));
  const cssEscape=value=>window.CSS?.escape?CSS.escape(value):String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
  let tabGroupSeq=0;

  function setupTabs(scope=document){
    const groups=[];
    for(const list of $$(scope,'[role="tablist"],.sc-tabs')) if(!groups.includes(list))groups.push(list);
    for(const group of groups){
      const tabs=$$(group,'[data-tab]');
      if(!tabs.length || group.dataset.soknaTabsReady==='1')continue;
      group.dataset.soknaTabsReady='1';
      const groupId=++tabGroupSeq;
      const container=group.closest('[data-tab-scope]')||group.closest('.sc-workspace')||group.closest('main')||document.body;
      const panels=new Map();
      for(const tab of tabs){
        const key=tab.dataset.tab||'';
        if(!key)continue;
        const panel=container.querySelector(`[data-panel="${cssEscape(key)}"]`);
        if(panel)panels.set(key,panel);
      }
      group.setAttribute('role','tablist');
      group.setAttribute('aria-orientation',group.getAttribute('aria-orientation')||'horizontal');
      tabs.forEach((tab,index)=>{
        const key=tab.dataset.tab||String(index);
        const panel=panels.get(key)||null;
        if(!tab.id)tab.id=`sokna-tab-${groupId}-${index}`;
        tab.setAttribute('role','tab');
        if(panel){
          if(!panel.id)panel.id=`sokna-panel-${groupId}-${index}`;
          tab.setAttribute('aria-controls',panel.id);
          panel.setAttribute('role','tabpanel');
          panel.setAttribute('aria-labelledby',tab.id);
        }
      });
      const activate=(tab,{focus=false,history=false}={})=>{
        if(!tab || tab.disabled || tab.getAttribute('aria-disabled')==='true')return;
        const key=tab.dataset.tab||'';
        tabs.forEach(t=>{
          const active=t===tab;
          t.setAttribute('aria-selected',active?'true':'false');
          t.tabIndex=active?0:-1;
        });
        for(const [panelKey,panel] of panels)panel.hidden=panelKey!==key;
        if(focus)tab.focus({preventScroll:true});
        if(history && key){
          try{const url=new URL(location.href);url.searchParams.set('tab',key);window.history.replaceState(null,'',url);}catch{}
        }
        group.dispatchEvent(new CustomEvent('sokna:tabchange',{bubbles:true,detail:{tab:key}}));
      };
      group.addEventListener('click',event=>{
        const tab=event.target.closest('[data-tab]');
        if(tab && group.contains(tab))activate(tab,{history:true});
      });
      group.addEventListener('keydown',event=>{
        const tab=event.target.closest('[data-tab]');
        if(!tab || !['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;
        event.preventDefault();
        const enabled=tabs.filter(t=>!t.disabled&&t.getAttribute('aria-disabled')!=='true');
        const i=enabled.indexOf(tab);if(i<0||!enabled.length)return;
        let n=i;
        if(event.key==='Home')n=0;else if(event.key==='End')n=enabled.length-1;else{
          const rtl=getComputedStyle(group).direction==='rtl';
          const forward=event.key==='ArrowRight'?!rtl:rtl;
          n=(i+(forward?1:-1)+enabled.length)%enabled.length;
        }
        activate(enabled[n],{focus:true,history:true});
      });
      let initial=tabs.find(t=>t.getAttribute('aria-selected')==='true')||tabs[0];
      try{const requested=new URL(location.href).searchParams.get('tab');if(requested)initial=tabs.find(t=>t.dataset.tab===requested)||initial;}catch{}
      activate(initial);
    }
  }

  function normalizeClickable(scope=document){
    // Outside forms, a missing type is always a non-submit action. Inside forms the
    // markup must be explicit; the regression gate enforces that contract.
    for(const button of $$(scope,'button:not([type])'))if(!button.closest('form'))button.type='button';
    for(const link of $$(scope,'a[href="#"]'))link.addEventListener('click',event=>{
      if(link.getAttribute('href')==='#'&&!link.dataset.allowPlaceholder)event.preventDefault();
    });
  }

  function setupDialogClose(){
    if(document.documentElement.dataset.soknaDialogCloseReady==='1')return;
    document.documentElement.dataset.soknaDialogCloseReady='1';
    document.addEventListener('click',event=>{
      const close=event.target.closest('[data-dialog-close]');
      if(!close)return;
      const dialog=close.closest('dialog');
      if(dialog instanceof HTMLDialogElement)dialog.close('cancel');
    });
  }

  function setupDialogs(scope=document){
    let seq=0;
    for(const dialog of $$(scope,'dialog')){
      if(dialog.dataset.soknaDialogReady==='1')continue;
      dialog.dataset.soknaDialogReady='1';
      const heading=dialog.querySelector('h1,h2,h3,[data-dialog-title]');
      if(heading){
        if(!heading.id)heading.id=`sokna-dialog-title-${++seq}`;
        if(!dialog.hasAttribute('aria-label')&&!dialog.hasAttribute('aria-labelledby'))dialog.setAttribute('aria-labelledby',heading.id);
      }
    }
  }

  function setupBusyForms(scope=document){
    for(const form of $$(scope,'form')){
      if(form.dataset.soknaBusyReady==='1')continue;
      form.dataset.soknaBusyReady='1';
      form.addEventListener('submit',event=>{
        const submit=event.submitter;
        if(submit instanceof HTMLElement)submit.setAttribute('data-submit-attempt','1');
      });
    }
  }

  function boot(){setupTabs();normalizeClickable();setupDialogs();setupDialogClose();setupBusyForms();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
  window.SoknaInteractions={refresh:boot,setupTabs};
})();
