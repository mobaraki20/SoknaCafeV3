'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../overlays/local/assets/staff-app/hall-workspace.js','utf8');
const start=source.indexOf('function accountPriceChangeRows(){'),end=source.indexOf('function accountPriceChangeSummary(){',start);
assert(start>0&&end>start);
const code=source.slice(start,end);
function rows(changes){return vm.runInNewContext(code+'accountPriceChangeRows()', {account:{price_changes:changes},Map,Number,String,Array,JSON});}
let n=0;function check(cond,name){assert(cond,name);n++}
const make=(item_id,name,qty=1,recorded_price=20000,current_price=24000)=>({item_id,name,quantity:qty,recorded_price,current_price});
let out=rows([make(17,'لاته'),make(18,'لاته')]);check(out.length===2,'same name different SKUs do not merge');
out=rows([make(17,'لاته',2),make(17,'لاته',3)]);check(out.length===1&&out[0].quantity===5,'same SKU/price snapshots merge');
out=rows([make(17,'لاته',1,10000,20000),make(17,'لاته',1,12000,20000)]);check(out.length===2,'same SKU different recorded price remains distinct');
out=rows([make(17,'لاته',0),make(18,'لاته',-3)]);check(out.length===0,'zero/negative quantity hidden');
out=rows([make(17,'لاته',1,22000,22000)]);check(out.length===0,'no price change no warning');
out=rows([make(0,'بدون شناسه'),make(0,'بدون شناسه')]);check(out.length===1&&out[0].quantity===2,'legacy item without ID groups conservatively');
out=rows([make(17,'لاته',1,-10,20000)]);check(out.length===0,'invalid historical price ignored');
out=rows([]);check(out.length===0,'empty account has no price warnings');
console.log('PASS '+n+' narrow price change identity checks');
