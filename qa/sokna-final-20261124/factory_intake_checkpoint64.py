#!/usr/bin/env python3
"""Factory intake for the exact frozen checkpoint46 product. QA only.
Reuses successful receipts, reconstructs the archived Public origin extension
without touching product logic, and refuses to issue a production approval.
Inputs are immutable artifacts downloaded from the recorded successful runs.
"""
import hashlib,io,json,pathlib,sys,zipfile,shutil
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def need(ok,why):
    if not ok: raise ValueError(why)
def read(b):
    with zipfile.ZipFile(io.BytesIO(b)) as z:
        ns=z.namelist()
        need(len(ns)==len(set(ns)) and z.testzip() is None,'ZIP_INTEGRITY')
        need(all(not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in ns),'ZIP_PATH')
        return {n:z.read(n) for n in ns}
def pack(d):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w') as z:
        for n,v in sorted(d.items()):
            i=zipfile.ZipInfo(n,(2026,11,24,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(i,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return b.getvalue()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def load(p):return json.loads(p.read_text(encoding='utf-8-sig'))
def file(p,h):need(H(p.read_bytes())==h,'PIN_DRIFT:'+p.name);return p.read_bytes()
def main():
    if len(sys.argv)!=6:sys.exit('usage: factory_intake.py PACKAGES EVIDENCE ADMISSION HELPER OUTPUT')
    pkgs,evdir,addir,helperdir,out=map(P,sys.argv[1:])
    need(not out.exists(),'OUTPUT_EXISTS')
    pins={'LOCAL-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip':'63f7db98d092fc7f40b785fbae0864aab810e3d277598de0794bff0bc92809a5','LOCAL-FINAL-WEB-QA-DO-NOT-INSTALL.zip':'45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4','PUBLIC-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip':'fbf446d0e29c876bf72e01535cb8e252f21de1a2df888e76239ba5338a9170f5','PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip':'f65f3ca6d478a2d851b169f79583e66845f86ec9cbd93f1881420d51a6e16d85'}
    blobs={n:file(pkgs/n,h) for n,h in pins.items()}
    ln,lw,pn,pw=pins
    l=read(blobs[ln]);m=json.loads(l['release-set.json'])
    need(m['for_install'] is False and m['production_pass'] is False and m['status']=='QUARANTINED_RELEASE_CANDIDATE','LOCAL_QUARANTINE')
    need(l['artifacts/local/web.zip']==blobs[lw] and H(blobs[lw])==m['artifacts']['local-web']['sha256'],'LOCAL_WEB_BINDING')
    need(tree(read(blobs[lw]))==m['targets']['local']['managed_tree_sha256'],'LOCAL_TREE')
    e=load(evdir/'evidence-intake.json');a=load(addir/'admission-proof.json')
    need(e['status']=='PASS_EXACT_COMPONENT_EVIDENCE_INTAKE' and e['local_package_sha256']==pins[ln] and e['local_web_sha256']==pins[lw],'NATIVE_EVIDENCE_DRIFT')
    need(a['status']=='PASS' and a['package_sha256']==pins[ln] and a['quarantine_stage_rejected'] and a['wrong_tree_rejected'] and a['unsigned_outside_inbox_rejected'] and not a['stage_or_sequence_created'],'ADMISSION_DIAGNOSTIC_DRIFT')
    need(set(a['verified_declared_baselines'])=={x['local']['version'] for x in m['supported_baselines']},'BASELINE_COVERAGE')
    need(len(e['native_cases'])==18 and all(x['candidate_sha256']==pins[ln] and x['status']=='PASS' and x['component_bridge_only'] and not x['distribution_authorized'] for x in e['native_cases']),'NATIVE_CASES')
    need(all(x['distribution_authorized'] is False for x in (e,a)),'UNEXPECTED_AUTHORIZATION')
    need(e['clean_web']['actual_windows'] and e['clean_web']['actual_http_setup'] and e['clean_web']['db_migrations']==93 and e['clean_web']['package_sha256']==pins[lw],'WEB_CLEAN')
    idx=load(helperdir/'artifact-index.json')
    need(idx['version']=='1.0.5-qa.2','HELPER_VERSION')
    helper=file(helperdir/idx['file'],'e36a7f5507ca6ff44614d8cef06f057462f80336969bee47b69e33fcf0bd91a3')
    need(H(helper)==idx['sha256'],'HELPER_INDEX')
    need(all(e['infra'][k] is True for k in ('fresh','repair','recover_service_registration','real_db_marker_preserved','apache_php_http')),'INFRA_QA')
    # Exact reproduction of already accepted archive-origin metadata extension,
    # checkpoint52. This is not evidence of the actual installed Home host.
    p=read(blobs[pn]);pm=json.loads(p.pop('manifest.json'))
    full=read(blobs[pw]);managed={n[8:]:b for n,b in p.items()}
    protected={n:b for n,b in full.items() if n not in managed}
    need(len(protected)==6 and full==managed|protected and tree(managed)==pm['target_managed_tree_sha256'],'PUBLIC_TREE')
    home={'version':'3.0.0-g3.6-workspace.35hv-r181-home-ux-uat','managed_tree_sha256':'90b26f6d15899b3d53dc85d653928b9851e3580ea73d38e5d3e83cf258126619'}
    base=pm['compatibility']['supported_baselines'];need(all(x['version']!=home['version'] for x in base),'DUPLICATE_ORIGIN')
    base.insert(len(base)-1,home)
    n='resources/release-identity-v1.json';i=json.loads(managed[n]);i['baseline']['source_versions']=[x['version'] for x in base];managed[n]=J(i)
    pm['target_managed_tree_sha256']=tree(managed)
    pm['files']=[{'path':n,'size':len(b),'sha256':H(b)} for n,b in sorted(managed.items())]
    # The source reference marks only the additional origin; release identity
    # itself already excludes this metadata file from its canonical tree.
    need(pm['provenance']['installation_authorized'] is False,'PUBLIC_QUARANTINE')
    blobs[pn]=pack({'manifest.json':J(pm)}|{'payload/'+n:b for n,b in managed.items()})
    blobs[pw]=pack(managed|protected)
    need(H(blobs[pn])=='6099b60f9a5869d7cc24ca568a8be104139a10bb25b136882e5a40a77402e6d9','PUBLIC52_OUTER_DRIFT')
    need(H(blobs[pw])=='3198a800e00fb97888dde61014aab24129b5c78e3f0c938468223fe8e2667c8f','PUBLIC52_WEB_DRIFT')
    out.mkdir(parents=True)
    for n,b in blobs.items():(out/n).write_bytes(b)
    receipt={'format':'sokna-factory-evidence-intake-v1','status':'QA_EVIDENCE_INTAKE_PASS_RELEASE_HOLD','source_sha256':'f54d05e3606852bf61ac25fc5cb8fb3b93b4a84794c791dcc90cbbd1196379bb','files':{n:H(b) for n,b in blobs.items()},'evidence_runs':{'factory':45706374139,'native_intake':45714421467,'quarantine_preflight':45714663165,'infra':45710818450},'evidence_hashes':{'native':H((evdir/'evidence-intake.json').read_bytes()),'admission':H((addir/'admission-proof.json').read_bytes()),'helper_exe':H(helper)},'verified':{'native_component_cases':18,'declared_local_baselines':7,'archive_home_local_case':True,'local_clean_web':True,'infrastructure_repair_recovery':True,'quarantined_package_preflight':True,'public_checkpoint52_exact_reproduction':True},'required_remaining':['actual_home_local_installed_identity','public_home_fence_owner_and_live_schema_proof','production_release_qualification_including_80_case_matrix_and_canary','actual_installable_panel_stage_and_activation','field_acceptance_and_real_host_requirements'],'distribution_authorized':False,'deployment_authorized':False}
    (out/'factory-evidence-decision.json').write_bytes(J(receipt))
    print('PASS EXACT CURRENT FOUR QA PACKAGES + REUSED VERIFIED RECEIPTS; RELEASE HOLD')
if __name__=='__main__':main()
