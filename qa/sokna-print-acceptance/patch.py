from pathlib import Path
import hashlib,sys
p=Path(sys.argv[1])/'local/public/assets/printing-workspace.js'
b=p.read_bytes();h=lambda b:hashlib.sha256(b).hexdigest()
assert h(b)=='e0077ae3b0e2a4dd195352f90f02324acb674a7b80e0d68cae3e17d0451bfde4'
s=b.decode()
def replace(a,b):
 global s
 assert s.count(a)==1,a
 s=s.replace(a,b)
replace("const t=textValue(key).trim();const n=t!==''?Number(t):Number(base[key]??defaultValue);","const t=templateNumericText(textValue(key));const n=t!==''?Number(t):Number(base[key]??defaultValue);")
replace('function renderTemplateDraftPreview(){',"// Numeric fields are displayed in Persian; the draft preview is not a submit event.\nfunction templateNumericText(value){return L.canonical(value).trim().replace(/[\\s,٬\\u061c\\u200e\\u200f]/g,'');}\nfunction renderTemplateDraftPreview(){")
replace("const value=String(form.elements[key]?.value||'').trim();if(value!=='')template[key]=Number(value)","const value=templateNumericText(form.elements[key]?.value??'');if(value!==''){if(!/^[0-9]+$/.test(value))throw new Error('مقدار عددی قالب معتبر نیست.');const number=Number(value);if(!Number.isSafeInteger(number))throw new Error('مقدار عددی قالب معتبر نیست.');template[key]=number}")
b=s.encode();assert h(b)=='b3034a5258193b41ab50e674205e8e788d4d715f6a7dfceda92fc12fcc13ec5a';p.write_bytes(b)
print('PASS exact one-file Persian draft preview fix')

# Preserve intrinsic grid track height for closed details; do not touch other editors.
p=Path(sys.argv[1])/'local/public/assets/product-ui.css'
b=p.read_bytes();assert h(b)=='9f1078b8949b3c44d7008e109c2fe9021df05097fb39f344de4879f492d572a6'
old=b'.sc-print-template-editor .sc-entity-editor__body>.sc-print-template-disclosure{grid-column:1/-1}'
new=b'.sc-print-template-editor .sc-entity-editor__body{align-content:start;grid-auto-rows:max-content}\n.sc-print-template-editor .sc-entity-editor__body>.sc-print-template-disclosure{grid-column:1/-1;min-block-size:max-content}'
assert b.count(old)==1;b=b.replace(old,new)
assert h(b)=='c5cfe233e02bfcdf090843d107c4063ff72af7d5e0455a209912a0dd958d5243';p.write_bytes(b)
print('PASS scoped print editor grid correction')
