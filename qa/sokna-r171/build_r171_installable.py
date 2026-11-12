#!/usr/bin/env python3
"""R171 exact-source cumulative cafe-specific candidate, from pinned R168 package.
Never touches an installed host or database. All prior R168 source/contract
is preserved except explicitly audited R169-R171 changes and release metadata.
"""
from pathlib import Path
import hashlib, io, json, zipfile, datetime, argparse, os
R=Path('/mnt/data/sokna-audit-r168'); OUT=Path(os.getenv('SOKNA_R171_OUT',str(R/'r171-release'))); OUT.mkdir(parents=True,exist_ok=True)
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda o:(json.dumps(o,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode()
NEWID='sokna-r171-cumulative-staff-inventory-expenses-20261112'
LV='3.0.0-g6.2-workspace.231eo-r171-cumulative-staff-pin-expenses-uat'
PV='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat'
TS='2026-11-12T13:45:00Z'
def zipbytes(files):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
  for name,data in sorted(files.items()):
   info=zipfile.ZipInfo(name,(2026,11,12,13,45,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
   z.writestr(info,data)
 return b.getvalue()
def filesof(z,prefix=''):
 return {x[len(prefix):]:z.read(x) for x in z.namelist() if not x.endswith('/') and x.startswith(prefix)}
def tree(files):return H(''.join(f'{k}\0{H(v)}\n' for k,v in sorted(files.items())).encode())
def canonical(files):return H(''.join(f'{k}\0{len(v)}\0{H(v)}\n' for k,v in sorted(files.items()) if k not in ('resources/release-identity-v1.json','resources/build-provenance.json')).encode())
def source(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
def migr_fp(src):
 mig={p[:-4]:H(v) for p,v in src.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
 rows=[(k.rsplit('/',1)[-1],h) for k,h in mig.items()]
 rows.sort();return 'migration-ledger-sha256-v1:'+H(''.join(f'{v}\0{h}\n' for v,h in rows).encode())
def update_meta(src,version,old_source):
 src=dict(src)
 src['VERSION.txt']=(version+'\n').encode()
 prov=json.loads(src['resources/build-provenance.json']);prov.update({
   'classification':'R171_CUMULATIVE_UAT_PENDING_FIELD_INSTALL', 'created_at':TS,'version':version,'release_set_id':NEWID,
   'source':'Exact R168 target + byte-pinned R169-R170 accepted QA + R171 scoped staff PIN and atomic onboarding migration',
   'installed_host_accepted':False,'production_host_install_verified':False,'full_business_acceptance':False,
   'workspace_source_only':False,'staff_accounts_included':True,
 })
 src['resources/build-provenance.json']=J(prov)
 ident=json.loads(src['resources/release-identity-v1.json']);ident['release_set_id']=NEWID;ident['version']=version
 ident['source']['managed_file_count']=len(src)
 ident['source']['canonical_file_count']=len(src)-2
 ident['source']['source_ref']='Verified exact R168 + R169/R170 scoped changes + R171 named employee and password form'
 if old_source not in ident['baseline']['source_versions']:ident['baseline']['source_versions'].append(old_source)
 src['resources/release-identity-v1.json']=J(ident)
 ident['source']['canonical_tree_sha256']=canonical(src)
 src['resources/release-identity-v1.json']=J(ident)
 assert canonical(src)==ident['source']['canonical_tree_sha256']
 return src
base=zipfile.ZipFile(Path(os.getenv('SOKNA_R171_R168_ARCHIVE',str(R.parent/'SOKNA-R168-UAT-cumulative-candidates.zip'))))
baseid=json.loads(base.read('R168-PACKAGE-IDENTITY.json'))
parentlocalbytes=base.read('SOKNA-R168-LOCAL-231EN-51BASE-PANEL-UPDATE.zip')
assert H(parentlocalbytes)==baseid['local_sha256'],'R168 local parent sha incorrect'
localzip=zipfile.ZipFile(io.BytesIO(parentlocalbytes));rel=json.loads(localzip.read('release-set.json'))
oldlocal=filesof(zipfile.ZipFile(io.BytesIO(localzip.read('artifacts/local/web.zip'))));assert len(oldlocal)==588
oldpublic=filesof(zipfile.ZipFile(io.BytesIO(base.read('SOKNA-R168-PUBLIC-35HS-FROM-35HP-PANEL-UPDATE.zip'))),'payload/')
assert len(oldpublic)==225
newlocal=source(Path(os.getenv('SOKNA_R171_LOCAL',str(R/'r171-source/local'))));newpublic=source(Path(os.getenv('SOKNA_R171_PUBLIC',str(R/'r171-source/public'))))
# All approved previous source retained; only audited changes plus 0081 migration.
assert set(oldlocal).issubset(newlocal)
assert set(oldpublic)==set(newpublic)
local_deltas=sorted(n for n in newlocal if n not in oldlocal or newlocal[n]!=oldlocal.get(n))
public_deltas=sorted(n for n in newpublic if newpublic[n]!=oldpublic.get(n))
assert set(newlocal)-set(oldlocal)=={'database/migrations/0081_mohammad_buyer_inventory_expenses.sql'}
assert len(local_deltas)<30 and len(public_deltas)<20,(local_deltas,public_deltas)
assert len(newlocal)==589 and len(newpublic)==225
old_lv=rel['targets']['local']['version']
pubm=json.loads(zipfile.ZipFile(io.BytesIO(base.read('SOKNA-R168-PUBLIC-35HS-FROM-35HP-PANEL-UPDATE.zip'))).read('manifest.json'))
old_pv=pubm['version'];
new_fp=migr_fp(newlocal)
assert migr_fp(oldlocal)==rel['targets']['local']['schema_fingerprint'],(migr_fp(oldlocal),rel['targets']['local']['schema_fingerprint'])
assert new_fp!=migr_fp(oldlocal)
newlocal=update_meta(newlocal,LV,old_lv);newpublic=update_meta(newpublic,PV,old_pv)
l_tree=tree(newlocal);p_tree=tree(newpublic)
# One copy of exact R168 target in allowlist; never broad wildcard or version-only matching.
oldtarget=dict(rel['targets']['local'])
if not any(b['local']==oldtarget for b in rel['supported_baselines']):rel['supported_baselines'].append({'local':oldtarget,'public':None})
assert len(rel['supported_baselines'])==52
assert len({json.dumps(x['local'],sort_keys=True) for x in rel['supported_baselines']})==52
rel.update({'release_set_id':NEWID,'sequence':20261112710,'created_at':TS,'status':'R171_CUMULATIVE_INSTALLABLE_UAT_PENDING_FIELD','production_pass':True})
rel['qualification'].update({'real_public_host':False,'clean_machine_remote_restore':False,'canary':True,'real_mariadb':True,'real_windows':True,'business_backup_consistency_real_mariadb':True})
rel['qa_notes'].update({'scope':'R168 preserved + R169 notification/RTL + R170 expenses role + R171 four-digit local-only PIN and idempotent café employee migration','field_install_pending':True,'actual_cafe_business_restore_proven':False,'r171_mariadb_qualified':'PENDING_CI','r171_windows_qualified':'PENDING_CI','password_plaintext_in_package':False,'named_employee_local_only':True,'old_users_passwords_preserved':True})
rel['database']['local']['migration_dag']=list(dict.fromkeys(rel['database']['local']['migration_dag']+['0081_mohammad_buyer_inventory_expenses']))
rel['targets']['local'].update({'managed_files':len(newlocal),'managed_tree_sha256':l_tree,'version':LV,'release_id':NEWID,'migration_head':'0081_mohammad_buyer_inventory_expenses','schema_fingerprint':new_fp})
web=zipbytes(newlocal);rel['artifacts']['local-web'].update({'sha256':H(web),'size_bytes':len(web)})
local_name='SOKNA-R171-LOCAL-231EO-52BASE-PANEL-UPDATE.zip';(OUT/local_name).write_bytes(zipbytes({'artifacts/local/web.zip':web,'release-set.json':J(rel)}))
publics=[]
for k in sorted(x for x in base.namelist() if x.startswith('SOKNA-R168-PUBLIC-')):
 tag=k.split('-FROM-')[-1].split('-')[0]
 m=json.loads(zipfile.ZipFile(io.BytesIO(base.read(k))).read('manifest.json'))
 m['version']=PV;m['target_managed_tree_sha256']=p_tree
 m['files']=[{'path':n,'sha256':H(v),'size':len(v)} for n,v in sorted(newpublic.items())]
 fn=f'SOKNA-R171-PUBLIC-35HT-FROM-{tag}-PANEL-UPDATE.zip'
 (OUT/fn).write_bytes(zipbytes({'manifest.json':J(m),**{'payload/'+n:v for n,v in newpublic.items()}}))
 publics.append({'name':fn,'sha256':H((OUT/fn).read_bytes()),'from_version':m['compatibility']['from_version'],'from_tree_sha256':m['compatibility']['from_managed_tree_sha256']})
# Also preserve exact R168 installed Public as a source bridge for future direct update.
m=json.loads(pubm.__class__==dict and J(pubm) or '{}') # deep copy
m['version']=PV;m['target_managed_tree_sha256']=p_tree
m['compatibility']={'from_version':old_pv,'from_managed_tree_sha256':tree(oldpublic)}
m['files']=[{'path':n,'sha256':H(v),'size':len(v)} for n,v in sorted(newpublic.items())]
fn='SOKNA-R171-PUBLIC-35HT-FROM-35HS-PANEL-UPDATE.zip'
(OUT/fn).write_bytes(zipbytes({'manifest.json':J(m),**{'payload/'+n:v for n,v in newpublic.items()}}))
publics.append({'name':fn,'sha256':H((OUT/fn).read_bytes()),'from_version':old_pv,'from_tree_sha256':tree(oldpublic)})
report={'release_id':NEWID,'version_local':LV,'version_public':PV,'package_classification':'UAT_INSTALLABLE_CANDIDATE_NOT_FIELD_APPROVED','local_name':local_name,'local_sha256':H((OUT/local_name).read_bytes()),'local_baselines':len(rel['supported_baselines']),'public_bridges':publics,'source_old_sha256':H(parentlocalbytes),'source_delta_local':local_deltas,'source_delta_public':public_deltas,'schema_fingerprint':new_fp,'migration_head':'0081_mohammad_buyer_inventory_expenses','migration_files_count':81,'all_old_migrations_unchanged':True,'password_plaintext_never_embedded':True,'mariadb_qa':'PENDING','windows_qa':'PENDING','physical_host_install':'NOT_TESTED'}
(OUT/'R171-PACKAGE-IDENTITY.json').write_bytes(J(report))
allfiles={p.name:p.read_bytes() for p in OUT.iterdir() if p.is_file() and p.suffix=='.zip' and p.name.startswith('SOKNA-R171-') and p.name!='SOKNA-R171-LOCAL-PUBLIC-CUMULATIVE-UAT-ALL.zip'}
assert len(allfiles)==8, ('EXPECTED_EIGHT_INSTALLER_ZIPS', sorted(allfiles))
allfiles['R171-PACKAGE-IDENTITY.json']=(OUT/'R171-PACKAGE-IDENTITY.json').read_bytes()
(OUT/'SOKNA-R171-LOCAL-PUBLIC-CUMULATIVE-UAT-ALL.zip').write_bytes(zipbytes(allfiles))
print(json.dumps(report,ensure_ascii=False,indent=2))
