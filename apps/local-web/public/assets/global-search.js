(()=>{'use strict';
const root=document.querySelector('[data-global-search]');if(!root)return;
const input=root.querySelector('[data-global-search-input]'),results=root.querySelector('[data-global-search-results]'),api=root.dataset.api||'/search/api.php';
let timer=null,controller=null,last='';
const el=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node};
function close(){results.hidden=true;results.replaceChildren();input.setAttribute('aria-expanded','false')}
function state(text){results.replaceChildren(el('div','sc-command-palette__state',text));results.hidden=false;input.setAttribute('aria-expanded','true')}
function render(rows){results.replaceChildren();if(!rows.length){state('نتیجه‌ای پیدا نشد.');return}rows.forEach(row=>{const card=el('article','sc-command-result');card.setAttribute('role','option');const head=el('div','sc-command-result__head'),copy=el('div','sc-command-result__copy');copy.append(el('strong','',row.title||''));if(row.subtitle)copy.append(el('small','',row.subtitle));head.append(copy,el('span','sc-badge sc-command-result__context',row.context||'نتیجه'));card.append(head);const actions=el('div','sc-command-result__actions');(row.actions||[]).forEach(action=>{const a=el('a','sc-button sc-button--secondary',action.label||'بازکردن');a.href=action.href||'#';actions.append(a)});if(actions.childElementCount)card.append(actions);results.append(card)});results.hidden=false;input.setAttribute('aria-expanded','true')}
async function search(){const q=input.value.trim();if(q===last&& !results.hidden)return;last=q;if(controller)controller.abort();if([...q].length<2){close();return}controller=new AbortController();state('در حال جست‌وجو…');try{const r=await fetch(`${api}?q=${encodeURIComponent(q)}`,{cache:'no-store',signal:controller.signal});const j=await r.json();if(!r.ok||j.success===false)throw new Error(j.message||'جست‌وجو انجام نشد.');render(j.search?.results||[])}catch(e){if(e.name==='AbortError')return;state(e.message||'جست‌وجو انجام نشد.')}}
input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(search,260)});
input.addEventListener('focus',()=>{if(input.value.trim().length>=2)search()});
input.addEventListener('keydown',e=>{if(e.key==='Escape'){close();input.blur();}});
document.addEventListener('keydown',e=>{const target=e.target;if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();input.focus();input.select();return}if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey&&!(target instanceof HTMLInputElement)&&!(target instanceof HTMLTextAreaElement)&&!(target instanceof HTMLSelectElement)){e.preventDefault();input.focus();}});
document.addEventListener('click',e=>{if(!root.contains(e.target))close()});
})();
