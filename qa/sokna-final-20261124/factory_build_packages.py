#!/usr/bin/env python3
"""SOKNA deterministic offline release-format preflight from exact QA source and R306 artifact.

This deliberately produces packages marked NOT INSTALLABLE until exact migration,
Windows Maintenance, Public HTTP, baseline coverage and backup/recovery gates pass.
No application, update engine, emergency engine, host, or database is mutated.
"""
import collections, copy, hashlib, io, json, os, pathlib, zipfile

BASE = pathlib.Path(os.environ.get('SOKNA_RELEASE_BUILD_DIR', '/mnt/data/sokna_release_build'))
SRC_ZIP = pathlib.Path(os.environ.get('SOKNA_SOURCE_ARCHIVE', '/mnt/data/SOKNA-CONSOLIDATED-SOURCE-20261124-QA-NOT-INSTALL.zip'))
L306 = BASE/'r306/SOKNA-R306-LOCAL-231FV-CONTROLLED-PREFLIGHT.zip'
P306 = BASE/'r306/SOKNA-R306-PUBLIC-35IN-EMERGENCY-PREFLIGHT.zip'
L307 = BASE/'r307/SOKNA-R307-LOCAL-231FW-FROM-231FV-CONTROLLED-PREFLIGHT.zip'
OUT = BASE/'preflight'; OUT.mkdir(exist_ok=True)

PIN = {
  str(SRC_ZIP): 'c95dbb4a39c2b99b3cbb6072f45d5e435212f1ac6f94b4c0345a7512805a4013',
  str(L306): '1050c045b3da28f19a726109ef487d6e9940f01120cba35c6a41273b1e709209',
  str(P306): 'e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51',
  str(L307): '069a267f920b2d0614ca61443851b4f385994dca379d2bbbb3e1c42aba8047fa'
}
LVER = '3.0.0-g6.2-workspace.231ga-consolidated-20261124-preflight'
PVER = '3.0.0-g3.6-workspace.35io-consolidated-20261124-preflight'
REL = 'sokna-consolidated-release-preflight-20261124-r2'
H = lambda b: hashlib.sha256(b).hexdigest()
J = lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()

def tree(d):
    return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())

def canonical(d):
    excludes = ('resources/build-provenance.json','resources/release-identity-v1.json')
    return H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(d.items()) if k not in excludes).encode())

def zbytes(d, compression=zipfile.ZIP_DEFLATED):
    buf=io.BytesIO()
    with zipfile.ZipFile(buf,'w') as z:
        for n,b in sorted(d.items()):
            assert n and not n.startswith('/') and '..' not in n.split('/') and '\\' not in n
            assert isinstance(b,bytes)
            zi=zipfile.ZipInfo(n,(2026,11,24,0,0,0))
            zi.create_system=3;zi.external_attr=0o100644<<16;zi.compress_type=compression
            z.writestr(zi,b,compress_type=compression,compresslevel=9 if compression==zipfile.ZIP_DEFLATED else None)
    return buf.getvalue()

def zread(blob):
    with zipfile.ZipFile(io.BytesIO(blob)) as z:
        ns=z.namelist()
        assert len(ns)==len(set(ns)) and z.testzip() is None
        assert all(not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in ns)
        return {n:z.read(n) for n in ns if not n.endswith('/')}

for p,want in PIN.items():
    assert H(pathlib.Path(p).read_bytes())==want, 'PIN_DRIFT_'+p
    assert zipfile.ZipFile(p).testzip() is None

orig=zread(SRC_ZIP.read_bytes())
local={n.removeprefix('source-working/local/'):v for n,v in orig.items() if n.startswith('source-working/local/')}
public={n.removeprefix('source-working/public/'):v for n,v in orig.items() if n.startswith('source-working/public/')}
assert len(local)==619 and len(public)==251,(len(local),len(public))
assert local['VERSION.txt'].decode().strip()=='3.0.0-g6.2-workspace.231fv-r306-local-public-ui-parity'
assert public['VERSION.txt'].decode().strip()=='3.0.0-g3.6-workspace.35in-r306-local-public-ui-parity'

