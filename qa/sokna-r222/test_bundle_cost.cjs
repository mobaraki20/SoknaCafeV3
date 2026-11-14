const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(process.argv[2],'utf8');
const beg=source.indexOf('function calculateBundleRecipeCosts('),end=source.indexOf('function renderBundleCostPreview(',beg);
assert(beg>0&&end>beg,'cost helper present in exact source');
const code=source.slice(beg,end);const context={L:{money:x=>String(x)}};vm.runInNewContext(code+';globalCost=calculateBundleRecipeCosts;',context);
const calc=context.globalCost;
const inv=[{id:1,active:true,average_unit_cost:3,cost_status:'known',review_status:'ready'}, {id:2,active:true,average_unit_cost:5,cost_status:'known',review_status:'ready'}];
const items=[{id:8,name:'cake',preparation_station:'kitchen'},{id:9,name:'tea',preparation_station:'hot_bar'},{id:10,name:'coffee',preparation_station:'cold_bar'}];
const recipes={'8':{review_status:'ready',completeness_status:'complete',components:[{inventory_item_id:1,quantity_base:10,quantity_status:'known'}]},'9':{review_status:'ready',completeness_status:'complete',components:[{inventory_item_id:2,quantity_base:4,quantity_status:'known'}]},'10':{review_status:'ready',completeness_status:'complete',components:[{inventory_item_id:2,quantity_base:2,quantity_status:'known'}]}};
let c=calc([{mode:'fixed',component_item_id:8,quantity:2,price_delta:300},{mode:'choice',component_item_id:9,quantity:1,price_delta:100},{mode:'choice',component_item_id:10,quantity:3,price_delta:-100}],5000,items,recipes,inv);
assert.strictEqual(c.length,2);assert.strictEqual(c[0].cost,80);assert.strictEqual(c[0].price,5400);assert.strictEqual(c[1].cost,90);assert.strictEqual(c[1].price,5200);
console.log('PASS_R222_FIXED_CHOICE_QUANTITY_COST_AND_PRICE');
c=calc([{mode:'fixed',component_item_id:8,quantity:2,price_delta:300}],5000,items,recipes,inv);
assert.strictEqual(c.length,1);assert.strictEqual(c[0].cost,60);console.log('PASS_R222_FIXED_ONLY_KNOWN_COST');
const m={...recipes};delete m['9'];c=calc([{mode:'choice',component_item_id:9,quantity:1,price_delta:0}],5000,items,m,inv);
assert.strictEqual(c[0].cost,null);assert.strictEqual(c[0].missing,1);console.log('PASS_R222_MISSING_RECIPE_NOT_ZERO_COST');
const u=inv.map(x=>x.id===2?{...x,cost_status:'unknown',average_unit_cost:null}:x);
c=calc([{mode:'choice',component_item_id:9,quantity:1,price_delta:0}],5000,items,recipes,u);
assert.strictEqual(c[0].cost,null);console.log('PASS_R222_UNKNOWN_INVENTORY_COST_NOT_ZERO');
const e={...recipes,'9':{...recipes['9'],review_status:'needs_review'}};
c=calc([{mode:'choice',component_item_id:9,quantity:1,price_delta:0}],5000,items,e,inv);
assert.strictEqual(c[0].cost,20);assert.strictEqual(c[0].estimated,true);console.log('PASS_R222_UNREVIEWED_RECIPE_ESTIMATED');
c=calc([{mode:'choice',component_item_id:999,quantity:1,price_delta:0}],5000,items,recipes,inv);
assert.strictEqual(c[0].cost,null);console.log('PASS_R222_MISSING_COMPONENT_NOT_ZERO_COST');

if(process.argv[3]){
 const fixture=JSON.parse(fs.readFileSync(process.argv[3],'utf8'));
 const actual=calc(fixture.components,fixture.basePrice,fixture.items,fixture.recipes,fixture.inventory);
 assert.strictEqual(actual.length,1);
 assert.strictEqual(actual[0].price,fixture.expectedPrice);
 assert.strictEqual(actual[0].cost,fixture.expectedCost);
 console.log('PASS_R222_REAL_MARIADB_COMPONENT_RECIPE_COST_AND_PRICE');
}
