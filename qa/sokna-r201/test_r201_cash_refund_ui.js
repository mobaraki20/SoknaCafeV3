const fs=require('fs'),vm=require('vm'),assert=require('assert');
const file=(process.env.SOKNA_R201_WEB||'/mnt/data/SOKNA-R200-FIN-QA/local')+'/public/assets/staff-app/hall-workspace.js';
const src=fs.readFileSync(file,'utf8');
function pick(a,b){const start=src.indexOf(a),end=src.indexOf(b,start);assert(start>=0&&end>start,`missing ${a}`);return src.slice(start,end);}
const round=pick('function accountRound(', '\nfunction pendingAccountSummary(');
const commands=pick('async function cancelWholeUnsettledOrder(', '\nfunction accountRounds(');
async function scenario(p={}){
 const sent=[],flags=[],status=[],errors=[];let loads=0;
 const roundData={order_id:91,order_number:7,status:p.status||'accounted',order_source:p.source||'staff',accounting_origin:'regular',items:[{id:1}]};
 const responses=p.responses??['R-1405-000001','دلیل پرداخت برگشت خورد'];
 const ctx={account:{order_rounds:[roundData],table:{id:4,session_id:3}},canCashier:p.cashier??true,canSupervise:p.supervisor??true,canOrder:true,featureCommittedAdjust:true,
  mutationsAllowed:()=>p.allowed??true,L:{integer:x=>String(x),time:x=>String(x||'')},roundState:x=>x,esc:x=>String(x),accountLine:()=>'',
  SoknaUI:{prompt:async()=>responses.shift()??null,confirm:async()=>p.confirm??true},
  selectedTableId:4,clientRequestId:()=>'r201-unique-request-id',toast:(...x)=>flags.push(x),setStatus:(...x)=>status.push(x),actionError:(...x)=>errors.push(x),
  mutate:async x=>{sent.push(x);if(p.error)throw p.error;return {success:true,status:'cancelled'}},load:async()=>{loads++},Number,String,Array,Object,Boolean};
 vm.runInNewContext(round+'\n'+commands+'\nthis.render=accountRound;this.cancel=cancelRefundedCashOrder;this.cancelOld=cancelWholeUnsettledOrder',ctx);
 const html=ctx.render(roundData,0,1);
 if(p.invoke!==false)await ctx.cancel({dataset:{orderCancelRefunded:91}});
 return {sent,flags,status,errors,loads,html,ctx};
}
(async()=>{
 let a=await scenario();assert(a.html.includes('data-order-cancel-refunded="91"'));assert(a.html.includes('data-order-cancel-unsettled="91"'));
 assert.strictEqual(a.sent.length,1);assert.strictEqual(a.sent[0].action,'order_cancel_refunded_cash');
 assert.strictEqual(a.sent[0].cash_refund_physically_confirmed,true);assert.strictEqual(a.sent[0].refund_document_number,'R-1405-000001');assert.strictEqual(a.loads,1);
 console.log('PASS_R201_UI_EXPLICIT_CASH_REFUND_CONFIRMATION');
 a=await scenario({cashier:false});assert(!a.html.includes('data-order-cancel-refunded='));assert.strictEqual(a.sent.length,0);
 a=await scenario({supervisor:false});assert(!a.html.includes('data-order-cancel-refunded='));assert.strictEqual(a.sent.length,0);
 a=await scenario({source:'guest'});assert(!a.html.includes('data-order-cancel-refunded='));assert.strictEqual(a.sent.length,0);
 a=await scenario({status:'cancelled'});assert(!a.html.includes('data-order-cancel-refunded='));assert.strictEqual(a.sent.length,0);
 a=await scenario({allowed:false});assert(!a.html.includes('data-order-cancel-refunded='));assert.strictEqual(a.sent.length,0);
 console.log('PASS_R201_UI_REFUND_ACCESS_RESTRICTIONS');
 a=await scenario({responses:['I-1405-000001','reason']});assert.strictEqual(a.sent.length,0);
 a=await scenario({responses:['R-1405-000001','']});assert.strictEqual(a.sent.length,0);
 a=await scenario({confirm:false});assert.strictEqual(a.sent.length,0);
 console.log('PASS_R201_UI_REQUIRES_R_NUMBER_REASON_AND_ATTESTATION');
 a=await scenario({error:Object.assign(new Error('offline'),{unknown_outcome:true})});assert.strictEqual(a.sent.length,1);assert(a.status.some(x=>String(x[0]).includes('نامشخص')));
 console.log('PASS_R201_UI_UNKNOWN_OUTCOME_NO_AUTO_RETRY');
 a=await scenario({invoke:false});await a.ctx.cancelOld({dataset:{orderCancelUnsettled:91}});assert.strictEqual(a.sent[0].action,'order_cancel_unsettled');
 console.log('PASS_R201_UI_LEGACY_CANCEL_ACTION_UNCHANGED');
 console.log('PASS_R201_REFUNDED_CASH_UI_11_CASES');
})().catch(e=>{console.error(e);process.exit(1)});
