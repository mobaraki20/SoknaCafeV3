#!/usr/bin/env python3
"""Deterministic, operator-uploaded Local v2.1 ZIP for the exact M06 baseline."""
import argparse, hashlib, io, json, tempfile, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RELEASE = 'sokna-local-231bf-u01-iran-time-updater-20261023'
STAMP = '2026-10-23T00:00:00Z'
def sha(raw): return hashlib.sha256(raw).hexdigest()
def encoded(value): return (json.dumps(value, ensure_ascii=False, indent=2)+'\n').encode()
def zip_bytes(files):
    buf=io.BytesIO()
    with zipfile.ZipFile(buf,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name,raw in sorted(files.items()):
            entry=zipfile.ZipInfo(name,(2026,10,23,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED
            entry.external_attr=0o100644<<16;z.writestr(entry,raw)
    return buf.getvalue()
def build(out, refresh=False, baselines_file=None, universal=False):
    source=ROOT/'apps/local-web'
    files={p.relative_to(source).as_posix():p.read_bytes() for p in source.rglob('*') if p.is_file() and p.relative_to(source).as_posix() not in {'tools/build-update-package.py','tools/build-clean-install-package.py'}}
    assert not any(n in files for n in ('config.php','install.lock','.sokna-installation.json'))
    version=files['VERSION.txt'].decode().strip()
    files['resources/local-managed-files-v1.json']=encoded({'format':'sokna-local-managed-files-v1','schema_version':1,'files':sorted(files)})
    canonical=sha(''.join(n+'\0'+str(len(raw))+'\0'+sha(raw)+'\n' for n,raw in sorted(files.items()) if n not in ('resources/release-identity-v1.json','resources/build-provenance.json')).encode())
    identity=json.loads(files['resources/release-identity-v1.json'])
    identity.update(release_set_id=RELEASE,version=version,qualification={'tier':'ci_release_candidate','claim_rule':'Windows CI is not an installed Cafe/Home claim'})
    identity['source']={'canonical_tree_sha256':canonical,'canonical_file_count':len(files)-2,'managed_file_count':len(files),'source_ref':'M06 + Theme R2/CSS review + T01 + U01','digest_algorithm':'sha256(path\\0size\\0file_sha256\\n), lexical path order','digest_excludes':['resources/build-provenance.json','resources/release-identity-v1.json']}
    files['resources/release-identity-v1.json']=encoded(identity)
    files['resources/build-provenance.json']=encoded({'format':'sokna-build-provenance-v1','release_set_id':RELEASE,'version':version,'source':'M06 + subsequent theme corrections + T01 + U01','created_at':STAMP,'production_host_install_verified':False})
    tree=sha(''.join(n+'\0'+sha(raw)+'\n' for n,raw in sorted(files.items())).encode())
    migrations={Path(n).stem:raw for n,raw in sorted(files.items()) if n.startswith('database/migrations/') and n.endswith('.sql')}
    fingerprint='migration-ledger-sha256-v1:'+sha(''.join(n+'\0'+sha(raw)+'\n' for n,raw in migrations.items()).encode())
    baseline=json.loads((ROOT/'release/local-u01/m06-baseline.json').read_text())
    source_baselines=[baseline]
    if baselines_file is not None:
        supplied=json.loads(Path(baselines_file).read_text(encoding='utf-8'))
        if not isinstance(supplied,list) or not 1 <= len(supplied) <= 64:
            raise ValueError('UUP01_BASELINE_LIST_INVALID')
        if any(not isinstance(v,dict) for v in supplied):
            raise ValueError('UUP01_BASELINE_IDENTITY_INVALID')
        source_baselines=supplied
    if universal and len(source_baselines)<2:
        raise ValueError('UUP01_TWO_INDEPENDENT_BASELINES_REQUIRED')
    # Identity is installation-generic, NOT a machine path or source device ID.
    required=('release_id','version','managed_tree_sha256','schema_fingerprint','migration_head')
    for b in source_baselines:
        if any(not isinstance(b.get(k),str) or not b[k] for k in required):
            raise ValueError('UUP01_BASELINE_IDENTITY_INCOMPLETE')
        if any(k in b for k in ('computer_name','hostname','host_id','machine_id','install_path','installed_path')):
            raise ValueError('UUP01_HOST_SPECIFIC_BASELINE_FORBIDDEN')
    source_baselines=sorted(source_baselines,key=lambda b:(b['version'],b['managed_tree_sha256'],b['release_id']))
    if len({json.dumps(b,sort_keys=True) for b in source_baselines})!=len(source_baselines):
        raise ValueError('UUP01_DUPLICATE_BASELINE')
    target={'release_id':RELEASE,'version':version,'managed_tree_sha256':tree,'managed_files':len(files),'schema_fingerprint':fingerprint,'migration_head':next(reversed(migrations)),'authority_epoch_policy':'preserved'}
    web=zip_bytes(files)
    database_side={'migration_dag':[],'forward_boundary_phase':'db_forward_boundary','rollback_after_boundary':'forbidden'}
    manifest={'format':'sokna-release-set','schema_version':'2.1','status':'OPERATOR_UPLOAD_NOT_PUBLISHER_SIGNED','release_set_id':RELEASE,'sequence':2026102301,'release_type':'local_only','created_at':STAMP,'expires_at':'2031-10-23T00:00:00Z','for_install':False,'production_pass':False,
        'requirements':{'maintenance_supervisor_min':'0.3.12','update_engine_min':'0.3.12'},'supported_baselines':[{'local':item,'public':None} for item in source_baselines], 'targets':{'local':target},
        'artifacts':{'local-web':{'path':'artifacts/local/web.zip','sha256':sha(web),'size_bytes':len(web),'component':'local-web','kind':'web-tree-zip-v1'}},'dependency_graph':[],
        'install_plan':[{'phase':'preflight','component':'local-web','mutation':False},{'phase':'db_forward_boundary','component':'local-web','mutation':True},{'phase':'activate','component':'local-web','mutation':True}],
        'compatibility':{'allowed_intermediate_pairs':[],'max_stagger_seconds':0,'client_build_window':{},'protocol_window':{}},'mutation_policy':{'guest':'queue','staff_public':'queue','local':'drain_then_block'},
        'database':{'local':database_side,'public':database_side},'recovery':{'pre_db':'rollback_or_discard_allowed','post_db':'retain_target_updater_forward_recovery_only','disaster_restore':'authority_epoch_reenrollment_required','business_backup_restore':'clean_target_only','machine_identity':'fresh_reprovision_required','public_reenrollment':'credential_rotation_and_authority_epoch_required'},
        'pre_update_backup_policy':{'required':True,'recent_local_verified_max_age_seconds':86400,'require_remote_verified_before_db_boundary':False,'system_recovery_snapshot_required':True,'on_failure':'reject_pre_mutation'},'qualification':{}}
    # Use the exact v2.1 wire format constant owned by the verifier.
    verifier=(ROOT/'windows/maintenance/src/Sokna.Maintenance.Core/ReleaseSetVerifierStager.cs').read_text()
    import re
    manifest['format']=re.search(r'const string Format = "([^"]+)"',verifier)[1]
    raw=zip_bytes({'release-set.json':encoded(manifest),'artifacts/local/web.zip':web})
    out.mkdir(parents=True,exist_ok=True)
    (out/'SOKNA-Local-231bf-U01-Panel-Update.zip').write_bytes(raw)
    (out/'target.json').write_bytes(encoded(target))
    (out/'SHA256SUMS-LOCAL.txt').write_text(sha(raw)+'  SOKNA-Local-231bf-U01-Panel-Update.zip\n')
    if refresh:
        for rel in ('resources/local-managed-files-v1.json','resources/release-identity-v1.json','resources/build-provenance.json'):(source/rel).write_bytes(files[rel])
    print(json.dumps({'package_sha256':sha(raw),'target':target,'source_baseline_count':len(source_baselines),'universal_source_candidate':universal,'install_qualification_claim':False},ensure_ascii=False))
if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--output',type=Path,required=True)
    p.add_argument('--refresh-source',action='store_true')
    p.add_argument('--supported-baselines-json',type=Path)
    p.add_argument('--universal',action='store_true')
    a=p.parse_args()
    build(a.output,a.refresh_source,a.supported_baselines_json,a.universal)
