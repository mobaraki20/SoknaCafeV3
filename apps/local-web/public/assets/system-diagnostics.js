(()=>{'use strict';
const root=document.querySelector('[data-system-workspace]');if(!root)return;
const $=s=>root.querySelector(s),$$=s=>[...root.querySelectorAll(s)],appPath=v=>(window.SoknaURL?.path?window.SoknaURL.path(v):v),api=root.dataset.api,csrf=root.dataset.csrf,L=window.SoknaLocale||{integer:v=>new Intl.NumberFormat('fa-IR').format(Number(v||0)),date:v=>String(v??''),time:v=>String(v??''),dateTime:v=>String(v??''),instantDateTime:v=>String(v??'')};let snap={components:{},checks:[],health_groups:[],issues:[],summary:{status:'checking'}},updates=null,recovery=null,sectionErrors={},legacyLocalBlocked=true,maintenanceReady=false,maintenanceVerifyReady=false;const healthDone=new Set(),healthPartErrors=new Map();
const statusLabels={operational:'عملیاتی',degraded:'آماده با محدودیت',action_required:'نیاز به اقدام',checking:'در حال بررسی',ok:'سالم',warning:'نیاز به توجه',critical:'نیاز به رسیدگی',attention:'نیاز به بررسی',observed_recently:'سالم و فعال',observed_stale:'شواهد قدیمی',not_seen:'هنوز متصل نشده',not_configured:'تنظیم نشده',configured_unprobed:'تنظیم شده',starting:'در حال شروع',running:'در حال اجرا',maintenance_paused:'متوقف برای نگهداری',failed:'خطا',stopped:'متوقف',disabled:'غیرفعال',unauthorized:'عدم احراز هویت',contract_mismatch:'ناسازگاری قرارداد',invalid_response:'پاسخ نامعتبر',unreachable:'عدم دسترسی',configuration_error:'خطای پیکربندی',http_error:'خطای ارتباط',unavailable:'در دسترس نیست',error:'خطا',not_created:'کدی ساخته نشده',issued:'منتظر اتصال',exchanged:'در حال اتصال',confirmed:'متصل',canceled:'لغو شده',expired:'منقضی شده',superseded:'جایگزین شده'};
const checkLabels={setup_lock:'نصب سامانه کامل است',data_dir:'محل نگهداری داده آماده است',pdo_mysql:'ارتباط با دیتابیس آماده است',migrations:'ساختار اطلاعات به‌روز است',database_version:'دیتابیس سازگار است',runtime_delivery:'Scheduler و اجرای jobهای Local سالم‌اند',print_required_destinations:'مسیرهای ضروری چاپ آماده‌اند',print_agent_version_compatibility:'نسخه Print Agent با Local Web سازگار است',public_edge_connectivity:'اتصال زنده و heartbeat وب عمومی آماده است',php_zip:'امکان نصب فایل به‌روزرسانی آماده است',update_chunk_transport:'بارگذاری بسته‌های بزرگ به‌روزرسانی آماده است',stable_recovery:'مسیر بازیابی اضطراری آماده است',clock_alignment:'ساعت سرور و دیتابیس هم‌راستا است'};
function badge(v){return statusLabels[v]||'نیاز به بررسی'}function badgeTone(v){return ['operational','ok','observed_recently','running','confirmed'].includes(String(v))?'sc-badge--success':['action_required','critical','failed','unavailable','error'].includes(String(v))?'sc-badge--danger':['degraded','warning','attention','observed_stale','not_seen','not_configured','configured_unprobed','starting','maintenance_paused','stopped'].includes(String(v))?'sc-badge--warning':'sc-badge--info'}function el(t,c='',x){const e=document.createElement(t);if(c)e.className=c;if(x!==undefined)e.textContent=String(x);return e}function shortVersion(v){const raw=String(v||'').trim(),m=raw.match(/^v?(\d+\.\d+\.\d+)/);if(!m)return raw||'—';const b=raw.match(/(?:^|[-+])workspace\.([0-9]+[a-z]*)(?:[-+.]|$)/i);return b?`${m[1]}.${b[1]}`:m[1]}function setEmergencyLink(link,url=''){if(!link)return;if(url){link.href=url;link.setAttribute('aria-disabled','false');link.removeAttribute('tabindex')}else{link.removeAttribute('href');link.setAttribute('aria-disabled','true');link.tabIndex=-1}}function setStatus(m,bad=false){const e=$('[data-status]');if(window.SoknaUI?.feedback)return SoknaUI.feedback(e,m,{tone:bad?'danger':'info'});e.textContent=m||'';e.hidden=!m;e.className='sc-alert '+(bad?'sc-alert--danger':'sc-alert--info')}function eventFeedback(sel,m,tone='info',busy=false){const e=$(sel);if(window.SoknaUI?.feedback)return SoknaUI.feedback(e,m,{tone,busy});if(!e)return;e.textContent=m||'';e.hidden=!m;e.className='sc-alert sc-event-feedback sc-alert--'+tone}function renderUpdateFailure(failure){const box=$('[data-local-update-last-failure]');if(!box)return;if(!failure||typeof failure!=='object'){box.hidden=true;box.replaceChildren();return}box.hidden=false;box.replaceChildren();box.append(el('strong','',failure.operation==='repair'?'آخرین ترمیم ناموفق':'آخرین نصب ناموفق'));const summary=el('div','',`${failure.failure_stage_label||'مرحله نامشخص'}: ${failure.cause_message||'علت ثبت نشده است.'}`);box.append(summary);const details=el('details','sc-maintenance-card__details');details.append(el('summary','','جزئیات خطا'));if(failure.cause_code)details.append(el('small','',`کد خطا: ${failure.cause_code}`));const d=failure.cause_details||{};if(d.migration)details.append(el('small','',`Migration: ${d.migration}${d.statement?` · statement ${L.integer(d.statement)}`:''}`));if(d.mysql_errno)details.append(el('small','',`MySQL: ${d.mysql_errno}${d.sql_state?` · SQLSTATE ${d.sql_state}`:''}`));const rb=failure.rollback||{};if(rb.retained_web===true)details.append(el('small','',`حالت بازیابی: فایل‌های نسخه جدید نگه داشته شدند؛ مسیر عادی سامانه تا نصب بسته اصلاحی مسدود است.`));else if(rb.ok===true)details.append(el('small','',`بازگشت خودکار: موفق${rb.restored_version?` · ${shortVersion(rb.restored_version)}`:''}`));else if(rb.ok===false)details.append(el('small','',`بازگشت خودکار: ناموفق${rb.error?.message?` · ${rb.error.message}`:''}`));if(Array.isArray(failure.forward_migrations)&&failure.forward_migrations.length)details.append(el('small','',`Migrationهای اجراشده قبل از توقف: ${failure.forward_migrations.join('، ')}`));if(failure.support_id)details.append(el('small','',`کد پیگیری: ${failure.support_id}`));box.append(details)}function toast(m,tone='success',title=''){window.SoknaUI?.toast?.(m,{tone,title})}function versionBadge(sel,raw){const node=$(sel);if(!node)return;node.textContent=shortVersion(raw);node.title=String(raw||'');}function resetPackageForm(sel){const form=$(sel);if(!form)return;form.reset();const name=form.querySelector('[data-file-name]');if(name)name.textContent='فایلی انتخاب نشده است';}
function sectionError(key){return sectionErrors?.[key]||null}
function sectionErrorText(key,fallback){const e=sectionError(key);if(!e)return '';return `${e.message||fallback}${e.support_id?` · کد پیگیری ${e.support_id}`:''}`}
function localUnavailable(host,key,fallback){if(!host)return;host.replaceChildren(el('div','sc-empty sc-section-unavailable',sectionErrorText(key,fallback)))}
function controlsDisabled(selectors,disabled){selectors.forEach(sel=>$$(sel).forEach(x=>x.disabled=disabled))}
function friendlyError(j,status=0){const code=String(j?.code||'');const serverMessage=String(j?.message||'').trim();if((code==='activation_failed'||code==='activation_failed_recovery_mode'||code==='activation_failed_rollback_failed'||code==='repair_failed'||code==='repair_failed_recovery_mode'||code==='repair_failed_rollback_failed')&&j?.details?.failure)return serverMessage||String(j.details.failure.cause_message||'نصب کامل نشد.');const direct=new Set(['upload_server_limit','upload_interrupted','upload_missing','upload_storage_unavailable','upload_blocked','upload_chunk_size','upload_offset_conflict','upload_incomplete','package_size','public_package_size','backup_size']);if(direct.has(code)&&serverMessage)return serverMessage;const map={signature_required:'این فایل برای نصب معتبر نیست.',invalid_zip:'فایل انتخاب‌شده قابل استفاده نیست.',manifest_missing:'ساختار فایل به‌روزرسانی معتبر نیست.',manifest_contract:'ساختار فایل به‌روزرسانی معتبر نیست.',invalid_version:'نسخه فایل به‌روزرسانی معتبر نیست.',invalid_file_count:'محتوای فایل به‌روزرسانی معتبر نیست.',package_too_large:'حجم فایل به‌روزرسانی بیش از حد مجاز است.',staged_integrity_failed:'بررسی سلامت فایل به‌روزرسانی کامل نشد.',payload_set_mismatch:'محتوای فایل به‌روزرسانی تغییر کرده است.',immutable_version_conflict:'برای این نسخه قبلاً فایل دیگری آماده شده است.',no_recovery_point:'نسخه سالم قبلی برای بازگشت پیدا نشد.',csrf_expired:'نشست صفحه منقضی شده است؛ صفحه را تازه کن.',public_url_invalid:'آدرس وب عمومی معتبر نیست. آدرس HTTPS کامل را وارد کن.',pairing_code_required:'کد اتصال لازم است.',public_unreachable:'ارتباط با Public Edge برقرار نشد. آدرس، HTTPS و دسترسی شبکه را بررسی کن.',public_not_configured:'اتصال Public Edge هنوز ثبت نشده است.',schema_downgrade_unsupported:'این بسته از ساختار دیتابیس نصب فعلی قدیمی‌تر است و مستقیم قابل نصب نیست.',migration_history_unverifiable:'تاریخچه دیتابیس این نصب قابل تأیید نیست؛ نصب مستقیم متوقف شد.',migration_history_changed:'تاریخچه migrationهای نصب فعلی با بسته جدید یکسان نیست.',database_preflight_failed:'بررسی سازگاری دیتابیس قبل از نصب کامل نشد.',migration_failed:'تغییرات دیتابیس نسخه جدید کامل نشد.',migration_incomplete:'ساختار دیتابیس نسخه جدید کامل نشد.',upgrade_contract_missing:'این بسته قرارداد لازم برای ارتقای مستقیم را ندارد.',incompatible_release_line:'این بسته متعلق به خط انتشار دیگری است.',database_policy_missing:'سیاست دیتابیس بسته معتبر نیست.',database_contract_invalid:'فهرست migrationهای بسته معتبر نیست.',database_catalog_mismatch:'فهرست migrationهای بسته با فایل‌های آن یکسان نیست.'};return map[code]||serverMessage||(status>=500?'عملیات کامل نشد. اگر مشکل ادامه داشت بسته پشتیبانی تهیه کن.':'درخواست قابل انجام نیست. اطلاعات واردشده را بررسی کن و دوباره تلاش کن.')}async function request(url,opt={}){let r;try{r=await fetch(url,{cache:'no-store',credentials:'same-origin',...opt})}catch{const err=new Error('ارتباط با سامانه هنگام انجام عملیات قطع شد. دوباره تلاش کن.');err.code='transport_unreachable';throw err}let j={};try{j=await r.json()}catch{const err=new Error('پاسخ سامانه قابل خواندن نبود.');err.code='response_unreadable';err.status=r.status;throw err}if(!r.ok||!j.success){const sid=String(j.support_id||j?.details?.failure?.support_id||'').trim(),code=String(j?.code||'').trim(),baseMsg=friendlyError(j,r.status),isPublicUpdate=String(url||'').includes('/system/public-update-upload.php')||code.startsWith('public_')||['repair_requires_same_version','same_version_requires_repair','nothing_staged','activation_failed','repair_failed'].includes(code),ctx=(j?.context&&typeof j.context==='object'&&!Array.isArray(j.context))?j.context:{},safeCtx=isPublicUpdate?Object.entries(ctx).filter(([k,v])=>/^(expected|actual|simulated|path|file|version|current_version|base_version|target_version)$/i.test(String(k))&&['string','number','boolean'].includes(typeof v)).slice(0,4).map(([k,v])=>`${k}=${String(v).length>32?String(v).slice(0,12)+'…'+String(v).slice(-8):String(v)}`).join(' · '):'',tech=isPublicUpdate&&code?`کد فنی: ${code}${safeCtx?` · ${safeCtx}`:''}`:'',msg=baseMsg+(tech&&!baseMsg.includes(code)?` · ${tech}`:'');const err=new Error(msg+(sid&&!msg.includes(sid)?` · کد پیگیری ${sid}`:''));err.payload=j;err.code=code;err.status=r.status;throw err}return j}async function post(data){return request(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...data,csrf_token:csrf})})}
async function chunkPost(data){return request(appPath('/system/update-chunk.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...data,csrf_token:csrf})})}
function lifecyclePost(data){if(legacyLocalBlocked)return Promise.reject(new Error('ساختار R8 تنها از موتور Maintenance قابل به‌روزرسانی است.'));return request(appPath('/local-update-runner.php'),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...data,csrf_token:csrf})})}
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function reconcileAmbiguousActivation(targetVersion){
 targetVersion=String(targetVersion||'').trim();if(!targetVersion)return null;
 for(const wait of [150,350,700,1200]){if(wait)await sleep(wait);try{const j=await request(`${api}?section=updates`),u=j.update_center||{},active=String(u.active_version||u.current_version||'').trim(),staged=String(u.staged?.version||'').trim();if(active===targetVersion&&staged==='')return {success:true,reconciled:true,update_center:u};if(u.last_failure&&String(u.last_failure.target_version||u.last_failure.version||'').trim()===targetVersion)return null}catch{}}
 return null
}
async function uploadLocalPackage(file,onProgress=()=>{}){const init=await chunkPost({action:'init',name:file.name,size:file.size}),id=init.upload.id,chunkSize=Number(init.upload.chunk_size||1048576);let offset=Number(init.upload.received||0);try{while(offset<file.size){const end=Math.min(file.size,offset+chunkSize),blob=file.slice(offset,end),j=await request(appPath(`/system/update-chunk.php?action=chunk&id=${encodeURIComponent(id)}&offset=${offset}`),{method:'PUT',headers:{'Content-Type':'application/octet-stream','X-CSRF-Token':csrf},body:blob});offset=Number(j.upload.received||end);onProgress(Math.min(1,offset/file.size))}return await chunkPost({action:'finalize',id})}catch(err){try{await chunkPost({action:'abort',id})}catch{}throw err}}
function ageText(value){const n=Number(value);if(!Number.isFinite(n)||n<0)return 'نامشخص';if(n<60)return `${L.integer(Math.round(n))} ثانیه پیش`;if(n<3600)return `${L.integer(Math.round(n/60))} دقیقه پیش`;if(n<86400)return `${L.integer(Math.round(n/3600))} ساعت پیش`;return `${L.integer(Math.round(n/86400))} روز پیش`}
function healthCard(title,state,description,lines=[]){const card=el('article','sc-maintenance-card'),head=el('div','sc-maintenance-card__head');head.append(el('h3','',title),el('span',`sc-badge ${badgeTone(state)}`,badge(state)));card.append(head,el('p','',description));const values=lines.filter(v=>String(v||'').trim()!=='');if(values.length){const details=el('div','sc-maintenance-card__details');values.forEach(v=>details.append(el('small','',v)));card.append(details)}return card}
function renderHealth(){
 const c=snap?.components||{},box=$('[data-components]');box.replaceChildren();
 const rt=c.runtime||null,pa=c.print_agent||null,pe=c.public_edge||null,db=c.database||null,lw=c.local_web||null,tm=c.time||null;
 if(lw)box.append(healthCard('سامانه محلی',lw.status,'صفحات و داده‌های محلی برای کار روزانه.',[`نسخه: ${shortVersion(lw.version)}`,`PHP: ${lw.php_version||'نامشخص'}`,lw.disk_free_mb!=null?`فضای آزاد Data: ${L.integer(lw.disk_free_mb)} MB`:'' ]));
 if(db)box.append(healthCard('اطلاعات و دیتابیس',db.status,'دسترسی به اطلاعات و ساختار موردنیاز سامانه.',[`MariaDB: ${db.server_version||'در دسترس نیست'}`,Number(db.pending_migrations)>0?`${L.integer(db.pending_migrations)} migration در انتظار`:'ساختار دیتابیس به‌روز است']));
 if(tm)box.append(healthCard('زمان سامانه',tm.status||'warning','زمان فنی، روز کاری و روز تقویمی ایران از یک مرجع واحد محاسبه می‌شوند.',[tm.iran_local?`زمان ایران: ${(L.instantDateTime||L.dateTime)(tm.server_utc||tm.iran_local)}`:'',tm.business_date?`روز کاری: ${(L.date||String)(tm.business_date)}${tm.business_cutoff?` · مرز ${(L.time||String)(tm.business_cutoff)}`:''}`:'',tm.civil_date?`روز تقویمی/بانکی: ${(L.date||String)(tm.civil_date)}`:'',tm.shift_label?`شیفت: ${tm.shift_label}`:'',tm.database_server_skew_seconds!=null?`اختلاف ساعت DB و سرور: ${L.integer(Math.abs(Number(tm.database_server_skew_seconds)))} ثانیه`:(tm.alignment_evidence==='unavailable'?'تطبیق ساعت دیتابیس قابل تأیید نیست.':'') ]));
 if(rt){const latestRt=(rt.instances||[])[0]||{},exec=rt.execution||{},execLabel=({completed:'آخرین job کامل شده',running:'job در حال اجرا',stalled:'job پذیرفته‌شده متوقف مانده',failed:'آخرین job ناموفق',not_seen:'هنوز job اجرا نشده'})[String(exec.state||'not_seen')]||String(exec.state||'نامشخص'),schedulerOk=rt.scheduler?.healthy===true&&rt.scheduler?.fresh===true;box.append(healthCard('سرویس‌های دستگاه',rt.status,'Scheduler، اجرای job در Local و انتقال کسب‌وکاری Public جداگانه سنجیده می‌شوند؛ پذیرفته‌شدن trigger به معنی پایان worker نیست.',[rt.runtime_version?`نسخه Runtime: ${rt.runtime_version}`:'',rt.service_health?.status?`وضعیت سرویس: ${badge(rt.service_health.status)}`:'',rt.probe?.status?`Probe: ${badge(rt.probe.status)}`:'',rt.runtime_instance_id?`Instance زنده Runtime: ${rt.runtime_instance_id}`:'',`Scheduler: ${schedulerOk?'فعال و تازه':'نیازمند بررسی'}${rt.scheduler?.last_cycle_at?` · ${ageText(rt.scheduler.cycle_age_seconds)}`:''}`,`اجرای Local job: ${execLabel}${Number(exec.pending_count||0)>0?` · ${L.integer(exec.pending_count)} در انتظار/در حال اجرا`:''}`,exec.last_completed_at?`آخرین job کامل: ${L.instantDateTime?L.instantDateTime(exec.last_completed_at):L.dateTime(exec.last_completed_at)}`:'',exec.last_failed_at?`آخرین job ناموفق: ${L.instantDateTime?L.instantDateTime(exec.last_failed_at):L.dateTime(exec.last_failed_at)}`:'',latestRt.runtime_instance_id?`آخرین Instance در Local: ${latestRt.runtime_instance_id}`:'هنوز triggerی در Local ثبت نشده است',latestRt.age_seconds!=null?`آخرین تحویل trigger: ${ageText(latestRt.age_seconds)}`:'',Number(latestRt.recent_failed_count)>0?`${L.integer(latestRt.recent_failed_count)} خطای worker در ۱۵ دقیقه اخیر`:'']));}
 if(pa){const connectedAgents=(pa.agents||[]).filter(a=>Number(a.active)===1&&a.connection_status==='connected'),versionRows=connectedAgents.map(a=>{const v=String(a.agent_version||'').trim()||'نامشخص',host=String(a.hostname||a.name||'').trim();return `Print Agent ${v}${host?` · ${host}`:''}`});const versionSummary=versionRows.length?versionRows:[`نسخه Print Agent: ${pa.agent_version||'هنوز Agent متصل نشده است'}`];box.append(healthCard('چاپ',pa.status,'آمادگی چاپ در سه لایه سرویس، صف چاپگر و مقصد عملیاتی سنجیده می‌شود.',[...versionSummary,`Protocol: v${L.integer(pa.protocol_version||0)} · نسخه پیشنهادی: ${pa.recommended_agent_version||'—'}`,`سرویس متصل: ${L.integer(pa.connected_agents||0)} از ${L.integer(pa.active_agents||0)}`,`صف چاپگر آماده: ${L.integer(pa.ready_printer_queues||0)} از ${L.integer(pa.reported_printer_queues||0)}`,`مقصد عملیاتی: ${L.integer(pa.ready_destinations||0)} از ${L.integer(pa.active_destinations||0)}`,`صف باز Local: ${L.integer(pa.server_backlog_count||0)} · بدون Claim: ${L.integer(pa.stalled_unclaimed_count||0)}`,`صف داخلی Windows Agent: ${L.integer(pa.local_backlog_count||0)}`]));}
 if(pe){const failedChannels=(pe.sync_channels||[]).filter(x=>x.status==='error').map(x=>`${x.channel}${x.last_http_status?` (HTTP ${x.last_http_status})`:''}`).join('، ');box.append(healthCard('وب عمومی',pe.status,'سلامت Edge از دید Local و heartbeat ثبت‌شده در خود Edge.',[(pe.base_url||pe.base_origin)?`نشانی: ${pe.base_url||pe.base_origin}`:'هنوز Pair نشده است',pe.last_sync_age_seconds!=null?`آخرین Sync موفق: ${ageText(pe.last_sync_age_seconds)}`:'Sync موفق ثبت نشده است',pe.edge_heartbeat_age_seconds!=null?`Heartbeat دیده‌شده در Edge: ${ageText(pe.edge_heartbeat_age_seconds)}`:'',pe.edge_runtime_status?`Runtime گزارش‌شده به Edge: ${badge(pe.edge_runtime_status)}`:'',failedChannels?`کانال خطادار: ${failedChannels}`:'',pe.probe_error?`Probe: ${pe.probe_error}`:'']));}
 if(!box.children.length)box.append(el('div','sc-empty','جزئیات فنی هر بخش پس از پایان همان بررسی نمایش داده می‌شود.'));
 const checks=$('[data-checks]');checks.replaceChildren();(snap?.checks||[]).forEach(c=>{const card=el('article','sc-card'),body=el('div','sc-card__body'),head=el('div','sc-section-head');head.append(el('strong','',checkLabels[c.id]||'بررسی ضروری'),el('span',`sc-badge ${c.ok?'sc-badge--success':c.severity==='critical'?'sc-badge--danger':'sc-badge--warning'}`,c.ok?'آماده':'نیاز به بررسی'));body.append(head);card.append(body);checks.append(card)});
 const issues=[...(snap?.issues||[])].sort((a,b)=>(a.severity==='action_required'?0:1)-(b.severity==='action_required'?0:1)),issueBox=$('[data-health-issues]');issueBox.replaceChildren();
 issues.forEach(i=>{const row=el('article','sc-system-action-item'),head=el('div','sc-system-action-item__main'),title=el('strong','',i.label||'نیاز به بررسی'),impact=el('p','sc-muted',i.impact||'این بخش نیاز به بررسی دارد.');head.append(title,impact);const meta=el('small','sc-muted',i.last_success?`آخرین موفقیت: ${L.dateTime(i.last_success)}`:`آخرین بررسی: ${L.instantDateTime?L.instantDateTime(i.last_checked):L.dateTime(i.last_checked)}`);head.append(meta);row.append(head);if(i.action_url){const a=el('a','sc-button sc-button--secondary',i.action_label||'بررسی');a.href=appPath(i.action_url);row.append(a)}issueBox.append(row)});
 const complete=healthDone.size>=4,action=issues.filter(i=>i.severity==='action_required').length,degraded=issues.filter(i=>i.severity==='degraded').length,groups=snap?.health_groups||[],groupMap=new Map(groups.map(g=>[g.id,g]));
 const combine=ids=>{const states=ids.map(id=>groupMap.get(id)?.status).filter(Boolean);if(!states.length)return 'checking';if(states.includes('action_required'))return 'action_required';if(states.some(x=>['degraded','not_configured'].includes(x)))return 'degraded';return 'operational'};
 const dimensions=[
  {label:'دردسترس‌بودن سامانه',status:combine(['local_db']),detail:'هسته محلی و دیتابیس'},
  {label:'آمادگی وابستگی‌ها',status:combine(['windows_runtime','canonical_time','recovery']),detail:'سرویس‌های دستگاه، زمان و بازیابی'},
  {label:'قابل‌اجرا بودن گردش‌کارها',status:combine(['public_guest','printing']),detail:'وب عمومی و چاپ'},
 ],dimBox=$('[data-health-dimensions]');if(dimBox){dimBox.replaceChildren();dimensions.forEach(d=>{const row=el('article','sc-readiness-item'),label=el('span','',d.label),value=el('strong','',badge(d.status)),detail=el('small','',d.detail);value.className=`sc-badge ${badgeTone(d.status)}`;row.append(label,value,detail);dimBox.append(row)})}
 const coreBlocked=groupMap.get('local_db')?.status==='action_required';let overall=coreBlocked?'action_required':((action||degraded)?'degraded':(complete?'operational':'checking'));const overallLabel=overall==='operational'?'سامانه در دسترس':overall==='degraded'?'سامانه در دسترس · با محدودیت':overall==='action_required'?'هسته سامانه نیازمند اقدام':'در حال بررسی';$('[data-overall]').textContent=overallLabel;$('[data-overall]').className='sc-badge '+badgeTone(overall);
 const summary=$('[data-health-summary-copy]');if(summary)summary.textContent=coreBlocked?'هسته محلی یا دیتابیس روی کار روزانه اثر می‌گذارد و باید بررسی شود.':action?`سامانه محلی در دسترس است؛ ${L.integer(action)} بخش جانبی نیاز به اقدام دارد.`:degraded?`سامانه محلی در دسترس است؛ ${L.integer(degraded)} بخش با محدودیت کار می‌کند.`:complete?'سامانه برای کار روزانه آماده است؛ جزئیات فنی در بخش پایین قابل مشاهده است.':'نتیجه هر بخش به‌محض آماده‌شدن نمایش داده می‌شود؛ بررسی‌های دیگر منتظر آن نمی‌مانند.';
 if(!issues.length){const e=el('div','sc-empty',complete?'مورد نیازمند اقدام وجود ندارد.':'تا این لحظه مورد نیازمند اقدام پیدا نشده است.');issueBox.append(e)}
 const probe=$('[data-health-probe-state]');if(probe)probe.textContent=`${L.integer(healthDone.size)} از ۴ بررسی آماده است.${healthPartErrors.size?` ${L.integer(healthPartErrors.size)} بررسی با خطا پایان یافته است.`:''}`;
}
function mergeHealthPart(part,key){
 const incoming=part||{},groups=incoming.health_groups||[],groupIds=new Set(groups.map(x=>x.id));snap.components={...(snap.components||{}),...(incoming.components||{})};
 const checks=new Map((snap.checks||[]).map(x=>[x.id,x]));(incoming.checks||[]).forEach(x=>checks.set(x.id,x));snap.checks=[...checks.values()];
 const groupMap=new Map((snap.health_groups||[]).map(x=>[x.id,x]));groups.forEach(x=>groupMap.set(x.id,x));snap.health_groups=[...groupMap.values()];
 const issueMap=new Map((snap.issues||[]).filter(x=>!groupIds.has(x.group)).map(x=>[x.id,x]));(incoming.issues||[]).forEach(x=>issueMap.set(x.id,x));snap.issues=[...issueMap.values()];healthDone.add(key);healthPartErrors.delete(key);renderHealth();
}
async function loadHealthPart(section,key){try{const j=await request(`${api}?section=${encodeURIComponent(section)}`);mergeHealthPart(j.health_component,key)}catch(e){healthDone.add(key);healthPartErrors.set(key,e.message);const id=`probe_${key}`,severity=['runtime','public'].includes(key)?'action_required':'degraded';snap.issues=[...(snap.issues||[]).filter(x=>x.id!==id),{id,group:id,severity,label:`بررسی ${key==='public'?'وب عمومی':key==='runtime'?'سرویس‌های ویندوز':key==='printing'?'چاپ':'سامانه محلی'} کامل نشد`,impact:e.message,action_label:'دوباره بررسی کن',action_url:'/system/?tab=health',last_checked:new Date().toISOString()}];renderHealth()}}
async function loadHealth(){healthDone.clear();healthPartErrors.clear();snap={components:{},checks:[],health_groups:[],issues:[],summary:{status:'checking'}};renderHealth();await Promise.allSettled([loadHealthPart('health_core','core'),loadHealthPart('health_runtime','runtime'),loadHealthPart('health_printing','printing'),loadHealthPart('health_public','public')])}
function renderUpdates(){const staged=$('[data-staged]'),ps=$('[data-public-staged]');const legacyForm=$('[data-update-upload]'),maintenanceForm=$('[data-maintenance-upload]'),legacyActions=$('[data-legacy-local-actions]');if(legacyForm)legacyForm.hidden=legacyLocalBlocked;if(maintenanceForm)maintenanceForm.hidden=!legacyLocalBlocked;if(legacyActions)legacyActions.hidden=legacyLocalBlocked;if(sectionError('updates')){$('[data-local-current-version]').textContent='—';$('[data-public-current-version]').textContent='—';staged.textContent=sectionErrorText('updates','وضعیت به‌روزرسانی‌ها در دسترس نیست.');ps.textContent=sectionErrorText('updates','وضعیت به‌روزرسانی‌ها در دسترس نیست.');controlsDisabled(['[data-activate]','[data-repair]','[data-rollback]','[data-public-update-upload] button','[data-public-update-upload] input','[data-public-activate]','[data-public-repair]','[data-public-rollback]','[data-public-emergency-code]'],true);controlsDisabled(['[data-update-upload] button','[data-update-upload] input'],legacyLocalBlocked);setEmergencyLink($('[data-public-emergency-link]'),'');return}controlsDisabled(['[data-update-upload] button','[data-update-upload] input','[data-public-update-upload] button','[data-public-update-upload] input'],false);const local=updates?.local||{},localVersion=shortVersion(local.current_version),recoveryRequired=local.recovery_mode?.required===true;versionBadge('[data-local-current-version]',local.current_version);const pendingMigrations=Number(local.staged?.database_preflight?.pending_count||0);if(recoveryRequired)eventFeedback('[data-local-update-feedback]','سامانه در حالت بازیابی به‌روزرسانی است. مسیرهای کاری موقتاً مسدود شده‌اند؛ بسته اصلاحی بعدی را همین‌جا انتخاب و نصب کن.','warning');staged.textContent=local.staged?.version?(local.staged?.status==='failed'?`نصب بسته ${shortVersion(local.staged.version)} ناموفق بوده و Stage برای تشخیص/بازیابی نگه داشته شده است.`:`بسته ${shortVersion(local.staged.version)} بررسی شده و آماده نصب است.${pendingMigrations>0?` ${L.integer(pendingMigrations)} تغییر ساختار داده نیز هنگام نصب اعمال می‌شود.`:''}`):'فایلی برای نصب آماده نشده است.';$('[data-activate]').disabled=!local.staged?.version||local.staged.version===local.current_version;$('[data-repair]').disabled=recoveryRequired||!local.staged?.version||local.staged.version!==local.current_version;const discard=$('[data-discard-staged]');if(discard)discard.disabled=!local.staged?.version;const rollbackSafety=local.rollback||{},rollbackReady=!recoveryRequired&&Boolean(local.lkg_recovery_id)&&rollbackSafety.allowed!==false;$('[data-rollback]').disabled=!rollbackReady;const localRollbackState=$('[data-local-rollback-state]');if(localRollbackState){const hasPoint=Boolean(local.lkg_recovery_id),blocked=recoveryRequired||(hasPoint&&rollbackSafety.allowed===false);localRollbackState.querySelector('span').textContent=recoveryRequired?'دیتابیس ممکن است رو‌به‌جلو تغییر کرده باشد؛ بازگرداندن کد قدیمی عمداً مسدود است و بازیابی با بسته اصلاحی از همین صفحه انجام می‌شود.':blocked?'نقطهٔ بازگشت فایل موجود است، اما چون migration رو‌به‌جلو اجرا شده بازگردانی کد به نسخه قبلی ایمن نیست.':hasPoint?'یک نقطهٔ بازگشت سالم و قابل‌استفاده آماده است.':'هنوز نقطهٔ بازگشت سالمی وجود ندارد؛ هنگام نصب نسخهٔ بعدی به‌صورت خودکار ساخته می‌شود.';$('[data-rollback]').title=recoveryRequired?'در حالت بازیابی، rollback کد قدیمی مجاز نیست':blocked?'Rollback پس از migrationهای رو‌به‌جلو مسدود است':hasPoint?'بازگردانی به آخرین وضعیت سالم':'نقطهٔ بازگشت سالمی موجود نیست'}
controlsDisabled(['[data-maintenance-upload] input','[data-maintenance-upload] button[type=submit]','[data-maintenance-upload] [data-file-trigger]'],!maintenanceVerifyReady);if(legacyLocalBlocked){const activation=$('[data-maintenance-activate]');if(activation)activation.disabled=!(maintenanceReady&&/^[a-fA-F0-9]{32}$/.test(verifiedMaintenanceStage));controlsDisabled(['[data-update-upload] button','[data-update-upload] input','[data-activate]','[data-repair]','[data-discard-staged]','[data-rollback]'],true);eventFeedback('[data-local-update-feedback]',maintenanceReady?'موتور استاندارد برای نصب نسخه تأییدشده آماده است.':maintenanceVerifyReady?'مسیر امضاشدهٔ بررسی و آماده‌سازی باز است؛ نصب نهایی هنوز مجاز نیست.':'مسیر قدیمی بسته است و سرویس Maintenance هنوز آماده نیست؛ برای نصب از مسیر قدیمی استفاده نکن.','warning');}
renderUpdateFailure(local.last_failure||null);
const pc=(updates?.components||[]).find(x=>x.id==='public-edge')||{},publicActionCurrent=String(pc?.operational?.staged_owner_active_version||pc.current_version||'').trim();versionBadge('[data-public-current-version]',pc.current_version);ps.textContent=pc.update_available?`بسته ${shortVersion(pc.update_available)} بررسی شده و آماده نصب است.`:'فایلی برای نصب آماده نشده است.';$('[data-public-activate]').disabled=!pc.update_available||pc.update_available===publicActionCurrent;$('[data-public-repair]').disabled=!pc.update_available||pc.update_available!==publicActionCurrent;$('[data-public-rollback]').disabled=pc.lkg!=='ready';const publicRollbackState=$('[data-public-rollback-state]');if(publicRollbackState){const ready=pc.lkg==='ready';publicRollbackState.querySelector('span').textContent=ready?'وب عمومی یک نسخهٔ سالم برای بازگشت دارد. بازگردانی از همین پنل Local انجام می‌شود.':'هنوز نسخهٔ سالمی برای بازگشت وب عمومی ثبت نشده است.';$('[data-public-rollback]').title=ready?'بازگردانی وب عمومی از طریق Local':'نسخهٔ سالمی برای بازگشت موجود نیست'}const publicBase=snap?.components?.public_edge?.base_url||snap?.components?.public_edge?.base_origin||'',link=$('[data-public-emergency-link]');setEmergencyLink(link,publicBase?publicBase.replace(/\/$/,'')+'/emergency.php':'')}
function renderRecovery(){const body=$('[data-backups]');body.replaceChildren();const selectors=['[data-backup-create]','[data-recovery-upload] button','[data-recovery-upload] input','[data-backup-inspect]','[data-backup-restore]'];if(sectionError('recovery')){controlsDisabled(selectors,true);const tr=document.createElement('tr'),td=el('td','sc-section-unavailable',sectionErrorText('recovery','اطلاعات پشتیبان و بازیابی در دسترس نیست.'));td.colSpan=3;tr.append(td);body.append(tr);return}controlsDisabled(selectors,false);(recovery?.backups||[]).forEach((b,i)=>{const tr=document.createElement('tr');const name=document.createElement('td');name.dataset.label='نسخه پشتیبان';name.textContent=`نسخه پشتیبان ${L.integer(i+1)}`;const size=document.createElement('td');size.dataset.label='حجم';size.textContent=`${L.integer(Math.max(1,Math.round(Number(b.size_bytes||0)/1024)))} کیلوبایت`;const action=document.createElement('td');action.dataset.label='عمل';const a=el('a','sc-button sc-button--secondary','دانلود');a.href=appPath(`/system/recovery-download.php?id=${encodeURIComponent(b.id)}`);action.append(a);tr.append(name,size,action);body.append(tr)});if(!body.children.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=3;td.textContent='هنوز نسخه پشتیبان ساخته نشده است.';tr.append(td);body.append(tr)}}
// Read-only recovery of the SAME durable request after Apache reload or
// browser refresh. Never infer success from a disconnected POST.
const maintenanceTrackKey='sokna-maintenance-pending-v1';
let maintenanceProgressPoll=null;
function trackedMaintenanceKey(){try{const k=sessionStorage.getItem(maintenanceTrackKey)||'';return /^[a-zA-Z0-9._:-]{16,128}$/.test(k)?k:''}catch{return ''}}
function rememberMaintenanceKey(key){if(!/^[a-zA-Z0-9._:-]{16,128}$/.test(key))return;try{sessionStorage.setItem(maintenanceTrackKey,key)}catch{}void refreshMaintenanceProgress()}
async function refreshMaintenanceProgress(){
 const host=$('[data-maintenance-progress]'),message=$('[data-maintenance-progress-message]'),button=$('[data-maintenance-progress-refresh]');
 if(!host||!message)return;
 const key=trackedMaintenanceKey();
 if(!key){host.hidden=true;return}
 host.hidden=false;
 if(button)button.disabled=true;
 let inFlight=true;
 try{
   const v=await request(`${api}?section=maintenance_operation&idempotency_key=${encodeURIComponent(key)}`);
   const op=v.maintenance_operation||{};
   const code=String(op.result_code||'');
   const phase=String(op.state||'unknown');
   const id=String(op.operation_id||'');
   if(op.requires_recovery_console===true){
     message.className='sc-alert sc-alert--warning';
     message.textContent=`بازیابی لازم است. درخواست جدید ثبت نکن؛ Recovery Console باید وضعیت را بررسی کند. ${code} · ${id}`;
     inFlight=false;
   }else if(phase==='committed'){
     message.className='sc-alert sc-alert--info';
     message.textContent=`ثبت نهایی در Maintenance انجام شده است (${id}). برای تأیید نصب، سلامت Apache و نسخهٔ واقعی Local نیز باید بررسی شوند.`;
     inFlight=false;
   }else if(op.terminal===true){
     message.className='sc-alert sc-alert--warning';
     message.textContent=`عملیات با وضعیت ${phase} پایان یافته است. ${code} · ${id}`;
     inFlight=false;
   }else{
     message.className='sc-alert sc-alert--info';
     message.textContent=`عملیات ${phase} است. درخواست تکراری ارسال نمی‌شود. ${id}`;
   }
 }catch(e){
   // Failed read is NOT missing operation and never authorizes retry.
   message.className='sc-alert sc-alert--warning';
   message.textContent='وضعیت عملیات قابل خواندن نیست؛ نصب را تکرار نکن. پس از بازگشت ارتباط، «بررسی مجدد» را بزن.';
 }
 finally{
   if(button)button.disabled=false;
   if(maintenanceProgressPoll!==null){clearTimeout(maintenanceProgressPoll);maintenanceProgressPoll=null}
   if(inFlight&&activeTab()==='updates')maintenanceProgressPoll=setTimeout(refreshMaintenanceProgress,3000);
 }
}
$('[data-maintenance-progress-refresh]')?.addEventListener('click',refreshMaintenanceProgress);
root.addEventListener('sokna:maintenance-operation-started',event=>{
 const key=String(event?.detail?.idempotency_key||'');rememberMaintenanceKey(key);
});
// Existing Update Center, independent Public route unchanged. A package is
// not installed by uploading it: signed preflight + Stage + explicit confirm
// and Supervisor's installed readback are separate mandatory checks.
// Stage IDs are non-secret references. Keep them across a harmless browser refresh;
// Supervisor rechecks the real operator, receipts, active host and capability.
const maintenanceStageStoreKey='sokna-maintenance-v4-stage-id';
function restoreMaintenanceStage(){try{const id=sessionStorage.getItem(maintenanceStageStoreKey)||'';return /^[a-fA-F0-9]{32}$/.test(id)?id:''}catch{return ''}}
function persistMaintenanceStage(id){try{if(/^[a-fA-F0-9]{32}$/.test(id))sessionStorage.setItem(maintenanceStageStoreKey,id);else sessionStorage.removeItem(maintenanceStageStoreKey)}catch{}}
let verifiedMaintenanceStage=restoreMaintenanceStage();
const hexBytes=bytes=>Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
// SHA-256 for LAN HTTP origins where crypto.subtle is unavailable. Same bytes
// and digest as WebCrypto; HMAC/credentials remain server-side only.
function sha256Fallback(input){
 const k=Uint32Array.from([
  0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
  0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
  0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
  0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
  0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
  0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
  0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
  0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
 ]);
 const h=Uint32Array.from([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]);
 const r=(x,n)=>(x>>>n)|(x<<(32-n)),w=new Uint32Array(64),length=input.length;
 const size=Math.ceil((length+9)/64)*64,bits=length*8;
 for(let start=0;start<size;start+=64){
   for(let n=0;n<16;n++){
     let word=0;
     for(let byte=0;byte<4;byte++){
       const i=start+n*4+byte;
       const b=i<length?input[i]:i===length?128:i>=size-8
         ? i<size-4? (Math.floor(bits/4294967296) >>> ((size-5-i)*8))&255
                     : ((bits>>>0) >>> ((size-1-i)*8))&255 :0;
       word=(word<<8)|b;
     }
     w[n]=word>>>0;
   }
   for(let n=16;n<64;n++){
     const a=w[n-15],b=w[n-2];
     w[n]=(w[n-16]+(r(a,7)^r(a,18)^(a>>>3))+w[n-7]+(r(b,17)^r(b,19)^(b>>>10)))>>>0;
   }
   let [a,b,c,d,e,f,g,q]=h;
   for(let n=0;n<64;n++){
     const t1=(q+(r(e,6)^r(e,11)^r(e,25))+((e&f)^(~e&g))+k[n]+w[n])>>>0;
     const t2=((r(a,2)^r(a,13)^r(a,22))+((a&b)^(a&c)^(b&c)))>>>0;
     q=g;g=f;f=e;e=(d+t1)>>>0;d=c;c=b;b=a;a=(t1+t2)>>>0;
   }
   h[0]=(h[0]+a)>>>0;h[1]=(h[1]+b)>>>0;h[2]=(h[2]+c)>>>0;h[3]=(h[3]+d)>>>0;
   h[4]=(h[4]+e)>>>0;h[5]=(h[5]+f)>>>0;h[6]=(h[6]+g)>>>0;h[7]=(h[7]+q)>>>0;
 }
 return Array.from(h,x=>x.toString(16).padStart(8,'0')).join('');
}
async function sha256ForMaintenance(arrayBuffer){
 if(globalThis.crypto?.subtle)return hexBytes(new Uint8Array(await crypto.subtle.digest('SHA-256',arrayBuffer)));
 return sha256Fallback(new Uint8Array(arrayBuffer));
}
const newMaintenanceId=()=>hexBytes(crypto.getRandomValues(new Uint8Array(16)));
function safeB64(bytes){let raw='';for(let i=0;i<bytes.length;i+=8192)
 raw+=String.fromCharCode(...bytes.subarray(i,Math.min(i+8192,bytes.length)));
 return btoa(raw)}
