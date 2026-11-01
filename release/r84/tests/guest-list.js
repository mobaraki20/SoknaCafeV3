const fs=require('fs'),vm=require('vm'),root=process.argv[2],results=[];
function check(pass,test,host){results.push({host,test,pass:!!pass});}
function setup(rel){const s=fs.readFileSync(root+'/'+rel,'utf8'),a=s.indexOf('  let ordersReadGeneration='),start=a>=0?a:s.indexOf('  const refreshOrders = async'),end=s.indexOf('  const renderOrders=',start);if(start<0||end<0)throw Error('source boundary missing');
 let tracker={order_code:'O-new',status:'new'},rendered=[{order_code:'O-existing'}],calls=[];
 const ctx={tableToken:'table',sessionToken:'session',deviceToken:'device',endpoints:{orders:'/test'},ordersState:{textContent:''},terminalOrderStatuses:new Set(['cancelled','completed','session_closed']),loadTracker:()=>tracker,apiOk:x=>x,renderOrders:r=>rendered=r,postJson:(_url,payload)=>new Promise((resolve,reject)=>calls.push({payload,resolve,reject}))};
 vm.createContext(ctx);vm.runInContext(s.slice(start,end)+'globalThis.run=refreshOrders;',ctx);
 return {ctx,calls,setTracker:x=>tracker=x,rendered:()=>rendered};}
(async()=>{for(const [host,rel] of [['local','local/public/assets/scds/guest.js'],['public','public/assets/scds/guest.js']]){
 let t=setup(rel),a=t.ctx.run(false);check(t.calls[0].payload.known_order_code==='O-new','read carries active receipt expectation',host);t.calls[0].resolve({orders:[{order_code:'O-new'}]});await a;
 check(t.rendered()[0].order_code==='O-new','current response renders normally',host);
 t=setup(rel);a=t.ctx.run(false);const b=t.ctx.run(false);t.calls[1].resolve({orders:[{order_code:'O-new'}]});await b;t.calls[0].resolve({orders:[]});await a;check(t.rendered()[0]?.order_code==='O-new','older empty reply cannot erase newer rendered list',host);
 t=setup(rel);a=t.ctx.run(false);t.setTracker({order_code:'O-just-committed',status:'new'});t.calls[0].resolve({orders:[]});await a;check(t.rendered()[0]?.order_code==='O-existing','receipt changed during read preserves previous display',host);
 t=setup(rel);a=t.ctx.run(false);t.ctx.sessionToken='other-session';t.calls[0].resolve({orders:[]});await a;check(t.rendered()[0]?.order_code==='O-existing','previous session response cannot alter current list',host);
 t=setup(rel);a=t.ctx.run(false);t.calls[0].reject(Error('Local unavailable'));await a;check(t.rendered()[0]?.order_code==='O-existing','unresolved/offline error preserves displayed orders',host);
 t=setup(rel);t.setTracker({order_code:'O-closed',status:'completed'});a=t.ctx.run(false);check(t.calls[0].payload.known_order_code==='','terminal receipt does not pin a new live session',host);t.calls[0].resolve({orders:[]});await a;check(t.rendered().length===0,'canonical empty result can clear completed history',host);
 }console.log(JSON.stringify(results,null,2));process.exit(results.every(x=>x.pass)?0:1)})().catch(e=>{console.error(e);process.exit(1)});
