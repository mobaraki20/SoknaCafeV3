import json,os,zipfile
from prepare import ROOT,OUT
qa=OUT/'qa';dist=OUT/'dist';groups={}
for name in ['time','guest-list','guest-list-js','pure','encoding','guest','local','public','settings-after','settings-js-after','finance-after','hall-after','package']:
 data=json.loads((qa/(name+'.json')).read_text());rows=data['tests'] if isinstance(data,dict) else data
 assert rows and all(r['pass'] is True for r in rows),name
 groups[name]={'passed':len(rows),'failed':0}
rows=json.loads((qa/'hall-before.json').read_text());assert rows
failed=[r['test'] for r in rows if r['pass'] is False]
for required in ['full settlement cannot mix','new order cannot split','partial payment between ID selection','account closing after finance ID selection','operations builder joins caller transaction','prepared publisher model binds old cursor']:
 assert any(required in x for x in failed),required
syntax=json.loads((qa/'syntax.json').read_text());assert syntax['failed']==0
raw={'source_commit':os.environ['GITHUB_SHA'],'run_url':'https://github.com/'+os.environ['GITHUB_REPOSITORY']+'/actions/runs/'+os.environ['GITHUB_RUN_ID'],'groups':groups,'total_passed':sum(x['passed'] for x in groups.values()),'baseline_reproduced':{'hall-before':failed},'syntax':syntax,'code_closed':['AUD-07-coherent-hall-orders-settlement-snapshot'],'public_source_changed':False,'public_version':'R86','remaining':['AUD-03-disabled-capabilities','AUD-04','AUD-05','AUD-06-recovery','real-cafe-seven-day-ledger-reconciliation','historical-late-commit-requests','intermittent-host-timeouts'],'field_verified':[],'live_installation':'NOT_RUN','native_windows_installation':'NOT_RUN','physical_print':'NOT_RUN','real_host_wan':'NOT_RUN','all_open_items_closed':False}
(dist/'QA-RESULTS.json').write_text(json.dumps(raw,ensure_ascii=False,indent=2)+'\n')
report=(ROOT/'REPORT-FA.md').read_text()+'\n\n## نتیجه نهایی همین ساخت\n\n'+str(raw['total_passed'])+' کنترل هدفمند و بسته موفق؛ '+str(syntax['passed'])+' فایل از نظر نحو موفق.\n\n[اجرای آزمون‌ها]('+raw['run_url']+')\n'
(dist/'REPORT-FA.md').write_text(report)
with zipfile.ZipFile(dist/'R87-QA-EVIDENCE.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
 for p in sorted(qa.glob('*.json')):z.write(p,p.name)
 z.write(dist/'QA-RESULTS.json','QA-RESULTS.json')
print(json.dumps(raw,ensure_ascii=False,indent=2))
