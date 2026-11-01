import json,os,pathlib,zipfile
from prepare import ROOT,OUT
qa=OUT/'qa';dist=OUT/'dist';groups={};baseline={}
for name in ['time-after','guest-list-after','guest-list-js-after','pure','encoding','guest','local','public','package']:
 data=json.loads((qa/(name+'.json')).read_text());rows=data['tests'] if isinstance(data,dict) else data
 assert rows and all(r['pass'] is True for r in rows),name
 groups[name]={'passed':len(rows),'failed':0}
for name in ['time-before','guest-list-before','guest-list-js-before']:
 rows=json.loads((qa/(name+'.json')).read_text());failed=[r['test'] for r in rows if r['pass'] is False];assert failed,name
 baseline[name]={'failed':len(failed),'reproduced':failed}
syntax=json.loads((qa/'syntax.json').read_text());assert syntax['failed']==0
raw={'source_commit':os.environ['GITHUB_SHA'],'run_url':'https://github.com/'+os.environ['GITHUB_REPOSITORY']+'/actions/runs/'+os.environ['GITHUB_RUN_ID'],'groups':groups,'total_passed':sum(x['passed'] for x in groups.values()),'red_baseline':baseline,'syntax':syntax,'code_closed':['AUD-01','AUD-02'],'field_verified':[],'live_installation':'NOT_RUN','physical_print':'NOT_RUN','real_host_wan':'NOT_RUN','all_open_items_closed':False}
(dist/'QA-RESULTS.json').write_text(json.dumps(raw,ensure_ascii=False,indent=2)+'\n')
report=(ROOT/'REPORT-FA.md').read_text()+'\n\n## نتیجه نهایی همین ساخت\n\n'+str(raw['total_passed'])+' کنترل هدفمند و بسته موفق؛ '+str(syntax['passed'])+' فایل از نظر نحو موفق.\n\n[اجرای آزمون‌ها]('+raw['run_url']+')\n'
(dist/'REPORT-FA.md').write_text(report)
with zipfile.ZipFile(dist/'R84-QA-EVIDENCE.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
 for p in sorted(qa.glob('*.json')):z.write(p,p.name)
 z.write(dist/'QA-RESULTS.json','QA-RESULTS.json')
print(json.dumps(raw,ensure_ascii=False,indent=2))
