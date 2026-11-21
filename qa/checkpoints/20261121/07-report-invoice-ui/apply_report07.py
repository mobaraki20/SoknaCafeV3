from pathlib import Path
import shutil, sys
if len(sys.argv)!=3: raise SystemExit("Usage: python apply_report07.py <extracted_06_source-working> <new_07_source-working>")
BASE=Path(sys.argv[1]);OUT=Path(sys.argv[2])
if OUT.exists(): shutil.rmtree(OUT)
shutil.copytree(BASE,OUT)
for rel in ['local/public/assets/reports-workspace.js','public/assets/reports-workspace.js']:
 p=OUT/rel
 s=p.read_text()
 # Reuse known safe parsing already present in Local; Public needs the same guard
 if rel.startswith('public/'):
  anchor="const displayMoney=v=>L.money?L.money(v):`${L.integer(v)} تومان`;\n"
  assert s.count(anchor)==1
  additional=("// Never infer a reliable invoice average from payment-event counts.\n"
  "function reportInteger(v){if(typeof v==='number')return Number.isSafeInteger(v)?v:null;if(typeof v!=='string'||!/^-?\\d+$/.test(v))return null;const n=Number(v);return Number.isSafeInteger(n)?n:null}\n"
  "function reportNumber(row,key){return row&&Object.prototype.hasOwnProperty.call(row,key)?reportInteger(row[key]):null}\n"
  "function reportCount(row,key){const n=reportNumber(row,key);return n!==null&&n>=0?n:null}\n"
  "function reportMoney(row,key){const n=reportNumber(row,key);return n===null?'—':displayMoney(n)}\n")
  s=s.replace(anchor,anchor+additional)
 # This is shared and is based on a closed table account, not settlement-event count
 anchor="let reportSectionKey='overview',reportAvailableSections=null,reportInvalid=false,reportRequestId=0;"
 assert s.count(anchor)==1
 s=s.replace(anchor,"function invoiceAverageText(summary){const count=reportCount(summary,'invoice_count');return count!==null&&count>0?reportMoney(summary,'avg_ticket'):'—'}\n"+anchor)
 # replace only the literal average drilldown case, not the other KPI reports
 start=s.index(" case 'avg_ticket':return ")
 stop=s.index("\n case 'completed_settlements':return ",start)
 avg_case=(" case 'avg_ticket':return {title:'میانگین فاکتور',"
           "note:'هر حساب نهایی‌شده یک فاکتور است، حتی با چند پرداخت آیتمی؛ میانگین از خالص همان حساب پس از برگشت محاسبه می‌شود.',"
           "columns:['شاخص','مقدار'],rows:[['میانگین فاکتور نهایی',invoiceAverageText(s)],"
           "['تعداد فاکتورهای نهایی',reportCount(s,'invoice_count')===null?'—':L.integer(reportCount(s,'invoice_count'))],"
           "['تعداد عملیات پرداخت',reportCount(s,'completed_settlements')===null?'—':L.integer(reportCount(s,'completed_settlements'))]]};")
 s=s[:start]+avg_case+s[stop:]
 # Align details and KPI labelling, preserving the stable API key completed_settlements
 start=s.index(" case 'completed_settlements':return ")
 stop=s.index("\n case 'order_count':return ",start)
 completed=(" case 'completed_settlements':return {title:'عملیات پرداخت',"
            "note:'هر پرداخت آیتمی یک عملیات است؛ این عدد الزاماً تعداد فاکتورهای نهایی نیست.',"
            "columns:['شاخص','مقدار'],rows:[['عملیات پرداخت ثبت‌شده',reportCount(s,'completed_settlements')===null?'—':L.integer(reportCount(s,'completed_settlements'))],"
            "['تعداد فاکتورهای نهایی',reportCount(s,'invoice_count')===null?'—':L.integer(reportCount(s,'invoice_count'))],"
            "['برگشت سند',reportCount(s,'reversals')===null?'—':L.integer(reportCount(s,'reversals'))]]};")
 s=s[:start]+completed+s[stop:]
 old="metric('میانگین فاکتور',displayMoney(s.avg_ticket),'میانگین مبلغ تسویه')"
 assert s.count(old)==1
 s=s.replace(old,"metric('میانگین فاکتور',invoiceAverageText(s),'خالص هر فاکتور نهایی')")
 old="metric('تسویه کامل',L.integer(s.completed_settlements),'سند ثبت‌شده','info')"
 assert s.count(old)==1
 s=s.replace(old,"metric('عملیات پرداخت',reportCount(s,'completed_settlements')===null?'—':L.integer(reportCount(s,'completed_settlements')),'اسناد پرداخت','info')")
 p.write_text(s)
 print(rel,'BEFORE',len((BASE/rel).read_text()),'AFTER',len(s))
