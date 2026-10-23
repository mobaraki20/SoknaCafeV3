(()=>{'use strict';
const adapters=new Map();
const norm=v=>String(v||'').trim().toLowerCase();
const appPath=v=>(window.SoknaURL?.path?window.SoknaURL.path(v):(window.SoknaAssets?.path?window.SoknaAssets.path(v):v));
const staffPath=value=>{const raw=String(value??'').trim();if(raw===''||raw.startsWith('#')||/^(?:https?:)?\/\//i.test(raw)||/^(?:mailto|tel|data):/i.test(raw))return appPath(raw);if(host()!=='public'||raw.startsWith('/staff/'))return appPath(raw);const map=[['/operator/','/staff/operator/'],['/waiter/','/staff/preparation/'],['/operations/','/staff/operations/'],['/subscribers/','/staff/subscribers/'],['/reports/','/staff/reports/'],['/notifications/','/staff/notifications/']];for(const [local,remote] of map){if(raw===local.slice(0,-1))return appPath(remote.slice(0,-1));if(raw.startsWith(local))return appPath(remote+raw.slice(local.length))}return raw==='/'?appPath('/staff/'):appPath(raw)};
async function json(url,opt={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json',...(opt.headers||{})},...opt});let j={};try{j=await r.json()}catch{}if(!r.ok||j.success===false||j.ok===false){const e=new Error(j.message||j.error||'عملیات انجام نشد.');e.code=String(j.code||j.error||'request_failed');e.details=j.details&&typeof j.details==='object'?j.details:{};e.httpStatus=Number(r.status||0);throw e}return j}
function register(scope,adapter){const key=norm(scope);if(!key)throw new Error('Staff runtime adapter scope is required.');if(!adapter||typeof adapter!=='object')throw new Error('Staff runtime adapter must be an object.');adapters.set(key,adapter);return adapter}
function adapter(scope){return adapters.get(norm(scope))||adapters.get('*')||null}
function requireAdapter(scope){const a=adapter(scope);if(!a)throw new Error(`Staff runtime adapter is not registered: ${scope}`);return a}
function emitMeta(model,meta={}){document.dispatchEvent(new CustomEvent('sokna:staff-read-meta',{detail:{model:String(model||''),...meta}}))}
function updateConnectivity(meta={}){const status=document.querySelector('[data-public-connectivity]');if(!status)return;if(meta.stale){status.className='sc-alert sc-alert--warning sc-public-connectivity';status.textContent='اطلاعات همگام‌شده تازه نیست؛ عملیات حساس تا بازیابی اتصال متوقف است.';return}if(status.dataset.connectivityState)return;status.className='sc-alert sc-alert--warning sc-public-connectivity';status.textContent='اطلاعات همگام‌شده تازه است؛ وضعیت مسیر زنده در حال تأیید است.';}
document.addEventListener('sokna:staff-read-meta',e=>{if(e.detail)updateConnectivity(e.detail)});
function host(){return String(document.body?.dataset.staffUiMode||'local')}
window.SoknaStaffRuntime=Object.freeze({register,adapter,requireAdapter,json,appPath,staffPath,emitMeta,host,scopes:()=>[...adapters.keys()]});
})();
