#!/usr/bin/env python3
"""R246 strictly scoped Emergency candidate from byte-pinned R245 QA payload.
No host mutation. Must pass independent Emergency bridge tests before release.
"""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
from hashlib import sha256
import io,json,os
R=Path(os.environ.get('SOKNA_R246_DIR','/mnt/data/return_group_r246')); R.mkdir(exist_ok=True)
P=Path(os.environ.get('SOKNA_R246_PARENT','/mnt/data/return_group_r245/out/SOKNA-R245-PUBLIC-35IA-FROM-EXACT-35HT-GROUP-QA-DO-NOT-INSTALL.zip'))
oldver='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat'
newver='3.0.0-g3.6-workspace.35ib-r246-emergency-bridge'
def h(data):return sha256(data).hexdigest()
def dump(data):return (json.dumps(data,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def tree(m):return h(''.join(p+'\0'+h(m[p])+'\n' for p in sorted(m)).encode())
with ZipFile(P) as z:
 assert z.testzip() is None
 man=json.loads(z.read('manifest.json'))
 data={p.removeprefix('payload/'):z.read(p) for p in z.namelist() if p.startswith('payload/')}
assert man['version']=='3.0.0-g3.6-workspace.35ia-r245-group-compat-recovery'
assert len(data)==228 and len(man['files'])==228
assert h(P.read_bytes())=='e3719ce3f37316e6635f537e9b600e7686cd76c6f7041db9d96aa0d170978c7b'
base=man['compatibility']
assert base['from_version']==oldver and len(base['from_managed_tree_sha256'])==64
data['VERSION.txt']=(newver+'\n').encode()
meta=json.loads(data['src/Update/emergency-owner-v1.json'])
meta['release_version']=newver
data['src/Update/emergency-owner-v1.json']=dump(meta)
assert h(data['src/Update/EmergencyOwnerCandidate.php'])==meta['sha256']
assert not any(p in data or any(k.startswith(p) for k in data) for p in ['emergency.php','src/Emergency/','resources/update-trust-v1.json'])
manifest={
 'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':newver,
 'compatibility':{'supported_baselines':[{'version':oldver,'managed_tree_sha256':base['from_managed_tree_sha256']}]},
 'contracts':{'local_public_contract':1},'target_managed_tree_sha256':tree(data),
 'provenance':{'build':'R246-35ht-strict-emergency-bridge','strict_multibaseline_v1':True,
 'Emergency_stable_owner':'not_in_payload',
 'production_host_qualified':False,'disclaimer':'QA candidate pending host Emergency forward bridge trust proof'},
 'files':[{'path':k,'sha256':h(v),'size':len(v)} for k,v in sorted(data.items())]
}
name='SOKNA-R246-PUBLIC-35IB-FROM-35HT-EMERGENCY-BRIDGE-QA-DO-NOT-INSTALL.zip'
out=R/name
with ZipFile(out,'w') as z:
 for n,b in [('manifest.json',dump(manifest))]+[('payload/'+p,v) for p,v in sorted(data.items())]:
  i=ZipInfo(n,date_time=(2026,11,15,12,0,0));i.compress_type=ZIP_DEFLATED;i.external_attr=(0o644<<16)
  z.writestr(i,b,compresslevel=8)
with ZipFile(out) as z:
 assert z.testzip() is None
 assert z.read('manifest.json')==dump(manifest)
 assert {n.removeprefix('payload/'):z.read(n) for n in z.namelist() if n.startswith('payload/')}==data
proof={'source_version':oldver,'target_version':newver,'source_tree_sha256':base['from_managed_tree_sha256'],
 'target_tree_sha256':tree(data),'files':len(data),'sha256':h(out.read_bytes()),'bytes':out.stat().st_size,
 'strict_multibaseline_v1':True,'migrations_unchanged':True,'host_verified':False,'status':'QA_DO_NOT_INSTALL'}
(R/'R246-BUILD-PROOF.json').write_bytes(dump(proof))
print(json.dumps(proof,ensure_ascii=False,indent=2))