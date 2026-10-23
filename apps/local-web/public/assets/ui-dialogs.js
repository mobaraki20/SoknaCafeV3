(()=>{
  'use strict';
  let dialog=null;
  const ensure=()=>{
    if(dialog)return dialog;
    dialog=document.createElement('dialog');dialog.className='sc-dialog sc-app-dialog';dialog.dataset.appDialog='';dialog.setAttribute('aria-labelledby','soknaDialogTitle');dialog.setAttribute('aria-describedby','soknaDialogMessage');
    dialog.innerHTML=`<form method="dialog" class="sc-app-dialog__panel"><div class="sc-app-dialog__head"><span class="sc-app-dialog__icon" data-dialog-icon aria-hidden="true">?</span><div><h2 id="soknaDialogTitle" class="sc-dialog__title" data-dialog-title tabindex="-1"></h2><p id="soknaDialogMessage" class="sc-dialog__description" data-dialog-message></p></div><button class="sc-icon-button sc-app-dialog__close" type="submit" value="cancel" aria-label="بستن پنجره"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg></button></div><div class="sc-app-dialog__body" data-dialog-body><div data-dialog-field></div><div class="sc-app-dialog__choices" data-dialog-choices></div></div><div class="sc-app-dialog__actions" data-dialog-actions><div class="sc-app-dialog__action-main" data-dialog-action-main></div><div class="sc-app-dialog__action-safe" data-dialog-action-safe></div></div></form>`;
    document.body.append(dialog);return dialog;
  };
  const button=(label,value,kind='secondary',role='choice')=>{const b=document.createElement('button');b.type='submit';b.value=value;b.dataset.dialogRole=role;b.className=`sc-button ${kind==='primary'?'':kind==='danger'?'sc-button--danger':'sc-button--secondary'}`;b.textContent=label;return b};

  // One global modal contract: when any native dialog is open, the page behind it
  // must not move. Keeping the scrollbar gutter stable prevents the horizontal
  // jump that used to happen when opening settlement/admin dialogs.
  const syncDialogLock=()=>{
    const open=Boolean(document.querySelector('dialog[open]'));
    document.documentElement.classList.toggle('sc-dialog-open',open);
    document.body?.classList.toggle('sc-dialog-open',open);
  };
  const dialogObserver=new MutationObserver(records=>{if(records.some(r=>r.type==='attributes'&&r.attributeName==='open'||r.type==='childList'))queueMicrotask(syncDialogLock)});
  dialogObserver.observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['open']});
  document.addEventListener('close',()=>queueMicrotask(syncDialogLock),true);
  document.addEventListener('cancel',()=>queueMicrotask(syncDialogLock),true);
  queueMicrotask(syncDialogLock);

  const dialogFocusState=new WeakMap();
  const resolveDialog=target=>typeof target==='string'?document.querySelector(target):target;
  const showDialog=(target,{initialFocus='',focusTitle=false}={})=>{
    const d=resolveDialog(target);if(!(d instanceof HTMLDialogElement))return false;if(d.open)return true;
    const opener=document.activeElement instanceof HTMLElement?document.activeElement:null;dialogFocusState.set(d,{opener});
    d.showModal();
    queueMicrotask(()=>{
      let targetNode=initialFocus?d.querySelector(initialFocus):d.querySelector('[data-dialog-initial-focus]');
      if(!targetNode&&focusTitle)targetNode=d.querySelector('[data-dialog-title],h1,h2,h3');
      if(targetNode&&focusTitle&&!targetNode.hasAttribute('tabindex'))targetNode.setAttribute('tabindex','-1');
      if(!targetNode)targetNode=d.querySelector('input:not([type="hidden"]):not(:disabled),select:not(:disabled),textarea:not(:disabled),button:not(:disabled),a[href],[tabindex="0"]');
      targetNode?.focus?.({preventScroll:true});
    });
    return true;
  };
  const closeDialog=(target,value='')=>{const d=resolveDialog(target);if(!(d instanceof HTMLDialogElement)||!d.open)return false;d.close(value);return true};
  document.addEventListener('close',event=>{const d=event.target;if(!(d instanceof HTMLDialogElement))return;const state=dialogFocusState.get(d);dialogFocusState.delete(d);const opener=state?.opener;if(opener?.isConnected)queueMicrotask(()=>opener.focus?.({preventScroll:true}))},true);

  let toastRegion=null;
  const ensureToastRegion=()=>{
    // A body-level toast sits behind a native <dialog> because dialogs live in
    // the browser top layer. Route feedback into the open dialog so errors are
    // never hidden by the blurred backdrop.
    const dialogs=[...document.querySelectorAll('dialog[open]')],active=dialogs.at(-1)||null;
    if(active){
      let local=active.querySelector(':scope > [data-dialog-toast-region]');
      if(!local){local=document.createElement('div');local.className='sc-toast-region sc-toast-region--dialog';local.dataset.dialogToastRegion='';local.setAttribute('aria-live','polite');local.setAttribute('aria-atomic','false');active.append(local)}
      return local;
    }
    if(toastRegion?.isConnected)return toastRegion;
    toastRegion=document.createElement('div');
    toastRegion.className='sc-toast-region';
    toastRegion.dataset.toastRegion='';
    toastRegion.setAttribute('aria-live','polite');
    toastRegion.setAttribute('aria-atomic','false');
    document.body.append(toastRegion);
    return toastRegion;
  };
  const toastRegistry=new Map(),toastDedupeWindowMs=2400;
  const toast=(message,{tone='success',title='',duration=null,actionLabel='',onAction=null,key=''}={})=>{
    const textValue=String(message||'').trim();if(!textValue)return null;
    const safeTone=['success','info','warning','danger'].includes(tone)?tone:'info';
    const identity=String(key||`${safeTone}\u001f${String(title||'')}\u001f${textValue}`),now=Date.now(),prior=toastRegistry.get(identity);
    const ttl=duration===null?(safeTone==='danger'?7000:safeTone==='warning'?5500:4200):Number(duration);
    const schedule=entry=>{if(entry.timer)clearTimeout(entry.timer);if(ttl>0)entry.timer=setTimeout(()=>{entry.item.classList.remove('is-visible');setTimeout(()=>{entry.item.remove();if(toastRegistry.get(identity)===entry)toastRegistry.delete(identity)},180)},ttl)};
    if(prior&&prior.item.isConnected&&(now-prior.at)<=toastDedupeWindowMs){prior.at=now;schedule(prior);return prior.item}
    const region=ensureToastRegion(),item=document.createElement('div');
    item.className=`sc-toast sc-toast--${safeTone}`;item.dataset.toastKey=identity;
    item.setAttribute('role',safeTone==='danger'?'alert':'status');
    const icon=document.createElement('span');icon.className='sc-toast__icon';icon.setAttribute('aria-hidden','true');icon.textContent=safeTone==='success'?'✓':safeTone==='warning'?'!':safeTone==='danger'?'×':'i';
    const copy=document.createElement('div');copy.className='sc-toast__copy';
    if(title){const strong=document.createElement('strong');strong.textContent=title;copy.append(strong)}
    const text=document.createElement('span');text.textContent=textValue;copy.append(text);item.append(icon,copy);
    const forget=()=>{item.remove();const active=toastRegistry.get(identity);if(active?.item===item){if(active.timer)clearTimeout(active.timer);toastRegistry.delete(identity)}};
    if(actionLabel&&typeof onAction==='function'){const action=document.createElement('button');action.type='button';action.className='sc-toast__action';action.textContent=actionLabel;action.addEventListener('click',()=>{onAction();forget()});item.append(action)}
    const close=document.createElement('button');close.type='button';close.className='sc-toast__close';close.setAttribute('aria-label','بستن اعلان');close.textContent='×';close.addEventListener('click',forget);item.append(close);
    region.prepend(item);while(region.children.length>3){const old=region.lastElementChild;if(old?.dataset.toastKey){const e=toastRegistry.get(old.dataset.toastKey);if(e?.item===old){if(e.timer)clearTimeout(e.timer);toastRegistry.delete(old.dataset.toastKey)}}old?.remove()}
    const entry={item,at:now,timer:null};toastRegistry.set(identity,entry);requestAnimationFrame(()=>item.classList.add('is-visible'));schedule(entry);return item;
  };
  addEventListener('sokna:toast',event=>{const d=event.detail||{};toast(d.message||'',d)});
  const dialogTone=(tone,kind)=>{const safe=['neutral','info','warning','danger','success'].includes(tone)?tone:'neutral';const glyph=safe==='danger'?'!':safe==='warning'?'!':safe==='info'?'i':safe==='success'?'✓':kind==='prompt'?'…':kind==='reveal'?'i':kind==='choose'?'↔':'?';return {tone:safe,glyph}};
  const open=({title,message='',field=null,actions=[],focus='safe',choiceLayout='auto',tone='neutral',kind='confirm'})=>{
    const d=ensure();
    // Duplicate clicks must never try to open the shared dialog twice. The second
    // request is treated as a cancelled duplicate rather than throwing InvalidStateError.
    if(d.open)return Promise.resolve({value:'cancel',input:''});
    return new Promise(resolve=>{
    const titleEl=d.querySelector('[data-dialog-title]'),msg=d.querySelector('[data-dialog-message]'),iconEl=d.querySelector('[data-dialog-icon]'),fieldBox=d.querySelector('[data-dialog-field]'),choicesBox=d.querySelector('[data-dialog-choices]'),actionBox=d.querySelector('[data-dialog-actions]'),mainBox=d.querySelector('[data-dialog-action-main]'),safeBox=d.querySelector('[data-dialog-action-safe]');
    const semantic=dialogTone(tone,kind);d.dataset.dialogTone=semantic.tone;d.dataset.dialogKind=kind;iconEl.textContent=semantic.glyph;
    titleEl.textContent=title;msg.textContent=message;msg.hidden=!message;fieldBox.replaceChildren();choicesBox.replaceChildren();mainBox.replaceChildren();safeBox.replaceChildren();choicesBox.dataset.layout=choiceLayout;
    let input=null;
    if(field){const label=document.createElement('label');label.className='sc-field';const span=document.createElement('span');span.className='sc-field__label';span.textContent=field.label||'';input=document.createElement(field.type==='textarea'?'textarea':'input');input.className='sc-control';if(input instanceof HTMLInputElement)input.type=field.type||'text';const preserveAscii=Boolean(field.preserveAscii||field.readOnly),humanize=!preserveAscii&&field.humanDigits!==false;input.value=humanize?(window.SoknaLocale?.humanDigits?.(field.value??'')??String(field.value??'')):(field.value||'');input.placeholder=field.placeholder||'';input.dir=field.dir||'auto';if(field.inputMode)input.inputMode=field.inputMode;if(field.humanNumeric)input.dataset.faNumber='1';if(field.money){input.dataset.moneyInput='1';input.inputMode='numeric'}if(field.maxLength)input.maxLength=field.maxLength;if(field.readOnly)input.readOnly=true;if(humanize&&!field.readOnly)input.addEventListener('input',()=>{const start=input.selectionStart,end=input.selectionEnd,next=window.SoknaLocale?.humanDigits?.(input.value)??input.value;if(next!==input.value){input.value=next;try{input.setSelectionRange(start,end)}catch{}}});label.append(span,input);fieldBox.append(label)}
    actions.forEach(a=>{const role=a.role||'choice',b=button(a.label,a.value,a.kind,role);if(role==='cancel')safeBox.append(b);else if(role==='choice')choicesBox.append(b);else mainBox.append(b)});
    choicesBox.hidden=!choicesBox.children.length;actionBox.hidden=!mainBox.children.length&&!safeBox.children.length;
    const bodyBox=d.querySelector('[data-dialog-body]'),hasBody=Boolean(input||choicesBox.children.length);
    bodyBox.hidden=!hasBody;d.classList.toggle('is-simple',!hasBody);
    const onClose=()=>{d.removeEventListener('close',onClose);const value=d.returnValue;resolve({value,input:input?.value??''})};d.addEventListener('close',onClose);showDialog(d);queueMicrotask(()=>{if(input){input.focus();if(input.readOnly)input.select?.();return}const safe=safeBox.querySelector('button'),primary=mainBox.querySelector('[data-dialog-role="primary"]'),fallback=mainBox.querySelector('button');const target=focus==='primary'?(primary||fallback||safe):(safe||primary||fallback);(target||titleEl).focus()});
    });
  };

  const feedback=(target,message,{tone='info',busy=false}={})=>{
    const node=typeof target==='string'?document.querySelector(target):target;
    if(!(node instanceof HTMLElement))return null;
    const valid=['success','info','warning','danger'].includes(tone)?tone:'info';
    node.textContent=String(message||'');
    node.hidden=!message;
    node.className=`sc-alert sc-event-feedback sc-alert--${valid}`;
    node.setAttribute('role',valid==='danger'?'alert':'status');
    node.setAttribute('aria-live',valid==='danger'?'assertive':'polite');
    if(busy)node.setAttribute('aria-busy','true');else node.removeAttribute('aria-busy');
    return node;
  };
  feedback.clear=target=>{
    const node=typeof target==='string'?document.querySelector(target):target;
    if(!(node instanceof HTMLElement))return;
    node.textContent='';node.hidden=true;node.removeAttribute('aria-busy');
  };

  const errorText=(error,fallback='عملیات انجام نشد. دوباره تلاش کنید.')=>{
    if(!error)return fallback;
    const name=String(error?.name||''),message=String(error?.message||'').trim();
    if(name==='AbortError')return '';
    if(/failed to fetch|networkerror|network request failed|load failed|fetch failed|the internet connection appears to be offline/i.test(message))
      return 'ارتباط با سامانه برقرار نشد. اتصال را بررسی کنید و دوباره تلاش کنید.';
    if(/invalidstateerror/i.test(name+':'+message))return fallback;
    return message||fallback;
  };

  const busy=async(target,task)=>{
    if(!(target instanceof HTMLElement)||typeof task!=='function')return await task?.();
    if(target.dataset.soknaBusy==='1')return null;
    target.dataset.soknaBusy='1';target.setAttribute('aria-busy','true');
    const controls=target instanceof HTMLFormElement?[...target.querySelectorAll('button[type=\"submit\"],input[type=\"submit\"]')]:[target];
    const state=controls.map(control=>[control,control.disabled]);controls.forEach(control=>{control.disabled=true});
    try{return await task()}finally{state.forEach(([control,disabled])=>{control.disabled=disabled});target.removeAttribute('aria-busy');delete target.dataset.soknaBusy}
  };
  window.SoknaUI={
    toast,busy,feedback,showDialog,closeDialog,errorText,
    async confirm(title,message='',danger=false){const r=await open({title,message,focus:'safe',tone:danger?'danger':'neutral',kind:'confirm',actions:[{label:'تأیید',value:'ok',kind:danger?'danger':'primary',role:'primary'},{label:'انصراف',value:'cancel',role:'cancel'}]});return r.value==='ok'},
    async prompt(title,{message='',label='توضیح',value='',placeholder='',maxLength=500,required=true,dir='auto',inputMode='',humanNumeric=false,money=false,humanDigits=true,preserveAscii=false}={}){const r=await open({title,message,tone:'neutral',kind:'prompt',field:{label,value,placeholder,maxLength,dir,inputMode,humanNumeric,money,humanDigits,preserveAscii},actions:[{label:'تأیید',value:'ok',kind:'primary',role:'primary'},{label:'انصراف',value:'cancel',role:'cancel'}]});if(r.value!=='ok')return null;const v=r.input.trim();return required&&!v?null:v},
    async choose(title,choices,{message='',layout='auto',cancelLabel='انصراف'}={}){const actions=[...choices.map(c=>({label:c.label,value:c.value,kind:c.kind||'secondary',role:'choice'})),{label:String(cancelLabel||'انصراف'),value:'cancel',role:'cancel'}];const danger=choices.some(c=>c.kind==='danger');const r=await open({title,message,tone:danger?'warning':'neutral',kind:'choose',actions,choiceLayout:layout});return r.value==='cancel'?null:r.value},
    async reveal(title,value,{message='این مقدار فقط همین‌بار نمایش داده می‌شود.'}={}){const r=await open({title,message,tone:'info',kind:'reveal',field:{label:'مقدار',value,dir:'ltr',readOnly:true,preserveAscii:true},actions:[{label:'بستن',value:'ok',kind:'primary',role:'primary'}]});return r.value==='ok'}
  };
})();
