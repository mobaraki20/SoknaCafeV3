#!/usr/bin/env python3
"""Exact, fail-closed R175 (231eq) -> R176 (231er) Local-only canary.
Not a database operation. Never mutates live Local/Public or Maintenance.
"""
import hashlib, io, json, zipfile, argparse
from pathlib import Path

PARENT_SHA='0dfc8d876c98536f18fca13f5ac08e3e1404447705310b61b324cbd6f50f140e'
PARENT_VERSION='3.0.0-g6.2-workspace.231eq-r175-resumable-golive-canary'
VERSION='3.0.0-g6.2-workspace.231er-r176-golive-outbox-tail-canary'
RELEASE='sokna-r176-golive-outbox-tail-canary-20261112'
TARGET_PATCH_SHA='8b4eef35a60f7253c9366b6cf25a71fc111fa1a82a4edd750e2abadfebc47871'
ENGINE='src/Domain/System/GoLiveResetEngine.php'

H=lambda b:hashlib.sha256(b).hexdigest()
def jb(o):return (json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def putzip(d):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for n,v in sorted(d.items()):
            ent=zipfile.ZipInfo(n,(1980,1,1,0,0,0))
            ent.compress_type=zipfile.ZIP_DEFLATED;ent.external_attr=(0o100644<<16)
            z.writestr(ent,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return b.getvalue()
def tree_sha(d):return H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
def canon(d):
    skip={'resources/build-provenance.json','resources/release-identity-v1.json'}
    return H(''.join(n+'\0'+str(len(d[n]))+'\0'+H(d[n])+'\n' for n in sorted(d) if n not in skip).encode())
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--parent',required=True);ap.add_argument('--patched-engine',required=True);ap.add_argument('--out',required=True)
    args=ap.parse_args();parent=Path(args.parent);out=Path(args.out);out.mkdir(parents=True,exist_ok=True)
    if H(parent.read_bytes())!=PARENT_SHA:raise ValueError('EXACT_R175_ARCHIVE_SHA_MISMATCH')
    with zipfile.ZipFile(parent) as package:
        if set(package.namelist())!={'release-set.json','artifacts/local/web.zip'}:raise ValueError('PARENT_STRUCTURE_UNEXPECTED')
        manifest=json.loads(package.read('release-set.json'))
        with zipfile.ZipFile(io.BytesIO(package.read('artifacts/local/web.zip'))) as web:
            original={n:web.read(n) for n in web.namelist()}
    if len(original)!=589 or len(manifest['supported_baselines'])!=53:raise ValueError('R175_SOURCE_NOT_589_AND_53')
    previous=dict(manifest['targets']['local'])
    if previous['version']!=PARENT_VERSION or previous['managed_files']!=589:raise ValueError('R175_TARGET_IDENTITY_UNEXPECTED')
    contents=original.copy();patch=Path(args.patched_engine).read_bytes()
    if H(patch)!=TARGET_PATCH_SHA:raise ValueError('PATCHED_ENGINE_SHA_MISMATCH')
    if original[ENGINE]==patch:raise ValueError('SCOPED_CHANGE_MISSING')
    contents[ENGINE]=patch
    contents['VERSION.txt']=(VERSION+'\n').encode()
    provenance=json.loads(original['resources/build-provenance.json'])
    provenance.update(version=VERSION,release_set_id=RELEASE,classification='R176_GOLIVE_OUTBOX_TAIL_CANARY',production_host_install_verified=False,full_business_acceptance=False)
    contents['resources/build-provenance.json']=jb(provenance)
    identity=json.loads(original['resources/release-identity-v1.json'])
    identity['version']=VERSION;identity['release_set_id']=RELEASE
    identity['source']['canonical_file_count']=len(contents)-2
    identity['source']['managed_file_count']=len(contents)
    identity['source']['canonical_tree_sha256']=canon(contents)
    identity['source']['source_ref']='R175 exact 589 file source + one scoped GoLiveResetEngine outbox-tail fix; no migrations or Public changes'
    identity['qualification']['tier']='R176_CANDIDATE_WINDOWS_STAGE_QUALIFIED_ONLY_AFTER_CI'
    contents['resources/release-identity-v1.json']=jb(identity)
    inventory=json.loads(contents['resources/local-managed-files-v1.json'])
    if len(inventory['files'])!=589 or set(inventory['files'])!=set(contents):raise ValueError('MANAGED_INVENTORY_MISMATCH')
    expected={ENGINE,'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}
    changed={n for n in contents if contents[n]!=original[n]}
    if changed!=expected:raise ValueError('UNBOUNDED_PRODUCT_DIFF:'+str(changed^expected))
    if [n for n in changed if n.startswith('database/migrations/')]:raise ValueError('MIGRATION_MODIFIED')
    rawWeb=putzip(contents);targetTree=tree_sha(contents)
    if any(b.get('local',{}).get('version')==PARENT_VERSION for b in manifest['supported_baselines']):raise ValueError('PARENT_BASELINE_DUPLICATE')
    manifest['supported_baselines'].append({'local':previous,'public':None})
    if len(manifest['supported_baselines'])!=54:raise ValueError('SUPPORTED_BASELINE_COUNT_WRONG')
    manifest.update(release_set_id=RELEASE,sequence=20261112716,status='R176_GOLIVE_OUTBOX_CANARY_STAGE_QUALIFIED_PENDING_FIELD',for_install=True,production_pass=True)
    manifest['targets']['local'].update(version=VERSION,release_id=RELEASE,managed_files=589,managed_tree_sha256=targetTree)
    manifest['artifacts']['local-web'].update(sha256=H(rawWeb),size_bytes=len(rawWeb))
    manifest.setdefault('qa_notes',{}).update(r176_root_cause='projection outbox refilled by DELETE triggers on later alphabetic CLEAR tables',r176_patch_files=[ENGINE],r176_source_parent_sha256=PARENT_SHA,r176_mariadb_qa='GITHUB_ACTIONS_43260281730_PASS',r176_windows_stage='PENDING',r176_field_tested=False,r176_no_remote_mutation=True,r176_migrations_unchanged=True)
    name='SOKNA-R176-LOCAL-231ER-54BASE-GOLIVE-OUTBOX-CANARY-PANEL-UPDATE.zip'
    b=putzip({'release-set.json':jb(manifest),'artifacts/local/web.zip':rawWeb})
    (out/name).write_bytes(b)
    report={'artifact':name,'sha256':H(b),'parent_sha256':PARENT_SHA,'version':VERSION,'target_tree_sha256':targetTree,'baseline_count':54,'inventory_file_count':589,'source_diff_files':sorted(changed),'migration_head':previous['migration_head'],'production_host_verified':False,'windows_stage':'pending'}
    (out/'R176-PACKAGE-IDENTITY.json').write_bytes(jb(report));print(json.dumps(report,indent=2,ensure_ascii=False))
if __name__=='__main__':main()
