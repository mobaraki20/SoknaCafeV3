(()=>{'use strict';
const TYPES=[
 {type:'cash',label:'نقدی'},
 {type:'pos',label:'کارتخوان'},
 {type:'bank_transfer',label:'کارت‌به‌کارت'}
];
const faToEn=v=>String(v??'').replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[٬،,\s]/g,'');
const amount=v=>{const n=Number(faToEn(v));return Number.isFinite(n)?Math.max(0,Math.trunc(n)):0};
const fresh=()=>({total:0,selected:[],amounts:{}});
function rebalance(state){if(state.selected.length<2)return state;const auto=state.selected[state.selected.length-1];let allocated=0;state.selected.slice(0,-1).forEach(type=>allocated+=amount(state.amounts[type]));state.amounts[auto]=Math.max(0,Number(state.total||0)-allocated);return state}
function sync(state,total){state.total=Math.max(0,Math.trunc(Number(total||0)));if(state.selected.length===1){state.amounts={[state.selected[0]]:state.total}}else if(state.selected.length===0){state.amounts={}}else{const next={};state.selected.forEach(t=>{next[t]=amount(state.amounts[t])});state.amounts=next;rebalance(state)}return state}
function setAmount(state,type,value){if(!state.selected.includes(type))return state;const auto=state.selected[state.selected.length-1];if(state.selected.length>1&&type===auto)return rebalance(state);state.amounts[type]=amount(value);return rebalance(state)}
function toggle(state,type,total){if(!TYPES.some(x=>x.type===type))return state;const before=state.selected.length,idx=state.selected.indexOf(type);if(idx>=0){state.selected.splice(idx,1);delete state.amounts[type]}else state.selected.push(type);if(before===1&&state.selected.length===2){state.amounts[state.selected[0]]=0;state.amounts[state.selected[1]]=Math.max(0,Math.trunc(Number(total||0)))}return sync(state,total)}
function rows(state){return state.selected.map(type=>({type,amount:amount(state.amounts[type])})).filter(x=>x.amount>0)}
function sum(state){return rows(state).reduce((n,x)=>n+x.amount,0)}
function remaining(state){return Math.max(0,Number(state.total||0)-sum(state))}
function delta(state){return Number(state.total||0)-sum(state)}
function valid(state){return Number(state.total||0)>0&&state.selected.length>0&&rows(state).length===state.selected.length&&delta(state)===0}
function label(type){return TYPES.find(x=>x.type===type)?.label||type}
window.SoknaSettlementTender={TYPES,fresh,sync,toggle,setAmount,rows,sum,remaining,delta,valid,label,amount,rebalance,autoType:state=>state.selected.length>1?state.selected[state.selected.length-1]:''};
})();
