import json,os,pathlib,hashlib,zipfile
ROOT=pathlib.Path(__file__).resolve().parent;OUT=ROOT/'build';qa=OUT/'qa';dist=OUT/'dist'
names=['time','guest-list','guest-list-js','pure','encoding','guest','local','public','settings-after','settings-js-after','finance-after','hall-after','features-after','actions','actions-js','package-bytes','native-local','rebind']+[f'lifecycle-{b}-{r}' for b in ['r53','r81','r83','r84','r85','r86','r88'] for r in ['routine','emergency']]
groups={}
for name in names:
 data=json.loads((qa/(name+'.json')).read_text(encoding='utf-8-sig'));rows=data['tests'] if isinstance(data,dict) else data
 assert rows and all(r['pass'] is True for r in rows),name
 groups[name]={'passed':len(rows),'failed':0}
syntax=json.loads((qa/'syntax.json').read_text());assert syntax['failed']==0
verification=json.loads((dist/'PACKAGE-VERIFICATION.json').read_text())
for p in verification['packages']:assert hashlib.sha256((dist/p['name']).read_bytes()).hexdigest()==p['sha256']
result={'source_commit':os.environ['GITHUB_SHA'],'run_url':'https://github.com/'+os.environ['GITHUB_REPOSITORY']+'/actions/runs/'+os.environ['GITHUB_RUN_ID'],'groups':groups,'total_passed':sum(x['passed'] for x in groups.values()),'syntax':syntax,'package_mode':'full_managed_snapshot','local_baselines':9,'public_baselines':7,'public_same_zip_both_routes_lab':True,'emergency_fixture':'Library R18 GuardCandidate sha256 4271742b7eb999666b0ae651802a1061e515cc25c66bb71af12d8168fa904cd2','native_maintenance_source':'586fdf2abeab4d11283f2c65d9130f31f7830ea0','native_local_coverage':['VerifyOnly','VerifyAndStage','materialization','managed_tree_attestation','wrong_baseline_rejection'],'actual_cafe_installation':'NOT_RUN','actual_emergency_owner':'NOT_VERIFIED','native_local_activation':'NOT_RUN','physical_print':'NOT_RUN','all_open_items_closed':False}
(dist/'QA-RESULTS.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
report=(ROOT/'REPORT-FA.md').read_text()+'\n\nنتیجه ساخت: '+str(result['total_passed'])+' کنترل موفق و '+str(syntax['passed'])+' فایل با نحو صحیح.\n\n[اجرای آزمون‌ها]('+result['run_url']+')\n'
(dist/'REPORT-FA.md').write_text(report)
with zipfile.ZipFile(dist/'R89-QA-EVIDENCE.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
 for p in sorted(qa.glob('*.json')):z.write(p,p.name)
 z.write(dist/'QA-RESULTS.json','QA-RESULTS.json')
print(json.dumps(result,ensure_ascii=False,indent=2))
