(()=>{
  'use strict';
  const $$=(root,selector)=>Array.from(root.querySelectorAll(selector));
  const cssEscape=value=>window.CSS?.escape?CSS.escape(value):String(value).replace(/[^a-zA-Z0-9_-]/g,'\\$&');
  let tabGroupSeq=0;
  const dirtyForms=new Set();

  const activeDirtyForm=form=>{
    if(!(form instanceof HTMLFormElement)||form.dataset.soknaDirty!=='1')return false;
    const dialog=form.closest('dialog');if(dialog&&!dialog.open)return false;
    if(form.closest('[hidden]'))return false;
    return true;
  };
  const markClean=form=>{if(form instanceof HTMLFormElement)form.dataset.soknaDirty='0'};
  const markDirty=form=>{if(form instanceof HTMLFormElement&&form.matches('[data-dirty-guard]'))form.dataset.soknaDirty='1'};
  const isDirty=form=>form instanceof HTMLFormElement&&form.dataset.soknaDirty==='1';
  const dirtyLabel=form=>String(form?.dataset?.dirtyLabel||'این فرم').trim()||'این فرم';
  const confirmDiscard=async form=>{
    if(!isDirty(form))return true;
    const title='تغییرات ذخیره نشده';
    const message=`تغییرات ${dirtyLabel(form)} هنوز ذخیره نشده است. بدون ذخیره خارج شوی؟`;
    if(typeof window.SoknaUI?.confirm==='function')return await window.SoknaUI.confirm(title,message,false);
    window.dispatchEvent(new CustomEvent('sokna:toast',{detail:{message:'پنجره تأیید در دسترس نیست؛ تغییرات ذخیره‌نشده حفظ شد.',tone:'warning',title:'خروج انجام نشد'}}));
    return false;
  };
  const guardClose=async(form,close)=>{
    if(!(form instanceof HTMLFormElement)){close?.();return true}
    if(!(await confirmDiscard(form)))return false;
    markClean(form);close?.();return true;
  };
  const guardScope=async scope=>{
    const forms=[...dirtyForms].filter(form=>scope?.contains?.(form)&&activeDirtyForm(form));
    for(const form of forms)if(!(await guardClose(form)))return false;
    return true;
  };

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
        if(!tab||!group.contains(tab))return;
        const current=tabs.find(t=>t.getAttribute('aria-selected')==='true');
        const currentPanel=current?panels.get(current.dataset.tab||''):null;
        if(current===tab){activate(tab,{history:true});return}
        event.preventDefault();
        void (async()=>{if(currentPanel&&!(await guardScope(currentPanel)))return;activate(tab,{history:true})})();
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
        const currentPanel=panels.get(tab.dataset.tab||'')||null,target=enabled[n];
        void (async()=>{if(currentPanel&&target!==tab&&!(await guardScope(currentPanel)))return;activate(target,{focus:true,history:true})})();
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
      if(!(dialog instanceof HTMLDialogElement))return;
      event.preventDefault();event.stopPropagation();
      const form=dialog.querySelector('form[data-dirty-guard]');
      void guardClose(form,()=>dialog.close('cancel'));
    },true);
    document.addEventListener('cancel',event=>{
      const dialog=event.target;if(!(dialog instanceof HTMLDialogElement))return;
      const form=dialog.querySelector('form[data-dirty-guard]');if(!isDirty(form))return;
      event.preventDefault();
      if(dialog.dataset.soknaDiscardPending==='1')return;dialog.dataset.soknaDiscardPending='1';
      void guardClose(form,()=>dialog.close('cancel')).finally(()=>delete dialog.dataset.soknaDiscardPending);
    },true);
  }

  let dialogA11ySeq=0;
  function setupDialogs(scope=document){
    for(const dialog of $$(scope,'dialog')){
      if(dialog.dataset.soknaDialogReady==='1')continue;
      dialog.dataset.soknaDialogReady='1';
      const heading=dialog.querySelector('h1,h2,h3,[data-dialog-title]');
      if(heading){
        heading.classList.add('sc-dialog__title');
        if(!heading.id)heading.id=`sokna-dialog-title-${++dialogA11ySeq}`;
        if(!dialog.hasAttribute('aria-label')&&!dialog.hasAttribute('aria-labelledby'))dialog.setAttribute('aria-labelledby',heading.id);
        const titleWrap=heading.parentElement;
        const description=(heading.nextElementSibling?.matches?.('p,.sc-muted')?heading.nextElementSibling:null)||(titleWrap?.querySelector?.(':scope > p,:scope > .sc-muted')||null);
        if(description instanceof HTMLElement){
          description.classList.add('sc-dialog__description');
          if(!description.id)description.id=`sokna-dialog-description-${++dialogA11ySeq}`;
          if(!dialog.hasAttribute('aria-describedby'))dialog.setAttribute('aria-describedby',description.id);
        }
      }
      // A07: every task dialog keeps an explicit close control available at the
      // top edge. Existing custom headers keep their own close icon; legacy
      // forms receive a non-destructive primitive close button without moving
      // their fields/actions or changing business behavior.
      const head=heading?.closest?.('header,.sc-section-head,[class*="__head"]')||null;
      const headClose=head?.querySelector?.('[data-dialog-close],[aria-label="بستن"],[aria-label="بستن پنجره"]')||null;
      if(dialog.classList.contains('sc-dialog')&&!headClose){
        const close=document.createElement('button');
        close.type='button';close.className='sc-icon-button sc-dialog__close';close.dataset.dialogClose='';close.setAttribute('aria-label','بستن پنجره');close.textContent='×';
        dialog.classList.add('sc-dialog--auto-close');dialog.prepend(close);
      }
    }
  }

  function setupDirtyForms(scope=document){
    for(const form of $$(scope,'form[data-dirty-guard]')){
      if(form.dataset.soknaDirtyReady==='1')continue;
      form.dataset.soknaDirtyReady='1';markClean(form);dirtyForms.add(form);
      form.addEventListener('input',()=>markDirty(form));
      form.addEventListener('change',()=>markDirty(form));
      form.addEventListener('reset',()=>queueMicrotask(()=>markClean(form)));
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

  const comboNormalize=value=>String(value??'').trim().toLocaleLowerCase('fa-IR').replace(/[يك]/g,m=>m==='ي'?'ی':'ک').replace(/[‌\-_/]/g,' ').replace(/\s+/g,' ');
  function setupComboboxes(scope=document){
    for(const box of $$(scope,'[data-combobox]')){
      if(box.dataset.soknaComboboxReady==='1')continue;
      const input=box.querySelector('[data-combobox-input]'),value=box.querySelector('[data-combobox-value]'),list=box.querySelector('[data-combobox-list]');
      if(!(input instanceof HTMLInputElement)||!(value instanceof HTMLInputElement)||!(list instanceof HTMLElement))continue;
      box.dataset.soknaComboboxReady='1';input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');
      if(list.id)input.setAttribute('aria-controls',list.id);list.setAttribute('role','listbox');
      let active=-1;
      const options=()=>[...list.querySelectorAll('[data-combobox-option]')].filter(o=>!o.hidden&&o.getAttribute('aria-disabled')!=='true');
      const setOpen=open=>{list.hidden=!open;input.setAttribute('aria-expanded',open?'true':'false');if(!open){active=-1;options().forEach(o=>o.classList.remove('is-active'))}};
      const filter=()=>{const q=comboNormalize(input.value),selectedLabel=comboNormalize(input.dataset.selectedLabel||'');for(const option of [...list.querySelectorAll('[data-combobox-option]')]){const hay=comboNormalize(option.dataset.searchText||option.textContent||'');option.hidden=Boolean(q&&q!==selectedLabel&&!hay.includes(q))}active=-1;};
      const activate=index=>{const rows=options();if(!rows.length)return;active=(index+rows.length)%rows.length;rows.forEach((o,i)=>o.classList.toggle('is-active',i===active));rows[active].scrollIntoView({block:'nearest'})};
      const choose=option=>{if(!(option instanceof HTMLElement)||option.getAttribute('aria-disabled')==='true')return;const label=option.dataset.label||option.textContent?.trim()||'';value.value=String(option.dataset.value||'');input.value=label;input.dataset.selectedLabel=label;setOpen(false);value.dispatchEvent(new Event('change',{bubbles:true}));input.dispatchEvent(new CustomEvent('sokna:comboboxchange',{bubbles:true,detail:{value:value.value,label}}));};
      input.addEventListener('focus',()=>{filter();setOpen(true)});
      input.addEventListener('input',()=>{if(comboNormalize(input.value)!==comboNormalize(input.dataset.selectedLabel||'')){value.value='';delete input.dataset.selectedLabel;value.dispatchEvent(new Event('change',{bubbles:true}))}filter();setOpen(true)});
      input.addEventListener('keydown',event=>{if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();setOpen(true);activate(active+(event.key==='ArrowDown'?1:-1));return}if(event.key==='Enter'&&!list.hidden&&active>=0){event.preventDefault();choose(options()[active]);return}if(event.key==='Escape'){event.preventDefault();setOpen(false)}});
      list.addEventListener('mousedown',event=>{const option=event.target.closest('[data-combobox-option]');if(option&&list.contains(option))event.preventDefault()});
      list.addEventListener('click',event=>{const option=event.target.closest('[data-combobox-option]');if(option&&list.contains(option))choose(option)});
      box.addEventListener('focusout',event=>{if(box.contains(event.relatedTarget))return;setTimeout(()=>{if(!box.contains(document.activeElement))setOpen(false)},0)});
      new MutationObserver(()=>{filter();if(!list.querySelector('[data-combobox-option]:not([hidden])'))active=-1}).observe(list,{childList:true,subtree:true});
    }
  }

  function setupFilePickers(scope=document){
    for(const picker of $$(scope,'[data-file-picker]')){
      if(picker.dataset.soknaFilePickerReady==='1')continue;
      const input=picker.querySelector('input[type="file"]');
      const trigger=picker.querySelector('[data-file-trigger]');
      const name=picker.querySelector('[data-file-name]');
      if(!(input instanceof HTMLInputElement)||!(trigger instanceof HTMLElement)||!(name instanceof HTMLElement))continue;
      picker.dataset.soknaFilePickerReady='1';
      const empty=name.textContent?.trim()||'فایلی انتخاب نشده است';
      const sync=()=>{
        const files=Array.from(input.files||[]);
        name.textContent=files.length===0?empty:(files.length===1?files[0].name:`${window.SoknaLocale?.integer?window.SoknaLocale.integer(files.length):new Intl.NumberFormat('fa-IR').format(files.length)} فایل انتخاب شد`);
        picker.dataset.hasFile=files.length?'1':'0';
      };
      trigger.addEventListener('click',()=>{if(!input.disabled)input.click();});
      input.addEventListener('change',sync);
      sync();
    }
  }

  function boot(){setupDirtyForms();setupTabs();normalizeClickable();setupDialogs();setupDialogClose();setupBusyForms();setupComboboxes();setupFilePickers();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
  addEventListener('beforeunload',event=>{if(![...dirtyForms].some(activeDirtyForm))return;event.preventDefault();event.returnValue='';});
  window.SoknaInteractions={refresh:boot,setupTabs,setupComboboxes,markClean,markDirty,isDirty,guardClose,confirmDiscard};
})();
