#!/usr/bin/env python3
"""QA only: reconstruct exact source-only R167 from byte-pinned R166 parent sources."""
import argparse,pathlib,zipfile,hashlib,subprocess,tempfile,json
H=lambda b:hashlib.sha256(b).hexdigest()
EXPECTED={
 'local':('f4d6511b6bf961cc66d43ed56a4d3c6575de022959b44a3d6f76b7f8883ff495','f8e887de231e86031f6881c99b3b3fe786ee877d393f1510d905f3934132383e',588,80),
 'public':('a4797d25cbd1fc4cc2b5c3ff5ac4856966bd319fa60626a5e9a7f06830fe068f','ab7d6891fc4b6ed62f9aebfbc62ad288eb763074f405bc4eb2b8e1c2591089fe',225,8),
}
def tree(d):return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def main():
 p=argparse.ArgumentParser();p.add_argument('--local-parent',required=True);p.add_argument('--public-parent',required=True);p.add_argument('--local-patch',required=True);p.add_argument('--public-patch',required=True);p.add_argument('--outdir',required=True);o=p.parse_args();out=pathlib.Path(o.outdir);out.mkdir(parents=True,exist_ok=True)
 for scope in ['local','public']:
  par=pathlib.Path(getattr(o,scope+'_parent'));patch=pathlib.Path(getattr(o,scope+'_patch')).resolve()
  with zipfile.ZipFile(par) as z:old={n:z.read(n) for n in z.namelist() if not n.endswith('/')}
  target=EXPECTED[scope];assert len(old)==target[2] and tree(old)==target[0],f'{scope}: R166 parent tree mismatch'
  assert len([n for n in old if n.startswith('database/migrations/')])==target[3],f'{scope}: migration count mismatch'
  with tempfile.TemporaryDirectory() as td:
   path=pathlib.Path(td)
   for n,b in old.items(): f=path/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
   r=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',td,'-i',str(patch)],capture_output=True,text=True)
   assert r.returncode==0,r.stdout+'\n'+r.stderr
   new={n:(path/n).read_bytes() for n in old}
  assert tree(new)==target[1],f'{scope}: exact candidate mismatch'
  assert all(old[n]==new[n] for n in old if n.startswith('database/migrations/'))
  changed=[n for n in old if old[n]!=new[n]]
  assert len(changed)==(5 if scope=='local' else 1) and not any(n.startswith('database/migrations/') for n in changed)
  dst=out/scope
  for n,b in new.items():f=dst/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
  with zipfile.ZipFile(out/f'SOKNA-R167-{scope.upper()}-SOURCE-QA-NOT-INSTALL.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
   for n,b in sorted(new.items()):z.writestr(n,b)
  print('PASS',scope,'parent',target[0],'candidate',target[1],'files',len(new),'changed',changed)
if __name__=='__main__':main()
