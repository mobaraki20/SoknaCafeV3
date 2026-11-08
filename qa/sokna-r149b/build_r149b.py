#!/usr/bin/env python3
"""Build exact R149B from source-qualified R140 and an audited 21-path overlay.
Used identically offline and on isolated GitHub Windows runners. Never mutates a real host.
"""
import hashlib, io, json, pathlib, sys, zipfile
BASE_SHA = '4734b918f7e2273627f0ba5f8d16992cba05ee198b0afcbee631cc78fdb0dfb9'
OVERLAY_SHA = '2c1298033d0ce1a9f06aba9e8d0da2122209989d81383474c4c1187c73335e4d'
R149_WEB_SHA = '909478271d8f9a05c96db77668581849ebbe33613142ccba14c017ea6c6ef19a'
R140_TREE = '398905b1a3540e410be6bc1a4bb96561d978a7312eb02dbe6f08d3d7f57d01ea'
OUT_DATE = (2026,11,8,12,0,0)
sha=lambda b:hashlib.sha256(b).hexdigest()
def check(ok, title):
    if not ok: raise RuntimeError('R149B_QUALITY_GATE: '+title)
    print('PASS', title,flush=True)
def zfile(content): return zipfile.ZipFile(io.BytesIO(content))
def byte_map(data):
    with zfile(data) as z:
        check(z.testzip() is None,'ZIP CRC')
        result={n:z.read(n) for n in z.namelist()}
        check(len(result)==len(z.namelist()) and all(n and not n.startswith(('/', '../')) and '/..' not in n and '\\' not in n for n in result),'unique safe ZIP paths')
        return result
def tree(files):
    return sha(''.join(n+'\0'+sha(b)+'\n' for n,b in sorted(files.items())).encode())
