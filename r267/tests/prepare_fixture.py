from pathlib import Path
import zipfile,shutil,json,os
w=Path(os.environ.get('SOKNA_FIX_ROOT','/mnt/data/sokna_public_emergency_fix'));root=w/'tests/fixture_public'
if root.exists():shutil.rmtree(root)
root.mkdir(parents=True)
R=Path(os.environ.get('SOKNA_INPUT_ROOT','/mnt/data/public-go-live-audit'))
with zipfile.ZipFile(R/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip') as z:
 for n in z.namelist():
  if n.startswith('payload/') and not n.endswith('/'):
   p=root/n[len('payload/'):];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
with zipfile.ZipFile(R/'SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip') as z:
 for n in z.namelist():
  if not n.endswith('/') and n not in ['manifest.json']:
   rel=n.removeprefix('payload/')
   if (rel=='emergency.php' or rel=='recovery.php' or rel.startswith('src/Emergency/') or rel.startswith('src/Recovery/') or rel=='resources/update-trust-v1.json') and not (root/rel).exists():
    p=root/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
(root/'emergency.php').write_text('<?php // QA-only protected owner stub\n')
(root/'storage/routine-update-v2').mkdir(parents=True)
# Simulate the exact authoritative R249 identity reported by the host.
state={'format':'sokna-public-routine-update-state-v2','schema_version':2,'active_version':'3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate','active_managed_tree_sha256':'1a5d041fd63ec5425ed9f01f0eff63c86c90c5c76f26530b773784c74884c928','previous_version':'','lkg_recovery_id':'','staged':None,'pending_verification':None,'activation_journal':None,'db_forward_fence':None,'history':[]}
(root/'storage/routine-update-v2/state.json').write_text(json.dumps(state))
(root/'storage/test-applied.json').write_text(json.dumps(sorted(p.stem for p in (root/'database/migrations').glob('*.sql'))))
print('fixture',root,'files',sum(p.is_file() for p in root.rglob('*')))

# Isolate original R249's eight migrations from the lifecycle fixture,
# which has been forward-upgraded to eleven by the preceding Emergency test.
dbbase=root.parent/'fixture_db_base'
if dbbase.exists():shutil.rmtree(dbbase)
with zipfile.ZipFile(R/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip') as z:
 for n in z.namelist():
  if n.startswith('payload/database/migrations/') and n.endswith('.sql'):
   p=dbbase/n[len('payload/'):];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
assert len(list((dbbase/'database/migrations').glob('*.sql')))==8

# Separate real MariaDB test roots. Never install code in a user's host.
final=root.parent/'fixture_db_final'
if final.exists():shutil.rmtree(final)
final.mkdir()
with zipfile.ZipFile(w/'output/SOKNA-R266-PUBLIC-35IG-SCHEMA-MOVE-CUMULATIVE-QA.zip') as z:
 for n in z.namelist():
  if n.startswith('payload/') and not n.endswith('/'):
   p=final/n[len('payload/'):];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
