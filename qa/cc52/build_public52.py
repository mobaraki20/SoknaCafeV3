"""Controlled Public operator handoff candidate; delivery requires exact route and clean receipts.
No code, migrations, Emergency owner code, or installed-host state is modified.
"""
from pathlib import Path
import hashlib, json, zipfile, io, sys, stat
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def read(path):
 with zipfile.ZipFile(path) as z:
  assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))
  assert all(n and not n.startswith('/') and '..' not in n.split('/') and '\\' not in n and not n.endswith('/') for n in z.namelist())
  assert all(not stat.S_ISLNK(i.external_attr>>16) for i in z.infolist())
  return {n:z.read(n) for n in z.namelist()}
def pack(path,d):
 with zipfile.ZipFile(path,'w') as z:
  for n,b in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,27,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,b,compresslevel=9)
 assert read(path)==d
src,out=map(Path,sys.argv[1:]);out.mkdir(parents=True,exist_ok=True)
a=src/'PUBLIC-35JA-UPDATE-QA-DO-NOT-INSTALL.zip';b=src/'PUBLIC-35JA-CLEAN-WEB-QA-DO-NOT-INSTALL.zip'
assert H(a.read_bytes())=='3b297bb2561caeb9fd1dcb57d165270d6e4f4ba476d432b2672c6d2162c42077'
assert H(b.read_bytes())=='fd320cacb1518c1937d3251b8a950a5019cca7267eb60e3f2bfc7b1511f744ca'
u=read(a);m=json.loads(u.pop('manifest.json'));old=read(b);d=dict(old)
managed={n[8:]:v for n,v in u.items()};protected={n:H(v) for n,v in old.items() if n not in managed}
assert len(managed)==252 and len(protected)==6 and all(old[n]==v for n,v in managed.items())
assert not any(n in ('config.php','install.lock','manifest.json') or n.startswith('storage/') for n in old)
assert len([n for n in managed if n.startswith('database/migrations/') and n.endswith('.sql')])==12
version='3.0.0-g3.6-workspace.35jb-controlled-operator-20261127';rid='sokna-public-35jb-controlled-operator-20261127';tier='CONTROLLED_OPERATOR_FIELD_ACCEPTANCE_PENDING'
metadata={'VERSION.txt','src/Update/emergency-owner-v1.json','resources/release-identity-v1.json','resources/build-provenance.json'}
assert d['VERSION.txt'].decode().strip()==m['version']
d['VERSION.txt']=(version+'\n').encode()
k='src/Update/emergency-owner-v1.json';o=json.loads(d[k]);assert o['release_version']==m['version'] and o['sha256']==H(d['src/Update/EmergencyOwnerCandidate.php']);o['release_version']=version;d[k]=J(o)
k='resources/release-identity-v1.json';i=json.loads(d[k]);ex=set(i['source']['digest_excludes']);canonical=lambda data:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(data.items()) if n not in ex and n not in protected).encode());assert canonical(old)==i['source']['canonical_tree_sha256']
i.update(version=version,release_set_id=rid,qualification={'tier':tier,'claim_rule':'Controlled explicit operator only; exact baseline and independent final delivery receipt required. Clean is empty path and empty DB only. Field and production acceptance pending.'});i['source']['canonical_tree_sha256']=canonical(d);d[k]=J(i)
k='resources/build-provenance.json';p=json.loads(d[k]);p.update(version=version,release_set_id=rid,classification=tier,scope='Controlled Public operator delivery pending exact final archive gates; update exact origins only, clean empty path and DB only',operator_warning='Install only with separately verified delivery receipt, exact host preflight and verified backup; no automatic deployment.',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False,qualification_only=False,qa_only_schema_compatibility=False);d[k]=J(p)
assert {n for n in d if d[n]!=old[n]}==metadata and canonical(d)==i['source']['canonical_tree_sha256']
assert all(H(d[n])==h for n,h in protected.items())
managed={n:v for n,v in d.items() if n not in protected}
tree=H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(managed.items())).encode())
m.update(version=version,target_managed_tree_sha256=tree,files=[{'path':n,'sha256':H(v),'size':len(v)} for n,v in sorted(managed.items())]);m['provenance'].update(build='CC52 controlled operator metadata from exact CC43 functional tree',release_qualification=tier,qualification_qa_run='EXACT_FINAL_DELIVERY_RECEIPT_REQUIRED',installation_authorized=False,production_host_qualified=False,offline_qualified=False,host_operator_note='Exact listed baselines only; independent final delivery receipt and actual host preflight required; no automatic deployment.')
up=out/'SOKNA-PUBLIC-35JB-CONTROLLED-UPDATE.zip';cl=out/'SOKNA-PUBLIC-35JB-CONTROLLED-CLEAN.zip'
pack(up,{'manifest.json':J(m)}|{'payload/'+n:v for n,v in managed.items()});pack(cl,d)
r={'status':'PENDING_EXACT_FINAL_GATE','target':version,'tree':tree,'canonical':canonical(d),'protected':protected,'update_sha256':H(up.read_bytes()),'clean_sha256':H(cl.read_bytes()),'files':len(d),'changed_files':sorted(metadata),'baselines':m['compatibility']['supported_baselines'],'scope':'CONTROLLED_OPERATOR_ONLY','delivery_authorized':False,'production_authorized':False,'field_acceptance':False}
(out/'factory-build-receipt.json').write_bytes(J(r));print(json.dumps(r,indent=2))
assert r['update_sha256']=='936f665e4641b3ccaef1c78ff37caa2c395cdb3c3723328999b40105f5cdabb8'
assert r['clean_sha256']=='09fd9b7f7b968fa8721a5944c82970f702180c82b77e2b66c37104c87d1b725f'
