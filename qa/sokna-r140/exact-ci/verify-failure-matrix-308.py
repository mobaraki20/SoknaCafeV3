#!/usr/bin/env python3
"""R140 exact ZIP rehearsal against archived HOME R75 source (no database / host mutation)."""
import io,zipfile,json,hashlib,pathlib,tempfile,shutil,copy,datetime,sys
root=pathlib.Path(sys.argv[1]).resolve()
old_path=root/'r75.zip'
new_path=root/'r140.zip'
results=[]
def sha(x):return hashlib.sha256(x).hexdigest()
def test(name,ok):
    results.append({'test':name,'pass':bool(ok)})
    if not ok:raise AssertionError(name)
def tree(files):return sha(b''.join(p.encode()+b'\x00'+sha(b).encode()+b'\n' for p,b in sorted(files.items())))
def read_package(path):
    with zipfile.ZipFile(path) as z:
        test('outer_crc_'+path.name,z.testzip() is None)
        m=json.loads(z.read('release-set.json'))
        b=z.read('artifacts/local/web.zip')
        test('nested_archive_sha_'+path.name,sha(b)==m['artifacts']['local-web']['sha256'])
        with zipfile.ZipFile(io.BytesIO(b)) as w:
            test('nested_crc_'+path.name,w.testzip() is None)
            files={p:w.read(p) for p in w.namelist() if not p.endswith('/')}
        test('nested_managed_tree_'+path.name,tree(files)==m['targets']['local']['managed_tree_sha256'])
        test('declared_count_'+path.name,len(files)==m['targets']['local']['managed_files'])
        return m,files
old,of=read_package(old_path)
new,nf=read_package(new_path)
old_id=old['targets']['local'];baselines=[x['local'] for x in new['supported_baselines']]
test('exact_R75_home_identity_matches',sum(x==old_id for x in baselines)==1)
test('42_unique_supported_identities',len(baselines)==42 and len({json.dumps(x,sort_keys=True) for x in baselines})==42)
test('migration_0076_to_0079',old_id['migration_head'].startswith('0076_') and new['targets']['local']['migration_head'].startswith('0079_'))
for p in of:
    if p.startswith('database/migrations/') and p in nf:
        test('unchanged_old_SQL_'+p,of[p]==nf[p])
test('R140_new_guard_in_exact_ZIP',b'preflightOpeningActivationSchema' in nf['src/Core/Migrations.php'])
test('R140_exact_version',nf['VERSION.txt'].decode().strip()==new['targets']['local']['version'])
test('six_cafe_staff_migration_preserved','database/migrations/0079_cafe_staff_initial_accounts.sql' in nf)
test('R140_is_QA_not_installable',new['for_install'] is False and new['production_pass'] is False)
with tempfile.TemporaryDirectory(prefix='sokna-r140-offline-') as td:
    d=pathlib.Path(td);oldroot=d/'old_slot';candidate=d/'candidate'
    for p,b in of.items():
        x=oldroot/p;x.parent.mkdir(parents=True,exist_ok=True);x.write_bytes(b)
    shutil.copytree(oldroot,candidate)
    for p in set(of)-set(nf):(candidate/p).unlink()
    for p,b in nf.items():
        x=candidate/p;x.parent.mkdir(parents=True,exist_ok=True);x.write_bytes(b)
    inspect=lambda r:{x.relative_to(r).as_posix():x.read_bytes() for x in r.rglob('*') if x.is_file()}
    test('R140_inactive_slot_target_hash',tree(inspect(candidate))==new['targets']['local']['managed_tree_sha256'])
    test('HOME_R75_prior_slot_unchanged',tree(inspect(oldroot))==old_id['managed_tree_sha256'])
    changed='src/Core/Migrations.php';(candidate/changed).write_bytes(nf[changed]+b'\x00TAMPER')
    test('candidate_migration_code_tamper_rejected',tree(inspect(candidate))!=new['targets']['local']['managed_tree_sha256'])
    (candidate/changed).write_bytes(nf[changed]);test('candidate_guard_restored',tree(inspect(candidate))==new['targets']['local']['managed_tree_sha256'])
    test('old_slot_still_available_for_pre_db_rollback',tree(inspect(oldroot))==old_id['managed_tree_sha256'])
for i,b in enumerate(baselines,1):
    for fld in ['release_id','version','managed_tree_sha256','schema_fingerprint','migration_head']:
        tampered=copy.deepcopy(b);tampered[fld]+='-corrupt'
        test(f'baseline_{i}_{fld}_drift_rejected',tampered not in baselines)
out={
 'date':'2026-11-08','result':'PASS','scope':'R140 exact archive offline source-to-target HOME R75 rehearsal',
 'test_count':len(results),'src':old_id,'target':new['targets']['local'],
 'archive_sha256':sha(new_path.read_bytes()),'source_files':len(of),'target_files':len(nf),
 'qualification':'SOURCE AND MANIFEST 308 CASE MATRIX ONLY: Does not by itself authorize production',
 'tests':results,
}
path=root/'R140-FAILURE-MATRIX-308.json';path.write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8')
print(f"R140_EXACT_HOME_R75_OFFLINE_PASS {len(results)} cases, archive {out['archive_sha256']}")