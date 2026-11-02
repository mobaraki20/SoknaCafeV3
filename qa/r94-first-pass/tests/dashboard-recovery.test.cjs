const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const script=fs.readFileSync(require('node:path').join(__dirname,'../overlays/public/assets/staff-app/dashboard-recovery.js'),'utf8');
async function runCase({initialStale=true,responseStale=false,success=true,visible=true}={}){
 let reloads=0,fetches=0,task=null;
 const listeners={};
 const document={hidden:!visible,querySelector:s=>s==='[data-projection-stale-banner]'&&initialStale?{}:null,addEventListener:(n,fn)=>{listeners['document:'+n]=fn}};
 const window={location:{reload(){reloads++}},addEventListener:(n,fn)=>{listeners['window:'+n]=fn}};
 const scheduler=(fn,ms)=>{task={fn,ms};return task};
 const parser={parseFromString:()=>({querySelector:s=>s==='.sc-dashboard'?{}:(s==='[data-projection-stale-banner]'&&responseStale?{}:null)})};
 const globals={document,window,location:{href:'https://example.test/hpublic/staff/'},
  fetch:async(_url,opts)=>{fetches++;assert.equal(opts.method,'GET');assert.equal(opts.cache,'no-store');return{ok:success,headers:{get:()=> 'text/html; charset=UTF-8'},text:async()=>'<html></html>'}},
  DOMParser:class{parseFromString(){return parser.parseFromString()}},AbortController,clearTimeout:()=>{},setTimeout:scheduler};
 vm.runInNewContext(script,globals);
 if(task){const job=task;task=null;await job.fn();}
 await new Promise(resolve=>setImmediate(resolve));
 return{reloads,fetches,task};
}
(async()=>{
 let r=await runCase({initialStale:false});assert.equal(r.fetches,0);assert.equal(r.reloads,0);
 r=await runCase({initialStale:true,responseStale:false});assert.equal(r.fetches,1);assert.equal(r.reloads,1);
 r=await runCase({initialStale:true,responseStale:true});assert.equal(r.fetches,1);assert.equal(r.reloads,0);assert(r.task);
 r=await runCase({initialStale:true,success:false});assert.equal(r.reloads,0);assert(r.task);
 r=await runCase({initialStale:true,visible:false});assert.equal(r.fetches,0);assert(r.task);
 console.log('PASS: dashboard recovery tests');
})().catch(e=>{console.error(e);process.exitCode=1});
