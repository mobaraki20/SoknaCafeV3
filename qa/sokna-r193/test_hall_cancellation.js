const fs=require('fs'), vm=require('vm'), assert=require('assert');
const s=fs.readFileSync((process.env.SOKNA_R193_WEB||'/mnt/data/SOKNA-R193-QA/local')+'/public/assets/staff-app/hall-workspace.js','utf8');
function excerpt(a,b){const start=s.indexOf(a);assert(start>=0,a);const end=s.indexOf(b,start);assert(end>start,b);return s.slice(start,end)}
const round=excerpt('function accountRound(', '\nfunction pendingAccountSummary(');
const cancel=excerpt('async function cancelWholeUnsettledOrder(', '\nfunction accountRounds(');
async function simulate(opts){
 let posts=[],prompts=0,confirms=0,loads=0,toasts=[],status=[],errs=[];
 const account={order_rounds:[{order_id:91,order_number:5,status:opts.status||'accounted',order_source:opts.source||'staff',accounting_origin:'regular',items:[{id:1}]}],table:{session_id:3,id:4}};
 const ctx={account,canSupervise:opts.supervisor??true,canOrder:true,featureCommittedAdjust:true,canSuperviseFlag:true,
  mutationsAllowed:()=>opts.mutations??true,L:{integer:n=>String(n),time:v=>String(v||'')},roundState:s=>s,
  esc:x=>String(x),accountLine:()=>'<li></li>',
  SoknaUI:{prompt:async()=>{prompts++;return opts.reason??'ثبت اشتباه';},confirm:async()=>{confirms++;return opts.confirm??true;}},
  selectedTableId:4,clientRequestId:()=> 'id-unique',toast:(...args)=>{toasts.push(args)},setStatus:(...args)=>status.push(args),actionError:(...args)=>errs.push(args),
  mutate:async p=>{posts.push(p);if(opts.mutateError)throw opts.mutateError;return {success:true,status:'cancelled'};},
  load:async()=>{loads++}, Number, String, Boolean, Array, Object
 };
 vm.runInNewContext(round+'\n'+cancel+'\nthis.render=accountRound;this.cancel=cancelWholeUnsettledOrder',ctx);
 const html=ctx.render(account.order_rounds[0],0,1);
 if(opts.expectButton===false)assert(!html.includes('data-order-cancel-unsettled='));else assert(html.includes('data-order-cancel-unsettled="91"'));
 await ctx.cancel({dataset:{orderCancelUnsettled:91}});
 return {posts,prompts,confirms,loads,toasts,status,errs};
}
(async()=>{
 let x=await simulate({});assert(x.posts.length===1);assert.equal(x.posts[0].action,'order_cancel_unsettled');assert.equal(x.posts[0].request_id,'id-unique');assert.equal(x.posts[0].reason,'ثبت اشتباه');assert.equal(x.loads,1);
 x=await simulate({supervisor:false,expectButton:false});assert.equal(x.posts.length,0);
 x=await simulate({source:'guest',expectButton:false});assert.equal(x.posts.length,0);
 x=await simulate({status:'cancelled',expectButton:false});assert.equal(x.posts.length,0);
 x=await simulate({mutations:false,expectButton:false});assert.equal(x.posts.length,0);
 x=await simulate({reason:''});assert.equal(x.posts.length,0);
 x=await simulate({confirm:false});assert.equal(x.posts.length,0);
 x=await simulate({mutateError:Object.assign(new Error('timeout'),{unknown_outcome:true})});assert.equal(x.posts.length,1);assert(x.status.some(z=>String(z[0]).includes('نامشخص')));
 console.log('PASS_R193_UI_ACTION_TESTS=8');
})().catch(e=>{console.error(e);process.exit(1)});