baseL=zread(L306.read_bytes());baseP=zread(P306.read_bytes());base307=zread(L307.read_bytes())
assert set(base307)=={'release-set.json','artifacts/local/web.zip'}
r307manifest=json.loads(base307['release-set.json']);r307_target=copy.deepcopy(r307manifest['targets']['local'])
assert r307_target['version']=='3.0.0-g6.2-workspace.231fw-r307-dashboard-money-calendar-notify'
assert r307_target['managed_tree_sha256']=='5455c9d2ea4762a050d826f59c1896f7e22827b1cdad9f6106bca356ba94b186'
r307source=zread(base307['artifacts/local/web.zip'])
assert len(r307source)==r307_target['managed_files']==608 and tree(r307source)==r307_target['managed_tree_sha256']
assert set(baseL)=={'release-set.json','artifacts/local/web.zip'}
assert 'manifest.json' in baseP
oldlocal=zread(baseL['artifacts/local/web.zip'])
oldpublic={n[8:]:v for n,v in baseP.items() if n.startswith('payload/')}
assert len(oldlocal)==608 and len(oldpublic)==244
assert set(oldlocal)<=set(local) and set(oldpublic)<=set(public)
changed_local=sorted(k for k in local if k not in oldlocal or local[k]!=oldlocal[k])
changed_public=sorted(k for k in public if k not in oldpublic or public[k]!=oldpublic[k])
assert [n for n in oldlocal if n.startswith('database/migrations/') and n in local and oldlocal[n]!=local[n]]==[], 'HISTORICAL_MIGRATION_CHANGED'
assert [n for n in oldpublic if n.startswith('database/migrations/') and n in public and oldpublic[n]!=public[n]]==[], 'PUBLIC_HISTORICAL_MIGRATION_CHANGED'
assert not any(n in local for n in ['config.php','install.lock','.env'])
assert not any(n in public for n in ['config.php','install.lock','.env'])

# Local complete managed tree and identity are rebuilt from the exact source. 
L=dict(local)
L['VERSION.txt']=(LVER+'\n').encode()
mi=json.loads(L['resources/local-managed-files-v1.json'])
assert mi['format']=='sokna-local-managed-files-v1'
mi['files']=sorted(L)
L['resources/local-managed-files-v1.json']=J(mi)
ident=json.loads(L['resources/release-identity-v1.json'])
assert ident['format']=='sokna-release-component-identity-v1'
ident.update(version=LVER,release_set_id=REL)
ident['baseline']['source_versions']=list(dict.fromkeys(ident['baseline']['source_versions']+[oldlocal['VERSION.txt'].decode().strip(),r307_target['version']]))
ident['source'].update(managed_file_count=len(L),canonical_file_count=len(L)-2,canonical_tree_sha256=canonical(L),source_ref='Immutable consolidated source b3aad22d + exact R306 production-format package 1050c045; qualification pending')
ident['qualification']={'tier':'CONSOLIDATED_PACKAGE_PREFLIGHT_NOT_INSTALLABLE','claim_rule':'No install before exact MariaDB 0088, native Windows and Public root/subdir activation, and compatible installed baseline.'}
L['resources/release-identity-v1.json']=J(ident)
prov=json.loads(L['resources/build-provenance.json'])
prov.update(version=LVER,release_set_id=REL,created_at='2026-11-24T00:00:00Z',classification='PREFLIGHT_NOT_INSTALLABLE',source='sha256:'+PIN[str(SRC_ZIP)],workspace_source_only=False,full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False,installed_cafe_baseline='R307_231fw_support_20261120',consolidated_source_sha256=PIN[str(SRC_ZIP)],r306_base_zip_sha256=PIN[str(L306)])
L['resources/build-provenance.json']=J(prov)
assert sorted(json.loads(L['resources/local-managed-files-v1.json'])['files'])==sorted(L)
assert json.loads(L['resources/release-identity-v1.json'])['source']['canonical_tree_sha256']==canonical(L)
assert len(L)==619
lmetadata=['VERSION.txt','resources/local-managed-files-v1.json','resources/release-identity-v1.json','resources/build-provenance.json']
assert all(L[n]==local[n] for n in local if n not in lmetadata)

lmanifest=copy.deepcopy(json.loads(baseL['release-set.json']))
assert lmanifest['format']=='sokna-maintenance-release-set-v2.1'
base_target=copy.deepcopy(lmanifest['targets']['local'])
# Exact R301/R304/R306/R307 origins; R307 is installed at café according to 2026-11-20 support. Never wildcard.
existing=lmanifest['supported_baselines']
assert len(existing)==2 and all(x.get('local') for x in existing)
lmanifest['supported_baselines'] = existing+[{'local':base_target},{'local':r307_target}]
assert len({(x['local']['version'],x['local']['managed_tree_sha256']) for x in lmanifest['supported_baselines']})==4
assert all(x['local']['version']!=LVER for x in lmanifest['supported_baselines'])
lmanifest.update(release_set_id=REL,status='PREFLIGHT_NOT_INSTALLABLE_PENDING_NATIVE_GATES',created_at='2026-11-24T00:00:00Z',sequence=202611240002,for_install=False,production_pass=False)
for k in lmanifest['qualification']:
    if isinstance(lmanifest['qualification'][k],bool):lmanifest['qualification'][k]=False
