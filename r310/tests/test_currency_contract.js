const fs=require('fs'),vm=require('vm'),assert=require('assert');
const B='/mnt/data/sokna-r310-work/';
const helper={window:{},Number,BigInt,Intl};vm.runInNewContext(fs.readFileSync(B+'cumulative-r309/public/assets/settlement-display-currency.js','utf8'),helper);
const curr=helper.window.SoknaFinalAmountCurrency;
assert.strictEqual(curr.format(250000,false),'۲۵۰٬۰۰۰ تومان');
assert.strictEqual(curr.format(250000,true),'۲٬۵۰۰٬۰۰۰ ریال');
assert.strictEqual(curr.parseInput('۲٬۵۰۰٬۰۰۰',true),250000);
assert.strictEqual(curr.parseInput('۲٬۵۰۰٬۰۰۱',true),null);
assert.strictEqual(curr.parseInput('۲۵۰٬۰۰۰',false),250000);
assert.strictEqual(curr.inputValue(250000,true),'2500000');
function functionText(path,name){const src=fs.readFileSync(path,'utf8'),beg=src.indexOf('function '+name+'(');assert(beg>=0,name);let b=src.indexOf('{',beg),depth=0,i=b;for(;i<src.length;i++){if(src[i]==='{')depth++;if(src[i]==='}'&&!--depth)break;}return src.slice(beg,i+1);}
const local=B+'cumulative-r309/public/assets/staff-app/hall-workspace.js';
function request(mode,type,input,partial=false){
 const ctx={
  root:{dataset:{finalAmountRial:mode}},discountDraftType:type,
  discountDialog:{querySelector:()=>({value:input})},
  financeAccount:{session:{id:42},receipt_count:partial?1:0,signature:'guard-17',subtotal:300000,remaining_subtotal:210000,remaining_discount:10000},
  numericValue:v=>{const n=Number(String(v).replace(/[۰-۹]/g,x=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(x)));return Number.isFinite(n)?n:0},window:{SoknaFinalAmountCurrency:curr},Number,Math,String
 };
 vm.runInNewContext(functionText(local,'discountRequest'),ctx);
 return ctx.discountRequest();
}
let r=request('1','fixed','۳۵۰٬۰۰۰');assert.strictEqual(r.discount_value,35000);assert.strictEqual(r.subtotal,300000);
r=request('0','fixed','۳۵۰۰۰');assert.strictEqual(r.discount_value,35000);
r=request('1','fixed','۳۵۰٬۰۰۱');assert.strictEqual(r.discount_value,-1);
r=request('1','percent','۱۰',true);assert.strictEqual(r.discount_value,10);assert.strictEqual(r.subtotal,200000);
r=request('1','fixed','۱۰۰٬۰۰۰',true);assert.strictEqual(r.discount_value,10000);assert.strictEqual(r.subtotal,200000);
const pub=B+'source-public-r306/assets/staff-app/hall-workspace.js';
for(const [mode,input,expected] of [['1','۳۵۰٬۰۰۰',35000],['0','۳۵۰۰۰',35000],['1','۳۵۰٬۰۰۱',-1]]){
 const ctx={root:{dataset:{finalAmountRial:mode}},window:{SoknaFinalAmountCurrency:curr},numericValue: v=>Number(String(v).replace(/[۰-۹]/g,x=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(x)).replace(/[٬،,\s]/g,'')),Number};
 vm.runInNewContext(functionText(pub,'discountInputToman'),ctx);
 assert.strictEqual(ctx.discountInputToman({value:input},'fixed'),expected);
}
console.log(JSON.stringify({result:'PASS',cases:13,local:'R307+R308+R309+R310-QA',public:'35in+R310-QA',canonical_database_unit:'toman',installed:false},null,2));