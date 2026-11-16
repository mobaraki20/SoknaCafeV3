from pathlib import Path
import hashlib,json
ROOT=Path(__import__('os').environ.get('R264_ROOT','/mnt/data/sokna_r264_work'))
LOCAL=ROOT/'local'
PUB=ROOT
paths=[LOCAL/'public/assets/staff-app/hall-workspace.js',PUB/'public-hall.js']
manifest=[]
def replace_one(path,old,new,reason):
 s=path.read_text()
 if s.count(old)!=1:raise SystemExit(f'R264_ANCHOR_MISSING {path.name}: {reason}, count={s.count(old)}')
 before=hashlib.sha256(s.encode()).hexdigest()
 path.write_text(s.replace(old,new))
 manifest.append({'path':str(path),'before_sha256':before,'after_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'reason':reason})
for f in paths:
 # Make destructive zero operation a deliberate separate action without changing finance/inventory API.
 old=" const key=adjustmentReason(orderItemId),label=adjustmentReasonLabel(orderItemId),extra=String(accountAdjustmentNotes.get(orderItemId)||'').trim(),reason=(key==='other'&&extra)?`${label} — ${extra}`:label;\n const result=await mutate({action:'order_item_adjust'"
 new=" if(next===0&&current>0){const confirmed=await SoknaUI.confirm('تأیید حذف آیتم',`تعداد «${name}» از ${L.integer(current)} به صفر می‌رسد. این تغییر در حساب ثبت می‌شود و ممکن است به تأیید آماده‌سازی نیاز داشته باشد. ادامه می‌دهی؟`,true);if(!confirmed)return}\n const key=adjustmentReason(orderItemId),label=adjustmentReasonLabel(orderItemId),extra=String(accountAdjustmentNotes.get(orderItemId)||'').trim(),reason=(key==='other'&&extra)?`${label} — ${extra}`:label;\n const result=await mutate({action:'order_item_adjust'"
 replace_one(f,old,new,'Require explicit confirmation before quantity becomes zero')
 old="accountEditFeedback=null;renderAccountItemEditor()}));\n body.querySelectorAll('[data-modal-service-mode]')"
 new="accountEditFeedback=null;renderAccountItemEditor();body.querySelector(`[data-modal-item-delta=\"${step}\"]`)?.focus({preventScroll:true})}));\n body.querySelectorAll('[data-modal-service-mode]')"
 replace_one(f,old,new,'Restore keyboard focus after increment/decrement re-render')
 old="accountEditFeedback=null;renderAccountItemEditor()}));\n body.querySelector('[data-modal-item-note]')"
 new="accountEditFeedback=null;renderAccountItemEditor();body.querySelector(`[data-modal-service-mode=\"${mode}\"][data-modal-service-delta=\"${step}\"]`)?.focus({preventScroll:true})}));\n body.querySelector('[data-modal-item-note]')"
 replace_one(f,old,new,'Restore focus when moving units between dine-in/takeaway')
 old="if(String(b.dataset.modalAdjustReason||'')!=='other')accountAdjustmentNotes.delete(id);renderAccountItemEditor()}));"
 new="if(String(b.dataset.modalAdjustReason||'')!=='other')accountAdjustmentNotes.delete(id);renderAccountItemEditor();body.querySelector(`[data-modal-adjust-reason=\"${String(b.dataset.modalAdjustReason||'customer')}\"]`)?.focus({preventScroll:true})}));"
 replace_one(f,old,new,'Preserve focused reason after newly expanded form')
 # Previously reported extra instructional currency line in the account summary only.
 old="${root.dataset.finalAmountRial==='1'?'<small class=\"sc-hall-payable-help\">مبالغ نهایی پرداخت به ریال است؛ قیمت اقلام تومان باقی می‌ماند.</small>':''}"
 if old in f.read_text():replace_one(f,old,"",'Remove repeated final-currency instructional line from table account only')
for f in [LOCAL/'public/assets/product-ui.css',PUB/'public-product-ui.css']:
 s=f.read_text()
 block="""
/* R264: quantity correction footer stays visible when reason and split details expand.
   This is mobile-only; the accepted desktop account layout is untouched. */
@media (max-width: 520px) {
 .sc-account-item-edit-dialog .sc-dialog-frame {display:flex!important;flex-direction:column!important;max-block-size:min(92dvh,720px)!important;min-block-size:0!important}
 .sc-account-item-edit-dialog .sc-dialog-frame__body {flex:1 1 auto!important;min-block-size:0!important;overflow-y:auto!important;overscroll-behavior:contain;scroll-padding-block-end:16px}
 .sc-account-item-edit-dialog .sc-dialog-frame__foot {flex:0 0 auto!important;position:relative;z-index:1;background:var(--sc-surface-raised)!important;padding-block-end:max(11px,env(safe-area-inset-bottom))!important}
 .sc-account-item-editor__summary {gap:8px;min-inline-size:0}
 .sc-account-item-impact {min-inline-size:0}
 .sc-account-item-editor__section.is-quick .sc-order-stepper {justify-self:stretch;max-inline-size:100%}
 .sc-account-item-edit-dialog .sc-order-adjust-reasons {min-inline-size:0}
}
"""
 if 'R264: quantity correction footer' in s:raise SystemExit('already applied')
 before=hashlib.sha256(s.encode()).hexdigest();f.write_text(s+'\n'+block)
 manifest.append({'path':str(f),'before_sha256':before,'after_sha256':hashlib.sha256(f.read_bytes()).hexdigest(),'reason':'Mobile quantity editor keeps save controls visible and scrollable content bounded'})
# Use existing canonical server package: zero guessed fields, zero executable code.
f=LOCAL/'public/assets/printing-workspace.js'
s=f.read_text();where="async function importPrintTemplate(file){"
assert s.count(where)==1
func='''async function downloadPrintTemplate(packageId){
 try{
  const result=await request(`${api}?action=print_template_details&package_id=${encodeURIComponent(packageId)}`);
  const data=result.result||result;
  const p=data.package;
  if(!p||!p.template_key||!p.document_kind||!p.template)throw new Error('فایل قالب معتبر دریافت نشد.');
  const raw=JSON.stringify(p,null,2)+'\\n';
  if(new Blob([raw]).size>65536)throw new Error('قالب برای دانلود از سقف قرارداد بزرگ‌تر است.');
  const blob=new Blob([raw],{type:'application/json;charset=utf-8'}),url=URL.createObjectURL(blob);
  const a=document.createElement('a');a.href=url;a.download=`${String(p.template_key).replace(/[^a-z0-9._-]/gi,'_')}-${String(p.version||'1.0.0').replace(/[^a-z0-9._-]/gi,'_')}.soknaprint`;
  document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  toast('نسخه واقعی قالب دانلود شد؛ ویرایش و بازگرداندن آن از مسیر Import انجام می‌شود.','success','خروجی قالب');
 }catch(e){sectionState(e.message||'دانلود قالب انجام نشد.',true)}
}
'''
before=hashlib.sha256(s.encode()).hexdigest();s=s.replace(where,func+where)
# Add a download action alongside normal edit, both active and archived rows.
needle="actions.append(button('ویرایش به نسخه جدید',()=>openTemplateEditor(r.id),'secondary'));"
assert s.count(needle)==2
s=s.replace(needle,needle+"actions.append(button('دانلود قالب .soknaprint',()=>downloadPrintTemplate(Number(r.id)),'secondary'));")
f.write_text(s)
manifest.append({'path':str(f),'before_sha256':before,'after_sha256':hashlib.sha256(f.read_bytes()).hexdigest(),'reason':'Download real existing template in its accepted .soknaprint import contract, active/archive, no API changes'})
(ROOT/'R264-SOURCE-PATCH-MANIFEST.json').write_text(json.dumps(manifest,indent=2,ensure_ascii=False))
print(json.dumps(manifest,indent=2,ensure_ascii=False))