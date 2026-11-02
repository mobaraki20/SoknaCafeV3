'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const c=fs.readFileSync(path.join(__dirname,'../overlays/public/assets/staff-app/public-health.js'),'utf8');
const a=c.indexOf('function summarizePublicSales(j){'),b=c.indexOf('async function capturePublicSalesEvidence(){',a);
assert(a>=0&&b>a,'Missing Public sales extraction');
const snippet=c.slice(a,b);
function summarize(j){return vm.runInNewContext(snippet+'summarizePublicSales(j)',{j,Date,Map,Set,Number,String,Array});}
let n=0;function ok(cond,label){n++;assert(cond,label)}
const end='2026-11-02',start='2026-10-20',rows=Array.from({length:14},(_,i)=>({date:new Date(Date.parse(start+'T00:00:00Z')+i*86400000).toISOString().slice(0,10),net_sales:i===4?-20000:i*1000}));
const make=(overrides={})=>({ok:true,stale:false,source_version:'35fx',generated_at:'2026-11-02T05:00:00Z',last_sync_at:'2026-11-02T05:01:00Z',payload:{business_date:end,business_clock:{business_date:end,cutoff:'04:00'},archive:{sales:rows},dashboard:{daily:[]}},...overrides});
let r=summarize(null);ok(r.status==='unavailable','missing model');
r=summarize(make());ok(r.status==='complete'&&r.daily.length===14,'full coverage');ok(r.daily[0].net_sales===0,'known zero');ok(r.daily[4].net_sales===-20000,'negative sale');ok(r.source_version==='35fx','source version');
r=summarize(make({stale:true}));ok(r.status==='stale','stale remains stale');
r=summarize(make({payload:{business_date:end,business_clock:{business_date:end},archive:{sales:rows.slice(0,7)},dashboard:{daily:[]}}}));
ok(r.status==='partial'&&r.known_days===7,'partial coverage');ok(r.daily[13].status==='missing'&&!('net_sales' in r.daily[13]),'missing not zero');
r=summarize(make({payload:{business_date:end,business_clock:{business_date:end},archive:{sales:rows},dashboard:{daily:[{business_date:rows[6].date,net_sales:999999}]}}}));
ok(r.status==='conflict'&&r.conflict_days.includes(rows[6].date),'conflicting two sources');ok(r.daily[6].status==='conflict'&&!('net_sales' in r.daily[6]),'conflict not confident');
r=summarize(make({payload:{business_date:end,business_clock:{business_date:end},archive:{sales:[{date:end,net_sales:null}]}}}));
ok(r.daily[13].status==='missing','null is not zero');
r=summarize(make({payload:{business_date:'bad',business_clock:{business_date:'bad'}}}));ok(r.status==='unavailable','invalid business day');
r=summarize(make({payload:{business_date:end,business_clock:{business_date:end},archive:{sales:[{date:'2020-01-01',net_sales:1234}]}}}));ok(r.known_days===0,'out of range ignored');
ok(!JSON.stringify(summarize(make())).includes('invoice'),'no per-invoice data');
const startFn=c.indexOf('async function capturePublicSalesEvidence(){'),endFn=c.indexOf('function downloadReport(salesEvidence){',startFn);
assert(startFn>=0&&endFn>startFn);
async function fetchCase(response){
 let url='',calls=0;
 const context={systemApi:'https://unit.test/hpublic/api/staff/health/system',location:{href:'https://unit.test/hpublic/'},URL,AbortController,
 setTimeout:()=>1,clearTimeout:()=>{},summarizePublicSales:summarize,fetch:async(v,opt)=>{calls++;url=v;ok(opt.cache==='no-store'&&opt.credentials==='same-origin'&&!opt.method,'GET only');return response;}};
 vm.createContext(context);const result=await vm.runInContext(c.slice(startFn,endFn)+'capturePublicSalesEvidence()',context);
 return{url,calls,result};
}
(async()=>{
 let f=await fetchCase({ok:true,json:async()=>make()});ok(f.url.endsWith('/api/staff/read?model=reports'),'correct Public report model');ok(f.result.status==='complete','captured projection');
 f=await fetchCase({ok:false,status:403});ok(f.result.status==='unavailable'&&f.result.error_code==='reports_model_http_403','honor permission error');
 console.log('PASS '+n+' public sales support checks');
})().catch(e=>{console.error(e);process.exitCode=1});
