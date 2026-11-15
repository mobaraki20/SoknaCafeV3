#!/usr/bin/env python3
"""Consume byte-pinned NONINSTALLABLE R240 artifact for a disposable Windows-only engine test.
The ephemeral test flag is NEVER published or offered to a user.
"""
from pathlib import Path
import argparse,json,copy,hashlib,io,zipfile
Q_SHA='0e4b054903c990605a6c5bc0018f83b47e725ea654ab11a0790007e50ea20cc5'
TREE='28f537b7761a9bf527205340171eb2df336b35298c21b4c164ce00939ed8085b'
VERSION='3.0.0-g6.2-workspace.231fe-r240-managed-inventory-recovery-qa'
def H(b):return hashlib.sha256(b).hexdigest()
def J(v):return (json.dumps(v,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def Z(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for k,v in sorted(d.items()):
   i=zipfile.ZipInfo(k,(2026,11,15,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,v,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())
def main(qa,out):
 assert H(qa.read_bytes())==Q_SHA,'QA_ZIP_SHA_MISMATCH'
 with zipfile.ZipFile(qa) as z:
  assert z.testzip() is None
  assert set(z.namelist())=={'release-set.json','artifacts/local/web.zip'}
  m=json.loads(z.read('release-set.json'));web=z.read('artifacts/local/web.zip')
 with zipfile.ZipFile(io.BytesIO(web)) as z:
  assert z.testzip() is None
  d={p:z.read(p) for p in z.namelist()}
 assert m['for_install'] is False and m['production_pass'] is False
 assert len(d)==593 and tree(d)==TREE and m['targets']['local']['managed_tree_sha256']==TREE
 assert d['VERSION.txt'].decode().strip()==VERSION
 inv=json.loads(d['resources/local-managed-files-v1.json'])
 assert len(inv['files'])==593 and sorted(inv['files'])==sorted(d),'LOCAL_WEB_INVENTORY_FILES_MISMATCH'
 ci=copy.deepcopy(m)
 ci['for_install']=True;ci['production_pass']=True;ci['status']='R240_WINDOWS_EPHEMERAL_CI_STAGE'
 for k in ('real_windows','business_backup_consistency_real_mariadb','recovery_console_web_down'):ci['qualification'][k]=True
 ci['qa_notes']['ephemeral']='CI_DISPOSABLE_ONLY_DO_NOT_EXPORT_OR_DEPLOY'
 b=Z({'release-set.json':J(ci),'artifacts/local/web.zip':web})
 out.mkdir(parents=True,exist_ok=True)
 (out/'EPHEMERAL-WINDOWS-R240-STAGE.zip').write_bytes(b)
 (out/'CI-SHA256.txt').write_text(H(b)+'\n')
 print('PASS_R240_EXACT_INVENTORY_TRANSFERRED_TO_DISPOSABLE_WINDOWS_CI')
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--from-qa',type=Path,required=True);p.add_argument('--out',type=Path,required=True);a=p.parse_args();main(a.from_qa,a.out)