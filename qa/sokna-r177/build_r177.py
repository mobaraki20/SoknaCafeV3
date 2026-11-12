#!/usr/bin/env python3
"""Reproducible, fail-closed R176->R177 Local-only recovery canary.
No real DB, no Public/maintenance code, no schema migrations, no GoLive policy edits.
"""
import argparse,io,hashlib,json,zipfile
from pathlib import Path
PARENT_SHA='a3ea536b2a4a492fdd0ad2e2cd86adb9ff9c6f20846765d9efa8d53375d421ec'
PARENT_VERSION='3.0.0-g6.2-workspace.231er-r176-golive-outbox-tail-canary'
VERSION='3.0.0-g6.2-workspace.231es-r177-golive-resync-catalog-canary'
RELEASE='sokna-r177-golive-resync-catalog-canary-20261112'
CHANGED=['src/Domain/PublicEdge/PublicEdgePublisherService.php','src/Domain/System/GoLiveResetCoordinator.php']
EXPECTED={
 'src/Domain/PublicEdge/PublicEdgePublisherService.php':'d9e283376347ccc97314298d201b420d762f67ff43ec7a823b87e9cb5303aaea',
 'src/Domain/System/GoLiveResetCoordinator.php':'d5a223fe87b221dc268a24b9f5f4a1bb163baea899b62491d9b0682c8be93fa4',
}
H=lambda b:hashlib.sha256(b).hexdigest()
def jb(o):return (json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def putzip(d):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for n,v in sorted(d.items()):
            ent=zipfile.ZipInfo(n,(1980,1,1,0,0,0));ent.compress_type=zipfile.ZIP_DEFLATED;ent.external_attr=0o100644<<16
            z.writestr(ent,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return b.getvalue()
def tree_sha(d):return H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
def canonical(d):
    skip={'resources/build-provenance.json','resources/release-identity-v1.json'}
    return H(''.join(n+'\0'+str(len(d[n]))+'\0'+H(d[n])+'\n' for n in sorted(d) if n not in skip).encode())
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--parent',required=True);ap.add_argument('--patched-tree',required=True);ap.add_argument('--out',required=True)
    args=ap.parse_args();parent=Path(args.parent);out=Path(args.out);out.mkdir(parents=True,exist_ok=True)
    if H(parent.read_bytes())!=PARENT_SHA:raise ValueError('R176_EXACT_ARCHIVE_MISMATCH')
    with zipfile.ZipFile(parent) as z:
        if set(z.namelist())!={'release-set.json','artifacts/local/web.zip'}:raise ValueError('RELEASE_SET_STRUCTURE_UNEXPECTED')
        manifest=json.loads(z.read('release-set.json'))
        with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
            original={n:inner.read(n) for n in inner.namelist()}
    if len(original)!=589 or len(manifest['supported_baselines'])!=54:raise ValueError('R176_BASELINE_IDENTITY_UNEXPECTED')
    previous=dict(manifest['targets']['local'])
    if previous['version']!=PARENT_VERSION or previous['managed_files']!=589:raise ValueError('R176_TARGET_VERSION_UNEXPECTED')
    patched=Path(args.patched_tree);files=original.copy()
    for name in CHANGED:
        content=(patched/name).read_bytes()
        if H(content)!=EXPECTED[name] or content==original[name]:raise ValueError('SCOPED_FILE_MISMATCH:'+name)
        files[name]=content
    files['VERSION.txt']=(VERSION+'\n').encode()
    provenance=json.loads(files['resources/build-provenance.json'])
    provenance.update(version=VERSION,release_set_id=RELEASE,classification='R177_GOLIVE_RESYNC_MODEL_CATALOG_CANARY',production_host_install_verified=False,full_business_acceptance=False)
    files['resources/build-provenance.json']=jb(provenance)
    identity=json.loads(files['resources/release-identity-v1.json'])
    identity['version']=VERSION;identity['release_set_id']=RELEASE
    identity['source']['canonical_file_count']=len(files)-2
    identity['source']['managed_file_count']=len(files)
    identity['source']['canonical_tree_sha256']=canonical(files)
    identity['source']['source_ref']='Exact R176 589-file source + 2 scoped PHP resync catalog/diagnostic changes; policy/migrations/Public unchanged'
    identity['qualification']['tier']='R177_GOLIVE_RESYNC_STAGE_CANDIDATE'
    files['resources/release-identity-v1.json']=jb(identity)
    inventory=json.loads(files['resources/local-managed-files-v1.json'])
    if set(inventory['files'])!=set(files) or len(inventory['files'])!=len(files)!=589:raise ValueError('MANAGED_INVENTORY_NOT_EXACT')
    diff={name for name in files if files[name]!=original[name]}
    expected=set(CHANGED)|{'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}
    if diff!=expected:raise ValueError('UNSCOPED_DIFF:'+str(diff^expected))
    if files['resources/golive-clearance-policy.json']!=original['resources/golive-clearance-policy.json']:raise ValueError('IN_FLIGHT_CLEARANCE_POLICY_CHANGED')
    if any(name.startswith('database/') for name in diff):raise ValueError('MIGRATION_OR_DB_CHANGED')
    filesWeb=putzip(files)
    manifest['supported_baselines'].append({'local':previous,'public':None})
    if len(manifest['supported_baselines'])!=55:raise ValueError('BASELINE_COUNT_NOT_55')
    manifest.update(release_set_id=RELEASE,sequence=20261112717,status='R177_GOLIVE_RESYNC_CATALOG_CANARY_PENDING_FIELD',for_install=True,production_pass=True)
    manifest['targets']['local'].update(version=VERSION,release_id=RELEASE,managed_files=589,managed_tree_sha256=tree_sha(files))
    manifest['artifacts']['local-web'].update(sha256=H(filesWeb),size_bytes=len(filesWeb))
    manifest.setdefault('qa_notes',{}).update(r177_parent_sha256=PARENT_SHA,r177_change='Restore 11 static projection catalog keys during resync only; safe retry diagnostics',r177_field_tested=False,r177_policy_sha_unchanged=True,r177_public_unchanged=True,r177_migrations_unchanged=True)
    target='SOKNA-R177-LOCAL-231ES-55BASE-GOLIVE-RESYNC-CANARY-PANEL-UPDATE.zip'
    body=putzip({'release-set.json':jb(manifest),'artifacts/local/web.zip':filesWeb})
    (out/target).write_bytes(body)
    report={'artifact':target,'sha256':H(body),'parent_sha256':PARENT_SHA,'version':VERSION,'source_files':len(files),'source_diff_files':sorted(diff),'known_baselines':55,'target_tree_sha256':tree_sha(files),'for_install':True,'field_verified':False,'policy_unchanged':True,'public_unchanged':True}
    (out/'R177-PACKAGE-IDENTITY.json').write_bytes(jb(report))
    print(json.dumps(report,indent=2,ensure_ascii=False))
if __name__=='__main__':main()
