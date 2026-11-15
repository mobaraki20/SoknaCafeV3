#!/usr/bin/env python3
"""Deterministically derive R240 NON-INSTALLABLE QA source from R239 byte-pinned QA.
No production approval and no installed environment mutation.
"""
from pathlib import Path
import argparse,hashlib,json,io,zipfile,copy
R239_QA_SHA='1dab7e39905a48179eeb19f9dbd88bc51a02af098eaf780a93168e3a4d34b2e1'
R239_TREE='139899065a6d40e1fcf814020c52951fed40445f626190c8819a07fdd6a66e25'
TARGET_VERSION='3.0.0-g6.2-workspace.231fe-r240-managed-inventory-recovery-qa'
OUT_NAME='SOKNA-R240-LOCAL-231FA-EXACT-QA-DO-NOT-INSTALL.zip'
def H(b):return hashlib.sha256(b).hexdigest()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def J(x):return (json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def Z(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for n,v in sorted(d.items()):
   zi=zipfile.ZipInfo(n,(2026,11,15,0,0,0));zi.compress_type=zipfile.ZIP_DEFLATED;zi.external_attr=0o100644<<16
   z.writestr(zi,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def derive(p,out):
 assert H(p.read_bytes())==R239_QA_SHA,'UNPINNED_R239_SOURCE'
 with zipfile.ZipFile(p) as zip:
  assert zip.testzip() is None
  m=json.loads(zip.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(zip.read('artifacts/local/web.zip'))) as src:
   assert src.testzip() is None
   d={n:src.read(n) for n in src.namelist()}
 assert len(d)==593 and tree(d)==R239_TREE,'R239_TREE_UNEXPECTED'
 l=json.loads(d['resources/local-managed-files-v1.json'])
 assert l['format']=='sokna-local-managed-files-v1' and len(l['files'])==590,'R239_INVENTORY_NOT_REPRODUCED'
 l['files']=sorted(d)
 assert len(l['files'])==593 and len(set(l['files']))==593,'R240_INVENTORY_INCOMPLETE'
 d['resources/local-managed-files-v1.json']=json.dumps(l,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode('utf-8')+'\n'.encode()
 d['VERSION.txt']=(TARGET_VERSION+'\n').encode()
 inner=Z(d)
 new=copy.deepcopy(m)
 new.update(release_set_id='sokna-r240-local-authority-recovery-inventory-qa-only-20261115',sequence=202611150240,created_at='2026-11-15T10:00:00Z',status='R240_QA_INVENTORY_FIX_DO_NOT_INSTALL',for_install=False,production_pass=False)
 new['targets']['local'].update(version=TARGET_VERSION,managed_files=593,managed_tree_sha256=tree(d),release_id='sokna-r240-authority-recovery-managed-inventory-qa-not-installable')
 new['artifacts']['local-web'].update(sha256=H(inner),size_bytes=len(inner))
 new['qa_notes']['R240_windows_issue']='R239 package failed native Windows local managed inventory 590 instead of 593; corrected by verifying exact 593 paths'
 blob=Z({'release-set.json':J(new),'artifacts/local/web.zip':inner})
 out.mkdir(exist_ok=True,parents=True)
 result=out/OUT_NAME;result.write_bytes(blob)
 with zipfile.ZipFile(result) as zz:
  m2=json.loads(zz.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(zz.read('artifacts/local/web.zip'))) as web:
   rows={n:web.read(n) for n in web.namelist()}
   inventory=json.loads(rows['resources/local-managed-files-v1.json'])['files']
   assert sorted(rows)==inventory
   assert m2['targets']['local']['managed_tree_sha256']==tree(rows)
 proof={'qa_zip_sha256':H(blob),'target_tree_sha256':tree(d),'target_version':TARGET_VERSION,'managed_files':593,'inventory_entries':593,'source_parent_sha256':R239_QA_SHA,'for_install':False,'production_pass':False}
 (out/'R240-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(proof,ensure_ascii=False))
if __name__=='__main__':
 ap=argparse.ArgumentParser();ap.add_argument('--parent',required=True,type=Path);ap.add_argument('--out',required=True,type=Path);a=ap.parse_args();derive(a.parent,a.out)