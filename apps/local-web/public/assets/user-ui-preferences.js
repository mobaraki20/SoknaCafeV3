(()=>{'use strict';
const cfg=window.__SOKNA_UI_PREFERENCES__;if(!cfg||!cfg.endpoint)return;
const keys={theme:'sokna.theme',nav_compact:'sokna.nav.compact.v1',nav_groups:'sokna.nav.groups.v1',hall_sort:'sokna.hall.sort',hall_density:'sokna.hall.density'};
const valid={theme:v=>['light','dark','system'].includes(v),nav_compact:v=>typeof v==='boolean',nav_groups:v=>Array.isArray(v)&&v.every(x=>['daily','operations','guest','management'].includes(String(x))),hall_sort:v=>['number','time'].includes(v),hall_density:v=>['normal','compact','overview'].includes(v)};
const fromStore=key=>{try{const raw=localStorage.getItem(keys[key]);if(raw==null)return undefined;if(key==='nav_compact')return raw==='1';if(key==='nav_groups'){const v=JSON.parse(raw);return valid[key](v)?v:undefined}return valid[key](raw)?raw:undefined}catch{return undefined}};
const canonical=(cfg.values&&typeof cfg.values==='object')?cfg.values:{};const seed={};for(const key of Object.keys(keys)){if(Object.prototype.hasOwnProperty.call(canonical,key))continue;const v=fromStore(key);if(v!==undefined)seed[key]=v}
let queue={},timer=0,inflight=false;
const flush=async()=>{if(inflight||!Object.keys(queue).length)return;const patch=queue;queue={};inflight=true;try{const body={preferences:patch};if(cfg.csrf)body.csrf_token=cfg.csrf;const r=await fetch(cfg.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});if(!r.ok)throw new Error('preference_save_failed');const j=await r.json();if(j?.preferences&&typeof j.preferences==='object')cfg.values=j.preferences}catch(_){queue={...patch,...queue}}finally{inflight=false;if(Object.keys(queue).length)timer=setTimeout(flush,1200)}};
const schedule=patch=>{queue={...queue,...patch};clearTimeout(timer);timer=setTimeout(flush,250)};
if(Object.keys(seed).length)schedule(seed);
document.addEventListener('sokna:ui-preference-intent',e=>{const d=e.detail||{},key=String(d.key||'');if(!valid[key]||!valid[key](d.value))return;schedule({[key]:d.value})});
addEventListener('pagehide',()=>{if(!Object.keys(queue).length)return;const body={preferences:queue};if(cfg.csrf)body.csrf_token=cfg.csrf;try{navigator.sendBeacon?.(cfg.endpoint,new Blob([JSON.stringify(body)],{type:'application/json'}))}catch(_){}});
})();
