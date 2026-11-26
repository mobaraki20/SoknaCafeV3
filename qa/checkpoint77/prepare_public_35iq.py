import pathlib,zipfile,io,hashlib,json,sys,os
P=pathlib.Path;H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
assert os.environ.get('GITHUB_ACTIONS')=='true'
base,iq,target,oldroot,out=map(P,sys.argv[1:])
def read(b):
 with zipfile.ZipFile(io.BytesIO(b)) as z:
  assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))
  assert all(n and not n.startswith('/') and '..' not in n.split('/') and chr(92) not in n for n in z.namelist());return {n:z.read(n) for n in z.namelist()}
def tree(d):return H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
assert H(base.read_bytes())=='57589151165e5d73c0b0339623e3ee3b2db9ea7c54ffbac69246c9ff3532da2f'
assert H(iq.read_bytes())=='1d93994d3a013f470eba34c769cb5e3ac595a6724c5558f18bc596684bba9c98'
iqv=read(iq.read_bytes());im=json.loads(iqv.pop('manifest.json'));old={n[8:]:v for n,v in iqv.items()}
assert tree(old)==im['target_managed_tree_sha256']=='c08f3e42e5c9c2d7dc0dbc1a0d8f525ea2958d9deba73b0eb2e749d3515b02d4'
assert len(old)==251
b=read(base.read_bytes());oldclean=read(b['PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip'])
oldmanaged=read(b['PUBLIC-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip']);oldmanaged.pop('manifest.json')
protected={n:v for n,v in oldclean.items() if 'payload/'+n not in oldmanaged}
assert len(protected)==6 and not(set(old)&set(protected))
targetzip=target/'PUBLIC-35IU-UPDATE-FOUNDATION-FIXTURE-QA-DO-NOT-INSTALL.zip';clean=target/'PUBLIC-35IU-CLEAN-WEB-QA-DO-NOT-INSTALL.zip'
assert H(targetzip.read_bytes())=='692c4379a3ef8c2b8bfdbbd9cec5a319422dd80c587476f20a8a4ffe2ff03797'
assert H(clean.read_bytes())=='b6c622515cc1f532531b049fc9d252bbebeff39d09dc4faed2a6368a3a7ca6e3'
new=read(clean.read_bytes());u=read(targetzip.read_bytes());m=json.loads(u.pop('manifest.json'))
assert tree({n[8:]:v for n,v in u.items()})==m['target_managed_tree_sha256']
assert len([n for n in old if n.startswith('database/migrations/')])==11
assert all(new[n]==v for n,v in old.items() if n.startswith('database/migrations/'))
b35iq={'version':im['version'],'managed_tree_sha256':tree(old|{n:new[n] for n in ['recovery.php','src/Recovery/StableRecoveryService.php']})}
assert b35iq['managed_tree_sha256']=='23113433786f798c1eb5129072c9e59b7755ae1b4ce074af25d0176b4789a46e'
# Outer manifest only: exact candidate inner payload unchanged; no claim of
# release identity or deployable multi-origin package.
m['compatibility']['supported_baselines']=[b35iq];m['provenance']['host_operator_note']='Disposable 35iq 11->12 QA fixture only; no live deployment or new release'
m['provenance']['source_managed_tree_sha256']=b35iq['managed_tree_sha256']
assert m['provenance']['installation_authorized'] is False
out.mkdir();oldroot.mkdir()
for n,v in (old|protected).items():p=oldroot/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(v)
newroot=out/'new-web';newroot.mkdir()
for n,v in new.items():p=newroot/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(v)
f=out/'PUBLIC-35IQ-TO-35IU-FIXTURE-QA-ONLY.zip'
with zipfile.ZipFile(f,'w',compression=zipfile.ZIP_DEFLATED) as z:
 for n,v in sorted(u|{'manifest.json':J(m)}.items()):z.writestr(n,v)
check=read(f.read_bytes());check.pop('manifest.json');assert check==u
r=json.loads((target/'factory-evidence-decision.json').read_text());r['public']['qa_only_foundation_origin']=b35iq;r['files'][f.name]={'sha256':H(f.read_bytes()),'bytes':f.stat().st_size}
(out/'factory-evidence-decision.json').write_bytes(J(r))
print('PASS exact 35iq old archive + exact 35iu inner payload; test-only outer manifest')
