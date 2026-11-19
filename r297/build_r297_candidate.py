#!/usr/bin/env python3
"""Read-only R297 QA candidate, exact R296 source with corrected release identity and inventory.
NEVER self-authorize production. Requires native Windows, printer, Public and owner acceptance.
"""
from pathlib import Path
import json,zipfile,hashlib,io,copy,subprocess,os
D=Path(os.environ['SOKNA_R297_WORKDIR'])
OUTPUT=D/'output';OUTPUT.mkdir(exist_ok=True)
R278=D/'SOKNA-R278-LOCAL-231FN-CUMULATIVE-RIAL-PERSISTENCE-INSTALL-READY.zip'
R296_SOURCE=Path(os.environ['SOKNA_R296_SOURCE_DIR'])
PIN278='3a1070e18b14307fb2571634c961b20ca3e5d38e6c9a9dc1afad68be89fe6824'
PIN296='06bcb72ac468e5833fb34c0284cd00100494859a203ec582d3a0769421f7af1b'
ORIGINAL_QA_TREE='9ecbbc9850d3f077d57f0cff4e808975157710781682cfa8be0e0b3bca458246'
VERSION='3.0.0-g6.2-workspace.231fo-r297-r296-cumulative-qa'
RELEASE='sokna-r297-r296-issues-cumulative-candidate-20261119'
SHA=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
MANAGED=lambda d:SHA(''.join(k+'\0'+SHA(v)+'\n' for k,v in sorted(d.items())).encode())
CANONICAL=lambda d:SHA(''.join(k+'\0'+str(len(v))+'\0'+SHA(v)+'\n' for k,v in sorted(d.items()) if k not in ['resources/build-provenance.json','resources/release-identity-v1.json']).encode())
assert SHA(R278.read_bytes())==PIN278
assert R296_SOURCE.is_dir(), 'MISSING_PINNED_R296_SOURCE_DIRECTORY'
with zipfile.ZipFile(R278) as outer:
 assert outer.testzip() is None
 parent=copy.deepcopy(json.loads(outer.read('release-set.json')))
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  old={k:inner.read(k) for k in inner.namelist()}
source={p.relative_to(R296_SOURCE).as_posix():p.read_bytes() for p in R296_SOURCE.rglob('*') if p.is_file()}
assert len(old)==604 and len(source)==607
assert MANAGED(source)==ORIGINAL_QA_TREE,(MANAGED(source),ORIGINAL_QA_TREE)
assert set(old).issubset(source),"old managed files removed"
for k in old:
 if k.startswith('database/migrations/'):
  assert old[k]==source[k],'HISTORICAL_MIGRATION_CHANGED '+k
assert {k for k in source if k.startswith('database/migrations/')}-set(old)=={'database/migrations/0083_remaining_account_discount.sql'}
assert set(source)-set(old)=={'database/migrations/0083_remaining_account_discount.sql','public/assets/dashboard-open-accounts.js','public/assets/iran-calendar-1405.json'}
new=source.copy()
# Reconcile authoritative managed inventory; CI source R296 contains a stale R278 list.
inv=json.loads(new['resources/local-managed-files-v1.json'])
assert len(inv['files'])==604 and set(inv['files'])==set(old),('STALE_INVENTORY_NOT_EXPECTED',len(inv['files']))
inv['files']=sorted(new)
new['resources/local-managed-files-v1.json']=J(inv)
new['VERSION.txt']=(VERSION+'\n').encode()
prov=json.loads(new['resources/build-provenance.json'])
prov.update(version=VERSION,release_set_id=RELEASE,classification='R297_QA_CANDIDATE_NOT_FOR_INSTALL',
 source='Exact R296 607-file cumulative QA with version/inventory/identity/manifest reconciled; R278 installed baseline included',
 source_recovery_mode='forward-only-after-ddl',workspace_source_only=False,
 production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False,
 r297_qa_passed_gitHub_run=44501927499,r297_windows_native='NOT_RUN',
 r297_live_public_compatibility='NOT_RUN',r297_physical_print='NOT_RUN')
