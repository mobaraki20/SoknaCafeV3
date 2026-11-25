"""Exact factory Public clean candidate: no change to any payload byte."""
import pathlib as p,hashlib,zipfile,io,json,os
H=lambda b:hashlib.sha256(b).hexdigest()
r=p.Path(os.environ['RUNNER_TEMP'])
def read(b):
    with zipfile.ZipFile(io.BytesIO(b)) as z:
        assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))
        assert all(n and not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in z.namelist())
        return {n:z.read(n) for n in z.namelist()}
base=r/'factory'
clean=(base/'PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip').read_bytes()
update=(base/'PUBLIC-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip').read_bytes()
assert H(clean)=='3198a800e00fb97888dde61014aab24129b5c78e3f0c938468223fe8e2667c8f'
assert H(update)=='6099b60f9a5869d7cc24ca568a8be104139a10bb25b136882e5a40a77402e6d9'
receipt=json.loads((base/'factory-evidence-decision.json').read_text())
assert receipt['files']['PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip']==H(clean) and receipt['files']['PUBLIC-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip']==H(update)
w=read(clean);u=read(update);m=json.loads(u.pop('manifest.json'))
assert m['provenance']['installation_authorized'] is False
managed={n[8:]:b for n,b in u.items()};protected={n:H(b) for n,b in w.items() if n not in managed}
assert len(protected)==6 and all(w[n]==b for n,b in managed.items())
assert not any(n in ('config.php','install.lock') or n.startswith('storage/') for n in w)
assert H(''.join(n+'\0'+H(b)+'\n' for n,b in sorted(managed.items())).encode())==m['target_managed_tree_sha256']
assert sum(n.startswith('database/migrations/') and n.endswith('.sql') for n in w)==12
assert w['VERSION.txt'].decode().strip()==m['version']
work=p.Path('/var/www/sokna-public-clean-canary')
for role,sub in [('root',''),('sub','public/menu')]:
    dest=work/role/'webroot'/sub
    dest.mkdir(parents=True,exist_ok=False)
    for n,b in w.items():
        f=dest/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
(work/'contract.json').write_text(json.dumps({'clean_sha256':H(clean),'update_sha256':H(update),'target':m['version'],'tree':m['target_managed_tree_sha256'],'protected':protected}))
print('EXACT_PUBLIC_CLEAN_BYTES_READY',H(clean))
