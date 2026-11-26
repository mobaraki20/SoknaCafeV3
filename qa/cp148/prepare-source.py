"""Direct QA reconstruction from two previously published artifact payloads.
No network calls or remote workflow execution; inputs are downloaded by standard Actions.
"""
import pathlib, zipfile, io, hashlib, subprocess, gzip, sys
H=lambda b:hashlib.sha256(b).hexdigest()
root=pathlib.Path(sys.argv[1]).resolve(); inputs=pathlib.Path(sys.argv[2]).resolve(); data=pathlib.Path(__file__).parent
assert not (root/'local').exists() and not (root/'public').exists(), 'QA workspace must be fresh'
root.mkdir(parents=True,exist_ok=True)
base={'local':(624,'bafc41567eb3ed3db042855474bf51fdacc2a926094885f561411ae4dae42fa6'),'public':(252,'c71ab6c235f72afdb9762cc5460a63731770c17086dafa290a21398037fabbe6')}
target={'local':(625,'b794ec59924e1fb2540c90d697bede99d449cc2bdbe44dadef999891b0edce78'),'public':(258,'064480deb596985e34c17f06a7fd39aec3045d5325b49ad3d96eedc772d33db5')}
def canon(host):
 r=root/host; paths=sorted(p.relative_to(r).as_posix() for p in r.rglob('*') if p.is_file());return len(paths),H(''.join(n+'\0'+H((r/n).read_bytes())+'\n' for n in paths).encode())
for host,package,expected in [('local','SOKNA-LOCAL-231GG-CONTROLLED-CAFE-OPERATOR.zip','a179c2b339ba07eaecc847f5821c8af2441c972de5e1fd3aa2af1e07f02adb5f'),('public','SOKNA-PUBLIC-35IY-35IP-CAFE-QA.zip','2abc6270f88c59afc3e76dc11bd164f5238447b5f9e1f624ba2eb192f04f7e7e')]:
 raw=(inputs/host/package).read_bytes();assert H(raw)==expected,host+' package mismatch'
 if host=='local':
  with zipfile.ZipFile(io.BytesIO(raw)) as z: raw=z.read('artifacts/local/web.zip')
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  assert z.testzip() is None
  for n in z.namelist():
   if n.endswith('/'):continue
   if host=='public':
    if not n.startswith('payload/'):continue
    n2=n[8:]
   else:n2=n
   assert n2 and not n2.startswith('/') and '\\' not in n2 and '..' not in pathlib.PurePosixPath(n2).parts
   p=root/host/n2;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
 assert canon(host)==base[host],host+' baseline mismatch';print('PASS exact base',host,*canon(host),flush=True)
gz=(data/'source.delta.patch.gz').read_bytes();assert H(gz)=='d7e642d2c9ba46e34a3d1b72c4108dea83aa8be96570d5e5f7ccc2049a3f83c9'
p=gzip.decompress(gz);assert H(p)=='dd21abee7ed3817a38014d07f48a21a82cee657e5fceae019ecfb1aaf28dad23'
patch=root/'qa-source.patch';patch.write_bytes(p)
subprocess.run(['git','apply','--check',str(patch)],cwd=root,check=True)
subprocess.run(['git','apply',str(patch)],cwd=root,check=True);patch.unlink()
for host in ('local','public'):
 assert canon(host)==target[host],host+' target mismatch';print('PASS exact candidate',host,*canon(host),flush=True)
