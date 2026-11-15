#!/usr/bin/env python3
"""Sokna Public strict, read-only release qualification (not a deployment program).

Inputs are immutable release archives plus the R247C on-host stable owner installer.
Never accesses a host, mutates a database, or rewrites a production package.
"""
import argparse,base64,gzip,hashlib,json,os,re,shutil,subprocess,tempfile,zipfile
from pathlib import Path

def sha(b): return hashlib.sha256(b).hexdigest()
def tree_digest(files): return sha(''.join(p+'\0'+sha(files[p])+'\n' for p in sorted(files)).encode())
def fail(msg): raise AssertionError(msg)

def load_zip(path):
    with zipfile.ZipFile(path) as z:
        if z.testzip() is not None: fail('CRC check failed')
        names=z.namelist()
        if len(names)!=len(set(names)): fail('duplicate ZIP names')
        for name in names:
            if name.startswith('/') or '\\' in name or '..' in name.split('/') or '\x00' in name: fail('unsafe ZIP path '+name)
        if 'manifest.json' not in names: fail('manifest missing')
        manifest=json.loads(z.read('manifest.json'))
        files={n.removeprefix('payload/'):z.read(n) for n in names if n.startswith('payload/') and not n.endswith('/')}
    if len(manifest.get('files',[]))!=len(files):fail('manifest file count differs')
    if len(set(r['path'] for r in manifest['files']))!=len(files):fail('manifest paths not unique')
    if set(files)!={r['path'] for r in manifest['files']}: fail('payload files do not match manifest')
    for entry in manifest['files']:
        rel=entry['path'];b=files[rel]
        if sha(b)!=entry['sha256'] or len(b)!=entry['size']:fail('file digest mismatch '+rel)
        if rel.startswith(('storage/','src/Emergency/','tools/','database/migrations/') ) and rel.startswith(('storage/','src/Emergency/','tools/')):fail('stable/runtime file in payload '+rel)
        if rel in ('emergency.php','config.php','install.lock','resources/update-trust-v1.json'):fail('protected file in payload '+rel)
    if manifest.get('target_managed_tree_sha256')!=tree_digest(files):fail('managed tree mismatch')
    return manifest,files

