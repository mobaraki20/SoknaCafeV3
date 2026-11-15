#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,zipfile,io,pathlib,copy,os
ROOT=pathlib.Path(os.environ.get('SOKNA_R245_HOME','/mnt/data/return_group_r245'));OUT=ROOT/'out';OUT.mkdir(exist_ok=True)
R171=ROOT/'SOKNA-R171-PUBLIC-35HT-FROM-35HK-PANEL-UPDATE.zip'
R233=pathlib.Path(os.environ.get('SOKNA_R245_R233','/mnt/data/sokna_release_work/R233-QA-CANDIDATES/SOKNA-R233-PUBLIC-ROUTINE-UPDATE-QA-DO-NOT-INSTALL.zip'))
OVERLAY=pathlib.Path(os.environ.get('SOKNA_R245_OVERLAY','/mnt/data/SOKNA-RECONNECT-REPAIR/SOKNA-R233-PUBLIC-RECOVERY-HOTFIX-ROOT-OVERLAY-REQUIRES-BACKUP.zip'))
R242=pathlib.Path(os.environ.get('SOKNA_R245_R242','/mnt/data/SOKNA-R242-AUTHORITY-STATUS-HOTFIX/SOKNA-R233-PUBLIC-RECOVERY-STATUS-HOTFIX-ONE-FILE.zip'))
VER='3.0.0-g3.6-workspace.35ia-r245-group-compat-recovery'
BASE='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat'
def sha(b):return hashlib.sha256(b).hexdigest()
def tree(m):return sha(''.join(k+'\0'+sha(m[k])+'\n' for k in sorted(m)).encode())
def j(v):return (json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def load(p):
 with zipfile.ZipFile(p) as z:
  assert z.testzip() is None
  obj=json.loads(z.read('manifest.json'))
  files={n[8:]:z.read(n) for n in z.namelist() if n.startswith('payload/')}
  assert set(files)=={r['path'] for r in obj['files']}
  for r in obj['files']:
   b=files[r['path']];assert len(b)==r['size'] and sha(b)==r['sha256']
  return obj,files
r171,src=load(R171);m,files=load(R233)
assert r171['version']==BASE and r171['target_managed_tree_sha256']==tree(src)
assert len(src)==225 and len(files)==228 and set(src)<=set(files)
assert m['version']=='3.0.0-g3.6-workspace.35hz-r233-qa'
with zipfile.ZipFile(OVERLAY) as z:
 for path in ['authority-recovery.php','src/Authority/AuthorityEpochService.php','src/Http/AuthorityEpochHttpAdapter.php','src/Http/PublicHttpKernel.php']:
  files[path]=z.read(path)
with zipfile.ZipFile(R242) as z:
 files['src/Security/SignedLocalRequestVerifier.php']=z.read('src/Security/SignedLocalRequestVerifier.php')
from patch_recovery_compat import patch
files['authority-recovery.php']=patch(files['authority-recovery.php'])
files['VERSION.txt']=(VER+'\n').encode()
# The owner-candidate code is byte-identical between R171 and R233. Rebind only
# its preexisting immutable hash to the new release to avoid disabling the candidate.
owner=json.loads(files['src/Update/emergency-owner-v1.json'])
assert owner['sha256']==sha(files['src/Update/EmergencyOwnerCandidate.php'])
owner['release_version']=VER
files['src/Update/emergency-owner-v1.json']=j(owner)
protected=['emergency.php','resources/update-trust-v1.json','src/Emergency/']
assert all(not (path==x or x.endswith('/') and path.startswith(x)) for x in protected for path in files)
# Recovery owner is present in the full snapshot but must stay byte-identical.
for path in ['recovery.php','src/Recovery/StableRecoveryService.php']:
 assert files.get(path)==src.get(path), 'PROTECTED_RECOVERY_OWNER_CHANGED'
assert not any(p.startswith(('storage/','var/','config.php')) for p in files)
assert len(files)==228
# A versioned immutable bridge is NOT a bootstrap; old Emergency access-class
# compatibility must be validated separately before using authority-recovery.php.
manifest={
 'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':VER,
 'compatibility':{'from_version':BASE,'from_managed_tree_sha256':r171['target_managed_tree_sha256']},
 'contracts':{'local_public_contract':1},
 'target_managed_tree_sha256':tree(files),
 'provenance':{'build':'R245-exact-35ht-bridge','qualification':'QA_ONLY_NOT_INSTALL','reference_base_tree':r171['target_managed_tree_sha256'],
 'Emergency_stable_owner':'not_in_payload','protected_owner_must_be_qualified_separately':True},
 'files':[{'path':k,'sha256':sha(b),'size':len(b)} for k,b in sorted(files.items())],
}
name='SOKNA-R245-PUBLIC-35IA-FROM-EXACT-35HT-GROUP-QA-DO-NOT-INSTALL.zip';out=OUT/name
with zipfile.ZipFile(out,'w') as z:
 for n,b in [('manifest.json',j(manifest))]+[('payload/'+k,b) for k,b in sorted(files.items())]:
  info=zipfile.ZipInfo(n,date_time=(2026,11,15,12,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=(0o644<<16)
  z.writestr(info,b,compresslevel=8)
_,rt=load(out)
assert rt==files
proof={'classification':'QA_ONLY_NOT_INSTALL','source_R171':{'version':BASE,'sha256':sha(R171.read_bytes()),'managed_tree_sha256':tree(src),'count':len(src)},
'candidate':{'version':VER,'sha256':sha(out.read_bytes()),'managed_tree_sha256':tree(files),'count':len(files),'file_size_bytes':out.stat().st_size},
'changes':{'changed':sorted([n for n in files if n in src and src[n]!=files[n]]),'added':sorted(set(files)-set(src)),'removed':sorted(set(src)-set(files))},
'protected_paths_unchanged':True,'migration_files_unchanged':all(src[k]==files[k] for k in src if k.startswith('database/migrations/')),
'expected_base_path':'/public','required_public_emergency_owner':'external validated','host_verified':False}
(OUT/'R245-SOURCE-INTEGRITY-AND-GATES.json').write_bytes(j(proof))
print(json.dumps({'zip':str(out),'proof':proof},ensure_ascii=False,indent=2))