new['resources/build-provenance.json']=J(prov)
ident=json.loads(new['resources/release-identity-v1.json'])
ident.update(version=VERSION,release_set_id=RELEASE)
ident['source'].update(managed_file_count=607,canonical_file_count=605,canonical_tree_sha256=CANONICAL(new),source_ref='Exact R296 cumulative source; QA 44501927499; metadata and 607-file inventory corrected')
ident['qualification']['tier']='R297_QA_NATIVE_WINDOWS_HOST_PRINT_PUBLIC_PENDING'
new['resources/release-identity-v1.json']=J(ident)
assert set(json.loads(new['resources/local-managed-files-v1.json'])['files'])==set(new)
assert ident['source']['canonical_tree_sha256']==CANONICAL(new)
assert len(new)==607 and sum(k.startswith('database/migrations/') for k in new)==83
# Update source- and migration-lineage-sensitive envelope fields.
r=parent
old_target=copy.deepcopy(r['targets']['local'])
assert old_target['version']=='3.0.0-g6.2-workspace.231fn-r278-rial-policy-persist'
assert old_target['managed_tree_sha256']==MANAGED(old)
assert not any(b['local']['version']==old_target['version'] for b in r['supported_baselines'])
r['supported_baselines'].append({'local':old_target,'public':None})
r['release_set_id']=RELEASE;r['sequence']=202611190297
r['created_at']='2026-11-19T12:30:00Z'
r['status']='R297_QA_CANDIDATE_NOT_FOR_INSTALL'
r['for_install']=False;r['production_pass']=False
r['qa_notes']['operator_install_allowed']=False
r['qa_notes']['r297_source']='R296 QA 44501927499 PASS, 607 managed files; 3 stale inventory entries reconciled; version and identity generated'
r['qa_notes']['r297_windows']='NOT_RUN_NATIVELY_ON_FINAL_CANDIDATE'
r['qa_notes']['r297_print']='WINDOWS_GDI_QA_ONLY__PHYSICAL_58_80_NOT_PROVEN'
r['qa_notes']['r297_public']='LOCAL_PUBLIC_REAL_SYNC_AND_QR_NOT_PROVEN'
r['qa_notes']['r297_field']='CAFE_BACKUP_RESTORE_INSTALL_AND_OPERATOR_ACCEPTANCE_PENDING'
r['qualification'].update(real_windows=False,real_public_host=False,clean_machine_remote_restore=False,real_mariadb=True)
r['database']['local']['migration_dag'].append('0083_remaining_account_discount')
newmig=sorted((k.rsplit('/',1)[-1][:-4],SHA(v)) for k,v in new.items() if k.startswith('database/migrations/') and k.endswith('.sql'))
schema_fp=SHA(''.join(n+'\0'+s+'\n' for n,s in newmig).encode())
r['targets']['local'].update(version=VERSION,release_id=RELEASE,managed_tree_sha256=MANAGED(new),managed_files=len(new),
 migration_head='0083_remaining_account_discount',schema_fingerprint='migration-ledger-sha256-v1:'+schema_fp)
# ZIP format copied from proven R278 envelope, with exactly 2 outer members and deterministic UTC date.
def Z(d):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for k,v in sorted(d.items()):
   info=zipfile.ZipInfo(k,(2026,11,19,12,30,0));info.compress_type=zipfile.ZIP_STORED;info.create_system=3;info.external_attr=0o100644<<16
   z.writestr(info,v,compress_type=zipfile.ZIP_STORED)
 return buf.getvalue()
web=Z(new)
r['artifacts']['local-web'].update(sha256=SHA(web),size_bytes=len(web))
pack=Z({'release-set.json':J(r),'artifacts/local/web.zip':web})
name='SOKNA-R297-LOCAL-231FO-CUMULATIVE-CANDIDATE-NOT-INSTALL.zip'
p=OUTPUT/name;p.write_bytes(pack)
with zipfile.ZipFile(p) as zz:
 assert zz.testzip() is None
 assert set(zz.namelist())=={'release-set.json','artifacts/local/web.zip'}
 assert SHA(zz.read('artifacts/local/web.zip'))==r['artifacts']['local-web']['sha256']
 with zipfile.ZipFile(io.BytesIO(zz.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  assert len(inner.namelist())==607
  check={k:inner.read(k) for k in inner.namelist()}
  assert MANAGED(check)==r['targets']['local']['managed_tree_sha256']
  assert ident['source']['canonical_tree_sha256']==CANONICAL(check)
  assert set(json.loads(check['resources/local-managed-files-v1.json'])['files'])==set(check)
proof={
 'schema':'sokna-r297-qa-candidate-integrity-v1',
 'status':'QA_CANDIDATE__DO_NOT_INSTALL',
 'component':'local',
 'release_set_id':RELEASE,'version':VERSION,
 'parent_r278_sha256':PIN278,'parent_r296_qa_archive_library_reference_sha256':PIN296,
 'r296_original_tree_sha256':ORIGINAL_QA_TREE,
 'r297_final_sha256':SHA(pack),'r297_web_zip_sha256':SHA(web),
 'r297_managed_tree_sha256':MANAGED(new),
 'r297_canonical_tree_sha256':CANONICAL(new),
 'r297_migration_fingerprint_sha256':schema_fp,
 'files':len(new),'migrations':len(newmig),
 'new_files_vs_r278':sorted(set(source)-set(old)),
 'modified_files_vs_r278':len([k for k in old if old[k]!=source[k]]),
 'packaging_metadata_corrected':['VERSION.txt','resources/local-managed-files-v1.json','resources/build-provenance.json','resources/release-identity-v1.json','release-set.json'],
 'historical_sql_unchanged':True,
 'baseline_versions':[b['local']['version'] for b in r['supported_baselines']],
 'safe_static_invariants_pass':True,
 'github_mariadb_qa_run_44501927499':'PASS_ON_R296_SOURCE_NOT_FINAL_R297_PACKAGE',
 'native_windows_r297_update_backup_restore':'NOT_RUN',
 'physical_58_80_print':'NOT_RUN',
 'live_local_public_sync':'NOT_RUN',
 'field_approval':'NOT_GRANTED',
 'production_authorized':False,'for_install':False,
}
(OUTPUT/'R297-INTEGRITY-AND-RELEASE-GATES.json').write_text(json.dumps(proof,ensure_ascii=False,sort_keys=True,indent=2)+'\n')
print(json.dumps({k:proof[k] for k in ('status','version','r297_final_sha256','r297_managed_tree_sha256','r297_migration_fingerprint_sha256','files','migrations','modified_files_vs_r278')},indent=2))
print(p)