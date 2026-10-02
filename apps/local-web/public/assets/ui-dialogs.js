(()=>{
  'use strict';
  let dialog=null;
  const ensure=()=>{
    if(dialog)return dialog;
    dialog=document.createElement('dialog');dialog.className='sc-dialog sc-app-dialog';dialog.dataset.appDialog='';dialog.setAttribute('aria-labelledby','soknaDialogTitle');
    dialog.innerHTML=`<form method="dialog" class="sc-app-dialog__panel"><div class="sc-app-dialog__head"><span class="sc-app-dialog__icon" aria-hidden="true">!</span><div><h2 id="soknaDialogTitle" data-dialog-title tabindex="-1"></h2><p data-dialog-message></p></div></div><div data-dialog-field></div><div class="sc-app-dialog__actions" data-dialog-actions></div></form>`;
    document.body.append(dialog);return dialog;
  };
  const button=(label,value,kind='secondary')=>{const b=document.createElement('button');b.type='submit';b.value=value;b.className=`sc-button ${kind==='primary'?'':kind==='danger'?'sc-button--danger':'sc-button--secondary'}`;b.textContent=label;return b};

  let toastRegion=null;
  const ensureToastRegion=()=>{
    if(toastRegion)return toastRegion;
    toastRegion=document.createElement('div');
    toastRegion.className='sc-toast-region';
    toastRegion.dataset.toastRegion='';
    toastRegion.setAttribute('aria-live','polite');
    toastRegion.setAttribute('aria-atomic','false');
    document.body.append(toastRegion);
    return toastRegion;
  };
  const toast=(message,{tone='success',title='',duration=null,actionLabel='',onAction=null}={})=>{
    const region=ensureToastRegion(),item=document.createElement('div');
    item.className=`sc-toast sc-toast--${['success','info','warning','danger'].includes(tone)?tone:'info'}`;
    item.setAttribute('role',tone==='danger'?'alert':'status');
    const icon=document.createElement('span');icon.className='sc-toast__icon';icon.setAttribute('aria-hidden','true');icon.textContent=tone==='success'?'✓':tone==='warning'?'!':tone==='danger'?'×':'i';
    const copy=document.createElement('div');copy.className='sc-toast__copy';
    if(title){const strong=document.createElement('strong');strong.textContent=title;copy.append(strong)}
    const text=document.createElement('span');text.textContent=String(message||'');copy.append(text);
    item.append(icon,copy);
    if(actionLabel&&typeof onAction==='function'){
      const action=document.createElement('button');action.type='button';action.className='sc-toast__action';action.textContent=actionLabel;action.addEventListener('click',()=>{onAction();item.remove()});item.append(action);
    }
    const close=document.createElement('button');close.type='button';close.className='sc-toast__close';close.setAttribute('aria-label','بستن اعلان');close.textContent='×';close.addEventListener('click',()=>item.remove());item.append(close);
    region.prepend(item);
    while(region.children.length>3)region.lastElementChild?.remove();
    requestAnimationFrame(()=>item.classList.add('is-visible'));
    const ttl=duration===null?(tone==='danger'?7000:tone==='warning'?5500:4200):Number(duration);if(ttl>0)setTimeout(()=>{item.classList.remove('is-visible');setTimeout(()=>item.remove(),180)},ttl);
    return item;
  };
  addEventListener('sokna:toast',event=>{const d=event.detail||{};toast(d.message||'',d)});
  const open=({title,message='',field=null,actions=[],focus='safe'})=>{
    const d=ensure();
    // Duplicate clicks must never try to open the shared dialog twice. The second
    // request is treated as a cancelled duplicate rather than throwing InvalidStateError.
    if(d.open)return Promise.resolve({value:'cancel',input:''});
    return new Promise(resolve=>{
    const titleEl=d.querySelector('[data-dialog-title]'),msg=d.querySelector('[data-dialog-message]'),fieldBox=d.querySelector('[data-dialog-field]'),actionBox=d.querySelector('[data-dialog-actions]');
    titleEl.textContent=title;msg.textContent=message;msg.hidden=!message;fieldBox.replaceChildren();actionBox.replaceChildren();
    let input=null;
    if(field){const label=document.createElement('label');label.className='sc-field';const span=document.createElement('span');span.className='sc-field__label';span.textContent=field.label||'';input=document.createElement(field.type==='textarea'?'textarea':'input');input.className='sc-control';if(input instanceof HTMLInputElement)input.type=field.type||'text';input.value=field.value||'';input.placeholder=field.placeholder||'';input.dir=field.dir||'auto';if(field.maxLength)input.maxLength=field.maxLength;if(field.readOnly)input.readOnly=true;label.append(span,input);fieldBox.append(label)}
    actions.forEach(a=>actionBox.append(button(a.label,a.value,a.kind)));
    const onClose=()=>{d.removeEventListener('close',onClose);const value=d.returnValue;resolve({value,input:input?.value??''})};d.addEventListener('close',onClose);d.showModal();queueMicrotask(()=>{if(input){input.focus();if(input.readOnly)input.select?.();return}const buttons=[...actionBox.querySelectorAll('button')];const target=focus==='primary'?buttons.at(-1):buttons[0];(target||titleEl).focus()});
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

  const busy=async(target,task)=>{
    if(!(target instanceof HTMLElement)||typeof task!=='function')return await task?.();
    if(target.dataset.soknaBusy==='1')return null;
    target.dataset.soknaBusy='1';target.setAttribute('aria-busy','true');
    const controls=target instanceof HTMLFormElement?[...target.querySelectorAll('button[type=\"submit\"],input[type=\"submit\"]')]:[target];
    const state=controls.map(control=>[control,control.disabled]);controls.forEach(control=>{control.disabled=true});
    try{return await task()}finally{state.forEach(([control,disabled])=>{control.disabled=disabled});target.removeAttribute('aria-busy');delete target.dataset.soknaBusy}
  };
  window.SoknaUI={
    toast,busy,feedback,
    async confirm(title,message='',danger=false){const r=await open({title,message,focus:'safe',actions:[{label:'انصراف',value:'cancel'},{label:'تأیید',value:'ok',kind:danger?'danger':'primary'}]});return r.value==='ok'},
    async prompt(title,{message='',label='توضیح',value='',placeholder='',maxLength=500,required=true,dir='auto'}={}){const r=await open({title,message,field:{label,value,placeholder,maxLength,dir},actions:[{label:'انصراف',value:'cancel'},{label:'تأیید',value:'ok',kind:'primary'}]});if(r.value!=='ok')return null;const v=r.input.trim();return required&&!v?null:v},
    async choose(title,choices,{message=''}={}){const actions=[{label:'انصراف',value:'cancel'},...choices.map(c=>({label:c.label,value:c.value,kind:c.kind||'secondary'}))];const r=await open({title,message,actions});return r.value==='cancel'?null:r.value},
    async reveal(title,value,{message='این مقدار فقط همین‌بار نمایش داده می‌شود.'}={}){const r=await open({title,message,field:{label:'مقدار',value,dir:'ltr',readOnly:true},actions:[{label:'بستن',value:'ok',kind:'primary'}]});return r.value==='ok'}
  };
})();
