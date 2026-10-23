(function(factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else window.SoknaOperationsCore=api;})(function(){
'use strict';
function numberText(value,{decimal=true,allowEmpty=true}={}){
 const fa='۰۱۲۳۴۵۶۷۸۹',ar='٠١٢٣٤٥٦٧٨٩';
 const raw=String(value??'').trim().replace(/[۰-۹]/g,c=>fa.indexOf(c)).replace(/[٠-٩]/g,c=>ar.indexOf(c)).replace(/[\s,٬]/g,'').replace(/٫/g,'.');
 if(raw===''&&allowEmpty)return '';
 if(!(decimal?/^(?:\d+(?:\.\d+)?|\.\d+)$/:/^\d+$/).test(raw)||!Number.isFinite(Number(raw))||Number(raw)>9007199254740)throw Object.assign(new Error('مقدار عددی معتبر نیست.'),{code:'invalid_quantity',definitive:true});
 return raw;
}
function quantityBase(value,unit){const raw=numberText(value);if(raw==='')return 0;const n=Number(raw);if(unit==='count'&&!Number.isInteger(n))throw Object.assign(new Error('مقدار کالای تعدادی باید عدد صحیح باشد.'),{code:'invalid_quantity',definitive:true});return Math.round(n*(unit==='count'?1:1000));}
function stable(value){if(Array.isArray(value))return value.map(stable);if(value&&typeof value==='object')return Object.fromEntries(Object.keys(value).sort().map(k=>[k,stable(value[k])]));return value;}
class IntentRegistry{
 constructor({storage,key,uid,now=()=>new Date().toISOString()}={}){this.storage=storage;this.key=key;this.uid=uid;this.now=now;this.items={};try{const v=JSON.parse(storage?.getItem(key)||'{}');if(v&&typeof v==='object'&&!Array.isArray(v))this.items=v;}catch{}}
 persist(){try{const data=JSON.stringify(this.items);if(!this.storage)return false;this.storage.setItem(this.key,data);return this.storage.getItem(this.key)===data;}catch{return false;}}
 prepare(scope,payload){const fingerprint=JSON.stringify(stable(payload)),existing=this.items[scope];if(existing){if(existing.fingerprint!==fingerprint)throw Object.assign(new Error('نتیجه ثبت قبلی هنوز معلوم نیست؛ ابتدا همان ثبت را بررسی کن.'),{code:'operation_pending',scope,pending:existing});return existing;}
 const intent={id:this.uid(),created_at:this.now(),payload:JSON.parse(JSON.stringify(payload)),fingerprint};this.items[scope]=intent;if(!this.persist()){delete this.items[scope];throw Object.assign(new Error('ذخیره امن شناسه ثبت روی این دستگاه ممکن نیست؛ ثبت ارسال نشد.'),{code:'operation_identity_storage_unavailable',blocked_before_send:true,definitive:true});}return intent;}
 pending(scope){return this.items[scope]||null;}
 complete(scope,id){if(this.items[scope]?.id===id){delete this.items[scope];this.persist();}}
 list(){return Object.entries(this.items);}
}
function hasUnsaved(drafts,inFlight){return inFlight.size>0||[...drafts.values()].some(d=>d.dirty);}
return Object.freeze({numberText,quantityBase,stable,IntentRegistry,hasUnsaved});
});