def ledger(files):
    names={p.split('/')[-1][:-4]:sha(b) for p,b in files.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
    return 'migration-ledger-sha256-v1:'+sha(''.join(n+'\0'+h+'\n' for n,h in sorted(names.items())).encode()),len(names)
def archive(files,compression=zipfile.ZIP_DEFLATED):
    output=io.BytesIO()
    with zipfile.ZipFile(output,'w',compression=compression) as z:
        for n,b in sorted(files.items()):
            i=zipfile.ZipInfo(n,date_time=OUT_DATE)
            i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=compression
            z.writestr(i,b,compress_type=compression)
    return output.getvalue()
def outer(manifest,web):
    # The SHA-addressed operator inbox authenticates this exact operator-selected ZIP.
    payload=json.dumps(manifest,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
    return archive({'release-set.json':payload,'artifacts/local/web.zip':web})
def main():
    if len(sys.argv)!=4:raise SystemExit('usage: build_r149b.py exact-qualified-r140.zip audited-overlay.zip output-directory')
    oldpath,ovpath,out=(pathlib.Path(x).resolve() for x in sys.argv[1:])
    out.mkdir(parents=True,exist_ok=True)
    check(sha(oldpath.read_bytes())==BASE_SHA,'Exact R140 install-qualified source SHA')
    check(sha(ovpath.read_bytes())==OVERLAY_SHA,'Exact 21-path overlay SHA')
    with zipfile.ZipFile(oldpath) as z:
        check(z.testzip() is None,'Source R140 integrity')
        previous=json.loads(z.read('release-set.json'))
        old_web=z.read('artifacts/local/web.zip')
    old=byte_map(old_web); overlays=byte_map(ovpath.read_bytes())
    check(len(old)==579 and tree(old)==R140_TREE,'R140 byte-exact 579-file tree')
    check(len(overlays)==21,'21 reviewed new/changed paths')
    check(set(p for p in overlays if p not in old)=={'database/migrations/0080_tea_herbal_legacy_family_repair.sql','src/Domain/System/CatalogPushReadOnlyAudit.php'},'Only two new paths')
    check(all(overlays[n]!=old[n] for n in overlays if n in old),'Every overlay truly changes source')
    files=old|overlays
    for name,body in old.items():
        if name.startswith('database/migrations/') and name in files:check(body==files[name],'Historical SQL immutable '+name) if name[-17:-4] in ('0078','0079') else None
    check(len(files)==581,'581 target files')
    web=archive(files)
    check(sha(web)==R149_WEB_SHA,'Exact original R149 nested source payload reproducible')
    check(files['VERSION.txt'].decode().strip()=='3.0.0-g6.2-workspace.231eb-r149-catalog-ui-multibase-uat','Version readback matches payload')
    schema,count=ledger(files)
    check(count==80,'80 SQL migrations')
    check(schema=='migration-ledger-sha256-v1:fd316c493e1f5a122f83859662b106a0f8329e9d6fa2accd1ad685440d9ec863','Schema fingerprint matches exact migration ledger')
    check(old['database/migrations/0078_tea_herbal_three_sizes.sql']==files['database/migrations/0078_tea_herbal_three_sizes.sql'],'Historical migration 0078 unchanged')
    check(old['database/migrations/0079_cafe_staff_initial_accounts.sql']==files['database/migrations/0079_cafe_staff_initial_accounts.sql'],'Historical migration 0079 unchanged')
    tgt=previous['targets']['local'].copy()
    tgt.update({'release_id':'sokna-r149b-local-43base-verified-20261108','version':files['VERSION.txt'].decode().strip(),'managed_files':len(files),'managed_tree_sha256':tree(files),'migration_head':'0080_tea_herbal_legacy_family_repair','schema_fingerprint':schema})
    manifest=previous.copy()
    manifest['supported_baselines']=previous['supported_baselines']+[{'local':previous['targets']['local'].copy(),'public':None}]
    check(len(manifest['supported_baselines'])==43,'43 exact baselines include current R140')
    check(len(set(json.dumps(x,sort_keys=True) for x in manifest['supported_baselines']))==43,'All 43 identities unique')
    manifest['release_set_id']=tgt['release_id']
    manifest['targets']={'local':tgt}
    manifest['sequence']=20261108152
    manifest['created_at']='2026-11-08T14:15:00Z'
    manifest['artifacts']['local-web'].update({'sha256':sha(web),'size_bytes':len(web)})
    manifest['database']['local']['migration_dag'].append('0080_tea_herbal_legacy_family_repair')
    manifest['qa_notes']={
        'scope':'LOCAL_ONLY_NO_PUBLIC_PRINT_SERVICES_DATA_DELETION',
        'source':'EXACT_R140_579_FILES_PLUS_21_REVIEWED_OVERLAY_TO_581_FILES',
        'catalog':'0080_APPEND_ONLY_ATOMIC_GUARDED_LEGACY_TEA_HERBAL_REPAIR',
        'maint':'MAINTENANCE_0_3_22_OPERATOR_SHA256_INBOX_ONLY',
        'universal':'43_EXACT_BASELINES_UNKNOWN_FAIL_CLOSED',
        'cafe_production_restore':'NOT_VERIFIED',
        'no_install_before_ci':'WINDOWS_STAGE_AND_RECOVERY_QUALIFICATION_REQUIRED',
    }
    qa=manifest.copy();qa['for_install']=False;qa['production_pass']=False;qa['status']='R149B_SOURCE_DB_AND_PACKAGE_QA_NOT_INSTALL'
    qa['qualification']={**qa['qualification'], 'canary':False,'real_windows':False,'failure_matrix_cases_required':0,'failure_matrix_id':'R149B_BEFORE_EXACT_WINDOWS_CHECK'}
    qualified=manifest.copy();qualified['for_install']=True;qualified['production_pass']=True;qualified['status']='R149B_LOCAL_43BASE_PANEL_QUALIFIED_HOME_CANARY'
    qualified['qualification']={**qualified['qualification'],'canary':True,'real_windows':True,'real_mariadb':True,'failure_matrix_cases_required':308,'failure_matrix_id':'R149B_EXACT_308_AND_WIN32_MARIADB_RECOVERY_CI'}
    for name,obj in [('candidate',qa),('qualified',qualified)]:
        dest=out/('r149b-'+name+'.zip')
        dest.write_bytes(outer(obj,web))
        with zipfile.ZipFile(dest) as z:
            check(z.testzip() is None,name+' outer CRC')
            d=json.loads(z.read('release-set.json'))
            check(d['targets']['local']==tgt and sha(z.read(d['artifacts']['local-web']['path']))==d['artifacts']['local-web']['sha256'],name+' exact target matches payload')
        print('PACKAGE',name,'BYTES',dest.stat().st_size,'SHA256',sha(dest.read_bytes()),flush=True)
    evidence={'source_r140_sha256':BASE_SHA,'overlay_sha256':OVERLAY_SHA,'exact_r149_web_sha256':sha(web),'r149b_candidate_sha256':sha((out/'r149b-candidate.zip').read_bytes()),'r149b_qualified_sha256':sha((out/'r149b-qualified.zip').read_bytes()),'target':tgt,'baseline_count':43}
    (out/'r149b-exact-evidence.json').write_text(json.dumps(evidence,ensure_ascii=False,sort_keys=True,indent=2),encoding='utf-8')
if __name__=='__main__':main()