function maintenanceUploadMessage(text,tone='info'){const n=$('[data-maintenance-upload-message]');if(!n)return;
 n.hidden=false;n.className='sc-alert sc-alert--'+tone;n.textContent=text}
$('[data-maintenance-upload]')?.addEventListener('submit',async e=>{
 e.preventDefault();const form=e.currentTarget,button=form.querySelector('[type=submit]');
 const file=form.querySelector('input[type=file]')?.files?.[0];
 if(!maintenanceVerifyReady){maintenanceUploadMessage('بررسی فایل در Maintenance هنوز آماده نیست.','warning');return}
 if(!file||file.size<128||file.size>268435456){maintenanceUploadMessage('فایل ZIP یا حجم بسته معتبر نیست.','warning');return}
 // HTTP LAN is supported using the pure JS SHA-256 fallback.
 button.disabled=true;verifiedMaintenanceStage='';persistMaintenanceStage('');$('[data-maintenance-activate]').disabled=true;
 try{
   maintenanceUploadMessage('در حال محاسبه SHA-256 بسته…');
   const arrayBuffer=await file.arrayBuffer(),data=new Uint8Array(arrayBuffer);
   const sha=await sha256ForMaintenance(arrayBuffer);
   const uploadId=newMaintenanceId();let last=null;
   for(let offset=0;offset<data.length;offset+=131072){
     const end=Math.min(offset+131072,data.length);
     // Upload is idempotent only for byte-identical chunks. Never retry Activate.
     const requestPayload={action:'maintenance_inbox_chunk',upload_id:uploadId,sha256:sha,
       total_bytes:data.length,offset,chunk_base64:safeB64(data.subarray(offset,end))};
     last=await post(requestPayload);
     if(Number(last.upload?.received_bytes||-1)!==end)throw new Error('Windows دریافت کامل قطعه را تأیید نکرد.');
     maintenanceUploadMessage(`انتقال فایل: ${L.integer(Math.round(end*100/data.length))}٪`);
   }
   if(last?.upload?.complete!==true||last?.upload?.content_sha256_verified!==true||
       last?.upload?.release_signature_verified!==false||last?.upload?.package_ref!==sha+'.zip')
     throw new Error('بررسی نهایی فایل در Inbox تأیید نشد.');
   maintenanceUploadMessage('انتقال کامل شد؛ در حال بررسی هش و سازگاری Release Set…');
   const pre=await post({action:'maintenance_local_preflight',package_ref:sha+'.zip',
       idempotency_key:'pref-'+newMaintenanceId()});
   if(pre.operator_preflight_verified!==true||!/^[a-fA-F0-9]{32}$/.test(pre.preflight_operation_id||''))
     throw new Error('مدرک بررسی Preflight دریافت نشد.');
   maintenanceUploadMessage('Preflight تأیید شد؛ در حال آماده‌سازی نسخهٔ مستقل…');
   const stage=await post({action:'maintenance_local_stage',preflight_operation_id:pre.preflight_operation_id,
       idempotency_key:'stage-'+newMaintenanceId()});
   if(stage.operator_stage_verified!==true||!/^[a-fA-F0-9]{32}$/.test(stage.stage_operation_id||''))
     throw new Error('مدرک Stage دریافت نشد.');
    verifiedMaintenanceStage=stage.stage_operation_id;persistMaintenanceStage(verifiedMaintenanceStage);
    // Preflight can perform an operator-uploaded exact physical identity rebind. Previous
    // capabilities are stale: only a fresh server-side signed Supervisor
    // readback may enable Activate after this exact signed Stage.
    maintenanceReady=false; // Never reuse pre-rebind readiness.
    const fresh=await request(`${api}?section=updates`);
    maintenanceReady=fresh.maintenance_updater?.ready===true&&fresh.maintenance_panel_bound===true;
    maintenanceVerifyReady=fresh.maintenance_updater?.verification_ready===true&&fresh.maintenance_verification_bound===true;
    renderUnifiedUpdaterReadiness({...fresh.maintenance_updater,panel_bound:fresh.maintenance_panel_bound===true});
    $('[data-maintenance-activate]').disabled=!maintenanceReady;
    maintenanceUploadMessage(maintenanceReady
      ?'بسته بررسی شد؛ بدون کلید ناشر آماده نصب است. برای نصب تأیید نهایی را بزن.'
      :'بسته بررسی و Stage شد، اما موتور هنوز اجازه نصب نداده است؛ وضعیت سرویس را بررسی کن.','info');
  }catch(error){$('[data-maintenance-activate]').disabled=true;
    // A successful Stage remains durable even if the capability readback fails.
    // Never forget its ID or use stale permission for Activate.
    if(!/^[a-fA-F0-9]{32}$/.test(verifiedMaintenanceStage))verifiedMaintenanceStage='';
    maintenanceReady=false;
    maintenanceUploadMessage(verifiedMaintenanceStage
      ?'Stage ثبت شده اما وضعیت مجوز نصب مجدداً خوانده نشد؛ «تازه‌سازی» را بزن. نصب خودکار انجام نشده است.'
      :`فرآیند متوقف شد. هیچ نصب جدیدی تأیید نشده است. ${String(error.message||'')}`,'warning')}
 finally{button.disabled=!maintenanceVerifyReady}
});
$('[data-maintenance-activate]')?.addEventListener('click',async e=>{
 if(!maintenanceReady||!/^[a-fA-F0-9]{32}$/.test(verifiedMaintenanceStage)){maintenanceUploadMessage('Stage تأییدشده موجود نیست.','warning');return}
 if(!await SoknaUI.confirm('نصب نسخه Local','این عملیات ممکن است Apache را موقتاً متوقف کند. ادامه می‌دهی؟'))return;
 const button=e.currentTarget,stageId=verifiedMaintenanceStage,idem='activate-'+newMaintenanceId();
 button.disabled=true;maintenanceReady=false;rememberMaintenanceKey(idem); // Durable identity BEFORE the potentially disruptive POST.
 try{
   const result=await post({action:'maintenance_local_activate',stage_operation_id:stageId,idempotency_key:idem});
   if(result.success===true&&result.accepted===true&&result.activation_committed===false)
     maintenanceUploadMessage('درخواست نصب در Maintenance ثبت شد. اجرا در جریان است؛ وضعیت را از شناسه عملیات دنبال کن و نصب را دوباره شروع نکن.','info');
   else if(result.success===true&&result.activation_committed===true)
     maintenanceUploadMessage('Maintenance عملیات را ثبت نهایی کرده است؛ سلامت Apache و نسخه فعال Local نیز باید بررسی شوند.','info');
   else throw new Error('تأیید معتبر پذیرش یا نتیجه نصب دریافت نشد.');
 }catch(err){maintenanceUploadMessage('پاسخ نصب نامطمئن است؛ هرگز نصب را تکرار نکن. وضعیت عملیات در پایین بررسی می‌شود.','warning')}
 finally{void refreshMaintenanceProgress()}
});
// Diagnostic is a read-only signed GET. It never updates capabilities, starts
// an operation, copies secrets to the browser or changes the install gate.
const connectionLabels={
  maintenance_descriptor_missing:'شناسه کشف سرویس موجود نیست.',
  maintenance_descriptor_unreadable:'وب محلی اجازه خواندن اطلاعات اتصال سرویس را ندارد.',
  maintenance_descriptor_invalid:'قرارداد کشف سرویس معتبر نیست.',
  maintenance_web_adapter_key_missing:'کلید داخلی ارتباط پنل و سرویس ساخته یا نصب نشده است.',
  maintenance_web_adapter_key_unreadable:'وب محلی به کلید داخلی ارتباط سرویس دسترسی ندارد.',
  maintenance_web_adapter_key_invalid:'قالب کلید داخلی سرویس نامعتبر است.',
  maintenance_php_http_transport_disabled:'اتصال HTTP در تنظیمات PHP محلی غیرفعال است.',
  maintenance_http_timeout:'سرویس در زمان مقرر پاسخ HTTP نداد.',
  maintenance_http_connection_refused:'اتصال محلی HTTP توسط مقصد رد شد.',
  maintenance_http_access_denied:'دسترسی به مسیر HTTP محلی رد شد.',
  maintenance_unreachable:'سرویس در درخواست HTTP محلی پاسخ نداد.',
  maintenance_http_0:'پاسخ HTTP از سرویس دریافت نشد.',
  maintenance_unauthorized:'تأیید امضای داخلی ارتباط پذیرفته نشد.',
  maintenance_descriptor_identity_mismatch:'هویت سرویس با اطلاعات کشف‌شده یکسان نیست.',
  CAPABILITIES_CONTRACT_MISMATCH:'قرارداد قابلیت به‌روزرسانی ناسازگار است.',
  FOUNDATION_UPDATE_REQUIRED:'قابلیت نصب در سرویس یا نسخه نصب‌شده هنوز آماده نیست.'
};
$('[data-maintenance-connection-check]')?.addEventListener('click',async()=>{
  const button=$('[data-maintenance-connection-check]'),msg=$('[data-maintenance-connection-result]');
  button.disabled=true;
  eventFeedback('[data-maintenance-connection-result]','در حال بررسی امضاشدهٔ مسیر اتصال (بدون انجام نصب)…','info');
  try{
    const j=await request(`${api}?section=maintenance_connection`);
    const d=j.maintenance_connection||{},code=String(d.error_code||'MAINTENANCE_UNKNOWN');
    const stage=d.signed_health==='valid'?'HTTP امضاشده برقرار است':d.discovery!=='valid'?'کشف سرویس':d.web_adapter_key!=='available'?'دسترسی داخلی':'ارتباط HTTP';
    const message=d.update_ready===true?'اتصال و قابلیت نصب از پنل تأیید شد.':
       `${stage} · ${connectionLabels[code]||'جزئیات اتصال نیازمند بررسی است.'} · کد: ${code}${Number.isInteger(d.http_status)?` · HTTP ${d.http_status}`:''}`;
    eventFeedback('[data-maintenance-connection-result]',message,d.update_ready===true?'success':'warning');
  }catch(err){eventFeedback('[data-maintenance-connection-result]',`آزمایش اتصال انجام نشد: ${String(err.message||'خطای ارتباط')}`,'warning')}
  finally{button.disabled=false}
});
function renderUnifiedUpdaterReadiness(v){
 const n=$('[data-unified-updater-readiness]');if(!n)return;
 const ready=v?.ready===true&&v?.panel_bound===true;
 n.className='sc-alert '+(ready?'sc-alert--success':'sc-alert--warning');
 n.textContent=ready?'سامانه برای بررسی و نصب امن بسته به‌روزرسانی آماده است.':
   v?.verification_ready===true?'فایل به‌روزرسانی قابل بررسی و آماده‌سازی است، اما نصب نهایی فعلاً مجاز نیست. برای عیب‌یابی، بسته پشتیبانی را دریافت کن.':
   'نصب نسخه جدید فعلاً مجاز نیست. وضعیت بازیابی و سرویس نگهداری باید بررسی شود؛ برای پشتیبانی گزارش بگیر.';
} async function loadUpdates(msg=''){root.setAttribute('aria-busy','true');try{const j=await request(`${api}?section=updates`);updates=j.update_center;legacyLocalBlocked=j.legacy_local_blocked!==false;maintenanceReady=j.maintenance_updater?.ready===true&&j.maintenance_panel_bound===true&&!trackedMaintenanceKey();maintenanceVerifyReady=j.maintenance_updater?.verification_ready===true&&j.maintenance_verification_bound===true;renderUnifiedUpdaterReadiness({...j.maintenance_updater,panel_bound:j.maintenance_panel_bound===true});if(j.snapshot?.components)snap.components={...(snap.components||{}),...j.snapshot.components};sectionErrors.updates=null;renderUpdates();if(trackedMaintenanceKey())void refreshMaintenanceProgress();const pr=j.public_update_refresh||{};if(pr.status==='failed'){const code=String(pr.error_code||'public_update_probe_failed');eventFeedback('[data-public-update-feedback]',`وضعیت زنده Public در این بررسی دریافت نشد؛ اطلاعات نمایش‌داده‌شده ممکن است کش‌شده باشد. کد: ${code}`,'warning')}else if(pr.status==='fresh'){const r=pr.routine_update||{},v=String(r.active_version||r.current_version||'').trim();if(v)eventFeedback('[data-public-update-feedback]',`وضعیت زنده Public تأیید شد: ${shortVersion(v)}`,'info')}setStatus('');if(msg)toast(msg)}catch(e){sectionErrors.updates={message:e.message};renderUpdates();setStatus(e.message,true)}finally{root.removeAttribute('aria-busy')}}
async function loadRecovery(msg=''){root.setAttribute('aria-busy','true');try{const j=await request(`${api}?section=recovery`);recovery=j.recovery;sectionErrors.recovery=null;renderRecovery();setStatus('');if(msg)toast(msg)}catch(e){sectionErrors.recovery={message:e.message};renderRecovery();setStatus(e.message,true)}finally{root.removeAttribute('aria-busy')}}
function forceTab(name){if(name==='maintenance')name='updates';if(!['health','updates','recovery'].includes(name))return;$$('[data-tab]').forEach(b=>b.setAttribute('aria-selected',b.dataset.tab===name?'true':'false'));$$('[data-panel]').forEach(p=>p.hidden=p.dataset.panel!==name)}const requestedTab=new URLSearchParams(location.search).get('tab');if(requestedTab)forceTab(requestedTab);
function activeTab(){return root.querySelector('[data-tab][aria-selected="true"]')?.dataset.tab||'health'}
async function refresh(msg=''){const tab=activeTab();if(tab==='updates')return loadUpdates(msg);if(tab==='recovery')return loadRecovery(msg);setStatus('');await loadHealth();setStatus('');if(msg)toast(msg)}

