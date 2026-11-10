#!/usr/bin/env python3
"""Disposable Windows QA adapter for a *real* Local DB-forward receipt + Public native proof.

This is NOT an installer, transaction manager, restore command, or release gate.
It never changes an application, database, backup, or Windows service. It
persists the DB-forward hazard across failures so no one infers atomicity
from two separately successful native component operations.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import uuid

LOCAL='968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4'
PUBLIC='1131d89c98e05c9597f372ed1d480e5637c3ad1969bc8733d2a837b644e57584'
FORMAT='sokna-native-db-forward-fence-qa-v1'
class Blocked(Exception): pass

def digest(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def doc(p):
    try: return json.loads(p.read_text(encoding='utf-8-sig'))
    except (OSError, ValueError) as exc: raise Blocked('EVIDENCE_UNREADABLE_OR_INVALID') from exc

def stable_write(p, obj):
    p.parent.mkdir(parents=True,exist_ok=True)
    tmp=p.with_name(p.name+'.tmp-'+uuid.uuid4().hex)
    try:
        with open(tmp,'xb') as out:
            out.write((json.dumps(obj,sort_keys=True,separators=(',',':'))+'\n').encode())
            out.flush();os.fsync(out.fileno())
        os.replace(tmp,p)
    finally:
        if tmp.exists():tmp.unlink()

def guard(root, state, backup, public=None):
    if os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('RUNNER_OS')!='Windows' or os.environ.get('SOKNA_NATIVE_COLOCATED_PUBLIC_QA')!='YES':
        raise Blocked('DISPOSABLE_WINDOWS_GITHUB_QA_ONLY')
    temp=Path(os.environ['RUNNER_TEMP']).resolve(strict=True)
    root=root.resolve(strict=False)
    base=temp/'sokna-r149b-windows-exact-e2e'/'artifacts'
    if root.parent!=base or root.name not in ('native-forward-r75','native-forward-r140'):
        raise Blocked('FENCE_ROOT_OUTSIDE_DISPOSABLE_LAB')
    for p in (root,state,backup,public):
        if p is None:continue
        p=p.resolve(strict=False)
        if p==temp or temp not in p.parents:raise Blocked('EVIDENCE_OUTSIDE_RUNNER_TEMP')
        for part in (p,*p.parents):
            if part==temp.parent:break
            if part.is_symlink():raise Blocked('SYMLINK_EVIDENCE_FORBIDDEN')
    if backup.suffix.lower()!='.sql' or backup.parent.name!='private-db-backups' or not state.name=='routine-state.json':
        raise Blocked('LOCAL_NATIVE_PROOF_PATH_UNRECOGNIZED')
    return root

def check_local(state, backup):
    item=doc(state)
    if item.get('phase')!='COMMITTED' or item.get('database_mutation_performed') is not True or item.get('recovery_required') is not False:
        raise Blocked('LOCAL_DATABASE_FORWARD_NOT_ATTESTED')
    if not backup.is_file() or backup.stat().st_size<=500:raise Blocked('INDEPENDENT_RESTORE_BACKUP_MISSING')
    return digest(state),digest(backup)

def check_public(public):
    data=doc(public)
    cases=data.get('cases')
    if data.get('format')!='sokna-public-two-adapter-qa-v1' or data.get('production_authorized') is not False or not isinstance(cases,list):
        raise Blocked('PUBLIC_QA_ATTESTATION_UNTRUSTED')
    if len(cases)!=4 or {(x.get('baseline'),x.get('channel')) for x in cases}!={(a,b) for a in ('r115','r126') for b in ('routine','emergency')}:
        raise Blocked('PUBLIC_NATIVE_CASE_MATRIX_INCOMPLETE')
    if any(x.get('target_zip_sha256')!=PUBLIC or x.get('fresh_process_commit') is not True for x in cases):
        raise Blocked('PUBLIC_COMMIT_NOT_ATTESTED')
    return digest(public)

def record_local(root,state,backup,profile):
    target=root/'forward-fence.json'
    if target.exists():raise Blocked('EXISTING_DB_FORWARD_FENCE_REQUIRES_RECONCILIATION')
    if root.name!='native-forward-'+profile:raise Blocked('PROFILE_FENCE_MISMATCH')
    local_hash,backup_hash=check_local(state,backup)
    item={'format':FORMAT,'phase':'LOCAL_DB_FORWARDED_PUBLIC_PENDING','source_profile':profile,
          'local_target_zip_sha256':LOCAL,'public_target_zip_sha256':PUBLIC,
          'local_native_state_sha256':local_hash,'isolated_restore_backup_sha256':backup_hash,
          'database_forward_performed':True,'public_native_commit_verified':False,
          'maintenance_supervisor_recovery_exe_qualified':False,
          'global_commit_rollback_verified':False,'recovery_required':True,
          'production_authorized':False,'field_install_authorized':False}
    stable_write(target,item)
    return item

def validate_fence(root,state,backup):
    item=doc(root/'forward-fence.json')
    if (item.get('format')!=FORMAT or item.get('source_profile') not in ('r75','r140') or
        item.get('local_target_zip_sha256')!=LOCAL or item.get('public_target_zip_sha256')!=PUBLIC or
        item.get('database_forward_performed') is not True or item.get('recovery_required') is not True or
        item.get('global_commit_rollback_verified') is not False or item.get('production_authorized') is not False or
        item.get('field_install_authorized') is not False or item.get('maintenance_supervisor_recovery_exe_qualified') is not False or
        item.get('phase') not in ('LOCAL_DB_FORWARDED_PUBLIC_PENDING','PUBLIC_NATIVE_DONE_MAINTENANCE_PENDING')):
        raise Blocked('FORWARD_FENCE_MALFORMED_OR_UNSAFE')
    local_hash,backup_hash=check_local(state,backup)
    if local_hash!=item.get('local_native_state_sha256') or backup_hash!=item.get('isolated_restore_backup_sha256'):
        raise Blocked('NATIVE_LOCAL_OR_BACKUP_DRIFT_AFTER_DB_FORWARD')
    return item

def record_public(root,state,backup,public):
    item=validate_fence(root,state,backup)
    if item['phase']!='LOCAL_DB_FORWARDED_PUBLIC_PENDING':raise Blocked('PUBLIC_FENCE_ALREADY_RECORDED')
    item['public_native_attestation_sha256']=check_public(public)
    item['public_native_commit_verified']=True
    item['phase']='PUBLIC_NATIVE_DONE_MAINTENANCE_PENDING'
    stable_write(root/'forward-fence.json',item)
    return item

def resume(root,state,backup,public=None):
    item=validate_fence(root,state,backup)
    if item['phase']=='PUBLIC_NATIVE_DONE_MAINTENANCE_PENDING':
        if public is None or check_public(public)!=item.get('public_native_attestation_sha256') or item.get('public_native_commit_verified') is not True:
            raise Blocked('PUBLIC_RECEIPT_DRIFT_OR_MISSING')
    # Deliberate refusal: no automatic rollback after a true DB-forward boundary.
    raise Blocked('DB_FORWARD_NEEDS_QUALIFIED_THREE_COMPONENT_RECOVERY')

def main():
    p=argparse.ArgumentParser()
    p.add_argument('mode',choices=('local','public','resume'))
    p.add_argument('--root',type=Path,required=True)
    p.add_argument('--state',type=Path,required=True)
    p.add_argument('--backup',type=Path,required=True)
    p.add_argument('--public-proof',type=Path)
    p.add_argument('--profile',choices=('r75','r140'))
    a=p.parse_args()
    try:
        root=guard(a.root,a.state,a.backup,a.public_proof)
        if a.mode=='local':
            if a.profile is None:raise Blocked('SOURCE_PROFILE_REQUIRED')
            result=record_local(root,a.state,a.backup,a.profile)
        elif a.mode=='public':
            if a.public_proof is None:raise Blocked('PUBLIC_PROOF_REQUIRED')
            result=record_public(root,a.state,a.backup,a.public_proof)
        else:result=resume(root,a.state,a.backup,a.public_proof)
        print(json.dumps({'result':'PASS','phase':result['phase'],'release_authorized':False}))
        return 0
    except Blocked as exc:
        print(json.dumps({'result':'BLOCKED','reason':str(exc),'release_authorized':False}))
        return 24
if __name__=='__main__':sys.exit(main())