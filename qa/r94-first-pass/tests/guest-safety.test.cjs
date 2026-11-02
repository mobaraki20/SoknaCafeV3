'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const src=fs.readFileSync(process.argv[2]||'', 'utf8');
function extract(a,b){const i=src.indexOf(a),j=src.indexOf(b,i+a.length);assert(i>=0&&j>i,'Bad source anchor '+a);return src.slice(i,j)}
const retry=extract('  // Retry only read-only context checks','  const statusLabel=');
async function checkReadiness(responses,hidden=false){
 let tasks=[],calls=0;
 const ctx={tableToken:'T',endpoints:{context:'url'},document:{hidden},contextTimer:null,
 fetchContextOnce:async()=>responses[Math.min(calls++,responses.length-1)],
 setTimeout:(fn,ms)=>{const t={fn,ms};tasks.push(t);return t},clearTimeout:t=>{tasks=tasks.filter(x=>x!==t)},
 setInterval:()=>({}),clearInterval:()=>{}};
 vm.createContext(ctx);vm.runInContext(retry+'globalThis.qa={refreshContext}',ctx);
 const first=ctx.qa.refreshContext(),parallel=ctx.qa.refreshContext();assert.equal(first,parallel);
 await first;return{get calls(){return calls},get tasks(){return tasks},async next(){const t=tasks.shift();assert(t);await t.fn();await new Promise(r=>setImmediate(r))}};
}
async function main(){
 let x=await checkReadiness([null,{mutation_ready:true}]);assert.equal(x.calls,1);assert.equal(x.tasks[0].ms,2000);await x.next();assert.equal(x.calls,2);assert.equal(x.tasks.length,0);
 x=await checkReadiness([null,null,{mutation_ready:true}]);await x.next();assert.equal(x.tasks[0].ms,4000);await x.next();assert.equal(x.tasks.length,0);
 x=await checkReadiness([null],true);assert.equal(x.tasks.length,0);
 const fn=extract('  const cartPayloadConsistent=()=>{','  const basket =');
 function safe(rows,items,payload){const c={cart:new Map(rows.map((r,i)=>[String(i),r])),itemMap:new Map(items.map(i=>[i.id,i])),cartCount:()=>rows.reduce((n,r)=>n+r.quantity,0),payloadLines:()=>payload};vm.createContext(c);return vm.runInContext(fn+'cartPayloadConsistent()',c)}
 const row={id:1,quantity:2,bundle_choice_item_id:0},item={id:1,is_bundle:false};
 assert.equal(safe([row],[item],[{id:1,quantity:2}]),true);
 assert.equal(safe([row],[],[{id:1,quantity:2}]),false);
 assert.equal(safe([row],[item],[{id:1,quantity:1}]),false);
 assert.equal(safe([row],[item],[{id:1,quantity:1},{id:1,quantity:1}]),true);
 const b={id:2,quantity:1,bundle_choice_item_id:0},bi={id:2,is_bundle:true,bundle_components:[{mode:'choice',item_id:3}]};
 assert.equal(safe([b],[bi],[{id:2,quantity:1}]),false);
 assert.equal(safe([{...b,bundle_choice_item_id:3}],[bi],[{id:2,quantity:1}]),true);
 assert(src.includes("!hasOrderContext?(item.available?'':'این آیتم فعلاً ناموجود است.')"));
 assert(src.includes("if (quickSubmit) quickSubmit.disabled = count === 0;"));
 console.log('PASS 10 guest read-only recovery/cart assertions');
}
main().catch(e=>{console.error(e);process.exitCode=1});
