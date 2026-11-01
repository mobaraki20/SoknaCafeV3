const fs=require('fs'),vm=require('vm');
const root=process.argv[2];
const rows=[];
async function run(file,status,previous='new',replace=false){
 const text=fs.readFileSync(file,'utf8');const from=text.indexOf('const pollWaiter=async()=>');const to=text.indexOf('const startWaiterTracking=',from);
 if(from<0||to<0)throw Error('actual pollWaiter missing');
 const source=text.slice(from,to)+'globalThis.run=pollWaiter;';
 const context={waiterState:{call_code:'W-original',client_token:'token-original',status:previous,owned:false},endpoints:{waiter:'/test'},tableToken:'table',sessionToken:'session',deviceToken:'device',apiOk:x=>x,saveWaiter:()=>{},renderWaiter:()=>{},postJson:async()=>{if(replace)context.waiterState={call_code:'W-new',client_token:'token-new',status:'new'};return {success:true,status}}};
 vm.createContext(context);vm.runInContext(source,context);const result=await context.run();return {context,result};
}
(async()=>{
 for(const [host,rel] of [['local','local/public/assets/scds/guest.js'],['public','public/assets/scds/guest.js']]){
  for(const terminal of ['none','done','completed','cancelled']){const r=await run(root+'/'+rel,terminal);rows.push({host,test:'terminal '+terminal,pass:r.context.waiterState===null&&r.result===false})}
  let r=await run(root+'/'+rel,'new','accepted');rows.push({host,test:'accepted never regresses to new snapshot',pass:r.context.waiterState.status==='accepted'});
  r=await run(root+'/'+rel,'unsupported','accepted');rows.push({host,test:'unknown state preserves receipt',pass:r.context.waiterState.status==='accepted'});
  r=await run(root+'/'+rel,'completed','new',true);rows.push({host,test:'late terminal reply cannot erase newer call',pass:r.context.waiterState.call_code==='W-new'});
  r=await run(root+'/'+rel,'accepted');rows.push({host,test:'shared ownership remains false without canonical proof',pass:r.context.waiterState.status==='accepted'&&r.context.waiterState.owned===false});
 }
 console.log(JSON.stringify(rows,null,2));process.exit(rows.every(x=>x.pass)?0:1);
})().catch(e=>{console.error(e);process.exit(1)});
