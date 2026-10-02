(()=>{'use strict';
const root=document.querySelector('[data-staff-order-route]');if(!root||!window.SoknaOrderWorkspace)return;
const appPath=v=>(window.SoknaURL?.path?window.SoknaURL.path(v):v);
const params=new URLSearchParams(location.search);const tableId=Number(params.get('table_id')||0);const returnUrl=params.get('return')||'';
const safeReturn=()=>returnUrl.startsWith('/')&&!returnUrl.startsWith('//')?appPath(returnUrl):'';
const goBack=({tableId:id=0}={})=>{const target=safeReturn();if(target){const u=new URL(target,location.origin);if(id)u.searchParams.set('table_id',String(id));location.href=u.pathname+u.search+u.hash;return}if(history.length>1)history.back();else location.href=appPath('/operator/')};
window.SoknaOrderWorkspace.mount(root.querySelector('[data-order-workspace-host]'),{
  api:root.dataset.api||appPath('/staff/api.php'),csrf:root.dataset.csrf||'',tableId,
  onExit:goBack,
  onFinalized:async({tableId:id})=>{const target=safeReturn();if(target){const u=new URL(target,location.origin);u.searchParams.set('table_id',String(id));location.href=u.pathname+u.search+u.hash;return}location.href=appPath(`/operator/?table_id=${encodeURIComponent(id)}`)}
});
})();
