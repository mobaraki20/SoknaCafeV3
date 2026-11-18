const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('path').join(process.env.R277_ROOT||'/mnt/data/sokna_r277','work/public/assets/staff-app/hall-workspace.js'),'utf8');
const take=(a,b)=>{const st=source.indexOf(a),en=source.indexOf(b,st);assert(st>=0&&en>st);return source.slice(st,en)};
const code=take('function discountRequest(){','function openDiscountDialog(){')+'\n'+take('async function applyHallDiscount(){','async function removeHallDiscount(){');
const element=()=>({hidden:false,textContent:'',disabled:false,dataset:{},classList:{toggle(){}}});
const things={};for(const k of ['discount-value','discount-input-label','discount-apply','discount-base','discount-amount','discount-tax','discount-tax-row','discount-net','discount-preview-status'])things[k]=element();
const dialog={querySelector:q=>things[(q.match(/data-([\w-]+)/)||[])[1]],querySelectorAll:q=>[],open:true};
things['discount-value'].value='۳۲۰٬۰۰۰';
const calls=[];
let mode='mismatch',freshMutation=0;
const ctx={discountDialog:dialog,financeAccount:{session:{id:7},subtotal:845000},discountDraftType:'fixed',discountPreviewSeq:0,discountPreviewTimer:0,discountPreviewValid:false,discountPreviewSnapshot:null,
 numericValue:x=>Number(String(x).replace(/[۰-۹]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).replace(/[^0-9]/g,'')),
 money:x=>String(x)+' تومان',L:{humanizeNumericInput:()=>{}},setTimeout,clearTimeout,Number,console,
 financeMutate:async req=>{calls.push(req); if(req.action==='discount_preview') {const d=mode==='mismatch'?32000:req.discount_value;return {result:{session_id:req.session_id,discount_type:req.discount_type,discount_value:req.discount_value,subtotal:845000,discount_amount:d,net:845000-d,tax:0,total:845000-d}};}if(req.action==='discount'){freshMutation++;return {result:{discount_value:req.discount_value,discount_type:req.discount_type,discount_amount:req.discount_value}}}throw Error('unknown action')},
 closeDiscountDialog:()=>{},loadAccount:async()=>{},selectedTableId:4,toast:()=>{}};
vm.createContext(ctx);vm.runInContext(code,ctx);
const wait=()=>new Promise(r=>setTimeout(r,220));
(async()=>{
 vm.runInContext('discountPreview()',ctx);await wait();assert.equal(ctx.discountPreviewValid,false);assert.equal(things['discount-apply'].disabled,true);assert.match(things['discount-preview-status'].textContent,/مطابقت ندارد/);try{await vm.runInContext('applyHallDiscount()',ctx);assert.fail('invalid preview applied')}catch(e){assert.match(e.message,/پیش‌نمایش/)}assert.equal(freshMutation,0);console.log('R277_DISCOUNT_10X_MISMATCH_BLOCKS_APPLY_PASS');
 mode='correct';vm.runInContext('discountPreview()',ctx);await wait();assert.equal(ctx.discountPreviewValid,true);assert.equal(things['discount-apply'].disabled,false);assert.equal(things['discount-amount'].textContent,'− 320000 تومان');assert.equal(things['discount-net'].textContent,'525000 تومان');console.log('R277_DISCOUNT_320000_PREVIEW_525000_PASS');
 things['discount-value'].value='۳۲٬۰۰۰';try{await vm.runInContext('applyHallDiscount()',ctx);assert.fail('edited input applied')}catch(e){assert.match(e.message,/پیش‌نمایش/)}assert.equal(freshMutation,0);console.log('R277_DISCOUNT_STALE_EDIT_BLOCKS_APPLY_PASS');
 things['discount-value'].value='۳۲۰٬۰۰۰';vm.runInContext('discountPreview()',ctx);await wait();await vm.runInContext('applyHallDiscount()',ctx);assert.equal(freshMutation,1);assert.equal(calls.filter(x=>x.action==='discount_preview').length,4);console.log('R277_DISCOUNT_DOUBLE_READBACK_AND_ONE_WRITE_PASS');
 console.log('R277_DISCOUNT_SAFETY_TEST_PASS');
})().catch(e=>{console.error(e);process.exitCode=1});