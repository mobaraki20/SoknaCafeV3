"""Controlled fresh Home web ZIP, derived from exact factory CP76; never an update."""
import pathlib as P,hashlib as H,zipfile as Z,io,json,sys,stat
sha=lambda b:H.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def need(ok,why):
 if not ok:raise RuntimeError(why)
def read(path):
 with Z.ZipFile(path) as z:
  need(z.testzip() is None and len(set(z.namelist()))==len(z.namelist()),'ZIP')
  need(all(n and not n.startswith('/') and '..' not in n.split('/') and '\\' not in n and not n.endswith('/') for n in z.namelist()),'PATH')
  need(all(not stat.S_ISLNK(i.external_attr>>16) for i in z.infolist()),'SYMLINK')
  return {n:z.read(n) for n in z.namelist()}
old='3.0.0-g3.6-workspace.35iu-checkpoint76-qa'
new='3.0.0-g3.6-workspace.35iv-home-clean-20261126'
src,out,webroot=map(P.Path,sys.argv[1:]);need(sha(src.read_bytes())=='b6c622515cc1f532531b049fc9d252bbebeff39d09dc4faed2a6368a3a7ca6e3','FACTORY_PIN')
w=read(src);d=dict(w)
need(len(w)==258 and w['VERSION.txt']==(old+'\n').encode(),'FACTORY_INVENTORY')
need(not any(n in ('config.php','install.lock','manifest.json') or n.startswith('storage/') for n in w),'NOT_FRESH')
protected={n:sha(b) for n,b in w.items() if n=='emergency.php' or n.startswith('src/Emergency/')}
need(len(protected)==6,'PROTECTED_COUNT')
sql=[n for n in sorted(w) if n.startswith('database/migrations/') and n.endswith('.sql')]
need(len(sql)==12 and P.Path(sql[-1]).stem=='0012_auth_snapshot_watermark','LEDGER')
# Change only release identity and the existing owner metadata's release label.
d['VERSION.txt']=(new+'\n').encode()
owner=json.loads(d['src/Update/emergency-owner-v1.json']);need(owner['release_version']==old,'OWNER_VERSION')
need(owner['sha256']==sha(d['src/Update/EmergencyOwnerCandidate.php']),'OWNER_CODE_PIN')
owner['release_version']=new;d['src/Update/emergency-owner-v1.json']=J(owner)
exclude={'resources/build-provenance.json','resources/release-identity-v1.json'}
canonical=lambda x:sha(''.join(n+'\0'+str(len(b))+'\0'+sha(b)+'\n' for n,b in sorted(x.items()) if n not in protected and n not in exclude).encode())
ident=json.loads(w['resources/release-identity-v1.json']);need(canonical(w)==ident['source']['canonical_tree_sha256'],'CANONICAL_SOURCE')
ident.update(version=new,release_set_id='sokna-public-35iv-home-clean-20261126',baseline={'mode':'clean_install_only','source_versions':[]},qualification={'tier':'CONTROLLED_HOME_CLEAN_FIELD_ACCEPTANCE_PENDING','claim_rule':'Only fresh isolated Home installation; no update or Cafe production permission. Exact HTTP acceptance and separate delivery receipt required.'})
ident['source']['canonical_tree_sha256']=canonical(d)
d['resources/release-identity-v1.json']=J(ident)
prov=json.loads(w['resources/build-provenance.json'])
prov.update(version=new,release_set_id=ident['release_set_id'],classification='CONTROLLED_HOME_CLEAN_FIELD_ACCEPTANCE_PENDING',scope='New empty Home Public webroot and independent empty database only',operator_warning='HOME CLEAN ONLY; exact clean HTTP gate and delivery receipt required; never overwrite installed site or use as update.',schema_migrations=[P.Path(n).stem for n in sql],qualification_only=False,qa_only_schema_compatibility=False)
d['resources/build-provenance.json']=J(prov)
need({n for n in d if d[n]!=w[n]}==exclude|{'VERSION.txt','src/Update/emergency-owner-v1.json'},'CHANGED_SCOPE')
need(all(sha(d[n])==h for n,h in protected.items()) and all(d[n]==w[n] for n in sql),'UNCHANGED_OWNER_SQL')
out.mkdir(parents=True,exist_ok=True);p=out/'SOKNA-PUBLIC-35IV-CONTROLLED-HOME-CLEAN.zip'
with Z.ZipFile(p,'w') as z:
 for n,b in sorted(d.items()):
  i=Z.ZipInfo(n,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=Z.ZIP_DEFLATED;z.writestr(i,b,compresslevel=9)
need(read(p)==d,'READBACK')
for name,sub in [('root',''),('sub','public/menu')]:
 dst=webroot/name/'webroot'/sub;dst.mkdir(parents=True,exist_ok=False)
 for n,b in d.items():
  f=dst/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
tree=sha(''.join(n+'\0'+sha(b)+'\n' for n,b in sorted(d.items()) if n not in protected).encode())
receipt={'clean_sha256':sha(p.read_bytes()),'factory_clean_sha256':sha(src.read_bytes()),'target':new,'tree':tree,'canonical':canonical(d),'protected':protected,'files':len(d),'changed_files':sorted(n for n in d if d[n]!=w[n]),'scope':'HOME_EMPTY_WEBROOT_AND_EMPTY_DB_ONLY','cafe_production_authorized':False,'old_host_update_authorized':False}
(webroot/'contract.json').write_bytes(J(receipt));(out/'factory-build-receipt.json').write_bytes(J(receipt))
print('EXACT_CONTROLLED_HOME_CLEAN_CANDIDATE',receipt['clean_sha256'])
