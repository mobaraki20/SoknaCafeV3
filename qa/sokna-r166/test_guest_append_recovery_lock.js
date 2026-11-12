'use strict';
const fs=require('fs'), vm=require('vm'), assert=require('assert/strict'), path=require('path');
function handler(file){
 const code=fs.readFileSync(file,'utf8');
 const a=code.indexOf('const submitOrder = async () => {');
 const b=code.indexOf("submit?.addEventListener('click',submitOrder);",a);
 assert(a>=0&&b>a,`cannot find original submit handler ${file}`);
 return code.slice(a,b)+'\nglobalThis.__invoke=submitOrder;';
}
async function run(file,{mut=false,sub=false,online=true,edit=false}={}){
 const stats={reads:0,writes:0}, ctx={
  submit:null,orderMessage:null,editingOrder:edit?{order_code:'O1'}:null,
  payloadLines:()=>[{id:1,quantity:1}],cartPayloadConsistent:()=>true,
  orderCommitAllowed:()=>online,unsafeAllowed:()=>online,orderingEnabled:true,
  loadPendingSubmission:()=>sub?{client_token:'old'}:null,
  loadPendingMutation:()=>mut?{action:'update',payload:{action:'append'}}:null,
  refreshOrders:async()=>{stats.reads++;throw Error('INTENTIONAL_TEST_STOP');},
  toast:()=>{},renderCart:()=>{},saveGuestDraft:()=>{},closeLayer:()=>{},
  postJson:async()=>{stats.writes++;throw Error('UNEXPECTED_WRITE');},apiOk:()=>({}),
  preparePendingMutation:()=>{},savePendingMutation:()=>{},schedulePendingMutationRecovery:()=>{},definitiveClientFailure:()=>false,
  endpoints:{orders:'/fake'},tableToken:'T1',sessionToken:'S1',deviceToken:'D1',
  cart:new Map(),customerNote:null,orderTokenKey:''
 };
 ctx.globalThis=ctx;vm.createContext(ctx);vm.runInContext(handler(file),ctx);
 await ctx.__invoke();return stats;
}
(async()=>{
 const [a,b]=process.argv.slice(2);assert(a&&b,'Usage: baseline_dir candidate_dir');
 for(const part of ['local','public']){
  const old=path.join(a,part+'.js'),fresh=path.join(b,part+'.js');
  assert.equal((await run(old,{mut:true})).reads,1,`${part}: reproduce replay gap`);
  assert.equal((await run(old,{mut:true,edit:true})).writes,1,`${part}: reproduce edit gap`);
  assert.equal((await run(fresh,{mut:true})).reads,0,`${part}: block overlapping append`);
  assert.equal((await run(fresh,{mut:true,edit:true})).writes,0,`${part}: block overlapping edit`);
  assert.equal((await run(fresh,{mut:true,sub:true})).reads,0,`${part}: block combined pending`);
  assert.equal((await run(fresh,{sub:true})).reads,0,`${part}: retain submit guard`);
  assert.equal((await run(fresh,{online:false})).reads,0,`${part}: retain offline guard`);
  assert.equal((await run(fresh,{})).reads,1,`${part}: normal path unchanged`);
  console.log('PASS '+part+' baseline gap reproduced, 6 protections and normal path checked (8 scenarios)');
 }
 console.log('PASS R166 16/16 REAL SOURCE HANDLER SCENARIOS');
})().catch(e=>{console.error(e.stack);process.exit(1)});
