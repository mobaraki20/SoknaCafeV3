'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync(require('node:path').join(__dirname,'../overlays/local/assets/staff-app/hall-workspace.js'),'utf8');
let checks=0;function ok(v,msg){assert.ok(v,msg);checks++}
const helperStart=src.indexOf('function accountScope('),helperEnd=src.indexOf('async function loadFinanceAccount(',helperStart);
const loadStart=src.indexOf('async function loadAccount('),loadEnd=src.indexOf('async function selectTable(',loadStart);
ok(helperStart>=0&&helperEnd>helperStart&&loadStart>=0&&loadEnd>loadStart,'exact production functions exist');
const script='let accountLoadSeq=0,accountDraftScope="7:101";'+src.slice(helperStart,helperEnd)+src.slice(loadStart,loadEnd);
function setup(){
 const ctx={
 api:'/hall',selectedTableId:7,account:{table:{id:7,session_id:101}},financeAccount:{session:{id:101}},financeError:'',
 snap:{tables:[{id:7,session_id:101},{id:8,session_id:203}]},accountEditingItemId:45,accountBillScrollTop:68,
 accountAdjustmentDrafts:new Map([[45,2]]),accountAdjustmentReasons:new Map([[45,'x']]),accountAdjustmentNotes:new Map([[45,'y']]),
 accountAdjustmentModes:new Map([[45,'dine_in']]),accountServiceDistributionDrafts:new Map([[45,{dine_in:2,takeaway:0}]]),
 accountItemNoteDrafts:new Map([[45,'note']]),accountEditFeedback:'typing',discountOpen:true,discountDraftType:'amount',discountDraftValue:'5000',
 closes:0,discountCloses:0,renders:0,emptyMessages:[],financeLoads:[],
 accountPane:{setAttribute(){},removeAttribute(){}},
 closeAccountItemEditor(){ctx.closes++;ctx.accountEditingItemId=0;},closeDiscountDialog(){ctx.discountCloses++;},
 renderAccount(){ctx.renders++},renderAccountEmpty(msg){ctx.emptyMessages.push(msg)},
 async loadFinanceAccount(session){ctx.financeLoads.push(session)},
 request:async()=>({table:{id:7,session_id:101}}),
 };
 vm.createContext(ctx);vm.runInContext(script,ctx);return ctx;
}
const run=(ctx,id)=>vm.runInContext('loadAccount('+id+')',ctx);
(async()=>{
 let c=setup();await run(c,7);
 ok(c.accountDraftScope===undefined||vm.runInContext('accountDraftScope',c)==='7:101','same session preserved');
 ok(c.accountAdjustmentDrafts.get(45)===2&&c.accountItemNoteDrafts.has(45),'same-session refresh retains unfinished edits');
 ok(c.closes===0&&c.discountCloses===0,'normal refresh does not close dialogs');
 ok(c.financeLoads[0]===101&&c.renders===1,'same-session finance and render');
 c.snap.tables[0].session_id=102;c.request=async()=>({table:{id:7,session_id:102}});
 await run(c,7);
 ok(vm.runInContext('accountDraftScope',c)==='7:102','session scope updates');
 ok(c.accountAdjustmentDrafts.size===0&&c.accountAdjustmentReasons.size===0&&c.accountAdjustmentNotes.size===0&&c.accountItemNoteDrafts.size===0,'old-session draft maps cleared');
 ok(c.accountAdjustmentModes.size===0&&c.accountServiceDistributionDrafts.size===0,'old-session service edits cleared');
 ok(c.closes===1&&c.discountCloses===1&&c.accountEditingItemId===0,'old-session dialogs closed');
 ok(c.discountOpen===false&&c.discountDraftType==='percent'&&c.discountDraftValue===''&&c.accountBillScrollTop===0,'old-session controls and scroll reset');
 ok(c.account?.table?.session_id===102&&c.financeLoads.at(-1)===102,'new session used for finance');
 c=setup();c.selectedTableId=8;c.request=async()=>({table:{id:8,session_id:203}});
 await run(c,8);
 ok(vm.runInContext('accountDraftScope',c)==='8:203'&&c.accountAdjustmentDrafts.size===0,'switch to different table clears drafts');
 ok(c.emptyMessages.length===1&&c.emptyMessages[0].includes('در حال دریافت'),'old table not shown during switch');
 c=setup();c.snap.tables[0].session_id=102;c.request=async()=>({table:{id:7,session_id:101}});
 await run(c,7);
 ok(c.account===null&&c.financeLoads.length===0&&c.emptyMessages.at(-1).includes('نشست حساب'),'snapshot/detail session mismatch rejected');
 ok(c.accountAdjustmentDrafts.size===1&&vm.runInContext('accountDraftScope',c)==='7:101','uncertain read preserves edits until verified new session');
 c=setup();c.request=async()=>({table:{id:8,session_id:203}});await run(c,7);
 ok(c.account===null&&c.emptyMessages.at(-1).includes('میز انتخاب‌شده'),'wrong table detail never accepted');
 c=setup();c.request=async()=>{throw Error('network timeout')};await run(c,7);
 ok(c.account===null&&c.accountAdjustmentDrafts.size===1&&c.emptyMessages.at(-1)==='network timeout','transient read error never discards draft');
 c=setup();const resolvers=[];c.request=()=>new Promise(resolve=>resolvers.push(resolve));
 const first=run(c,7);const second=run(c,7);
 resolvers[1]({table:{id:7,session_id:101}});await second;
 resolvers[0]({table:{id:7,session_id:777}});await first;
 ok(c.account?.table?.session_id===101&&c.financeLoads.length===1,'out-of-order responses cannot overwrite newest account');
 c=setup();c.snap.tables=[];c.request=async()=>({table:{id:7,session_id:101}});await run(c,7);
 ok(c.account?.table?.session_id===101,'absent snapshot row does not invent mismatch');
 c=setup();c.snap.tables[0].session_id=0;c.request=async()=>({table:{id:7,session_id:0}});await run(c,7);
 ok(vm.runInContext('accountDraftScope',c)===''&&c.accountAdjustmentDrafts.size===0,'closed table invalidates previous session scope');
 ok(src.includes('function accountPriceChangeRows(){')&&src.includes('const anchorCreatedAt=String(anchor.created_at||'),'C04 protections retained');
 ok(src.includes('financial.discount>0?')&&src.includes('const candidate=j.account||null'),'C05 bill and finance provenance retained');
 console.log('PASS',checks,'focused session and bill safety assertions');
})().catch(e=>{console.error(e);process.exitCode=1});
