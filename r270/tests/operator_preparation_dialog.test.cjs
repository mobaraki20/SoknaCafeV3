'use strict';
// Isolated test of the exact Local cashier dialog; no simulated remote kitchen ACK.
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../source/public/assets/staff-app/hall-workspace.js'),'utf8');
const start=source.indexOf('function operatorPreparationDispositionDialog(');
const end=source.indexOf('// Deliberately no automatic retry after uncertain POST.',start);
assert(start>=0&&end>start,'real dialog source found');
const functionSource=source.slice(start,end);
function setup({status,accepted,reason}){
 let dialog;
 const createElement=tag=>{
  assert.equal(tag,'dialog');
  const form={events:{},addEventListener(type,fn){this.events[type]=fn}};
  dialog={open:false,removed:false,form,events:{},querySelector(selector){
    if(selector==='[data-prep-responsibility-form]')return form;
    if(selector==='input[name="disposition"]:checked')return status?{value:status}:null;
    if(selector==='input[name="responsibility"]')return {checked:accepted};
    if(selector==='input[name="reason"]')return {value:reason};
    return null;
   },querySelectorAll(){return [{addEventListener(){}}]},addEventListener(type,fn){this.events[type]=fn},
   close(){this.open=false},remove(){this.removed=true},showModal(){this.open=true},
   setAttribute(){},set innerHTML(x){this.html=x},get innerHTML(){return this.html}};
  return dialog;
 };
 const fakeDocument={createElement,body:{append(){}}};
 const context={document:fakeDocument,window:{SoknaUI:{showDialog(el){el.open=true;return true}}},L:{integer:x=>String(x)},String};
 vm.runInNewContext(functionSource+'\nthis.testDialog=operatorPreparationDispositionDialog;',context);
 return {testDialog:context.testDialog, get dialog(){return dialog}};
}
(async()=>{
 const x=setup({status:'unknown',accepted:true,reason:'بازگشت سفارش'});
 const promise=x.testDialog({order_id:17,order_number:31},true);
 assert(x.dialog.innerHTML.includes('دلیل لغو سفارش'),'one dialog with reason');
 assert(x.dialog.innerHTML.includes('وضعیت دریافت نامشخص'),'explicit unknown option');
 x.dialog.form.events.submit({preventDefault(){}});
 const result=await promise;
 assert.equal(result.reason,'بازگشت سفارش');
 assert.equal(result.operator_preparation_disposition,'unknown');
 assert.equal(result.operator_accepts_preparation_responsibility,true);
 assert.equal(x.dialog.removed,true);
 const y=setup({status:'received_or_preparing',accepted:true,reason:''});
 const p=y.testDialog(null,false);y.dialog.form.events.submit({preventDefault(){}});
 const r=await p;assert.equal(r.operator_preparation_disposition,'received_or_preparing');
 assert(!Object.prototype.hasOwnProperty.call(r,'reason'),'refund workflow retains existing reason');
 const z=setup({status:'not_received',accepted:false,reason:'mistake'});
 const q=z.testDialog({order_id:15},true);z.dialog.form.events.submit({preventDefault(){}});
 assert.equal(z.dialog.removed,false,'missing liability acknowledgement does not submit');
 z.dialog.events.cancel({preventDefault(){}});
 assert.equal(await q,null);
 console.log(JSON.stringify({ok:true,test:'real Local operator preparation decision dialog',single_dialog_reason:true,unknown_allowed:true,confirmed_responsibility_required:true,no_kitchen_ack_required:true}));
})().catch(e=>{console.error(e);process.exit(1)});
