#!/usr/bin/env python3
"""Rebuild R262 Public from byte-pinned R249 + already-qualified minimal R260 source.
No site/domain-specific defaults and no old Public data; QA source/download only.
The original 8 migrations must remain byte identical, and protected Emergency
owner/stable data must NOT be introduced in these payloads.
"""
from __future__ import annotations
import argparse,hashlib,json,io,zipfile,pathlib,copy
H=lambda b:hashlib.sha256(b).hexdigest()
VERSION='3.0.0-g3.6-workspace.35id-r262-public-move'
PIN='5c887968b6fcec1126e2b1b3123552a9ba0ba2c7672d29774c05ec58fa5cd0e4'
NAME_UPDATE='SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip'
NAME_CLEAN='SOKNA-R262-PUBLIC-35ID-CLEAN-SOURCE-QA-NOT-FOR-INSTALL.zip'
def J(o):return (json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def tree(d):return H(''.join(p+'\0'+H(d[p])+'\n' for p in sorted(d)).encode())
def zip_d(d):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w') as z:
  for name,b in sorted(d.items()):
   i=zipfile.ZipInfo(name,(2026,11,16,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return out.getvalue()
def build(base,source,out):
 base=pathlib.Path(base);source=pathlib.Path(source);out=pathlib.Path(out)
 assert H(base.read_bytes())==PIN,'R249_PIN_MISMATCH'
 with zipfile.ZipFile(base) as z:
  assert z.testzip() is None
  oldm=json.loads(z.read('manifest.json'))
  old={n.removeprefix('payload/'):z.read(n) for n in z.namelist() if n.startswith('payload/') and not n.endswith('/')}
 with zipfile.ZipFile(source) as z:
  assert z.testzip() is None
  new={n:z.read(n) for n in z.namelist() if not n.endswith('/')}
 assert len(old)==228 and len(new)==244,'WRONG_SOURCE_COUNTS'
 assert set(old).issubset(new),'R249_DELETION_DETECTED'
 assert sorted(k for k in new if k.startswith('database/migrations/') and k.endswith('.sql'))[-1].startswith('database/migrations/0011_')
 assert len([k for k in new if k.startswith('database/migrations/') and k.endswith('.sql')])==11
 assert all(new[k]==v for k,v in old.items() if k.startswith('database/migrations/')),'ORIGINAL_DB_MIGRATION_MUTATED'
 assert oldm['version']==old['VERSION.txt'].decode().strip()
 assert set(new).isdisjoint({'config.php','install.lock','.env','emergency.php','resources/update-trust-v1.json'})
 assert not any(n.startswith(('src/Emergency/','storage/','tools/')) for n in new)
 for k in old:
  if k=='recovery.php' or k.startswith('src/Recovery/'):
   assert new[k]==old[k],'PROTECTED_RECOVERY_CHANGED'
 changed=sorted(k for k in old if old[k]!=new[k]);added=sorted(set(new)-set(old))
 assert 4<=len(changed)<=10 and 12<=len(added)<=20,'UNEXPECTED_PRODUCT_SCOPE'
 new['VERSION.txt']=(VERSION+'\n').encode()
 if 'src/Update/emergency-owner-v1.json' in new:
  m=json.loads(new['src/Update/emergency-owner-v1.json'])
  assert m['sha256']==H(new['src/Update/EmergencyOwnerCandidate.php'])
  m['release_version']=VERSION
  new['src/Update/emergency-owner-v1.json']=J(m)
 if 'resources/build-provenance.json' in new:
  m=json.loads(new['resources/build-provenance.json'])
  m.update(classification='R262_PUBLIC_QA_CUMULATIVE_PENDING_HOST_QUALIFICATION',version=VERSION,
           installed_host_accepted=False,full_business_acceptance=False,production_host_install_verified=False,
           source='R249 SHA256 pinned R262 additional 0009-0011 and simple owner-approved Public replacement')
  new['resources/build-provenance.json']=J(m)
 manifest=copy.deepcopy(oldm)
 manifest.update(version=VERSION,target_managed_tree_sha256=tree(new))
 manifest['compatibility']['supported_baselines'].append({'version':oldm['version'],'managed_tree_sha256':oldm['target_managed_tree_sha256']})
 assert len(manifest['compatibility']['supported_baselines'])==3
 manifest['provenance'].update(build='R262-byte-pinned-R249-simple-public-replacement',source_package_sha256=PIN,
    database_migrations='original_0001_to_0008_byte_identical_plus_forward_only_0009_to_0011',
    offline_qualified=False,production_host_qualified=False,qa_only_hold=['native_public_host_install_and_upgrade_pending'],
    host_operator_note='QA-only. Do not deploy until independent host clean install and update qualification passes.')
 manifest['files']=[{'path':k,'sha256':H(v),'size':len(v)} for k,v in sorted(new.items())]
 full=zip_d({'manifest.json':J(manifest),**{'payload/'+k:v for k,v in new.items()}})
 clean=zip_d(new)
 out.mkdir(parents=True,exist_ok=True)
 (out/NAME_UPDATE).write_bytes(full);(out/NAME_CLEAN).write_bytes(clean)
 with zipfile.ZipFile(io.BytesIO(full)) as z:
  assert z.testzip() is None
  m=json.loads(z.read('manifest.json'))
  assert len(m['files'])==len(new) and tree(new)==m['target_managed_tree_sha256']
  assert {k.removeprefix('payload/'):H(z.read(k)) for k in z.namelist() if k.startswith('payload/')}=={k:H(v) for k,v in new.items()}
 proof={'source_base_sha256':PIN,'source_files':len(old),'target_files':len(new),'original_sql_preserved':8,
  'new_sql_count':3,'changed_before_metadata':changed,'added':added,'version':VERSION,
  'target_tree_sha256':tree(new),'update_qa_sha256':H(full),'clean_qa_sha256':H(clean),
  'manifest_format':manifest['format'],'qa_only':True,'operator_install_allowed':False,'host_qualification':'PENDING'}
 (out/'R262-PUBLIC-PACKAGE-PROOF.json').write_bytes(json.dumps(proof,ensure_ascii=False,indent=2).encode()+b'\n')
 print(json.dumps(proof,ensure_ascii=False,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--base',required=True);p.add_argument('--source',required=True);p.add_argument('--out',required=True)
 a=p.parse_args();build(a.base,a.source,a.out)