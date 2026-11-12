'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict'),path=require('path');
const root=process.argv[2]||'/mnt/data/qa_sokna_current';
function extract(src){
 let code=fs.readFileSync(src,'utf8');
 const marker='const submitOrder = async (priceRetry=false) => {';
 const a=code.indexOf(marker), b=code.indexOf("submit?.addEventListener('click',submitOrder);",a);
 assert(a>=0&&b>a, 'new R167 source must contain protected handler');
 return code.slice(a,b)+'\nglobalThis.__invoke=submitOrder;';
}
async function run(file, opts){
 let reads=0,writes=0;
 const ctx={
  payloadLines:()=>[{id:11,quantity:1,expected_price:210000}],cartPayloadConsistent:()=>true,editingOrder:opts.edit?{order_code:'O1'}:null,
  orderCommitAllowed:()=>!opts.offline,unsafeAllowed:()=>!opts.offline,orderingEnabled:true,
  loadPendingMutation:()=>opts.pendingMutation?{action:'update',payload:{action:'append'}}:null,
  loadPendingSubmission:()=>opts.pendingSubmission?{client_token:'old'}:null,
  refreshOrders:async()=>{reads++;throw Error('QASTOP');},
  toast:()=>{},renderCart:()=>{},saveGuestDraft:()=>{},closeLayer:()=>{},
  postJson:async()=>{writes++;throw Error('SHOULD_NOT_WRITE');},apiOk:()=>({}),
  preparePendingMutation:()=>{},savePendingMutation:()=>{},schedulePendingMutationRecovery:()=>{},definitiveClientFailure:()=>false,
  endpoints:{orders:'/test'},tableToken:'T',sessionToken:'S',deviceToken:'D',customerNote:null,cart:new Map(),orderTokenKey:'',submit:null,orderMessage:null,
  guestConfirm:async()=>false,resolveCartPriceConflict:async()=>{throw Error('NOT_SUBMITTING')}
 };
 ctx.globalThis=ctx;vm.createContext(ctx);vm.runInContext(extract(file),ctx);
 await ctx.__invoke();return {reads,writes};
}
(async()=>{
 for(const scope of ['local','public']){
  const src=path.join(root,scope,scope==='local'?'public/assets/scds/guest.js':'assets/scds/guest.js');
  for(const [name,opt,expected] of [
    ['existing pending mutation',{pendingMutation:true},0],['existing pending submission',{pendingSubmission:true},0],
    ['both pending',{pendingMutation:true,pendingSubmission:true},0],['editing pending',{pendingMutation:true,edit:true},0],
    ['offline',{offline:true},0],['normal first read',{},1],
  ]){
   const r=await run(src,opt);assert.equal(r.reads,expected,scope+' '+name);assert.equal(r.writes,0,scope+' '+name);
   console.log('PASS',scope,name,r.reads===1?'one read':'no read / no write');
  }
 }
})().catch(e=>{console.error(e);process.exitCode=1});
