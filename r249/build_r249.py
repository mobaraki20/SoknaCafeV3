#!/usr/bin/env python3
"""Reproducible Public R249 full-source operator ZIP from byte-pinned R246.
No stable Emergency owner, config, storage or DB data ever goes in the operator ZIP.
Release on verified baselines only: 35HK/R153 and 35HT/R171; no wildcard.
"""
import hashlib,json,sys,zipfile,os
from pathlib import Path
BASE=Path(__file__).resolve().parent
INPUT=Path(os.environ.get('SOKNA_R249_INPUT','/mnt/data/sokna_emergency'))
R171=Path(os.environ.get('SOKNA_R249_R171',str(INPUT/'SOKNA-R171-PUBLIC-35HT-FROM-35HK-PANEL-UPDATE.zip')))
R246=Path(os.environ.get('SOKNA_R249_R246',str(INPUT/'SOKNA-R246-PUBLIC-35IB-FROM-35HT-EMERGENCY-BRIDGE-QA-DO-NOT-INSTALL.zip')))
R153=Path(os.environ.get('SOKNA_R249_R153',str(BASE/'SOKNA-PUBLIC-CURRENT-SOURCE-35HK.zip')))
OUT=BASE/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip'
TARGET='3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate'
OLD={'35hk':('3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa','0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'),
     '35ht':('3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat','be2ebe23091472480beadd6a06cad621b43da365d3677f54fadaab6ba56242b6')}
PINS={'R171':'ea1d2f7d90242d5b45fe232da7ff5510f03ee55c835e2dabe5f61980430be157',
      'R246':'db51819e2fd7fed99be194e17a884100a5f076d1820925bfecdb632a40a10a76'}
def sha(x):return hashlib.sha256(x).hexdigest()
def canonical(x):return (json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def payload(z):return {p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/') and not p.endswith('/')}
def tree(d):return sha(''.join(p+'\0'+sha(d[p])+'\n' for p in sorted(d)).encode())
for key,p in [('R171',R171),('R246',R246)]:
 assert sha(p.read_bytes())==PINS[key],(key,'pin mismatch')
with zipfile.ZipFile(R171) as z:
 assert z.testzip() is None
 old171=json.loads(z.read('manifest.json')); d171=payload(z)
 assert len(d171)==225 and tree(d171)==OLD['35ht'][1] == old171['target_managed_tree_sha256']
R153_PIN='2389bd131cf877e442ed2196d4700973d88fc4071a052377338838178634c32d'
if R153.is_file():
 assert sha(R153.read_bytes())==R153_PIN
 with zipfile.ZipFile(R153) as z:
  assert z.testzip() is None
  d153={p:z.read(p) for p in z.namelist() if not p.endswith('/')}
 assert len(d153)==222 and tree(d153)==OLD['35hk'][1]
 assert d153['VERSION.txt'].decode().strip()==OLD['35hk'][0]
else:
 assert os.environ.get('SOKNA_R249_ALLOW_PINNED_R153')=='1','35HK exact source required; CI may only use prevalidated fixed pin'
 d153=None
with zipfile.ZipFile(R246) as z:
 assert z.testzip() is None
 previous=json.loads(z.read('manifest.json')); data=payload(z)
assert previous['version']=='3.0.0-g3.6-workspace.35ib-r246-emergency-bridge'
assert len(data)==228 and set(d171)<=set(data)
assert all(sha(data[p])==r['sha256'] and len(data[p])==r['size'] for r in previous['files'] for p in [r['path']])
assert tree(data)==previous['target_managed_tree_sha256']
for p in data:
 assert p not in ['emergency.php','config.php','resources/update-trust-v1.json'],p
 assert not p.startswith(('src/Emergency/','storage/','tools/','Q3_P1_')),p
 if p=='recovery.php' or p.startswith('src/Recovery/'):
  assert p in d171 and data[p]==d171[p],('protected_recovery_changed',p)
assert {p for p in data if p.startswith('database/migrations/')}=={p for p in d171 if p.startswith('database/migrations/')}
assert all(data[p]==d171[p] for p in d171 if p.startswith('database/migrations/'))
if d153 is not None:
 assert all(d153[p]==d171[p] for p in d153 if p.startswith('database/migrations/'))
assert previous['provenance']['strict_multibaseline_v1'] is True
# Only release identity and owner metadata are touched compared to the separately tested R246 code.
data['VERSION.txt']=(TARGET+'\n').encode()
meta=json.loads(data['src/Update/emergency-owner-v1.json'])
assert meta['sha256']==sha(data['src/Update/EmergencyOwnerCandidate.php'])
assert meta['release_version']==previous['version']
meta['release_version']=TARGET
data['src/Update/emergency-owner-v1.json']=canonical(meta)
manifest={
 'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':TARGET,
 'compatibility':{'supported_baselines':[{'version':OLD[k][0],'managed_tree_sha256':OLD[k][1]} for k in ('35hk','35ht')]},
 'contracts':{'local_public_contract':1},'target_managed_tree_sha256':tree(data),
 'provenance':{
    'source_package_sha256':PINS['R246'],
    'source_r171_sha256':PINS['R171'],
    'source_35hk_sha256':R153_PIN,
    'build':'R249-35hk-35ht-exact-baselines-from-r246',
    'strict_multibaseline_v1':True,'stable_emergency_owner':'not_in_payload',
    'release_gate':'host_stage_then_independent_activate_commit',
    'offline_qualified':True,'offline_scope':'R171-real-PHP-lifecycle;R153-byte-and-structure-only','production_host_qualified':False,
    'qa_only_hold':['R244-WAF-BUDGET'],
    'database_migrations':'byte-identical-8-existing',
    'host_operator_note':'Do not activate unless stage accepted exact installed version/tree and health + database verified; DB-forward fence forbids old-code rollback.'},
 'files':[{'path':p,'sha256':sha(d),'size':len(d)} for p,d in sorted(data.items())]}
with zipfile.ZipFile(OUT,'w') as z:
 for n,b in [('manifest.json',canonical(manifest))]+[('payload/'+p,b) for p,b in sorted(data.items())]:
  zi=zipfile.ZipInfo(n,date_time=(2026,11,15,18,0,0));zi.compress_type=zipfile.ZIP_DEFLATED;zi.external_attr=0o644<<16
  z.writestr(zi,b,compresslevel=8)
with zipfile.ZipFile(OUT) as z:
 assert z.testzip() is None and json.loads(z.read('manifest.json'))==manifest
 assert payload(z)==data
proof={'product':'Sokna Public','release':'R249 / 35IC','status':'OFFLINE_BUILD_PENDING_HTTP_HOST_QA',
  'full_source_files':len(data),'bases':list(OLD),'baseline_tree_pins':dict(OLD),
  'target_version':TARGET,'target_tree_sha256':tree(data),'zip_sha256':sha(OUT.read_bytes()),
  'zip_size':OUT.stat().st_size,'source_r246_sha256':PINS['R246'],
  'byte_identical_to_r246_except':['VERSION.txt','src/Update/emergency-owner-v1.json'],
  'retains_r244_hold':True,'no_operator_host_mutation':True}
(BASE/'R249-BUILD-PROOF.json').write_bytes(canonical(proof))
print(json.dumps(proof,ensure_ascii=False,indent=2))