newhead='0088_historical_variant_order_snapshots'
newmigrations=[f'{i:04d}_'+next(n for n in L if n.startswith(f'database/migrations/{i:04d}_')).split(f'/{i:04d}_',1)[1].removesuffix('.sql') for i in range(84,89)]
assert len(newmigrations)==5 and newmigrations[-1]==newhead
assert lmanifest['database']['local']['migration_dag'][-1]=='0083_remaining_account_discount'
lmanifest['database']['local']['migration_dag'].extend(newmigrations)
lmanifest['targets']['local'].update(version=LVER,release_id=REL,managed_files=len(L),managed_tree_sha256=tree(L),migration_head=newhead,
    schema_fingerprint='UNVERIFIED_PENDING_REAL_MARIADB_MIGRATION_0088')
lmanifest['qa_notes']['consolidated']='Source + package shape reconstructed from SHA-pinned R306; no MariaDB/Windows/public host qualification. DO NOT INSTALL.'
lmanifest['qa_notes']['verified_baselines_only']=[x['local']['version'] for x in lmanifest['supported_baselines']]
lmanifest['qa_notes']['schema_fingerprint']='UNVERIFIED; cannot reuse R306 fingerprint across five new migrations.'
lmanifest['signature']=None
web=zbytes(L)
lmanifest['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
localzip=zbytes({'release-set.json':J(lmanifest),'artifacts/local/web.zip':web})
localpath=OUT/'SOKNA-LOCAL-CONSOLIDATED-MAINTENANCE-PREFLIGHT-DO-NOT-INSTALL.zip'
localpath.write_bytes(localzip)

# Public Routine/Emergency managed payload with exact R306 and R304 known origins.
P=dict(public)
P['VERSION.txt']=(PVER+'\n').encode()
owner=json.loads(P['src/Update/emergency-owner-v1.json'])
assert owner['format']=='sokna-emergency-owner-v1'
owner.update(release_version=PVER,sha256=H(P['src/Update/EmergencyOwnerCandidate.php']))
P['src/Update/emergency-owner-v1.json']=J(owner)
pident=json.loads(P['resources/release-identity-v1.json'])
assert pident['format']=='sokna-release-component-identity-v1'
pident.update(version=PVER,release_set_id=REL)
pident['baseline']['source_versions']=list(dict.fromkeys(pident['baseline']['source_versions']+[oldpublic['VERSION.txt'].decode().strip()]))
pident['source'].update(managed_file_count=len(P),canonical_file_count=len(P)-2,canonical_tree_sha256=canonical(P),source_ref='Immutable consolidated source b3aad22d + R306 Public exact original e2d78030; qualification pending')
pident['qualification']={'tier':'CONSOLIDATED_PUBLIC_PREFLIGHT_NOT_INSTALLABLE','claim_rule':'No install before exact Public Emergency/Routine root/subfolder SQL/HTTP tests and installed baseline identity.'}
P['resources/release-identity-v1.json']=J(pident)
pprov=json.loads(P['resources/build-provenance.json'])
pprov.update(version=PVER,release_set_id=REL,classification='PREFLIGHT_NOT_INSTALLABLE',created_at='2026-11-24T00:00:00Z',full_business_acceptance=False,installed_host_accepted=False,target_host_install_verified=False,production_host_install_verified=False,source='sha256:'+PIN[str(SRC_ZIP)])
P['resources/build-provenance.json']=J(pprov)
assert json.loads(P['resources/release-identity-v1.json'])['source']['canonical_tree_sha256']==canonical(P)
assert len(P)==251
pmetadata=['VERSION.txt','src/Update/emergency-owner-v1.json','resources/release-identity-v1.json','resources/build-provenance.json']
assert all(P[n]==public[n] for n in public if n not in pmetadata)

pmanifest=copy.deepcopy(json.loads(baseP['manifest.json']))
assert pmanifest['format']=='sokna-component-package-v1'
assert len(pmanifest['compatibility']['supported_baselines'])==1
# R304 confirmed by R306 source. R306 target is independently hashed, not a guessed installed version.
pmanifest['compatibility']['supported_baselines'].append({'version':oldpublic['VERSION.txt'].decode().strip(),'managed_tree_sha256':tree(oldpublic)})
assert len({(v['version'],v['managed_tree_sha256']) for v in pmanifest['compatibility']['supported_baselines']})==2
pmanifest.update(version=PVER,target_managed_tree_sha256=tree(P),files=[{'path':n,'size':len(b),'sha256':H(b)} for n,b in sorted(P.items())])
pmanifest['provenance']={'build':'SOKNA consolidated source SHA b3aad22d based on R306 exact Public package SHA e2d78030','source_archive_sha256':PIN[str(SRC_ZIP)],'origin_package_sha256':PIN[str(P306)],'source_managed_tree_sha256':tree(oldpublic),'modified_files':changed_public,'strict_multibaseline_v1':True,'offline_qualified':False,'qualification_qa_run':'NOT_RUN','installation_authorized':False,'production_host_qualified':False,'release_qualification':'PREFLIGHT_DO_NOT_INSTALL','host_operator_note':'Do NOT install; only R304/R306 exact identities declared and no real host activation tested.'}
publiczip=zbytes({'manifest.json':J(pmanifest),**{'payload/'+n:v for n,v in P.items()}})
publicpath=OUT/'SOKNA-PUBLIC-CONSOLIDATED-ROUTINE-EMERGENCY-PREFLIGHT-DO-NOT-INSTALL.zip'
publicpath.write_bytes(publiczip)

# Full source layout for isolated clean-install inspection, NOT runnable clean installer.
cleanpath=OUT/'SOKNA-PUBLIC-CONSOLIDATED-CLEAN-SOURCE-PREFLIGHT-DO-NOT-INSTALL.zip'
cleanpath.write_bytes(zbytes(P))

# Verify all exact payload digests, public manifest, local release shape, and source conservation.
outer=zread(localpath.read_bytes()); lm=json.loads(outer['release-set.json'])
assert set(outer)=={'release-set.json','artifacts/local/web.zip'}
assert H(outer['artifacts/local/web.zip'])==lm['artifacts']['local-web']['sha256']
assert lm['artifacts']['local-web']['size_bytes']==len(outer['artifacts/local/web.zip'])
assert zread(outer['artifacts/local/web.zip'])==L
assert tree(L)==lm['targets']['local']['managed_tree_sha256']
assert lm['targets']['local']['managed_files']==len(L)
assert lm['for_install'] is False and lm['production_pass'] is False
pub_out=zread(publicpath.read_bytes()); pm=json.loads(pub_out.pop('manifest.json'))
assert set(pub_out)=={'payload/'+n for n in P}
assert len(pm['files'])==len(P)
assert pm['target_managed_tree_sha256']==tree(P)
assert all(pub_out['payload/'+r['path']]==P[r['path']] and H(P[r['path']])==r['sha256'] and len(P[r['path']])==r['size'] for r in pm['files'])
assert zread(cleanpath.read_bytes())==P
assert pm['provenance']['installation_authorized'] is False
assert not any(n.startswith('payload/src/Emergency/') for n in pub_out)

result={
 'schema':'sokna-consolidated-offline-package-preflight-v1',
 'date':'2026-11-24','source_sha256':PIN[str(SRC_ZIP)],'r306_local_sha256':PIN[str(L306)],'r307_cafe_local_sha256':PIN[str(L307)],'r307_cafe_baseline_tree_sha256':r307_target['managed_tree_sha256'],'r306_public_sha256':PIN[str(P306)],
 'local':{'file':localpath.name,'sha256':H(localzip),'manifest_format':lm['format'],'target_version':LVER,'managed_files':len(L),'functional_drift_from_source':0,'changed_or_added_from_r306':len(changed_local),'new_migrations':newmigrations,'declared_exact_baselines':len(lm['supported_baselines']),'status':'STRUCTURAL_PASS_NOT_INSTALLABLE'},
 'public':{'file':publicpath.name,'sha256':H(publiczip),'manifest_format':pm['format'],'target_version':PVER,'managed_files':len(P),'functional_drift_from_source':0,'changed_or_added_from_r306':len(changed_public),'declared_exact_baselines':len(pmanifest['compatibility']['supported_baselines']),'status':'STRUCTURAL_PASS_NOT_INSTALLABLE'},
 'public_clean_source':{'file':cleanpath.name,'sha256':H(cleanpath.read_bytes()),'note':'Full web source only, not independently qualified clean installer'},
 'blocked_gates':['Real MariaDB 11.4 migration 0084-0088 and schema fingerprint','Native Windows Maintenance preflight/stage/activate/restore for exact Local ZIP','Public Routine/Emergency HTTP root/subfolder activation and rollback/readback','Installed baseline identity coverage for cafe/home/public','Local clean-machine installer + integrated release producer/admission'],
 'source_changed_product_files':0,'legacy_original_unchanged':True,'release_authorized':False,'operator_install_authorized':False,'production_changed':False
}
(OUT/'SOKNA-RELEASE-PREFLIGHT-RESULT-20261124.json').write_bytes(J(result))
print(json.dumps(result,ensure_ascii=False,indent=2))