$('[data-refresh]')?.addEventListener('click',()=>refresh());
async function downloadSupportBundle(bundle){const box=$('[data-support-download]'),url=appPath(bundle?.download||''),name=String(bundle?.file_name||'support-bundle.json.gz');if(!url)throw new Error('مسیر دانلود بسته پشتیبانی آماده نشد.');if(box){box.hidden=false;box.replaceChildren();box.append(document.createTextNode('بسته پشتیبانی آماده است. '));const direct=el('a','sc-link','دانلود مستقیم');direct.href=url;direct.download=name;box.append(direct)}let r;try{r=await fetch(url,{cache:'no-store',credentials:'same-origin'})}catch{throw new Error('بسته ساخته شد، اما دانلود خودکار برقرار نشد؛ از «دانلود مستقیم» استفاده کن.')}if(!r.ok)throw new Error('بسته ساخته شد، اما دریافت فایل کامل نشد؛ از «دانلود مستقیم» استفاده کن.');const blob=await r.blob();if(!blob.size)throw new Error('فایل بسته پشتیبانی خالی بود؛ از «دانلود مستقیم» استفاده کن.');const objectUrl=URL.createObjectURL(blob),a=document.createElement('a');a.href=objectUrl;a.download=name;a.hidden=true;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(objectUrl),30000)}
$('[data-support]')?.addEventListener('click',async()=>{const b=$('[data-support]');b.disabled=true;try{const j=await post({action:'support_bundle'});setStatus('');await downloadSupportBundle(j.bundle);toast('بسته پشتیبانی آماده و دانلود شد.')}catch(e){toast('مسیر اصلی عیب‌یابی در دسترس نبود؛ بسته مستقل بازیابی باز می‌شود.','warning','عیب‌یابی مستقل');window.location.assign(appPath('/update-rescue.php?download=diagnostics'))}finally{b.disabled=false}});
$('[data-update-upload] input[type="file"]')?.addEventListener('change',e=>{const file=e.currentTarget.files?.[0];eventFeedback('[data-local-update-feedback]',file?'فایل انتخاب شد؛ برای بررسی روی «بررسی فایل» بزن.':'','info')});
$('[data-update-upload]')?.addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget,b=form.querySelector('button'),file=form.querySelector('input[type="file"]')?.files?.[0];if(!file){eventFeedback('[data-local-update-feedback]','ابتدا فایل به‌روزرسانی را انتخاب کن.','warning');return}b.disabled=true;eventFeedback('[data-local-update-feedback]','در حال بارگذاری بسته… ۰٪','info',true);try{const j=await uploadLocalPackage(file,p=>eventFeedback('[data-local-update-feedback]',`در حال بارگذاری بسته… ${L.integer(Math.round(p*100))}٪`,'info',true));await refresh();const version=shortVersion(j.staged?.version);eventFeedback('[data-local-update-feedback]','','info');toast(`بسته ${version} آماده نصب است.`,'success','به‌روزرسانی')}catch(err){eventFeedback('[data-local-update-feedback]',err.message,'danger');toast(err.message,'danger','بررسی بسته انجام نشد')}finally{b.disabled=false}});
$('[data-activate]')?.addEventListener('click',async e=>{const b=e.currentTarget;if(!await SoknaUI.confirm('نصب به‌روزرسانی','پیش از نصب، امکان بازگشت خودکار آماده می‌شود.'))return;const target=String(updates?.staged?.version||'').trim();await SoknaUI.busy(b,async()=>{eventFeedback('[data-local-update-feedback]','در حال نصب به‌روزرسانی…','info',true);try{await lifecyclePost({action:'update_activate'});location.reload()}catch(err){if(['transport_unreachable','response_unreadable'].includes(String(err?.code||''))){eventFeedback('[data-local-update-feedback]','پاسخ نهایی نصب مبهم است؛ در حال تطبیق وضعیت واقعی…','warning',true);const confirmed=await reconcileAmbiguousActivation(target);if(confirmed){toast('نصب انجام شد؛ وضعیت نهایی سامانه تأیید شد.','success','به‌روزرسانی');location.reload();return}}renderUpdateFailure(err?.payload?.details?.failure||null);const msg=String(err?.message||'نصب کامل نشد.');eventFeedback('[data-local-update-feedback]',msg,String(err?.code||'')==='activation_failed_recovery_mode'?'warning':'danger');toast(msg,String(err?.code||'')==='activation_failed_recovery_mode'?'warning':'danger','نصب انجام نشد');if(String(err?.code||'')==='activation_failed_recovery_mode')setTimeout(()=>location.assign(appPath('/system/?tab=updates&recovery=1')),250)}})});
$('[data-repair]')?.addEventListener('click',async e=>{const b=e.currentTarget;if(!await SoknaUI.confirm('ترمیم نصب','فایل‌های برنامه دوباره اعمال می‌شوند و اطلاعات کسب‌وکار حفظ می‌شود.'))return;await SoknaUI.busy(b,async()=>{eventFeedback('[data-local-update-feedback]','در حال ترمیم نصب…','info',true);try{await lifecyclePost({action:'update_repair'});location.reload()}catch(err){renderUpdateFailure(err?.payload?.details?.failure||null);eventFeedback('[data-local-update-feedback]',err.message,'danger')}})});
$('[data-discard-staged]')?.addEventListener('click',async e=>{const b=e.currentTarget;if(!await SoknaUI.confirm('لغو بسته آماده‌شده','فقط فایل Stage کنار گذاشته می‌شود؛ نسخه فعال و اطلاعات کسب‌وکار تغییر نمی‌کنند.'))return;await SoknaUI.busy(b,async()=>{try{await post({action:'update_discard'});await refresh('بسته آماده‌شده کنار گذاشته شد.')}catch(err){eventFeedback('[data-local-update-feedback]',err.message,'danger');toast(err.message,'danger','لغو Stage انجام نشد')}})});
$('[data-rollback]')?.addEventListener('click',async e=>{const b=e.currentTarget;if(!await SoknaUI.confirm('بازگشت به آخرین وضعیت سالم','وضعیت فعلی کنار گذاشته می‌شود و آخرین حالت سالم سامانه برمی‌گردد.',true))return;await SoknaUI.busy(b,async()=>{eventFeedback('[data-local-update-feedback]','در حال بازگشت به آخرین وضعیت سالم…','warning',true);try{await lifecyclePost({action:'update_rollback'});location.reload()}catch(err){eventFeedback('[data-local-update-feedback]',err.message,'danger')}})});
$('[data-public-update-upload] input[type="file"]')?.addEventListener('change',e=>{const file=e.currentTarget.files?.[0];eventFeedback('[data-public-update-feedback]',file?'فایل انتخاب شد؛ برای بررسی روی «بررسی فایل» بزن.':'','info')});
$('[data-public-update-upload]')?.addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget,b=form.querySelector('button'),file=form.querySelector('input[type="file"]')?.files?.[0];if(!file){eventFeedback('[data-public-update-feedback]','ابتدا فایل وب عمومی را انتخاب کن.','warning');return}b.disabled=true;eventFeedback('[data-public-update-feedback]','در حال بارگذاری و بررسی بسته وب عمومی…','info',true);try{const j=await request(appPath('/system/public-update-upload.php'),{method:'POST',body:new FormData(form)}),u=j?.public_update||{},stagedVersion=String(u?.routine_update?.staged?.version||u?.update?.staged?.version||u?.staged?.version||'').trim();if(!stagedVersion){const x=new Error('Public Edge تأیید نکرد که بسته برای نصب Stage شده است. نصب شروع نمی‌شود.');x.code='public_stage_unconfirmed';throw x}await refresh();const pc=(updates?.components||[]).find(x=>x.id==='public-edge')||{},visible=String(pc.update_available||'').trim();if(visible!==stagedVersion){eventFeedback('[data-public-update-feedback]',`بسته ${shortVersion(stagedVersion)} روی Public Stage شد، اما وضعیت محلی هنوز همان Stage را تأیید نکرده است. نصب را شروع نکن و بسته پشتیبانی بگیر.`,'warning');toast('Stage انجام شد اما وضعیت پنل با Public تطابق ندارد؛ نصب مسدود ماند.','warning','به‌روزرسانی وب عمومی');return}eventFeedback('[data-public-update-feedback]','','info');toast(`بسته ${shortVersion(stagedVersion)} آماده نصب است.`,'success','به‌روزرسانی وب عمومی')}catch(err){eventFeedback('[data-public-update-feedback]',err.message,'danger');toast(err.message,'danger','بررسی بسته انجام نشد')}finally{b.disabled=false}});
for(const [sel,action,title,msg,danger] of [['[data-public-activate]','public_update_activate','نصب به‌روزرسانی وب عمومی','نسخه آماده‌شده روی وب عمومی فعال شود؟',false],['[data-public-repair]','public_update_repair','ترمیم وب عمومی','فایل‌های وب عمومی دوباره اعمال شوند؟',false],['[data-public-rollback]','public_update_rollback','بازگشت وب عمومی','وب عمومی به آخرین نسخه سالم برگردد؟',true]]){$(sel)?.addEventListener('click',async e=>{const b=e.currentTarget;if(!await SoknaUI.confirm(title,msg,danger))return;await SoknaUI.busy(b,async()=>{try{await post({action});resetPackageForm('[data-public-update-upload]');eventFeedback('[data-public-update-feedback]','','info');await refresh('عملیات وب عمومی انجام شد.')}catch(err){eventFeedback('[data-public-update-feedback]',err.message,'danger')}})})}
$('[data-public-emergency-code]')?.addEventListener('click',async e=>{await SoknaUI.busy(e.currentTarget,async()=>{try{const j=await post({action:'public_emergency_code_rotate'}),box=$('[data-public-emergency-secret]');box.classList.remove('sc-hidden');box.textContent=j.emergency.emergency_code||'';if(j.emergency.emergency_url)setEmergencyLink($('[data-public-emergency-link]'),j.emergency.emergency_url)}catch(err){toast(err.message,'danger','عملیات انجام نشد')}})});
$('[data-backup-create]')?.addEventListener('click',async e=>{await SoknaUI.busy(e.currentTarget,async()=>{const pass=$('[data-backup-pass]').value;try{const j=await post({action:'backup_create',passphrase:pass});$('[data-backup-pass]').value='';await refresh('نسخه پشتیبان ساخته شد.');location.href=appPath(j.backup.download)}catch(err){toast(err.message,'danger','عملیات انجام نشد')}})});
$('[data-recovery-upload]')?.addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget;await SoknaUI.busy(form,async()=>{try{const j=await request(appPath('/system/recovery-upload.php'),{method:'POST',body:new FormData(form)});$('[data-restore-id]').value=j.backup.id;toast('فایل انتخاب شد؛ برای اطمینان ابتدا آن را بررسی کن.','info','بازیابی')}catch(err){toast(err.message,'danger','عملیات انجام نشد')}})});
$('[data-backup-inspect]')?.addEventListener('click',async e=>{await SoknaUI.busy(e.currentTarget,async()=>{try{await post({action:'backup_inspect',id:$('[data-restore-id]').value,passphrase:$('[data-restore-pass]').value});const box=$('[data-backup-manifest]');box.classList.remove('sc-hidden');box.textContent='فایل پشتیبان معتبر است و برای بازیابی آماده است.';toast('فایل پشتیبان تأیید شد.','success','بازیابی')}catch(err){toast(err.message,'danger','عملیات انجام نشد')}})});
$('[data-backup-restore]')?.addEventListener('click',async e=>{const b=e.currentTarget;if(!await SoknaUI.confirm('بازیابی اطلاعات','این عملیات فقط روی نصب خالی مجاز است. ادامه می‌دهی؟',true))return;await SoknaUI.busy(b,async()=>{try{await post({action:'backup_restore',id:$('[data-restore-id]').value,passphrase:$('[data-restore-pass]').value});location.assign(appPath('/logout.php?restored=1'));return}catch(err){toast(err.message,'danger','عملیات انجام نشد')}})});
root.addEventListener('sokna:tabchange',event=>{const tab=event.detail?.tab;if(tab==='updates'&&!updates)loadUpdates();else if(tab==='recovery'&&!recovery)loadRecovery();else if(tab==='health'&&healthDone.size===0)loadHealth()});
if(activeTab()==='updates')loadUpdates();else if(activeTab()==='recovery')loadRecovery();else loadHealth();
})();
