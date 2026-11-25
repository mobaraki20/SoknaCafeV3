"""Factory: controlled Home canary assembly, using inherited qualified engine
contract and exact current component evidence. Never a Cafe production approval.
The resulting archive must stay on disposable CI until the positive panel gate
for these exact bytes passes. No changes to payload, update engine or SQL."""
import hashlib,io,json,pathlib,zipfile,sys
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def need(ok,msg):
    if not ok: raise ValueError(msg)
def load(path,pin):
    raw=path.read_bytes();need(H(raw)==pin,'ARCHIVE_PIN')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        need(z.testzip() is None and set(z.namelist())=={'release-set.json','artifacts/local/web.zip'},'ARCHIVE_SHAPE')
        m=json.loads(z.read('release-set.json'));w=z.read('artifacts/local/web.zip')
    need(H(w)==m['artifacts']['local-web']['sha256'],'WEB_PIN')
    return m,w
def build(current,home,evidence,out):
    m,w=load(current,'63f7db98d092fc7f40b785fbae0864aab810e3d277598de0794bff0bc92809a5')
    p,pw=load(home,'279d64dcf3800a764c3fcf751a0fc800857de501943af32428c87cc8db2539a3')
    need(H(w)=='45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4','EXACT_PAYLOAD')
    need(m['for_install'] is False and m['production_pass'] is False and m['status']=='QUARANTINED_RELEASE_CANDIDATE','SOURCE_QUARANTINE')
    # The existing historical Home Canary qualification is already present in
    # the candidate, not synthesized from the 80 negative cases. Scope is
    # controlled Home admission only and field validation remains pending.
    need(p['for_install'] and p['production_pass'] and p['qualification']==m['qualification'],'HISTORICAL_QUALIFICATION_DRIFT')
    q=p['qualification'];need(q['canary'] and q['real_windows'] and q['real_mariadb'] and q['recovery_console_web_down'] and q['business_backup_consistency_real_mariadb'] and q['failure_matrix_cases_required']>=80,'HISTORICAL_CANARY_INCOMPLETE')
    e=json.loads(evidence.read_text(encoding='utf-8-sig'))
    need(e['status']=='QA_RECEIPTS_BOUND_RELEASE_HOLD' and e['native_cases']==18 and e['native_negative_cases']==80 and e['declared_baselines']==7 and e['archived_origins']==1 and e['distribution_authorized'] is False,'EXACT_FACTORY_EVIDENCE')
    need(e['packages']['local_update']['sha256']==H(current.read_bytes()) and e['packages']['local_clean']['sha256']==H(w),'EVIDENCE_PACKAGE_BINDING')
    need(e['source_sha256']=='f54d05e3606852bf61ac25fc5cb8fb3b93b4a84794c791dcc90cbbd1196379bb','EVIDENCE_SOURCE')
    origin=p['targets']['local']
    need(origin['managed_tree_sha256']=='f6edff0b649bf06209ac262808c402ffc13079100686359b8b06398a610054f6' and origin['managed_files']==590,'HOME_ORIGIN')
    with zipfile.ZipFile(io.BytesIO(w)) as a,zipfile.ZipFile(io.BytesIO(pw)) as b:
        old={n:H(b.read(n)) for n in b.namelist() if n.startswith('database/migrations/') and n.endswith('.sql')}
        new={n:H(a.read(n)) for n in a.namelist() if n.startswith('database/migrations/') and n.endswith('.sql')}
        need(len(old)==81 and len(new)==93 and all(new.get(n)==h for n,h in old.items()),'APPEND_ONLY')
    need(len(m['supported_baselines'])==7 and all(x['local']['version']!=origin['version'] for x in m['supported_baselines']),'BASELINE_COUNT')
    m['supported_baselines'].append({'local':origin})
    m['for_install']=True;m['production_pass']=True
    m['status']='CONTROLLED_HOME_CANARY_FIELD_ACCEPTANCE_PENDING'
    m['sequence']=202611260003
    m['qa_notes']={'scope':'CONTROLLED_HOME_CANARY_ONLY_NOT_CAFE_PRODUCTION_APPROVAL','operator_install_allowed':True,'cafe_production_approved':False,'field_install_verified':False,'qualification_scope':'Historical R204/R184 native recovery/backup qualification inherited without modification; current exact payload passed 18 native component and 80 native verifier cases; new positive panel gate for this outer manifest mandatory before delivery. No claim of field or production acceptance.','factory_gate_run':45716869600,'current_exact_source_sha256':e['source_sha256'],'original_quarantine_sha256':H(current.read_bytes()),'current_web_sha256':H(w),'home_archive_sha256':H(home.read_bytes()),'installation':'Explicit operator upload; Maintenance exact baseline, backup and rollback/forward-only guards required; no automatic deployment.','public_update':'Independent; historical Home Public forward fence is not covered.'}
    m['signature']=None
    out.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(out,'w') as z:
        for n,b in sorted({'release-set.json':J(m),'artifacts/local/web.zip':w}.items()):
            i=zipfile.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    print('CONTROLLED_HOME_CANDIDATE_PENDING_EXACT_PANEL_GATE',H(out.read_bytes()))
if __name__=='__main__':build(*map(P,sys.argv[1:]))
