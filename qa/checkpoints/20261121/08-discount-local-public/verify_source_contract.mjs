// QA read-only regression guard for Local/Public discount UI working source.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
const root=path.resolve(import.meta.dirname,'../../../..');
const paths=[
 ['apps/local-web/public/assets/staff-app/hall-workspace.js','946808c8e7991bf074f55527a388d03a0a894195'],
 ['apps/public/assets/staff-app/hall-workspace.js','f28d78f339d1a900142ac116d31b010225b2209b']
];
const term="const discountDialog=root.querySelector('[data-hall-discount-dialog]')";
const end='async function chooseSubscriber()';
const blocks=[];
let assertions=0;
for(const [rel,expected] of paths){
 const data=fs.readFileSync(path.join(root,rel));
 const blob=crypto.createHash('sha1').update('blob '+data.length+String.fromCharCode(0)).update(data).digest('hex');
 assert.equal(blob,expected,rel+' must match tested source');assertions++;
 const source=data.toString('utf8'),a=source.indexOf(term),b=source.indexOf(end,a);
 assert(a>=0&&b>a);assertions++;
 const block=source.slice(a,b);
 for(const marker of [
  'preview_authority_valid',
  "if(value<=0||req.session_id<=0||!req.preview_authority_valid)return;",
  'expected_signature:req.expected_signature',
  "درصد تخفیف باید عدد صحیح بین ۱ تا ۱۰۰ باشد.",
  'if(input)input.value=partial?',
  'fresh.discount_amount!==expected.response.discount_amount',
  'if(Number(financeAccount.receipt_count||0)>0)throw new Error'
 ]){assert(block.includes(marker),rel+' missing '+marker);assertions++}
 assert(!block.includes('Math.min(100,value)'),'invalid percent should not be clamped silently');assertions++;
 blocks.push(block);
}
assert.equal(blocks[0],blocks[1],'Local and Public discount flows should share same confirmed contract');assertions++;
console.log(JSON.stringify({qa:'SOKNA-08-discount-ui',assertions,passed:assertions,files:paths.map(p=>p[0])},null,2));
