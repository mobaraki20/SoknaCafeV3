"""Reusable read-only QA factory receipt gate. Never promotes, builds or deploys a release."""
import argparse, hashlib, io, json, pathlib, re, subprocess, zipfile, copy
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
def need(ok,msg):
    if not ok: raise ValueError(msg)
def safezip(b):
    with zipfile.ZipFile(io.BytesIO(b)) as z:
        n=z.namelist()
        need(len(set(n))==len(n) and all(x and not x.startswith('/') and '\\' not in x and '..' not in x.split('/') for x in n) and z.testzip() is None,'ZIP_INTEGRITY')
        return {x:z.read(x) for x in n if not x.endswith('/')}
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def j(b):return json.loads(b.decode('utf-8-sig'))
def gh(path):return json.loads(subprocess.check_output(['gh','api',path]))
def fetch(c,root):
    repo='mobaraki20/SoknaCafeV3'
    for role,a in c['artifacts'].items():
        need(type(a['run']) is int and type(a['id']) is int and re.fullmatch('[0-9a-f]{64}',a['sha256']) and re.fullmatch('[0-9a-f]{40}',a['head']),'PIN_FORMAT')
        r=gh(f'repos/{repo}/actions/runs/{a["run"]}')
        need(r['conclusion']=='success' and r['head_sha']==a['head'] and r['path']==a['workflow'] and r['head_repository']['full_name']==repo,'RUN_PROVENANCE_'+role)
        v=gh(f'repos/{repo}/actions/artifacts/{a["id"]}')
        need(v['name']==a['name'] and v['digest']=='sha256:'+a['sha256'] and not v['expired'] and v['workflow_run']['id']==a['run'],'ARTIFACT_PROVENANCE_'+role)
        with (root/(role+'.zip')).open('wb') as f:
            subprocess.run(['gh','api',f'repos/{repo}/actions/artifacts/{a["id"]}/zip'],stdout=f,check=True)
