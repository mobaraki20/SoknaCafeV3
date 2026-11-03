#!/usr/bin/env node
'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const js=fs.readFileSync('apps/local-web/public/assets/system-diagnostics.js','utf8');
const html=fs.readFileSync('apps/local-web/public/system/index.php','utf8');
const api=fs.readFileSync('apps/local-web/public/system/api.php','utf8');
const client=fs.readFileSync('apps/local-web/src/Domain/System/MaintenanceSupervisorClient.php','utf8');
const core=fs.readFileSync('windows/maintenance/src/Sokna.Maintenance.Core/UnifiedUpdaterCapabilities.cs','utf8');
const engine=fs.readFileSync('windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Program.cs','utf8');
const collector=fs.readFileSync('packaging/windows/scripts/collect-support-unified-v1.ps1','utf8');

assert.match(js,/staged\.hidden=legacyLocalBlocked/,'obsolete Local stage must not be shown in Maintenance mode');
assert.match(js,/rollbackBox\.hidden=legacyLocalBlocked/,'legacy rollback state must not appear as Maintenance result');
assert.match(js,/legacyFeedback\.hidden=true/,'duplicated generic Local notification must be hidden');
assert.match(js,/ownedStage=String\(j\.maintenance_owned_stage\?\.operation_id/,'staged operation must be rehydrated after refresh');
assert.match(api,/maintenance_owned_stage.*\$ownedStage/,'server must bind owned Stage from signed operation');
assert.match(api,/\$record\['operator_id'\].*'user:'/,'do not adopt another operator Stage');
assert.match(html,/data-maintenance-stage-status/,'visible, compact Stage summary required');
assert.match(html,/data-maintenance-advanced/,'advanced diagnostics must be collapsed');
assert.match(core,/HostBlockers = host\.Blockers/,'exact safe C# host blockers must be reported');
assert.match(client,/host_blockers_available/,'Local adapter must preserve blocker availability, including unknown');
assert.match(engine,/if \(command == "host-readiness"\)/,'on-box support must read exactly which host readiness condition is blocked');
assert.match(collector,/maintenance-host-readiness-safe\.json/,'one Support ZIP must capture host readiness details');
assert.doesNotMatch(js,/برای عیب‌یابی، بسته پشتیبانی را دریافت کن/,'do not force manual support exports after a generic warning');

const start=js.indexOf('const hostBlockerText='),end=js.indexOf(' async function loadUpdates',start);
assert(start>0 && end>start,'readiness UI evaluator missing');
const code=js.slice(start,end);
let text='';
const badge={};
const context={
 '$':()=>({className:'',set textContent(value){text=value;},get textContent(){return text;}}),
};
vm.createContext(context);
vm.runInContext(code,context);
context.renderUnifiedUpdaterReadiness({ready:false,panel_bound:false,verification_ready:true,
 code:'LOCAL_HOST_PRECONDITIONS_UNPROVEN',host_blockers:['PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT']});
assert.match(text,/فایل‌های دائمی و فعال بازیابی یکسان نیستند/,'specific safe blocker should be visible in Persian');
assert.doesNotMatch(text,/بسته پشتیبانی/,'the button should not demand duplicate diagnostics');
context.renderUnifiedUpdaterReadiness({ready:false,panel_bound:false,verification_ready:true,
 code:'LOCAL_HOST_PRECONDITIONS_UNPROVEN'});
assert.match(text,/دلیل جزئی را به پنل گزارش نداده/,'missing host diagnostics is explicitly unknown');
context.renderUnifiedUpdaterReadiness({ready:true,panel_bound:true,verification_ready:true,code:'OK'});
assert.match(text,/آمادهٔ نصب/,'ready status must not be misreported blocked');
console.log('PASS R95: one current Stage UI, no legacy duplicate, safe specific host prerequisite text, unknown≠ready');
