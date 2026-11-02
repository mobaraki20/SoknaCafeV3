import json,pathlib,hashlib,zipfile,os
R=pathlib.Path(__file__).resolve().parent;B=R/'build';D=B/'dist';groups={}
for name in ['database','presentation','client','hall-regression','browser','native','public-routine','public-emergency']:
 d=json.loads((B/(name+'.json')).read_text());d=d['checks'] if isinstance(d,dict) else d;assert d and all(x['pass'] is True for x in d),name;groups[name]=len(d)
p=json.loads((D/'PACKAGE-VERIFICATION.json').read_text());assert p['source_commit']==os.environ['GITHUB_SHA']
for n,v in p['packages'].items():assert hashlib.sha256((D/n).read_bytes()).hexdigest()==v['sha256']
result={'source_commit':os.environ['GITHUB_SHA'],'groups':groups,'passed':sum(groups.values()),'actual_host_install':'NOT_RUN','all_open_items_closed':False};(D/'QA-RESULTS.json').write_text(json.dumps(result,indent=2)+'\n');(D/'REPORT-FA.md').write_text((R/'REPORT-FA.md').read_text()+'\nنتیجه گیت‌ها: '+str(result['passed'])+' کنترل اختصاصی موفق.\n')
with zipfile.ZipFile(D/'R93-QA-EVIDENCE.zip','w',zipfile.ZIP_DEFLATED) as z:
 for n in ['database','presentation','client','hall-regression','browser','native','public-routine','public-emergency']:z.write(B/(n+'.json'),n+'.json')
 for p in B.glob('*.png'):z.write(p,p.name)
 z.write(D/'QA-RESULTS.json','QA-RESULTS.json')
print(json.dumps(result,indent=2))
