#!/usr/bin/env python3
"""Build exact-source R184 LOCAL *QUARANTINED CI* candidate.

This script prepares a Stage-eligible candidate only inside isolated CI. It does
NOT certify a release or deliver a user-installable file. Maintains 589-file
inventory, source integrity, and exact known baselines from byte-pinned R177.
"""
import argparse, hashlib, io, json, pathlib, zipfile

PARENT_SHA = 'bb14b9bc93d7a37059331e24692c3bd60cc338c36e336a8cdcab6cb123b30286'
VERSION = '3.0.0-g6.2-workspace.231ev-r184-home-qualification-ci'
RELEASE = 'sokna-r184-home-qualification-ci-20261113'
# The expected source changes consist of the exact R182 and R183 text patches.
SCOPE = {
    'VERSION.txt', 'public/assets/account-workspace.js',
    'public/assets/catalog-workspace.js', 'public/assets/guest-content-workspace.js',
    'public/assets/operations-workspace.js', 'public/assets/preparation-workspace.js',
    'public/assets/printing-workspace.js', 'public/assets/product-ui.css',
    'public/assets/system-diagnostics.js','public/catalog/index.php',
    'resources/build-provenance.json','resources/release-identity-v1.json',
    'src/Domain/GuestContent/GuestContentService.php',
    'src/Domain/Orders/OrderStaffActionService.php',
    'src/Domain/Preparation/PreparationService.php',
}
H = lambda b: hashlib.sha256(b).hexdigest()
def jbytes(o):return (json.dumps(o,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def zipbytes(data):
    output=io.BytesIO()
    with zipfile.ZipFile(output,'w') as z:
        for key,value in sorted(data.items()):
            info=zipfile.ZipInfo(key,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
            z.writestr(info,value,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return output.getvalue()
def treehash(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def canonical(d):
    skip={'resources/build-provenance.json','resources/release-identity-v1.json'}
    return H(''.join(k+'\0'+str(len(d[k]))+'\0'+H(d[k])+'\n' for k in sorted(d) if k not in skip).encode())
def build(parent,src,out):
    if H(parent.read_bytes())!=PARENT_SHA:raise ValueError('WRONG_EXACT_R177_SOURCE_PACKAGE')
    with zipfile.ZipFile(parent) as z:
        if set(z.namelist())!={'release-set.json','artifacts/local/web.zip'}:raise ValueError('NOT_STANDALONE_LOCAL_RELEASE')
        m=json.loads(z.read('release-set.json'))
        with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
            originals={name:inner.read(name) for name in inner.namelist()}
    if len(originals)!=589 or len(m['supported_baselines'])!=55:raise ValueError('R177_EXPECTED_FILES_OR_BASELINES_WRONG')
    files={str(p.relative_to(src)).replace('\\','/'):p.read_bytes() for p in src.rglob('*') if p.is_file()}
    if set(files)!=set(originals):raise ValueError('EXACT_589_MANAGED_FILES_DIFFER')
    raw_diff={p for p in files if files[p]!=originals[p]}
    if raw_diff!=SCOPE:raise ValueError('SCOPE_DIFF_UNEXPECTED '+repr(sorted(raw_diff^SCOPE)))
    if any(p.startswith('database/') for p in raw_diff):raise ValueError('DB_MIGRATION_CHANGED')
    if files['resources/local-managed-files-v1.json']!=originals['resources/local-managed-files-v1.json']:raise ValueError('INVENTORY_CHANGED')
    if files['resources/golive-clearance-policy.json']!=originals['resources/golive-clearance-policy.json']:raise ValueError('GOLIVE_POLICY_CHANGED')
    files['VERSION.txt']=(VERSION+'\n').encode()
    prov=json.loads(files['resources/build-provenance.json'])
    prov.update(version=VERSION,release_set_id=RELEASE,classification='R184_CI_ONLY_QUARANTINED_STAGE_VALIDATION',production_host_install_verified=False,full_business_acceptance=False)
    files['resources/build-provenance.json']=jbytes(prov)
    identity=json.loads(files['resources/release-identity-v1.json'])
    identity['version']=VERSION;identity['release_set_id']=RELEASE
    identity['source']['canonical_file_count']=len(files)-2
    identity['source']['managed_file_count']=len(files)
    identity['source']['canonical_tree_sha256']=canonical(files)
    identity['source']['source_ref']='R177 exact 589-file source + R182 and R183 scoped patches; DB and Windows untouched'
    identity['qualification']['tier']='R184_QUARANTINED_CI_STAGE_ONLY_NOT_APPROVED'
    files['resources/release-identity-v1.json']=jbytes(identity)
    prev=m['targets']['local'].copy()
    if '231es-r177' not in prev['version']:raise ValueError('PARENT_R177_TARGET_UNEXPECTED')
    m['supported_baselines'].append({'local':prev,'public':None})
    if len(m['supported_baselines'])!=56 or not any('231ed-r153' in str(b['local']['version']) for b in m['supported_baselines']):raise ValueError('HOME_231ED_BASELINE_NOT_PRESENT')
    web=zipbytes(files)
    m.update(release_set_id=RELEASE,sequence=20261113884,status='R184_CI_QUARANTINED_CANARY_PENDING_FIELD',for_install=True,production_pass=True)
    m['targets']['local'].update(version=VERSION,release_id=RELEASE,managed_files=len(files),managed_tree_sha256=treehash(files))
    m['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
    m['qa_notes']={'r184_quarantined_ci_only':True,'r184_reused_r177_qualification_is_not_r184_certification':True,'r184_patches':['R177-TO-R182-EXACT-PAYLOAD.patch','R182-TO-R183-PREUPLOAD-BROWSER-GATE.patch'],'r184_not_for_customer_delivery':True}
    data=zipbytes({'release-set.json':jbytes(m),'artifacts/local/web.zip':web})
    out.mkdir(parents=True,exist_ok=True)
    path=out/'SOKNA-R184-CI-QUARANTINED-STAGE-PROBE-NOT-FOR-INSTALL.zip';path.write_bytes(data)
    report={'package':path.name,'package_sha256':H(data),'version':VERSION,'target_tree_sha256':treehash(files),'parent_sha256':PARENT_SHA,'managed_files':589,'baselines':56,'source_scope_changes':sorted(raw_diff),'blocked_from_customer_delivery':True,'stage_activation_or_recovery_proven':False}
    (out/'R184-CI-ONLY-PROVENANCE.json').write_bytes(jbytes(report))
    print(json.dumps(report,ensure_ascii=False,indent=2));return path
if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('--r177',required=True,type=pathlib.Path);a.add_argument('--source',required=True,type=pathlib.Path);a.add_argument('--out',required=True,type=pathlib.Path);args=a.parse_args();build(args.r177,args.source,args.out)