def verify(c,root):
    need(c['schema']==1 and c['status']=='QA_ONLY','QA_ONLY')
    need(set(c['artifacts'])=={'packages','native','admission','helper','infra'},'ARTIFACT_ROLES')
    ds={}
    for role,a in c['artifacts'].items():
        b=(root/(role+'.zip')).read_bytes()
        need(H(b)==a['sha256'],'ARTIFACT_HASH_'+role)
        ds[role]=safezip(b)
    z=ds['packages']; e=j(ds['native']['evidence-intake.json']);a=j(ds['admission']['admission-proof.json'])
    d=j(z['factory-evidence-decision.json']); i=j(ds['helper']['artifact-index.json']); infra=j(ds['infra']['infra-receipt.json'])
    need(set(c['files'])=={'local_update','local_clean','public_update','public_clean'},'PACKAGE_ROLES')
    b={}
    for role,f in c['files'].items():
        b[role]=z[f['name']];need(H(b[role])==f['sha256'],'PACKAGE_HASH_'+role)
    need(d['source_sha256']==c['source_sha256'] and d['files']=={v['name']:v['sha256'] for v in c['files'].values()},'FACTORY_RECEIPT_BINDING')
    need(d['distribution_authorized'] is False and d['deployment_authorized'] is False,'FACTORY_RELEASE_HOLD')
    l=safezip(b['local_update']);m=j(l['release-set.json']);w=safezip(b['local_clean']);t=m['targets']['local']
    need(m['for_install'] is False and m['production_pass'] is False and m['status']=='QUARANTINED_RELEASE_CANDIDATE','LOCAL_QUARANTINE')
    need(l['artifacts/local/web.zip']==b['local_clean'] and m['artifacts']['local-web']['sha256']==H(b['local_clean']) and tree(w)==t['managed_tree_sha256'] and len(w)==t['managed_files'],'LOCAL_INNER_TREE')
    sql=sorted(n for n in w if n.startswith('database/migrations/') and n.endswith('.sql'))
    need(len(sql)==c['local_migrations'] and P(sql[-1]).stem==t['migration_head'],'LOCAL_MIGRATIONS')
    need('migration-ledger-sha256-v1:'+H(''.join(P(n).stem+'\0'+H(w[n])+'\n' for n in sql).encode())==t['schema_fingerprint'],'LOCAL_SCHEMA_FINGERPRINT')
    p=safezip(b['public_update']);pm=j(p.pop('manifest.json'));pw=safezip(b['public_clean'])
    need(pm['provenance']['installation_authorized'] is False,'PUBLIC_QUARANTINE')
    managed={n[8:]:v for n,v in p.items() if n.startswith('payload/')}
    need(len(managed)==len(p) and tree(managed)==pm['target_managed_tree_sha256'] and all(pw.get(n)==v for n,v in managed.items()) and len(set(pw)-set(managed))==6,'PUBLIC_TREE')
    need({r['path']:(r['size'],r['sha256']) for r in pm['files']}=={n:(len(v),H(v)) for n,v in managed.items()} and len(pm['files'])==len(managed),'PUBLIC_MANIFEST')
    need(sum(n.startswith('database/migrations/') and n.endswith('.sql') for n in managed)==c['public_migrations'],'PUBLIC_MIGRATIONS')
    h=H(b['local_update']);wh=H(b['local_clean'])
    need(e['status']=='PASS_EXACT_COMPONENT_EVIDENCE_INTAKE' and e['local_package_sha256']==h and e['local_web_sha256']==wh and e['distribution_authorized'] is False,'NATIVE_BINDING')
    need(a['status']=='PASS' and a['package_sha256']==h and a['distribution_authorized'] is False and a['quarantine_stage_rejected'] and a['wrong_tree_rejected'] and a['unsigned_outside_inbox_rejected'] and not a['stage_or_sequence_created'],'ADMISSION_BINDING')
    origins=c['origins']; baseline=m['supported_baselines']; need({o['tree'] for o in origins.values() if not o.get('archive_only')}=={x['local']['managed_tree_sha256'] for x in baseline},'ORIGIN_COVERAGE')
    need(set(a['verified_declared_baselines'])=={x['local']['version'] for x in baseline},'PREFLIGHT_COVERAGE')
    faults=['none','APACHE_STOPPED','DATABASE_BACKUP_VERIFIED','DATABASE_FORWARD_INTENT','POINTER_SWITCHED','COMMITTED']
    expected={(name,f) for name,o in origins.items() for f in (faults if o.get('full_faults') else ['none'])}
    seen=set()
    for r in e['native_cases']:
        k=(r['origin'],r['fault']);need(k in expected and k not in seen,'CASE_DUPLICATE_OR_UNKNOWN');seen.add(k)
        o=origins[k[0]]; forward=k[1] not in ('APACHE_STOPPED','DATABASE_BACKUP_VERIFIED')
        need(r['status']=='PASS' and r['candidate_sha256']==h and r['origin_tree_sha256']==o['tree'] and r['target_tree_sha256']==t['managed_tree_sha256'] and r['phase']==('COMMITTED' if forward else 'RECOVERED_TO_PREVIOUS_SLOT') and r['migrations']==(c['local_migrations'] if forward else o['ledger']) and r['independent_restore'] is True and r['component_bridge_only'] is True and r['distribution_authorized'] is False,'CASE_SCOPE')
        if o.get('archive_only'):need(r['archive_origin_only'] is True,'ARCHIVE_ORIGIN_SCOPE')
    need(seen==expected,'MISSING_CASE')
    clean=e['clean_web'];need(clean['status']=='PASS' and clean['package_sha256']==wh and clean['db_migrations']==c['local_migrations'] and clean['actual_windows'] and clean['actual_http_setup'] and clean['duplicate_rejected'] and clean['distribution_authorized'] is False,'CLEAN_SCOPE')
    need(i['version']==c['helper_version'] and H(ds['helper'][i['file']])==i['sha256']==c['helper_sha256'],'HELPER_HASH')
    need(c['artifacts']['helper']['run']==c['artifacts']['infra']['run'] and infra==e['infra'] and infra['status']=='PASS' and all(infra[k] is True for k in ('fresh','repair','recover_service_registration','real_db_marker_preserved','apache_php_http')) and infra['operator_authorized'] is False,'INFRA_SCOPE')
    need(d['evidence_hashes']=={'native':H(ds['native']['evidence-intake.json']),'admission':H(ds['admission']['admission-proof.json']),'helper_exe':i['sha256']},'RECEIPT_HASH_BINDING')
    return {'status':'QA_RECEIPTS_BOUND_RELEASE_HOLD','source_sha256':c['source_sha256'],'packages':c['files'],'native_cases':len(seen),'declared_baselines':len(baseline),'archived_origins':sum(bool(o.get('archive_only')) for o in origins.values()),'production_qualification_proven':False,'actual_panel_activation_proven':False,'public_live_fence_resolved':False,'actual_home_identity_attested':False,'distribution_authorized':False,'deployment_authorized':False}
def main():
    ap=argparse.ArgumentParser();ap.add_argument('contract');ap.add_argument('archive_dir');ap.add_argument('receipt');ap.add_argument('--download',action='store_true');x=ap.parse_args()
    c=j(P(x.contract).read_bytes());root=P(x.archive_dir)
    if x.download:
        root.mkdir(parents=True,exist_ok=False);fetch(c,root)
    r=verify(c,root)
    # Negative cases use copied contract, not fabricated production evidence.
    for name,mutate in [
        ('wrong_package_hash',lambda z:z['files']['local_update'].update(sha256='0'*64)),
        ('missing_origin',lambda z:z['origins'].pop(next(iter(z['origins'])))),
        ('wrong_helper_hash',lambda z:z.update(helper_sha256='0'*64)),
        ('release_claim',lambda z:z.update(status='PRODUCTION')),
        ('wrong_receipt_digest',lambda z:z['artifacts']['native'].update(sha256='0'*64))]:
        altered=copy.deepcopy(c);mutate(altered)
        try:verify(altered,root)
        except ValueError as exc:print('PASS NEGATIVE',name,str(exc))
        else:raise RuntimeError('NEGATIVE_NOT_REJECTED_'+name)
    P(x.receipt).write_text(json.dumps(r,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(r))
if __name__=='__main__':main()
