import pathlib,sys,hashlib,re
r=pathlib.Path(sys.argv[1])
def file(n,h):
 p=r/n;assert hashlib.sha256(p.read_bytes()).hexdigest()==h,n;return p,p.read_text()
css,c=file('public/assets/product-ui.css','052037fe06e613b8ed24cb0b84e002bf671f5cb99cd0d750c9cfe3ad81dd6e69')
_,l=file('local/public/assets/product-ui.css','9f1078b8949b3c44d7008e109c2fe9021df05097fb39f344de4879f492d572a6')
start=l.index('/* R236 reports:');end=l.index('/* R237',start)
# Scope: copy the approved report CSS only, not unrelated Local UI.
css.write_text(c+'\n'+l[start:end])
for scope,h in [('public','849ef0829bfe1dd948c343c3ab8a74ad6de19d14602da92e88c5f4901f3715b1'),('local/public','bf34b738c191600535d33e5e01553b0231f4420fc5923a22523ea5818410af5c')]:
 p,s=file(scope+'/assets/staff-app/data-panels.js',h)
 a=s.index('function renderReportMetrics(');b=s.index('function renderReportSummary(',a)
 if scope=='public':
  _,source=file('local/public/assets/staff-app/data-panels.js','bf34b738c191600535d33e5e01553b0231f4420fc5923a22523ea5818410af5c')
  i=source.index('function renderReportMetrics(');j=source.index('function renderReportSummary(',i);section=source[i:j]
 else:section=s[a:b]
 # No invented zero on incomplete ranges; signed integers only.
 section=section.replace("const rows=[","const val=(v,fmt)=>{if(v===null||v===undefined||!(/^-?\\d+$/.test(String(v)))||!Number.isSafeInteger(Number(v)))return '—';return fmt(Number(v))};const rows=[",1)
 for key in ['gross_sales','discount_total']:section=section.replace("summary."+key+"==null?'—':money(summary."+key+")","val(summary."+key+",money)")
 section=section.replace('money(summary.net_sales??summary.sales_total??0)','val(summary.net_sales??summary.sales_total,money)')
 for key in ['completed_settlements','order_count','reversals']:section=section.replace('integer(summary.'+key+'??0)','val(summary.'+key+',integer)')
 section=section.replace('money(summary.net_expenses??0)','val(summary.net_expenses,money)')
 p.write_text(s[:a]+section+s[b:])
p,s=file('public/assets/reports-workspace.js','750b0bdb849eb6fd9a9931973336a73674e72d807b43278f88b238dea04df3b2')
_,source=file('local/public/assets/reports-workspace.js','d05397752991df8ad3af236a996cfdcbb58430919c2a782c5a7d95cfa685946c')
a=s.index('function reportKpiDetailRows(');b=s.index('function renderKpiDrilldown(',a);i=source.index('function reportKpiDetailRows(');j=source.index('function renderKpiDrilldown(',i)
s=s[:a]+source[i:j]+s[b:];a="dates=form?`\${form.elements.from.value} تا \${form.elements.to.value}`:'بازه انتخابی'".replace('\\$','$');b="dates=form?`\${L.date(form.elements.from.value)} تا \${L.date(form.elements.to.value)}`:'بازه انتخابی'".replace('\\$','$')
assert s.count(a)==1;s=s.replace(a,b);p.write_text(s)
print('PASS report parity scoped CSS, eight metrics, safe unknown drilldown')
