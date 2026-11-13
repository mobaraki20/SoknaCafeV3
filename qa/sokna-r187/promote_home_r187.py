#!/usr/bin/env python3
"""Promote a byte-pinned, exact R184 CI payload to a *Home-only* Local canary.

No business logic, migration, or Windows-service file is altered; only the web
VERSION and build/release metadata change. Must be followed by Windows full-cycle
Preflight->Stage->Activate->Readback against the resulting exact ZIP.
"""
import argparse, io, zipfile, hashlib, json, pathlib, sys, datetime

SOURCE_SHA='da884efc86b3801d11d3842411d6189d916ff2736451aefe3801dafbea7a59ec'
SOURCE_NAME='SOKNA-R184-CI-QUARANTINED-STAGE-PROBE-NOT-FOR-INSTALL.zip'
VERSION='3.0.0-g6.2-workspace.231ew-r187-home-canary-20261113'
RELEASE='sokna-r187-local-home-canary-20261113'
STATUS='R187_LOCAL_HOME_CANARY_56BASE_QUALIFIED'
NAME='SOKNA-R187-LOCAL-231EW-56BASE-HOME-CANARY-PANEL-UPDATE.zip'
ALLOWED_CHANGED={'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}
H=lambda b:hashlib.sha256(b).hexdigest()
def writej(x):return (json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode('utf-8')
def zz(data):
    out=io.BytesIO()
    with zipfile.ZipFile(out,'w') as z:
        for key, val in sorted(data.items()):
            info=zipfile.ZipInfo(key, (2026,11,13,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED
            info.external_attr=0o100644<<16;info.create_system=3
            z.writestr(info,val,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return out.getvalue()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def canon(d):
    skip={'resources/build-provenance.json','resources/release-identity-v1.json'}
    return H(''.join(k+'\0'+str(len(d[k]))+'\0'+H(d[k])+'\n' for k in sorted(d) if k not in skip).encode())
def promote(source, outdir):
    raw=source.read_bytes()
    if H(raw)!=SOURCE_SHA:raise ValueError('R184_INPUT_BLOB_NOT_PINNED_SHA256')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        if set(z.namelist())!={'release-set.json','artifacts/local/web.zip'} or z.testzip() is not None: raise ValueError('NOT_STANDALONE_R184_PACKAGE')
        manifest=json.loads(z.read('release-set.json'))
        web=z.read('artifacts/local/web.zip')
    if manifest['release_set_id']!='sokna-r184-home-qualification-ci-20261113' or manifest['targets']['local']['managed_tree_sha256']!='c818a1c396c9ca6c166451699f9544876521cc21e577d89231a48f236f3feaed': raise ValueError('R184_PROVENANCE_UNEXPECTED')
    if manifest['qa_notes'].get('r184_not_for_customer_delivery') is not True:raise ValueError('SOURCE_NOT_ACTUALLY_QUARANTINED')
    with zipfile.ZipFile(io.BytesIO(web)) as z:
        if z.testzip() is not None: raise ValueError('R184_BAD_WEB_CRC')
        original={k:z.read(k) for k in z.namelist()}
    if len(original)!=589 or not original['VERSION.txt'].decode().strip().endswith('231ev-r184-home-qualification-ci'):raise ValueError('R184_SOURCE_UNEXPECTED')
    files=dict(original)
    files['VERSION.txt']=(VERSION+'\n').encode()
    p=json.loads(files['resources/build-provenance.json'])
    p.update(version=VERSION,release_set_id=RELEASE,classification='R187_HOME_ONLY_CANARY_REQUIRES_HOST_BACKUP',production_host_install_verified=False,full_business_acceptance=False,installed_host_accepted=False,source='Exact R184 (R177 + scoped R182/R183 fixes); R186 native Windows 231ed to 231ev activation passed; 3 metadata files changed for R187')
    # Re-state actual limitations; no claim that cafe Production or all business defects were qualified.
    p['r186_exact_source_qa_run']=43386744942
    p['r185_backup_and_migration_qa_run']=43381864922
    files['resources/build-provenance.json']=writej(p)
    ident=json.loads(files['resources/release-identity-v1.json'])
    ident.update(version=VERSION,release_set_id=RELEASE)
    ident['qualification']['tier']='R187_HOME_CANARY_REQUIRES_HOST_BACKUP_CAFE_NO_GO'
    ident['qualification']['claim_rule']='Home canary only, actual Windows Stage/Activate must pass for exact R187 ZIP; on-host backup required before mutation; no cafe Production approval.'
    ident['source'].update(canonical_file_count=587,managed_file_count=589,canonical_tree_sha256=canon(files),source_ref='Byte-pinned R184 same functional logic; only VERSION and 2 metadata files changed for R187 Home canary')
    files['resources/release-identity-v1.json']=writej(ident)
    changed={k for k in files if files[k]!=original[k]}
    if changed!=ALLOWED_CHANGED:raise ValueError('OUT_OF_SCOPE_SOURCE_CHANGE '+repr(changed))
    if any(k.startswith('database/') for k in changed):raise ValueError('MIGRATIONS_CHANGED')
    if H(files['resources/local-managed-files-v1.json'])!=H(original['resources/local-managed-files-v1.json']):raise ValueError('MANAGED_INVENTORY_CHANGED')
    if H(files['resources/golive-clearance-policy.json'])!=H(original['resources/golive-clearance-policy.json']):raise ValueError('GOLIVE_POLICY_CHANGED')
    m=json.loads(json.dumps(manifest))
    if len(m['supported_baselines'])!=56 or m['signature'] is not None:raise ValueError('R184_BASELINE_OR_SIGNATURE_UNEXPECTED')
    m.update(release_set_id=RELEASE,sequence=20261113887,status=STATUS,for_install=True,production_pass=True,created_at='2026-11-13T07:40:00Z')
    m['targets']['local'].update(release_id=RELEASE,version=VERSION,managed_tree_sha256=tree(files),managed_files=589)
    web2=zz(files)
    m['artifacts']['local-web'].update(sha256=H(web2),size_bytes=len(web2))
    m['qualification']['failure_matrix_id']='R184_HISTORICAL_R162_MATRIX_PLUS_R185_12_WINDOWS_FAULTS_R186_EXACT_R153_TO_R184_ACTIVATION'
    m['qa_notes']={
        'scope':'HOME_ONLY_CANARY_NOT_CAFE_PRODUCTION',
        'reference_source_sha256':SOURCE_SHA,
        'functional_source_payload_diff':'THREE_METADATA_FILES_ONLY_FROM_R184; NO SQL/LOGIC CHANGES',
        'r185_windows_mariadb_backup_migration_ci':'https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43381864922',
        'r186_exact_home_stage_activate_readback_ci':'https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43386744942',
        'exact_r187_package_ci':'REQUIRES_SEPARATE_EXACT_R187_WINDOWS_PASS_BEFORE_DELIVERY',
        'operator_upload_single_ZIP_only':True,
        'on_host_preflight_and_backup_mandatory':True,
        'cafe_production_approved':False,
        'all_business_issues_resolved':False,
        'field_install_verified':False,
        'signature':'NONE_OPERATOR_SHA256_AND_NATIVE_MAINTENANCE_VERIFICATION',
    }
    candidate=zz({'release-set.json':writej(m),'artifacts/local/web.zip':web2})
    outdir.mkdir(exist_ok=True,parents=True)
    output=outdir/NAME
    output.write_bytes(candidate)
    with zipfile.ZipFile(output) as z:
        assert z.testzip() is None
        assert set(z.namelist())=={'release-set.json','artifacts/local/web.zip'}
    # Require consistency with outer target and exact signed source claims.
    report={
        'format':'sokna-r187-home-only-qualification-v1',
        'status':'CI_CANDIDATE_PENDING_EXACT_R187_WINDOWS',
        'package':NAME, 'sha256':H(candidate), 'bytes':len(candidate),
        'version':VERSION, 'release_set_id':RELEASE,
        'web_sha256':H(web2), 'target_tree_sha256':tree(files),
        'source_r184_sha256':SOURCE_SHA,'source_file_changes':sorted(changed),
        'business_code_files_unchanged':True, 'migrations_unchanged':True,
        'baseline_count':len(m['supported_baselines']), 'cafe_production_approved':False,
        'exact_r187_full_activation_tested':False, 'on_host_backup_verified':False,
    }
    (outdir/'R187-package-identity.json').write_bytes(writej(report))
    print(json.dumps(report,ensure_ascii=False,indent=2))
    return output
if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('--r184',required=True,type=pathlib.Path);a.add_argument('--out',required=True,type=pathlib.Path);x=a.parse_args();promote(x.r184,x.out)
