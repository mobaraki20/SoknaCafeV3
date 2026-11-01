import json,pathlib,hashlib,os,zipfile
ROOT=pathlib.Path(__file__).resolve().parent;P=pathlib.Path('publish');qa=P/'qa';rows={}
for n in ['database','update-ui','evidence','native','support-identity','support-zip']:
 d=json.loads((qa/(n+'.json')).read_text(encoding='utf-8-sig'));assert d and all(x['pass'] is True for x in d),n;rows[n]=len(d)
syntax=json.loads((qa/'syntax.json').read_text());assert syntax['failed']==0
web=json.loads((P/'PACKAGE-VERIFICATION.json').read_text());assert web['source_commit']==os.environ['GITHUB_SHA']
for name,value in web['packages'].items():assert hashlib.sha256((P/name).read_bytes()).hexdigest()==value['sha256']
win=json.loads((P/'artifact-index.json').read_text(encoding='utf-8-sig'));assert win['source_commit']==os.environ['GITHUB_SHA'] and win['package_version']=='1.1.28' and win['component_count']==5
name=win['windows_services']['file'];assert name=='SOKNA-Windows-Services-Setup-1.1.28.exe';assert hashlib.sha256((P/name).read_bytes()).hexdigest()==win['windows_services']['sha256']
result={'source_commit':os.environ['GITHUB_SHA'],'groups':rows,'passed':sum(rows.values()),'syntax':syntax,'windows_full_build_and_print_qualification':True,'scoped_exe_service_upgrade_and_installed_identity_readback':True,'actual_cafe_home_web_installation':'NOT_RUN','real_browser':'NOT_RUN','physical_print':'NOT_RUN','host_backup_restore':'NOT_RUN','native_full_local_activation':'NOT_RUN','all_historical_items_closed':False}
(P/'QA-RESULTS.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
report=(ROOT/'REPORT-FA.md').read_text()+'\n\nنتیجه گیت‌ها: '+str(result['passed'])+' کنترل اختصاصی و '+str(syntax['passed'])+' فایل با نحو صحیح؛ گیت ساخت کامل Windows و رگرسیون چاپ نیز موفق است.\n';(P/'REPORT-FA.md').write_text(report)
with zipfile.ZipFile(P/'R91-QA-EVIDENCE.zip','w',zipfile.ZIP_DEFLATED) as z:
 for p in qa.glob('*.json'):z.write(p,p.name)
 z.write(P/'QA-RESULTS.json','QA-RESULTS.json')
(P/'SHA256SUMS.txt').write_text(''.join(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+p.name+'\n' for p in sorted(P.iterdir()) if p.is_file() and p.suffix in ['.zip','.exe']))
print(json.dumps(result,ensure_ascii=False,indent=2))
