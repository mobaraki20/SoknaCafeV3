(()=>{'use strict';
const R=window.SoknaStaffRuntime||null,body=document.body,base=(body?.dataset.appBase||body?.dataset.basePath||'').replace(/\/$/,'');
const u=p=>base+'/'+String(p||'').replace(/^\//,'');
const browserBase=()=>{const href=typeof location==='object'&&location&&typeof location.href==='string'?String(location.href):'';if(href&&href!=='null'&&!href.startsWith('null:'))return href;const docBase=typeof document==='object'&&document&&typeof document.baseURI==='string'?String(document.baseURI):'';if(docBase&&docBase!=='null'&&!docBase.startsWith('null:'))return docBase;throw Object.assign(new Error('Browser origin is unavailable.'),{code:'browser_origin_unavailable'});};
const absolute=v=>new URL(String(v||''),browserBase());
const uid=(p='staff')=>`${p}-${Date.now().toString(36)}-${Math.random().toString(16).slice(2,10)}`;
const now=()=>window.SoknaLocale.nowInstant(),later=(s=120)=>new Date(Date.parse(window.SoknaLocale.nowInstant())+s*1000).toISOString(),sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const TEST=window.__SOKNA_STAFF_TRANSPORT_TEST__||{},OFFLINE_LAN_BOOTSTRAP=body?.dataset.lanOfflineBootstrap==='1',REQUEST_TIMEOUT_MS=Number(TEST.requestTimeoutMs||7000),RESULT_TIMEOUT_MS=Number(TEST.resultTimeoutMs||4500),WORKFLOW_DEADLINE_MS=Number(TEST.workflowDeadlineMs||12000),POLL_INTERVAL_MS=Number(TEST.pollIntervalMs||350),LEDGER_TTL_MS=Number(TEST.ledgerTtlMs||7*24*60*60*1000),LEDGER_MAX=Number(TEST.ledgerMax||100),WATCH_TIMEOUT_MS=Number(TEST.watchTimeoutMs||10500),VISIBILITY_POLL_MS=Number(TEST.visibilityPollMs||1200),LAN_READY_READ_PREFERENCE_MS=Number(TEST.lanReadyReadPreferenceMs||140),LAN_READY_MUTATION_PREFERENCE_MS=Number(TEST.lanReadyMutationPreferenceMs||100);
let stale=true,lastSync='',publicCommandReady=false;
function commandReadyFromConnectivity(connectivity){const c=connectivity&&typeof connectivity==='object'?connectivity:{};return c.local_fresh===true&&c.active!==false&&c.remote_enabled!==false}
function setPublicCommandReady(value,detail=''){const next=value===true;if(publicCommandReady===next)return;publicCommandReady=next;document.dispatchEvent(new CustomEvent('sokna:staff-mutation-readiness',{detail:{ready:liveMutationReady(),public_ready:publicCommandReady,lan_ready:lanState==='ready',detail:String(detail||'')}}))}
const lanToken=String(body?.dataset.lanToken||''),lanTokenExpiresAt=String(body?.dataset.lanTokenExpiresAt||'');
function normalizedLanBase(value){try{const x=new URL(String(value||''),browserBase());if(x.protocol!=='https:'||x.username||x.password||x.search||x.hash)return '';const current=new URL(browserBase());if(x.origin===current.origin)return '';x.pathname=x.pathname.replace(/\/+$/,'');return x.toString().replace(/\/$/,'')}catch{return ''}}
const lanBase=normalizedLanBase(body?.dataset.lanBaseUrl||'');
let lanState=lanBase?(body?.dataset.lanPrevalidated==='1'?'ready':'unknown'):'disabled',lanDisabledUntil=0,lanProbePromise=null,lanRealtimeKinds=null,lanFailureStreak=0,lanLastLatency=0,lanLastFailure='',lanLastBackoff=0;
function lanConfigured(){if(!lanBase||!lanToken)return false;const exp=Date.parse(lanTokenExpiresAt||'');return !Number.isFinite(exp)||exp>Date.now()+30000}
function lanEndpoint(action,params={}){const x=new URL('lan-staff/api.php',lanBase.replace(/\/?$/,'/') );x.searchParams.set('action',action);Object.entries(params).forEach(([k,v])=>x.searchParams.set(k,String(v)));return x.toString()}
function lanDiagnostics(){let origin='';try{origin=lanBase?new URL(lanBase).origin:''}catch{}return {state:lanState,configured:lanConfigured(),origin,latency_ms:lanLastLatency||0,failure_streak:lanFailureStreak,last_failure:lanLastFailure||'',backoff_ms:lanLastBackoff||0,retry_at:lanDisabledUntil?new Date(lanDisabledUntil).toISOString():''}}
function setLanState(state,detail=''){lanState=state;document.dispatchEvent(new CustomEvent('sokna:lan-transport',{detail:{...lanDiagnostics(),detail:String(detail||'')}}));document.dispatchEvent(new CustomEvent('sokna:staff-mutation-readiness',{detail:{ready:liveMutationReady(),public_ready:publicCommandReady,lan_ready:lanState==='ready',detail:String(detail||'')}}))}
function nextLanBackoff(minimumMs=0){const exp=Math.min(60000,2000*(2**Math.min(5,Math.max(0,lanFailureStreak-1)))),jitter=Math.floor(exp*(0.15+Math.random()*0.2));return Math.max(Number(minimumMs||0),Math.min(60000,exp+jitter))}
function suspendLan(detail='unreachable',minimumMs=0){lanFailureStreak=Math.min(8,lanFailureStreak+1);lanLastFailure=String(detail||'unreachable');if(detail==='unauthorized')lanRealtimeKinds=null;const delay=nextLanBackoff(detail==='unauthorized'?Math.max(60000,minimumMs):minimumMs);lanLastBackoff=delay;lanDisabledUntil=Date.now()+delay;setLanState(detail==='unauthorized'?'auth_invalid':'unavailable',detail)}
function resetLanCircuit(reason='network_change'){lanFailureStreak=0;lanLastFailure='';lanLastBackoff=0;lanDisabledUntil=0;if(lanConfigured()&&lanState!=='ready')setLanState('unknown',reason)}
function lanSupportsRealtime(kind){return lanRealtimeKinds===null||lanRealtimeKinds.has(String(kind||''))}
async function lanJson(action,{method='GET',params={},body:payload=null,timeoutMs=1100}={}){
 if(!lanConfigured())throw requestError('lan_not_configured');const controller=new AbortController(),timer=setTimeout(()=>controller.abort('deadline'),Math.max(250,timeoutMs));
 try{let r;try{r=await fetch(lanEndpoint(action,params),{method,mode:'cors',credentials:'omit',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-Sokna-Lan-Token':lanToken},body:payload===null?undefined:JSON.stringify(payload),signal:controller.signal})}catch(err){const e=requestError(controller.signal.aborted?'request_timeout':'lan_unreachable');e.unknown_outcome=method!=='GET';throw e}let j={};try{j=await r.json()}catch{const e=requestError('lan_invalid_response');e.unknown_outcome=method!=='GET';throw e}if(!r.ok||j.ok===false){const e=requestError(String(j.error||'lan_failed'));e.http_status=r.status;e.unknown_outcome=method!=='GET'&&(r.status>=500||[408,425,429].includes(r.status));throw e}return j}
 finally{clearTimeout(timer)}
}
async function probeLan(force=false){
 if(!lanConfigured())return false;if(!force&&lanState==='ready')return true;if(!force&&Date.now()<lanDisabledUntil)return false;if(lanProbePromise)return lanProbePromise;
 lanProbePromise=(async()=>{const started=performance.now?performance.now():Date.now();try{const j=await lanJson('health',{timeoutMs:700});if(String(j.projection_id||actorScope)!==actorScope)throw requestError('lan_identity_mismatch');const kinds=j?.transport?.realtime_kinds;lanRealtimeKinds=Array.isArray(kinds)?new Set(kinds.map(String)):null;lanLastLatency=Math.max(0,Math.round((performance.now?performance.now():Date.now())-started));lanFailureStreak=0;lanLastFailure='';lanLastBackoff=0;lanDisabledUntil=0;setLanState('ready','health_ok');return true}catch(e){lanLastLatency=Math.max(0,Math.round((performance.now?performance.now():Date.now())-started));suspendLan(Number(e?.http_status)===401?'unauthorized':String(e?.code||'unreachable'),Number(e?.http_status)===401?60000:0);return false}finally{lanProbePromise=null}})();return lanProbePromise;
}
function kickLanProbe(force=false){if(!lanConfigured())return;void probeLan(force).then(ok=>{if(ok)document.dispatchEvent(new CustomEvent('sokna:lan-ready'))}).catch(()=>{})}
function liveMutationReady(){return publicCommandReady||lanState==='ready'}
function humanError(code){return ({local_unavailable:'ارتباط زنده با سامانه محلی برقرار نیست؛ عملیات حساس موقتاً غیرفعال است.',stale_projection:'نسخه اطلاعات این عملیات با وضعیت فعلی هم‌خوان نیست؛ اطلاعات را تازه کنید و دوباره بررسی کنید.',forbidden:'برای انجام این کار دسترسی ندارید.',session_changed:'جلسه میز تغییر کرده است؛ اطلاعات را تازه کنید.',version_conflict:'اطلاعات از زمان آخرین مشاهده تغییر کرده است؛ دوباره بارگذاری کنید.',prices_changed:'قیمت یکی از آیتم‌ها تغییر کرده است؛ اطلاعات را تازه کنید.',request_pending:'درخواست ثبت شده یا نتیجه آن هنوز نامشخص است؛ وضعیت همان درخواست دوباره بررسی می‌شود.',request_timeout:'پاسخ شبکه در مهلت تعیین‌شده نرسید؛ نتیجه درخواست دوباره بررسی می‌شود.'}[String(code||'')]||'عملیات انجام نشد؛ دوباره تلاش کنید.');}
function requestError(code,message=''){const e=new Error(message||humanError(code));e.code=String(code||'request_failed');return e;}
async function json(url,opt={}){
 const cfg={...opt},timeoutMs=Number(cfg.timeoutMs||REQUEST_TIMEOUT_MS),externalSignal=cfg.signal||null,method=String(cfg.method||'GET').toUpperCase(),mutating=!['GET','HEAD','OPTIONS'].includes(method);delete cfg.timeoutMs;delete cfg.signal;
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort('deadline'),Math.max(250,timeoutMs));let abortForward=null;
 if(externalSignal){abortForward=()=>controller.abort(externalSignal.reason||'cancelled');if(externalSignal.aborted)abortForward();else externalSignal.addEventListener('abort',abortForward,{once:true});}
 try{
  let r;try{r=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json',...(cfg.headers||{})},...cfg,signal:controller.signal})}
  catch(err){if(controller.signal.aborted){const e=requestError('request_timeout');e.unknown_outcome=mutating;throw e}const m=window.SoknaUI?.errorText?.(err,'ارتباط با وب عمومی برقرار نشد. دوباره تلاش کنید.')||'ارتباط با وب عمومی برقرار نشد. دوباره تلاش کنید.';const e=requestError('request_failed',m);e.unknown_outcome=mutating;throw e}
  let j={};try{j=await r.json()}catch{const timedOut=controller.signal.aborted,e=requestError(timedOut?'request_timeout':'invalid_response',timedOut?humanError('request_timeout'):'پاسخ وب عمومی قابل خواندن نیست. نتیجه درخواست باید با همان شناسه پیگیری شود.');e.http_status=r.status;e.unknown_outcome=mutating;throw e}
  if(r.status===401){location.href=u('/staff/login');throw requestError('unauthorized','نشست شما پایان یافته است.')}
  if(!r.ok||j.ok===false||j.success===false){const code=String(j.error||j.code||'request_failed'),e=requestError(code,String(j.message||humanError(code)));e.details=j.details||{};e.http_status=r.status;e.unknown_outcome=mutating&&(r.status>=500||[408,425,429].includes(r.status));throw e}
  return j;
 }finally{clearTimeout(timer);if(externalSignal&&abortForward)externalSignal.removeEventListener('abort',abortForward);}
}
function stableValue(v){if(Array.isArray(v))return v.map(stableValue);if(v&&typeof v==='object'){const out={};Object.keys(v).sort().forEach(k=>{out[k]=stableValue(v[k])});return out}return v}
const actorScope=String(body?.dataset.projectionId||'unknown'),ledgerKey=`sokna:staff-command-ledger:v1:${base||'/'}:${actorScope}`;
// Remember when a write fell back to sessionStorage; otherwise a stale
// localStorage entry wins every subsequent read and can resurrect old request IDs.
const storageFallbackMarker=key=>`sokna:staff-storage-fallback:v1:${key}`;
function storageSet(key,value){
 try{localStorage.setItem(key,value);try{sessionStorage.removeItem(key);sessionStorage.removeItem(storageFallbackMarker(key))}catch{}return true}catch{}
 try{sessionStorage.setItem(key,value);sessionStorage.setItem(storageFallbackMarker(key),'1');return true}
 catch{try{sessionStorage.removeItem(key);sessionStorage.removeItem(storageFallbackMarker(key))}catch{}return false}
}
function storageGet(key){
 try{if(sessionStorage.getItem(storageFallbackMarker(key))==='1')return sessionStorage.getItem(key)}catch{}
 try{const v=localStorage.getItem(key);if(v!==null)return v}catch{}
 try{return sessionStorage.getItem(key)}catch{return null}
}
function storageRemove(key){try{localStorage.removeItem(key)}catch{}try{sessionStorage.removeItem(key);sessionStorage.removeItem(storageFallbackMarker(key))}catch{}}
function readLedger(){try{const raw=JSON.parse(storageGet(ledgerKey)||'[]'),t=Date.now();return Array.isArray(raw)?raw.filter(x=>x&&Number(x.keep_until||0)>t):[]}catch{return []}}
function writeLedger(rows){const value=JSON.stringify(rows);if(!rows.length){storageRemove(ledgerKey);return true}return storageSet(ledgerKey,value)}
function hashText128(value){const seeds=[2166136261,3339675911,2246822519,3266489917],parts=seeds.map(seed=>{let h=seed>>>0;for(let i=0;i<value.length;i++){h^=value.charCodeAt(i);h=Math.imul(h,16777619)}return (h>>>0).toString(16).padStart(8,'0')});return parts.join('')}
function commandFingerprint(kind,payload){return hashText128(JSON.stringify(stableValue({kind,payload})))}
function pendingCommand(kind,payload){const fp=commandFingerprint(kind,payload),rows=readLedger(),row=[...rows].reverse().find(x=>x.fingerprint===fp);return {fingerprint:fp,row,rows}}
// A pending finalize fences the whole table, not just an identical cart fingerprint.
function pendingFinalizationForTable(tableId){const id=Number(tableId||0);if(id<0)return null;return readLedger().find(row=>row.kind==='table_draft.finalize_atomic'&&(Number(row.table_id||0)===id||Number(row.table_id||0)===0))||null;}
function pendingSettlementForSession(sessionId){const id=Number(sessionId||0);return readLedger().find(row=>row.kind==='settlement.commit'&&(Number(row.session_id||0)===id||!Number(row.session_id||0)))||null;}
const activeCommands=new Set();
// Do not mistake an unreadable/corrupt durable command ledger for an empty one.
// A malformed or still-live entry fences reload until the operator can reconcile.
function hasUnresolvedMutations(){
 if(activeCommands.size>0)return true;
 try{
  const raw=storageGet(ledgerKey);
  if(raw===null)return false;
  const rows=JSON.parse(raw);
  return !Array.isArray(rows)||rows.some(x=>!x||typeof x!=='object'||!Number.isFinite(Number(x.keep_until))||Number(x.keep_until)>Date.now());
 }catch{return true}
}
function rememberCommand(kind,payload){const found=pendingCommand(kind,payload);if(found.row)return {record:found.row,reused:true};if(found.rows.length>=LEDGER_MAX){const e=requestError('command_recovery_ledger_full','چند درخواست قبلی هنوز تعیین تکلیف نشده‌اند؛ برای جلوگیری از ثبت تکراری، عملیات تازه موقتاً متوقف شد.');e.blocked_before_send=true;throw e}const row={request_id:uid(kind.replace(/[^a-z0-9]+/ig,'-')),kind,session_id:Number(payload?.session_id||payload?.expected_session_id||0),table_id:kind==='table_draft.finalize_atomic'?Number(payload?.table_id||0):0,fingerprint:found.fingerprint,created_at:now(),expires_at:later(180),keep_until:Date.now()+LEDGER_TTL_MS};found.rows.push(row);if(!writeLedger(found.rows)){const e=requestError('command_identity_storage_unavailable','شناسه پایدار این عملیات روی دستگاه قابل ذخیره نیست؛ برای جلوگیری از ارسال تکراری عملیات انجام نشد.');e.blocked_before_send=true;throw e}return {record:row,reused:false}}
function forgetCommand(requestId){writeLedger(readLedger().filter(x=>String(x.request_id)!==String(requestId)))}
function notifyCommandSettled(row,result=null,error=null){const detail={request_id:String(row.request_id),kind:row.kind,session_id:Number(row.session_id||0),table_id:Number(row.table_id||0),result,error_code:error?.code||'',error_message:error?.message||'',handled:false};document.dispatchEvent(new CustomEvent('sokna:staff-command-settled',{detail}));if(row.kind==='settlement.commit'&&!detail.handled){window.SoknaUI?.toast?.(result?'نتیجهٔ پرداخت قبلی دریافت شد؛ تسویه ثبت شده است.':detail.error_message||'نتیجهٔ پرداخت قبلی دریافت شد؛ تسویه ثبت نشد.',{tone:result?'success':'warning'});}}

const reconciliations=new Map();
async function reconcileCommand(row){
 if(!row)return {state:'none'};const id=String(row.request_id);if(activeCommands.has(id))return {state:'pending',request_id:id};if(reconciliations.has(id))return reconciliations.get(id);
 const task=(async()=>{try{const result=await readCommandResult(id,'',Date.now()+Math.min(RESULT_TIMEOUT_MS,4000),String(row.route||'public'));if(result){notifyCommandSettled(row,result,null);refreshProjectedSurface();return {state:'committed',result}}return {state:'pending',request_id:id}}catch(e){
 if(readLedger().some(r=>String(r.request_id)===id))return {state:'pending',request_id:id};
 notifyCommandSettled(row,null,e);return {state:'rejected',request_id:id,error_code:String(e?.code||'request_failed')};
 }} )().finally(()=>reconciliations.delete(id));reconciliations.set(id,task);return task;
}
async function reconcileFinalization(tableId){return reconcileCommand(pendingFinalizationForTable(tableId));}
// Result recovery only issues GETs. Unknown settlement must never become a second payment POST.
let recoveryRunning=false;const recoverySchedule=new Map();
async function recoverPendingCommands(){
 if(recoveryRunning||document.hidden)return;recoveryRunning=true;
 try{const rows=readLedger().filter(r=>['table_draft.finalize_atomic','settlement.commit'].includes(r.kind)&&!activeCommands.has(String(r.request_id)));
 const live=new Set(rows.map(r=>String(r.request_id)));for(const id of recoverySchedule.keys())if(!live.has(id))recoverySchedule.delete(id);
 const due=rows.filter(r=>(recoverySchedule.get(String(r.request_id))?.next||0)<=Date.now()).sort((a,b)=>(recoverySchedule.get(String(a.request_id))?.last||0)-(recoverySchedule.get(String(b.request_id))?.last||0));
 const row=due[0];if(!row)return;const id=String(row.request_id),previous=recoverySchedule.get(id)||{failures:0};
 const result=await reconcileCommand(row);if(result.state==='pending'){const failures=Math.min(5,previous.failures+1);recoverySchedule.set(id,{failures,last:Date.now(),next:Date.now()+Math.min(30000,2000*(2**(failures-1)))});}else recoverySchedule.delete(id);
 }finally{recoveryRunning=false;}
}
setInterval(()=>{void recoverPendingCommands()},5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)void recoverPendingCommands()});window.addEventListener('online',()=>{void recoverPendingCommands()});queueMicrotask(()=>{void recoverPendingCommands()});
function markCommandRoute(requestId,route){const rows=readLedger(),row=rows.find(x=>String(x.request_id)===String(requestId));if(!row)return String(route||'');row.route=String(route||'');writeLedger(rows);return row.route;}
function traceResult(r,requestId,initialTrace=''){const traceId=String(r?.trace_id||initialTrace||'');if(traceId)document.dispatchEvent(new CustomEvent('sokna:trace',{detail:{trace_id:traceId,request_id:requestId,state:String(r?.state||''),trace:r?.trace||null}}));return traceId}
const committedTables=new Map();
function rememberCommittedTables(result){
 const patch=result?.committed_table_states,seq=Number(patch?.sequence||0);
 if(patch?.format!=='sokna-committed-table-states-v1'||!Number.isSafeInteger(seq)||seq<1||!Array.isArray(patch.rows)||patch.rows.length>8)return;
 for(const row of patch.rows){const id=Number(row?.id||0);if(!Number.isSafeInteger(id)||id<1)continue;const old=committedTables.get(id);if(!old||seq>old.sequence)committedTables.set(id,{sequence:seq,row:{...row},until:Date.now()+120000});}
}
function committedHall(h,j){
 const cursor=Number(j?.freshness?.public_cursor_sequence||0),rows=new Map((h.tables||[]).map(row=>[Number(row.id),row]));let pending=false;
 for(const [id,patch] of committedTables){if(cursor>=patch.sequence||Date.now()>patch.until){committedTables.delete(id);continue;}if(rows.has(id)){rows.set(id,{...rows.get(id),...patch.row});pending=true;}}
 if(!pending)return h;
 const tables=[...rows.values()];return {...h,tables,metrics:{...(h.metrics||{}),open_tables:tables.filter(t=>t.occupancy_kind!=='free').length,draft_tables:tables.filter(t=>t.has_draft).length},committed_display_pending:true};
}
function committedResult(r,requestId,initialTrace=''){rememberCommittedTables(r?.result);refreshProjectedSurface();const traceId=traceResult(r,requestId,initialTrace),pending=String(r?.visibility?.state||'not_required')==='pending';if(pending){window.SoknaUI?.toast?.('عملیات در کافه ثبت شد؛ نمایش عمومی در پس‌زمینه تازه می‌شود.','warning','همگام‌سازی');document.dispatchEvent(new CustomEvent('sokna:public-visibility-pending',{detail:{request_id:requestId,visibility:r.visibility||null}}));trackVisibility(requestId,traceId)}return {...(r?.result||{success:true}),_visibility:r?.visibility||{state:'not_required',visible:true},_visibility_pending:pending,_trace_id:traceId,_trace:r?.trace||null};}
async function trackVisibility(requestId,initialTrace=''){const until=Date.now()+60000;while(Date.now()<until){await sleep(VISIBILITY_POLL_MS);try{const remaining=until-Date.now();if(remaining<=0)return;const r=await json(u('/api/staff/realtime/result')+'?request_id='+encodeURIComponent(requestId),{timeoutMs:Math.min(RESULT_TIMEOUT_MS,remaining)});traceResult(r,requestId,initialTrace);if(String(r?.visibility?.state||'not_required')!=='pending'){document.dispatchEvent(new CustomEvent('sokna:public-visibility-settled',{detail:{request_id:requestId,visibility:r.visibility||null}}));refreshProjectedSurface();return}}catch(e){if(['forbidden','unauthorized','not_found'].includes(String(e?.code||'')))return}}}
async function readCommandResult(requestId,initialTrace='',deadlineAt=Date.now()+WORKFLOW_DEADLINE_MS,routeHint=''){while(Date.now()<deadlineAt){const remaining=deadlineAt-Date.now();if(remaining<=0)break;
 if(lanState==='ready'&&routeHint!=='public'){try{const lr=await lanJson('realtime_result',{params:{request_id:requestId},timeoutMs:Math.min(900,remaining)});const state=String(lr.state||'');if(['committed','rejected','needs_review','unknown_review','expired','cancelled'].includes(state)){if(state==='committed'){forgetCommand(requestId);refreshProjectedSurface();return {...(lr.result||{success:true}),_transport:'lan',_trace_id:initialTrace}}if(state==='unknown_review'||state==='needs_review'){const e=requestError('request_pending','نتیجه عملیات نامطمئن است و نیاز به بررسی مسئول دارد؛ دوباره ارسال نکنید.');e.unknown_outcome=true;e.request_id=requestId;e.terminal_result=true;throw e;}if(state==='rejected'||state==='expired'||state==='cancelled'){forgetCommand(requestId);const e=requestError(String(lr.error_code||'request_failed'),String(lr.result?.message||humanError(lr.error_code)));e.details=lr.result?.details||{};e.terminal_result=true;throw e}}}catch(e){if(e?.terminal_result===true)throw e;if(!['not_found','request_timeout','request_failed','lan_failed','lan_invalid_response'].includes(String(e?.code||''))){if(Number(e?.http_status)===401)suspendLan('unauthorized',60000)}}}
 if(!OFFLINE_LAN_BOOTSTRAP){try{const r=await json(u('/api/staff/realtime/result')+'?request_id='+encodeURIComponent(requestId),{timeoutMs:Math.min(RESULT_TIMEOUT_MS,remaining)});traceResult(r,requestId,initialTrace);const state=String(r.state||'');if(r.terminal||['committed','rejected','needs_review','unknown_review','expired','cancelled'].includes(state)){if(state==='committed'){forgetCommand(requestId);return committedResult(r,requestId,initialTrace)}if(state==='unknown_review'||state==='needs_review'||!['rejected','expired','cancelled'].includes(state)){const e=requestError('request_pending','نتیجه درخواست نیاز به بررسی دارد؛ ثبت مجدد همان سفارش ممنوع است.');e.unknown_outcome=true;e.request_id=requestId;throw e;}forgetCommand(requestId);const code=String(r.error_code||'request_failed'),e=requestError(code,String(r.result?.message||humanError(code)));e.details=r.result?.details||{};throw e}}catch(e){if(String(e?.code||'')!=='not_found'&&String(e?.code||'')!=='request_timeout'&&String(e?.code||'')!=='request_failed')throw e}}const after=deadlineAt-Date.now();if(after<=0)break;await sleep(Math.min(POLL_INTERVAL_MS,after))}return null}
async function publicModelRaw(key,signal=null){return json(u('/api/staff/read')+'?model='+encodeURIComponent(key),{timeoutMs:9000,signal});}
function acceptPublicModel(key,j){stale=!!j.stale;lastSync=String(j.last_sync_at||'');setPublicCommandReady(commandReadyFromConnectivity(j.connectivity),'public_model');document.dispatchEvent(new CustomEvent('sokna:staff-read-meta',{detail:{model:key,stale,last_sync_at:lastSync,connectivity:j.connectivity||null,transport:'public',mutation_ready:liveMutationReady()}}));return j;}
async function publicModel(key,signal=null){return acceptPublicModel(key,await publicModelRaw(key,signal));}
async function lanModelRaw(key){return lanJson('read',{params:{model:key},timeoutMs:1200});}
function acceptLanModel(key,j){stale=false;lastSync=String(j.last_sync_at||j.generated_at||now());document.dispatchEvent(new CustomEvent('sokna:staff-read-meta',{detail:{model:key,stale:false,last_sync_at:lastSync,connectivity:j.connectivity||{local_fresh:true,mode:'local_lan'},transport:'lan',mutation_ready:true}}));return j;}
async function lanModel(key){return acceptLanModel(key,await lanModelRaw(key));}
async function raceModelRoute(key,{preferLanMs=0}={}){
 const startedAt=Date.now(),publicAbort=new AbortController();
 const publicAttempt=publicModelRaw(key,publicAbort.signal).then(value=>({route:'public',ok:true,value}),error=>({route:'public',ok:false,error}));
 const lanAttempt=(async()=>{if(lanState!=='ready'){const ok=await probeLan(false);if(!ok)throw requestError('lan_unreachable')}return lanModelRaw(key)})().then(value=>({route:'lan',ok:true,value}),error=>{if(lanState==='ready')suspendLan(Number(error?.http_status)===401?'unauthorized':String(error?.code||'read_failed'),Number(error?.http_status)===401?60000:15000);return {route:'lan',ok:false,error}});
 const accept=result=>result.route==='lan'?{route:'lan',value:acceptLanModel(key,result.value)}:{route:'public',value:acceptPublicModel(key,result.value)};
 const first=await Promise.race([publicAttempt,lanAttempt]);
 if(first.ok&&first.route==='lan'){publicAbort.abort('lan_won');return accept(first)}
 if(first.ok&&first.route==='public'){
  const elapsed=Date.now()-startedAt,graceMs=first.value?.stale===true?Math.max(0,900-elapsed):Math.max(0,Number(preferLanMs||0)-elapsed);
  if(graceMs>0){const grace=await Promise.race([lanAttempt,sleep(graceMs).then(()=>null)]);if(grace?.ok){publicAbort.abort(first.value?.stale===true?'lan_won_after_stale_public':'lan_won_with_ready_preference');return accept(grace)}}
  return accept(first);
 }
 const second=await (first.route==='public'?lanAttempt:publicAttempt);if(second.ok){if(second.route==='lan')publicAbort.abort('lan_won_after_public_failure');return accept(second)}throw (first.route==='public'?first.error:second.error);
}
async function model(key){
 if(OFFLINE_LAN_BOOTSTRAP){if(lanState==='ready'){try{return await lanModel(key)}catch(e){suspendLan(Number(e?.http_status)===401?'unauthorized':String(e?.code||'read_failed'),Number(e?.http_status)===401?60000:15000)}}const ok=await probeLan(true);if(ok)return model(key);throw requestError('local_unavailable','Local از این شبکه در دسترس نیست؛ در حالت بدون اینترنت fallback عمومی ممکن نیست.')}
 if(lanConfigured()&&(lanState==='unknown'||lanState==='ready')&&Date.now()>=lanDisabledUntil){const raced=await raceModelRoute(key,{preferLanMs:lanState==='ready'?LAN_READY_READ_PREFERENCE_MS:0});return raced.value}
 kickLanProbe(false);return publicModel(key);
}
async function realtime(kind,payload={}){
 if(kind==='settlement.commit'){
  const pending=pendingSettlementForSession(payload.session_id||payload.expected_session_id);
  if(pending){const recovered=await reconcileCommand(pending);if(recovered.state==='committed')return recovered.result;if(recovered.state==='rejected')throw requestError(recovered.error_code||'request_failed');
   const e=requestError('request_pending','نتیجه تسویه قبلی در حال بررسی است؛ پرداخت تازه ارسال نشده است.');e.request_id=pending.request_id;e.unknown_outcome=true;e.blocked_before_send=true;throw e;}
 }

 // Never issue another finalization for a table while a prior one is unresolved,
 // even if the operator edited the cart in the meantime.
 if(kind==='table_draft.finalize_atomic'&&pendingFinalizationForTable(payload?.table_id)){const pending=pendingFinalizationForTable(payload?.table_id);const result=await reconcileFinalization(payload.table_id);if(result.state==='committed')return result.result;const e=requestError('request_pending','سفارش قبلی این میز هنوز تعیین تکلیف نشده است. نتیجه همان درخواست را بررسی کنید؛ سفارش جدید ارسال نشده است.');e.request_id=pending.request_id;e.unknown_outcome=true;e.blocked_before_send=true;throw e;}
 // Do not persist a phantom command when no public or LAN write route can accept it.
 const remembered=rememberCommand(kind,payload),record=remembered.record,requestId=String(record.request_id),deadlineAt=Date.now()+WORKFLOW_DEADLINE_MS,envelope={request_id:requestId,kind,created_at:record.created_at||now(),expires_at:record.expires_at||later(180),payload};
 activeCommands.add(requestId);try{
 let routeHint=String(record.route||''),recovered=null;if(remembered.reused){recovered=await readCommandResult(requestId,'',Math.min(deadlineAt,Date.now()+900),routeHint);if(recovered)return recovered;}
 if(!OFFLINE_LAN_BOOTSTRAP&&lanConfigured()&&Date.now()>=lanDisabledUntil&&(lanState==='ready'||(stale&&lanState==='unknown'))){try{routeHint=(await raceModelRoute('operations',{preferLanMs:lanState==='ready'?LAN_READY_MUTATION_PREFERENCE_MS:0})).route}catch{}}
 kickLanProbe(false);if(routeHint!=='public'&&lanState==='ready'&&lanSupportsRealtime(kind)){markCommandRoute(requestId,'lan');routeHint='lan';try{const direct=await lanJson('realtime',{method:'POST',body:envelope,timeoutMs:Math.min(1600,Math.max(400,deadlineAt-Date.now()))}),state=String(direct.state||'');if(state==='committed'){forgetCommand(requestId);stale=false;refreshProjectedSurface();return {...(direct.result||{success:true}),_transport:'lan'};}if(state==='rejected'){forgetCommand(requestId);const e=requestError(String(direct.error_code||'request_failed'),String(direct.result?.message||humanError(direct.error_code)));e.details=direct.result?.details||{};e.definitive=true;throw e}}catch(e){const status=Number(e?.http_status||0),code=String(e?.code||'');if(e?.definitive){forgetCommand(requestId);throw e}if(status===401)suspendLan('unauthorized',60000);else if(code==='unsupported_kind'&&status===422){if(lanRealtimeKinds)lanRealtimeKinds.delete(kind);}else if(!e?.blocked_before_send)suspendLan(code||'write_failed',3000);if(code!=='unsupported_kind'&&!e?.unknown_outcome&&status>=400&&status<500&&status!==401){forgetCommand(requestId);throw e}}}
 if(OFFLINE_LAN_BOOTSTRAP){const recoveredLan=await readCommandResult(requestId,'',Math.min(deadlineAt,Date.now()+2200),'lan');if(recoveredLan)return recoveredLan;const e=requestError('request_pending','نتیجه عملیات LAN هنوز قطعی نشده است؛ همان درخواست با همان شناسه دوباره بررسی می‌شود.');e.request_id=requestId;e.unknown_outcome=true;throw e;}
 if(!publicCommandReady){if(!remembered.reused&&routeHint!=='lan')forgetCommand(requestId);throw requestError('local_unavailable');}
 markCommandRoute(requestId,'public');routeHint='public';let initialTrace='';try{const remaining=deadlineAt-Date.now();if(remaining<=0)throw requestError('request_timeout');const accepted=await json(u('/api/staff/realtime'),{method:'POST',timeoutMs:Math.min(REQUEST_TIMEOUT_MS,remaining),body:JSON.stringify(envelope)});initialTrace=String(accepted.trace_id||'')}
 catch(e){if(!e?.unknown_outcome&&!['request_timeout','request_failed'].includes(String(e?.code||''))){forgetCommand(requestId);throw e}}
 recovered=await readCommandResult(requestId,initialTrace,deadlineAt,routeHint);if(recovered)return recovered;
 const e=requestError('request_pending');e.request_id=requestId;e.unknown_outcome=true;document.dispatchEvent(new CustomEvent('sokna:staff-command-unknown',{detail:{request_id:requestId,kind}}));throw e;
 }catch(e){if(readLedger().some(row=>String(row.request_id)===requestId)){e.unknown_outcome=true;e.request_id=requestId;}throw e;}finally{activeCommands.delete(requestId);}
}
function caps(){return {order:body?.dataset.canOrder==='1',cashier:body?.dataset.canCashier==='1'};}
async function operations(){const j=await model('operations'),p=j.payload||{},h=committedHall(p.hall&&typeof p.hall==='object'?p.hall:{},j);return {j,p,h};}
async function financeAccount(sessionId){const {p}=await operations();if([...committedTables].some(([id,patch])=>Number(patch.row.session_id||0)===Number(sessionId)||(p.hall?.tables||[]).some(t=>Number(t.id)===id&&Number(t.session_id||0)===Number(sessionId))))throw requestError('request_pending','وضعیت تازه ثبت شده است؛ جزئیات مالی در حال تازه‌شدن است.');const a=(p.settlement_accounts||[]).find(x=>Number(x.session_id||0)===Number(sessionId));if(!a)throw new Error('حساب مالی تازه برای این میز در دسترس نیست.');if(a.account&&typeof a.account==='object')return {account:a.account};return {account:{session:{id:Number(a.session_id),table_name:String(a.table_name||''),discount_type:null,discount_value:0},subtotal:Number(a.remaining_total||0),remaining_subtotal:Number(a.remaining_total||0),remaining_discount:0,remaining_tax:0,paid_total:0,remaining_total:Number(a.remaining_total||0),signature:String(a.signature||''),receipts:[],lines:[]}};}
async function hallRequest(url,opt={}){const parsed=absolute(url),action=String(parsed.searchParams.get('action')||'');if(parsed.pathname.includes('finance')&&action==='account')return financeAccount(Number(parsed.searchParams.get('session_id')||0));if(action==='snapshot'){const {j,p,h}=await operations(),c=caps();return {...h,success:true,permissions:{orders_floor:c.order,cashier_accounts:c.cashier,shift_supervision:false},waiter_calls:Array.isArray(h.waiter_calls)?h.waiter_calls:(p.waiter_calls||[]),remote_stale:!!j.stale,last_sync_at:j.last_sync_at||'',order_acceptance:h.order_acceptance??null,print_quick_preferences:h.print_quick_preferences??null,accommodation_enabled:typeof h.accommodation_enabled==='boolean'?h.accommodation_enabled:null,hall_settings_available:h.hall_settings_available===true,can_manage_connections:false};}if(action==='account'){const id=Number(parsed.searchParams.get('table_id')||0),{h}=await operations(),a=h.accounts?.[String(id)]||h.accounts?.[id];if(committedTables.has(id))throw requestError('request_pending','وضعیت میز ثبت شده است؛ جزئیات حساب در حال تازه‌شدن است.');if(!a)throw new Error('حساب این میز در اطلاعات همگام‌شده موجود نیست.');const c=caps();return {...a,permissions:{orders_floor:c.order,cashier_accounts:c.cashier,shift_supervision:false}};}if(action==='search')return {success:true,rows:[]};throw new Error('این عملیات در دسترسی راه‌دور فعال نیست.');}
// Status-only reconciliation for a Hall move. Never issues a second command POST.
// The public transport envelope request ID is distinct from the business payload ID;
// find the former by the persisted command fingerprint, then GET the existing receipt.
async function hallMoveStatus(details={}){
 const payload={table_id:Number(details.table_id||0),target_table_id:Number(details.target_table_id||0),expected_session_id:Number(details.expected_session_id||0),request_id:String(details.request_id||'')};
 const row=pendingCommand('hall.table_move',payload).row;
 if(!row||row.kind!=='hall.table_move')return {success:true,state:'unconfirmed'};
 const r=await reconcileCommand(row);
 if(r.state==='committed')return {success:true,state:'committed',result:r.result};
 if(r.state==='rejected')return {success:true,state:'rejected',error_code:r.error_code||'request_failed'};
 return {success:true,state:'unconfirmed'};
}
async function hallMutate(payload={}){const action=String(payload.action||''),p={...payload};delete p.action;if(action==='order_status')return realtime('order.staff_status',p);if(action==='waiter_status')return realtime('hall.waiter_status',p);if(action==='table_move')return realtime('hall.table_move',p);if(action==='order_reprint')return realtime('hall.order_reprint',p);if(action==='order_item_adjust')return realtime('hall.order_item_adjust',p);throw new Error('این عملیات در دسترسی راه‌دور فعال نیست.');}
const orderTransport={
 canMutate:()=>caps().order&&liveMutationReady(),
 get:async(action,params={})=>{if(action==='workspace'){const j=await model('table_draft_context');return j.payload||{tables:[],drafts:{},catalog:[],catalog_groups:[]};}if(action==='draft'){const tableId=Number(params.table_id||0),j=await model('table_draft_context'),ctx=j.payload||{},draft=ctx.drafts?.[String(tableId)]||ctx.drafts?.[tableId]||null;return {success:true,draft};}throw new Error('عملیات سفارش معتبر نیست.');},
 post:async(payload={})=>{const action=String(payload.action||''),p={...payload};delete p.action;if(action==='draft_save')return realtime(Number(p.expected_version||0)>0?'table_draft.edit':'table_draft.create',p);if(action==='draft_finalize')return realtime('table_draft.finalize',p);if(action==='draft_cancel')return realtime('table_draft.cancel',p);throw new Error('عملیات سفارش معتبر نیست.');},
 finalizeAtomic:async(payload={})=>{const p={...payload};delete p.action;return realtime('table_draft.finalize_atomic',p)},
 pendingFinalizationForTable,
 reconcilePendingFinalization:reconcileFinalization
};
async function financeRequest(url,opt={}){const parsed=absolute(url),action=String(parsed.searchParams.get('action')||'');if(action==='account')return financeAccount(Number(parsed.searchParams.get('session_id')||0));throw new Error('این خواندن مالی در دسترسی راه‌دور فعال نیست.');}
async function financePost(url,payload={}){const p={...payload};delete p.csrf_token;const action=String(p.action||'');delete p.action;if(action==='late_served_catalog'){const result=await realtime('settlement.late_catalog',p);return {success:true,...result}}if(action==='late_served_commit'){const result=await realtime('settlement.late_commit',p);return {success:true,result}}if(action==='review'){const review=await realtime('settlement.review',p);return {success:true,review};}if(action==='discount_preview'){const result=await realtime('settlement.discount_preview',p);return {success:true,result};}if(action==='settle'){const result=await realtime('settlement.commit',p);return {success:true,result};}if(action==='discount'){const result=await realtime('settlement.discount',p);return {success:true,result};}if(action==='receipt_reprint'){const result=await realtime('settlement.receipt_reprint',p);return {success:true,result};}if(action==='followup_detach'){const result=await realtime('settlement.followup_detach',p);return {success:true,result};}if(action==='followup_destination'){const result=await realtime('settlement.followup_destination',p);return {success:true,result};}throw new Error('این عملیات مالی در دسترسی راه‌دور فعال نیست.');}
const financeAdapter={host:'public',request:financeRequest,post:financePost,pendingSettlementForSession,reconcileSettlement:sessionId=>reconcileCommand(pendingSettlementForSession(sessionId))};
const hallAdapter={host:'public',request:hallRequest,mutate:hallMutate,moveStatus:hallMoveStatus,financeMutate:payload=>financePost('',payload),orderTransport,canMutate:()=>liveMutationReady(),lastSync:()=>lastSync,hasUnresolvedMutations};
const preparationAdapter={host:'public',load:async()=>{const j=await model('preparation');return j.payload||{orders:[],permissions:{}}},mutate:async payload=>{const p={...payload};if(String(p.action||'')==='claim')p.action='claim_order_area';return realtime('preparation.mutate',p)},normalizeAction:action=>String(action||'')==='claim'?'claim_order_area':String(action||''),canMutate:()=>liveMutationReady(),isStale:()=>stale,lastSync:()=>lastSync,meta:()=>({stale,last_sync_at:lastSync,connectivity:null,mutation_ready:liveMutationReady()})};
let watchCursor='',watchStopped=false,watchRunning=false,watchAbort=null,projectionRefreshTimer=0,watchFailureStreak=0;
function refreshProjectedSurface(){clearTimeout(projectionRefreshTimer);projectionRefreshTimer=setTimeout(()=>{if(document.hidden)return;window.SoknaSharedHall?.refresh?.();},60)}
function stopWatcher(){watchStopped=true;if(watchAbort){try{watchAbort.abort('lifecycle')}catch{}}}
async function watchModels(){
 if(watchStopped||watchRunning||document.hidden)return;watchRunning=true;const controller=new AbortController();watchAbort=controller;const models=['operations','table_draft_context','preparation'];
 try{while(!watchStopped&&!document.hidden&&!controller.signal.aborted){try{const qs=new URLSearchParams({models:models.join(','),cursor:watchCursor,wait_ms:'9000'}),r=await json(u('/api/staff/read/watch')+'?'+qs.toString(),{signal:controller.signal,timeoutMs:WATCH_TIMEOUT_MS}),next=String(r.cursor||''),changed=r.changed===true&&watchCursor!=='';watchFailureStreak=0;watchCursor=next||watchCursor;if(r.connectivity&&typeof r.connectivity==='object')setPublicCommandReady(r.connectivity.local_fresh===true,'public_watch');document.dispatchEvent(new CustomEvent('sokna:public-watch-meta',{detail:r.watch||{}}));if(changed){document.dispatchEvent(new CustomEvent('sokna:public-projection-changed',{detail:r}));refreshProjectedSurface();}else{const retry=Math.max(0,Math.min(1500,Number(r.watch?.retry_after_ms||0)));if(retry)await sleep(retry);}}catch(e){if(controller.signal.aborted||watchStopped||document.hidden)break;watchFailureStreak=Math.min(6,watchFailureStreak+1);const base=lanState==='ready'?1500:800,delay=Math.min(15000,base*(2**Math.min(4,watchFailureStreak-1)));document.dispatchEvent(new CustomEvent('sokna:public-watch-backoff',{detail:{failures:watchFailureStreak,delay_ms:delay,lan_ready:lanState==='ready'}}));await sleep(delay)}}}
 finally{if(watchAbort===controller)watchAbort=null;watchRunning=false;if(!watchStopped&&!document.hidden)queueMicrotask(()=>watchModels())}
}
function resumeWatcher(revalidate=false){watchStopped=false;if(revalidate){watchCursor='';stale=true;model('operations').then(()=>refreshProjectedSurface()).catch(()=>{});}watchModels()}
document.addEventListener('visibilitychange',()=>{if(document.hidden){if(watchAbort){try{watchAbort.abort('hidden')}catch{}}}else{resumeWatcher(false);resetLanCircuit('visible');kickLanProbe(true)}},{passive:true});
window.addEventListener('online',()=>{resetLanCircuit('online');kickLanProbe(true)},{passive:true});window.addEventListener('offline',()=>{if(lanConfigured())setLanState(lanState,'offline_event')},{passive:true});window.addEventListener('pagehide',stopWatcher);window.addEventListener('pageshow',e=>{if(!OFFLINE_LAN_BOOTSTRAP)resumeWatcher(!!e.persisted);resetLanCircuit('pageshow');kickLanProbe(true)});try{navigator.connection?.addEventListener?.('change',()=>{resetLanCircuit('network_change');kickLanProbe(true)},{passive:true})}catch{}kickLanProbe(false);if(OFFLINE_LAN_BOOTSTRAP){setInterval(()=>{if(document.hidden||lanState!=='ready')return;refreshProjectedSurface();window.SoknaStaffPreparation?.load?.()},5000)}else watchModels();
R?.register?.('hall',hallAdapter);R?.register?.('finance',financeAdapter);R?.register?.('preparation',preparationAdapter);
window.SoknaFinanceTransport=financeAdapter;window.SoknaHallTransport=hallAdapter;
window.SoknaPublicRealtime={model,realtime,isStale:()=>stale,lastSync:()=>lastSync,lanState:()=>lanState,lanConfigured,lanDiagnostics};
})();
