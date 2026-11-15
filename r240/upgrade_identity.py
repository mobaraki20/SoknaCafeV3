#!/usr/bin/env python3
"""R240 QA revision 3: release-identity/provenance match exact verified payload.
Never claims production acceptance; preserves the original R201 versioned baseline.
"""
import argparse,copy,hashlib,io,json,zipfile
from pathlib import Path
BASE_SHA='0e4b054903c990605a6c5bc0018f83b47e725ea654ab11a0790007e50ea20cc5'
VERSION='3.0.0-g6.2-workspace.231fe-r240-managed-inventory-recovery-qa'
NAME='SOKNA-R240-LOCAL-R201-231FA-CUMULATIVE-REV3-QA-DO-NOT-INSTALL.zip'
EXCLUDE={'resources/release-identity-v1.json','resources/build-provenance.json'}
def H(b):return hashlib.sha256(b).hexdigest()
def J(x):return (json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def Z(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for n,v in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,15,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def canonical(d):return H(''.join(k+'\0'+str(len(v))+'\0'+H(v)+'\n' for k,v in sorted(d.items()) if k not in EXCLUDE).encode())
def derive(base,out):
 assert H(base.read_bytes())==BASE_SHA,'R240_QA_REV2_SHA_MISMATCH'
 with zipfile.ZipFile(base) as z:
  assert z.testzip() is None
  m=json.loads(z.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
   assert w.testzip() is None
   d={n:w.read(n) for n in w.namelist()}
 assert len(d)==593 and d['VERSION.txt'].decode().strip()==VERSION
 assert len(json.loads(d['resources/local-managed-files-v1.json'])['files'])==593
 ident=json.loads(d['resources/release-identity-v1.json']);prov=json.loads(d['resources/build-provenance.json'])
 new_id='sokna-r240-local-authority-recovery-identity-qa-rev3-20261115'
 prov.update(classification='R240_EXACT_WINDOWS_QA_NOT_HOST_ACCEPTED',release_set_id=new_id,version=VERSION,
            created_at='2026-11-15T10:05:00Z',source='R201 231fa pinned exact tree + R239 authority recovery + R240 managed inventory and identity')
 prov['r240_native_windows_status']='QA_REV2_STAGE_ACTIVATE_HTTP_81_MIGRATIONS_PASS_NOT_PRODUCTION'
 prov['r240_real_public_host_accepted']=False
 d['resources/build-provenance.json']=J(prov)
 ident['version']=VERSION;ident['release_set_id']=new_id
 ident['baseline']={'mode':'exact_compatible_allowlist','source_versions':[m['supported_baselines'][0]['local']['version']]}
 ident['source'].update(canonical_file_count=len(d)-len(EXCLUDE),managed_file_count=len(d),
           canonical_tree_sha256=canonical(d),source_ref='R201 exact pinned 231fa plus R239 recovery, R240 inventory and identity')
 d['resources/release-identity-v1.json']=J(ident)
 assert ident['source']['canonical_tree_sha256']==canonical(d)
 assert ident['source']['managed_file_count']==len(d)
 assert sorted(json.loads(d['resources/local-managed-files-v1.json'])['files'])==sorted(d)
 inner=Z(d)
 m=copy.deepcopy(m)
 m.update(release_set_id=new_id,status='R240_QA_REV3_IDENTITY_CORRECTED_DO_NOT_INSTALL',sequence=202611150241,created_at='2026-11-15T10:05:00Z',for_install=False,production_pass=False)
 m['targets']['local'].update(managed_tree_sha256=tree(d),managed_files=len(d))
 m['artifacts']['local-web'].update(sha256=H(inner),size_bytes=len(inner))
 m['qa_notes']['release_identity']='R240 complete metadata, version and canonical digest validated; no host acceptance'
 out.mkdir(parents=True,exist_ok=True)
 raw=Z({'release-set.json':J(m),'artifacts/local/web.zip':inner})
 (out/NAME).write_bytes(raw)
 proof={'qa_zip_sha256':H(raw),'target_tree_sha256':tree(d),'target_version':VERSION,'release_set_id':new_id,
        'managed_files':len(d),'canonical_sha256':canonical(d),'canonical_file_count':len(d)-len(EXCLUDE),'origin_sha256':BASE_SHA,'for_install':False,'production_pass':False}
 (out/'R240-REV3-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(proof,ensure_ascii=False))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--parent',type=Path,required=True);p.add_argument('--out',type=Path,required=True);a=p.parse_args();derive(a.parent,a.out)