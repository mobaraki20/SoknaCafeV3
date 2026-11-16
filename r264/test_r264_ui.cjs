'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm'),path=require('node:path');
const root=process.env.R264_ROOT||'/mnt/data/sokna_r264_work';
function excerpt(s,begin,end){const a=s.indexOf(begin),b=s.indexOf(end,a+begin.length);assert(a>=0&&b>a,'source anchor missing');return s.slice(a,b)}
async function adjustmentScenarios(file){
 const js=fs.readFileSync(file,'utf8');
 const source=excerpt(js,'async function commitOrderItemAdjustment(button){','async function reprintPreparationRound(');
 assert(source.includes("SoknaUI.confirm('تأیید حذف آیتم'"));
 let confirmed=false,mutationCount=0,confirmedCount=0,loaded=0;
 const map=x=>new Map(Object.entries(x||{}).map(([k,v])=>[Number(k),v]));
 const ctx={
  accountAdjustmentDrafts:map({1:0}),accountAdjustmentReasons:new Map(),accountAdjustmentNotes:new Map(),accountAdjustmentModes:new Map(),accountServiceDistributionDrafts:new Map(),accountItemNoteDrafts:new Map(),
  accountEditingItemId:1, accountEditFeedback:null,
  accountEditorGroup:()=>({anchor:{name:'چای',fulfillment_mode:'dine_in',unit_price:140000},total:2,distribution:{dine_in:2,takeaway:0},note:''}),
  serviceDistributionEqual:(a,b)=>a.dine_in===b.dine_in&&a.takeaway===b.takeaway,
  reconcileServiceDistribution:(d,q)=>({dine_in:q,takeaway:0}),
  human:x=>String(x||''), adjustmentReason:()=> 'customer', adjustmentReasonLabel:()=> 'درخواست مشتری',
  SoknaUI:{confirm:async()=>{confirmedCount++;return confirmed}},
  mutate:async o=>{mutationCount++;return {display_order_item_id:1,correction_print_job_id:0,success:true}},
  closeAccountItemEditor:()=>{},load:async()=>{loaded++},clientRequestId:()=>'r264-qa-req',
  L:{integer:x=>x},selectedTableId:7
 };
 vm.createContext(ctx);vm.runInContext(source,ctx);
 const button={dataset:{itemAdjustApply:'1',itemActual:'2',itemName:'چای'}};
 await ctx.commitOrderItemAdjustment(button);
 assert.equal(confirmedCount,1,'zero quantity must confirm');assert.equal(mutationCount,0,'rejected zero must not mutate');
 confirmed=true;await ctx.commitOrderItemAdjustment(button);
 assert.equal(mutationCount,1,'confirmed zero should mutate');assert.equal(loaded,1);
 ctx.accountAdjustmentDrafts.set(1,3);confirmedCount=0;
 await ctx.commitOrderItemAdjustment(button);
 assert.equal(confirmedCount,0,'nonzero count should not ask destructive confirmation');assert.equal(mutationCount,2);
 for (const token of ['data-modal-item-delta', 'data-modal-service-mode', 'data-modal-adjust-reason']) assert(js.includes(`.focus({preventScroll:true})`),token);
 return {file:path.basename(file),cases:3};
}
(async()=>{console.log(await adjustmentScenarios(root+'/local/public/assets/staff-app/hall-workspace.js'));console.log(await adjustmentScenarios(root+'/public-hall.js'));const p=fs.readFileSync(root+'/local/public/assets/printing-workspace.js','utf8');assert(p.includes('downloadPrintTemplate')&&p.includes('data.package')&&p.includes('print_template_details')&&p.includes('.soknaprint'),'canonical template export missing');for(const style of [root+'/local/public/assets/product-ui.css',root+'/public-product-ui.css']){assert(fs.readFileSync(style,'utf8').includes('R264: quantity correction footer stays visible'),'mobile modal scroll/footer patch missing');}console.log('R264_LOCAL_PUBLIC_QUANTITY_CONFIRM_AND_FOCUS_STATIC_QA_PASS')})().catch(e=>{console.error(e);process.exit(1)});