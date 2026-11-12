#!/usr/bin/env python3
"""Reconstruct exactly one Local R165 fix from R163 source. All other bytes retained."""
import argparse,hashlib,io,json,pathlib,subprocess,tempfile,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
PARENT_SHA='46547a1ad583da3d71f33de880734502183134d9739d4c8494ebc4c5cbc31a26'
PATCH_SHA='32760aa0526223c314d36ebc0b8d5059cbca4af1a47880f4c51a48551c0c1ce8'
OLD_FILE='55fb75ad4f53a358aa1afc3e0b3be03a1f4d176d773f3cc7b862d075a037ac59'
NEW_FILE='f5800943d4db185e50efdd91c8a50d80b71210a3df302e21b7dc284dd5e3441a'
PATH='src/Domain/Orders/GuestOrderService.php'
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def main():
 a=argparse.ArgumentParser();a.add_argument('--parent',required=True);a.add_argument('--patch',required=True);a.add_argument('--outdir',required=True);o=a.parse_args()
 z=pathlib.Path(o.parent);p=pathlib.Path(o.patch).resolve()
 assert H(z.read_bytes())==PARENT_SHA,'R163 parent SHA mismatch'
 assert H(p.read_bytes())==PATCH_SHA,'R165 patch SHA mismatch'
 with zipfile.ZipFile(z) as f: old={n:f.read(n) for n in f.namelist() if not n.endswith('/')}
 assert len(old)==588 and H(old[PATH])==OLD_FILE,'R163 Local source unexpected'
 with tempfile.TemporaryDirectory() as tmp:
  file=pathlib.Path(tmp)/PATH;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(old[PATH])
  r=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',tmp,'-i',str(p)],capture_output=True,text=True)
  assert r.returncode==0,r.stdout+r.stderr
  fixed=file.read_bytes();assert H(fixed)==NEW_FILE,'R165 candidate modified unexpectedly'
 new=dict(old);new[PATH]=fixed
 changes=[k for k in sorted(old) if new[k]!=old[k]]
 assert changes==[PATH],str(changes)
 mig=[k for k in old if k.startswith('database/migrations/')]; assert len(mig)==80
 assert all(old[k]==new[k] for k in mig)
 assert all(old[k]==new[k] for k in old if k!=PATH)
 dest=pathlib.Path(o.outdir);dest.mkdir(parents=True,exist_ok=True)
 for name,contents in [('r164-local-parent',old),('r165-local-candidate',new)]:
  target=dest/name
  for n,b in contents.items():
   file=target/n;file.parent.mkdir(parents=True,exist_ok=True);file.write_bytes(b)
 with zipfile.ZipFile(dest/'SOKNA-R165-LOCAL-FULL-SOURCE-QA-NOT-INSTALL.zip','w',compression=zipfile.ZIP_DEFLATED) as f:
  for n,b in sorted(new.items()):f.writestr(n,b)
 report={'status':'SOURCE-ONLY NOT-INSTALL','parent_sha256':PARENT_SHA,'parent_tree':tree(old),'candidate_tree':tree(new),'changed_paths':changes,'source_count':len(old),'migrations_unchanged':len(mig),'new_file_sha256':H(fixed),'public_parent_unchanged':'SOKNA-R164-PUBLIC-FULL-SOURCE-QA-NOT-INSTALL.zip','real_cafe_test':False,'main_changed':False}
 (dest/'R165-SOURCE-QA.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
