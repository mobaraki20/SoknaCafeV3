#!/usr/bin/env python3
"""Fail-closed exact R153 Home to R184 CI target source and migration compatibility.
No changes to host, DB, files, or release. Never grants install approval.
"""
import argparse,hashlib,io,json,pathlib,zipfile
R153_WRAPPER='7b836aba9cd1397fee56deb3b0eda4e2a08b1f99dc58ee69463ea0662dca7173'
R153_INNER='4f98e7eb948e78c49b09f4f41299ec7b4c68b562cfac8dbe72e0c827a69ef613'
R184_CANDIDATE='da884efc86b3801d11d3842411d6189d916ff2736451aefe3801dafbea7a59ec'
HOME={
'release_id':'sokna-r153-local-44base-home-canary-20261111',
'managed_files':583,
'version':'3.0.0-g6.2-workspace.231ed-r153-guest-readonly-meta-qa',
'managed_tree_sha256':'69e888a46cf1ed0ae763c2e83999957f5f067a2ef09a2bc68f0f189532259b96',
'schema_fingerprint':'migration-ledger-sha256-v1:fd316c493e1f5a122f83859662b106a0f8329e9d6fa2accd1ad685440d9ec863',
'migration_head':'0080_tea_herbal_legacy_family_repair',
'authority_epoch_policy':'preserved'}
sha=lambda b:hashlib.sha256(b).hexdigest()
def files(blob):
 with zipfile.ZipFile(io.BytesIO(blob)) as z:
  assert len(z.namelist())==len(set(z.namelist()))
  return {n:z.read(n) for n in z.namelist()}
def verify(r153,r184):
 a=r153.read_bytes();b=r184.read_bytes()
 if sha(a)==R153_WRAPPER:
  with zipfile.ZipFile(io.BytesIO(a)) as z:
   assert set(z.namelist())=={'SOKNA-R153-LOCAL-231ED-44BASE-CONTRACT-COMPLETE-QA-NOT-INSTALL.zip','CONTRACT-TEST-RESULT.txt'}
   old=z.read('SOKNA-R153-LOCAL-231ED-44BASE-CONTRACT-COMPLETE-QA-NOT-INSTALL.zip')
 else:
  old=a
 assert sha(old)==R153_INNER,'R153_INNER_SHA_DRIFT'
 assert sha(b)==R184_CANDIDATE,'R184_CANDIDATE_SHA_DRIFT'
 with zipfile.ZipFile(io.BytesIO(old)) as z:
  om=json.loads(z.read('release-set.json')); oldfiles=files(z.read('artifacts/local/web.zip'))
 with zipfile.ZipFile(io.BytesIO(b)) as z:
  m=json.loads(z.read('release-set.json'));newfiles=files(z.read('artifacts/local/web.zip'))
 assert om['targets']['local']['version']==HOME['version']
 assert om['targets']['local']['managed_tree_sha256']==HOME['managed_tree_sha256']
 assert om['targets']['local']['schema_fingerprint']==HOME['schema_fingerprint']
 assert om['targets']['local']['migration_head']==HOME['migration_head']
 exact=[x['local'] for x in m['supported_baselines'] if x.get('local')==HOME]
 assert len(exact)==1,'EXACT_SIX_FIELD_HOME_IDENTITY_MISSING_OR_AMBIGUOUS'
 assert len(oldfiles)==583 and len(newfiles)==589
 missing=sorted(oldfiles.keys()-newfiles.keys());added=sorted(newfiles.keys()-oldfiles.keys())
 assert not missing, 'OLD_FILE_REMOVED'
 expected={'database/migrations/0081_mohammad_buyer_inventory_expenses.sql', 'public/system/go-live-api.php','public/system/go-live.php','resources/golive-clearance-policy.json','src/Domain/System/GoLiveResetCoordinator.php','src/Domain/System/GoLiveResetEngine.php'}
 assert set(added)==expected,'NEW_FILES_UNEXPECTED'
 oldmigration={x:v for x,v in oldfiles.items() if x.startswith('database/migrations/')}
 newmigration={x:v for x,v in newfiles.items() if x.startswith('database/migrations/')}
 assert len(oldmigration)==80 and len(newmigration)==81
 assert all(newmigration[n]==v for n,v in oldmigration.items()),'HISTORICAL_MIGRATION_BYTES_CHANGED'
 assert set(newmigration)-set(oldmigration)=={'database/migrations/0081_mohammad_buyer_inventory_expenses.sql'}
 assert m['targets']['local']['migration_head']=='0081_mohammad_buyer_inventory_expenses'
 assert m['targets']['local']['schema_fingerprint']!=HOME['schema_fingerprint']
 assert m['qa_notes']['r184_quarantined_ci_only'] is True
 result={'format':'sokna-r185-exact-home-to-quarantined-target-v1','status':'PASS',
 'old_source_files':len(oldfiles),'target_files':len(newfiles),
 'historical_migrations_immutable':True,'old_migrations':len(oldmigration),'target_migrations':len(newmigration),
 'new_migration':next(iter(set(newmigration)-set(oldmigration))),
 'home_six_field_identity_verified':True,'files_added':added,'files_removed':missing,
 'target_ci_quarantined':True,'full_activate_readback_verified':False,'customer_install_approved':False}
 return result
if __name__=='__main__':
 ap=argparse.ArgumentParser();ap.add_argument('--r153',type=pathlib.Path,required=True);ap.add_argument('--r184',type=pathlib.Path,required=True);ap.add_argument('--out',type=pathlib.Path,required=True);a=ap.parse_args()
 result=verify(a.r153,a.r184);a.out.parent.mkdir(parents=True,exist_ok=True);a.out.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print('PASS_R185_EXACT_R153_TO_R184_SOURCE_AND_80_IMMUTABLE_MIGRATIONS')