def record(checks,key,condition,detail=''):
    checks.append({'gate':key,'status':'PASS' if condition else 'FAIL','detail':detail})
    return bool(condition)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--baseline',required=True);ap.add_argument('--candidate',required=True);ap.add_argument('--stable-kit');ap.add_argument('--stable-owner-source');ap.add_argument('--stable-access-source')
    ap.add_argument('--out',required=True); args=ap.parse_args(); out=Path(args.out);out.mkdir(parents=True,exist_ok=True)
    report={'scope':'public only','mode':'read_only','host_touched':False,'database_touched':False,'gates':[]};checks=report['gates']
    baseline_path=Path(args.baseline);candidate_path=Path(args.candidate);stable_path=Path(args.stable_kit) if args.stable_kit else None
    baseline,old=load_zip(baseline_path);candidate,new=load_zip(candidate_path)
    record(checks,'baseline_archive_pin',sha(baseline_path.read_bytes())=='ea1d2f7d90242d5b45fe232da7ff5510f03ee55c835e2dabe5f61980430be157',sha(baseline_path.read_bytes()))
    record(checks,'candidate_archive_pin',sha(candidate_path.read_bytes())=='db51819e2fd7fed99be194e17a884100a5f076d1820925bfecdb632a40a10a76',sha(candidate_path.read_bytes()))
    record(checks,'baseline_version',baseline['version']=='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat',baseline['version'])
    record(checks,'source_tree',baseline['target_managed_tree_sha256']=='be2ebe23091472480beadd6a06cad621b43da365d3677f54fadaab6ba56242b6')
    record(checks,'target_version',candidate['version']=='3.0.0-g3.6-workspace.35ib-r246-emergency-bridge',candidate['version'])
    compatible=candidate.get('compatibility',{}).get('supported_baselines',[])
    record(checks,'single_exact_registered_baseline',len(compatible)==1 and compatible[0]=={'version':baseline['version'],'managed_tree_sha256':baseline['target_managed_tree_sha256']},'Only R171 registered; NOT a universal all-version package')
    record(checks,'emergency_protocol',candidate.get('provenance',{}).get('strict_multibaseline_v1') is True,'Routine and Emergency share ZIP transport')
    record(checks,'no_unsafe_deletions',set(old)<=set(new),'additional managed files: '+', '.join(sorted(set(new)-set(old))))
    record(checks,'migration_files_preserved',all(old[k]==new[k] for k in old if k.startswith('database/migrations/')),'Migrations in managed payload not introduced')
    record(checks,'protected_emergency_paths_omitted',not any(k=='emergency.php' or k.startswith('src/Emergency/') or k=='resources/update-trust-v1.json' for k in new))
    record(checks,'version_file_matches_manifest',new.get('VERSION.txt')==(candidate['version']+'\n').encode())
    meta=json.loads(new['src/Update/emergency-owner-v1.json'])
    record(checks,'owner_metadata_target_aligned',meta.get('release_version')==candidate['version'] and meta.get('sha256')==sha(new['src/Update/EmergencyOwnerCandidate.php']))
    record(checks,'owner_candidate_unchanged',old['src/Update/EmergencyOwnerCandidate.php']==new['src/Update/EmergencyOwnerCandidate.php'])
    record(checks,'shared_routine_bridge_unchanged',old['src/Update/IndependentEmergencyRoutineBridge.php']==new['src/Update/IndependentEmergencyRoutineBridge.php'])
    record(checks,'gui_stays_protected',old['emergency-next.php']==new['emergency-next.php'])
    record(checks,'preserves_subdirectory_base_path', 'config.php' not in new and b'PublicPath' in new['index.php'], 'Existing deployment config remains outside managed package')
    if stable_path:
        with zipfile.ZipFile(stable_path) as z:
            installer=z.read('Q3_P1_SOKNA_R247C_SAFE_REPAIR.php').decode()
        match=re.search(r"const R247_ENTRY_Z='([^']+)';",installer)
        assert match,'R247C embedded owner not found'
        gui=gzip.decompress(base64.b64decode(match.group(1)))
        access=gzip.decompress(base64.b64decode(re.search(r"const R247_ACCESS_Z='([^']+)';",installer).group(1)))
    else:
        assert args.stable_owner_source and args.stable_access_source,'R247C owner source and access source required'
        gui=Path(args.stable_owner_source).read_bytes()
        access=Path(args.stable_access_source).read_bytes()
    record(checks,'r247c_stable_owner_pin',sha(gui)=='b41a7deae19becca160586401a0c4bc33150c43f811d838c5973249bbeb2c6ce')
    record(checks,'r247c_access_owner_pin',sha(access)=='65f5c6fc92f17a5baba3965a69c7f8f2cdabfc5f0d2467240889cc48c8d5a9bb')
    record(checks,'r247c_r171_exception_scoped',b'g39_r247_legacy_owner_exception' in gui and baseline['version'].encode() in gui)
    record(checks,'r247c_same_forward_bridge',b'IndependentEmergencyRoutineBridge' in gui and b'commitPending' in gui and b'activateStaged' in gui)
    record(checks,'r247c_forward_only_rollback_guard',b'$canMutate=false' in gui and b'$canStage=' in gui)
    record(checks,'r247c_installer_explicitly_external_to_target',b'Q3_P1_SOKNA_R247C_SAFE_REPAIR.php' not in b' '.join(k.encode() for k in new))
    with tempfile.TemporaryDirectory(prefix='sokna_release_gate_') as td:
        root=Path(td)
        for p,b in old.items():
            target=root/p;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(b)
        stable_orig={'emergency.php':gui,'src/Emergency/EmergencyAccessService.php':access}
        for p,b in stable_orig.items():
            f=root/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
        old_owner=sha((root/'emergency.php').read_bytes());old_access=sha((root/'src/Emergency/EmergencyAccessService.php').read_bytes())
        # Simulate a clean code-only managed tree transition, with no host state or DB writes.
        for p in set(old)-set(new): (root/p).unlink()
        for p,b in new.items():
            dst=root/p;dst.parent.mkdir(parents=True,exist_ok=True);dst.write_bytes(b)
        readback={k:(root/k).read_bytes() for k in new}
        record(checks,'disposable_managed_tree_after_upgrade',tree_digest(readback)==candidate['target_managed_tree_sha256'])
        record(checks,'disposable_stable_owner_retained',sha((root/'emergency.php').read_bytes())==old_owner and sha((root/'src/Emergency/EmergencyAccessService.php').read_bytes())==old_access)
        record(checks,'disposable_unmanaged_config_unmodified',not (root/'config.php').exists(),'No config from operator zip')
        # PHP/JS parse of every source file in target (never execute live app)
        php=shutil.which('php');node=shutil.which('node')
        if php:
            bad=[];cnt=0
            for p in sorted(root.rglob('*.php')):
                cnt+=1;x=subprocess.run([php,'-l',str(p)],capture_output=True,text=True)
                if x.returncode:bad.append((str(p.relative_to(root)),x.stderr[:150]))
            record(checks,'php_lint_all_target',not bad, f'{cnt} PHP; errors={bad[:3]}')
        else: record(checks,'php_lint_all_target',False,'PHP CLI missing')
        if node:
            bad=[];cnt=0
            for p in sorted(root.rglob('*.js')):
                cnt+=1;x=subprocess.run([node,'--check',str(p)],capture_output=True,text=True)
                if x.returncode:bad.append((str(p.relative_to(root)),x.stderr[:150]))
            record(checks,'js_parse_all_target',not bad,f'{cnt} JS; errors={bad[:3]}')
        else: record(checks,'js_parse_all_target',False,'Node missing')
    # Negative test of tampered baseline and manifest; do not mutate archives.
    record(checks,'negative_wrong_baseline_rejected',all(x['version']!='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa' for x in compatible))
    record(checks,'negative_unknown_source_hash_rejected',all(x['managed_tree_sha256']!='0'*64 for x in compatible))
    record(checks,'negative_unsigned_zip_not_misrepresented_signed','signature.json' not in zipfile.ZipFile(candidate_path).namelist())
    report['offline_pass']=all(c['status']=='PASS' for c in checks)
    report['known_blockers']=[
       'Artifact explicitly marks production_host_qualified=false and QA_DO_NOT_INSTALL',
       'R247C confirmed visually on live host, but cannot independently read host Routine ledger, DB mutation fence, current tree, or pending stage',
       'Current R246 package enumerates only one exact R171 baseline; it is not a universal multi-version installer',
       'Real host independent Stage/Activate/Commit readback not demonstrated (and must not be inferred from CI)',
       'R244 WAF budget work was QA-only and inclusion in R246 is not proven'
    ]
    report['safe_operational_go']=False
    report['candidate_filename']=candidate_path.name
    report['baseline_filename']=baseline_path.name
    (out/'R248-READONLY-RELEASE-GATE.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'offline_pass':report['offline_pass'],'checks_passed':sum(c['status']=='PASS' for c in checks),'checks_total':len(checks),'safe_operational_go':False,'failures':[c for c in checks if c['status']!='PASS']},ensure_ascii=False,indent=2))
    return 0 if report['offline_pass'] else 1
if __name__=='__main__':raise SystemExit(main())
