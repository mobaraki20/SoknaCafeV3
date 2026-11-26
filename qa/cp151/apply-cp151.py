import hashlib,pathlib,json,sys,subprocess
root=pathlib.Path(sys.argv[1]).resolve(); d=pathlib.Path(__file__).parent
m=json.loads((d/'manifest.json').read_text());H=lambda b:hashlib.sha256(b).hexdigest()
for p,x in m['changed'].items():assert H((root/p).read_bytes())==x['before'],p
patch=d/'cp151.patch';subprocess.run(['git','apply','--check',str(patch)],cwd=root,check=True);subprocess.run(['git','apply',str(patch)],cwd=root,check=True)
for p,x in m['changed'].items():assert H((root/p).read_bytes())==x['after'],p;print('PASS exact changed file',p,flush=True)
n=0
for host in ('local','public'):
 r=root/host; paths=sorted(p.relative_to(r).as_posix() for p in r.rglob('*') if p.is_file());n+=len(paths);h=H(''.join(p+'\0'+H((r/p).read_bytes())+'\n' for p in paths).encode());assert h==m['canonical'][host],host;print('PASS exact CP151 candidate',host,len(paths),h,flush=True)
assert n==m['files']==883
