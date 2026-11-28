"""A23-29 frozen QA intake using CC54/CC52 A21 archive recipe; no host writes.
Protected owner transition stays separate. No release or deployment authority.
"""
import hashlib,json,zipfile,io,pathlib,stat,copy,sys
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
tree=lambda d:H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
canonical=lambda d,ex:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in ex).encode())
SOURCE='a632310a446fb1036c72896a6f9816a250be35a378771cf2070b84378abc6810'
BASE='a1740cb594053c85c810f1641aeba0024cc3636ffab90b994c26935018ee75f1'
DELIVERY='68461c32298c61ceb302a86e417452bacb2ba8ae7b4e596bead29393f8232a55'
OWNER='src/Emergency/PublicTakeoverService.php'
OLD='100b9cd37384eea6828ed64ddfba9dac968e5912d6b62dafec2a66c55849e986'
NEW='948ac3beb554f5309950164eca5390342ad21681f9aa68631d7b31f0debcab45'
META={'resources/release-identity-v1.json','resources/build-provenance.json'}
LV='3.0.0-g6.2-workspace.231gm-a23-qa-20261128';LR='sokna-local-231gm-a23-qa-20261128'
PV='3.0.0-g3.6-workspace.35jd-a23-qa-20261128';PR='sokna-public-35jd-a23-qa-20261128'
def read(raw):
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  ns=z.namelist();assert len(ns)==len(set(ns)) and z.testzip() is None
  assert all(not P(n).is_absolute() and '..' not in P(n).parts and '\\' not in n and not n.endswith('/') and ':' not in n for n in ns)
  assert all(not stat.S_ISLNK(i.external_attr>>16) for i in z.infolist())
  return {n:z.read(n) for n in ns}
def pack(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for n,v in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,28,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,v,compresslevel=9)
 raw=b.getvalue();assert read(raw)==d;return raw
def load(p,h):
 b=p.read_bytes();assert H(b)==h,(p,H(b));return read(b)
def split(d,typ,prefix=''):
 p=prefix+typ+'/';return {n[len(p):]:v for n,v in d.items() if n.startswith(p)}
def sql(d):return {n:H(v) for n,v in d.items() if n.startswith('database/migrations/') and n.endswith('.sql')}
def refresh(d,version,rid,baselines,protected=()):
 d=d.copy();d['VERSION.txt']=(version+'\n').encode();k='resources/release-identity-v1.json';i=json.loads(d[k]);ex=set(i['source']['digest_excludes'])|set(protected)
 i.update(version=version,release_set_id=rid,qualification={'tier':'QUARANTINED_EXACT_CANDIDATE','claim_rule':'No distribution or field installation authorized; exact independent package and owner transition acceptance required.'});i['baseline']['source_versions']=baselines;i['source'].update(managed_file_count=len(set(d)-set(protected)),canonical_file_count=len(set(d)-ex),canonical_tree_sha256=canonical(d,ex),source_ref='SHA256:'+SOURCE+' A23-23 frozen candidate');d[k]=J(i)
 k='resources/build-provenance.json';v=json.loads(d[k]);v.update(version=version,release_set_id=rid,consolidated_source_sha256=SOURCE,source='sha256:'+SOURCE,classification='QUARANTINED_EXACT_CANDIDATE',scope='A23-23 exact source, A23-29 owner-authorized separate transition, acceptance pending',operator_warning='QA ONLY; DO NOT INSTALL',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False);d[k]=J(v);return d
def main():
 base,source,delivery,out=map(P,sys.argv[1:]);out.mkdir(parents=True,exist_ok=True)
 def folder(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
 L0=folder(base/'local');P0=folder(base/'public');L=folder(source/'local');U=folder(source/'public')
 assert (len(L0),tree(L0),len(P0),tree(P0))==(626,'e9d4a0e7f524d78dba676990cfbc83ec36ce9fd4d6548c8d3ab3cf8852c23728',258,'515d993be47e0e7fd67614955d348ebcc17e80f87ed4b595ac8ae0343b25272c')
 assert (len(L),tree(L),len(U),tree(U))==(627,'041e6fd09737497495e3d7961ade1b5933cd62b2330687585bb3dc565e11e3d0',259,'8286ca76a9d6e22fe5cd983c1f7ce307eab1848e2eecc4d9f2d3cc07d2c337e2')
 inputs={'SOKNA-LOCAL-231GL-UNIVERSAL-UPDATE.zip':('LOCAL-A21-UPDATE-CANDIDATE-QA-ONLY.zip','a0763360131c6e948bd3bff2acdac41afa657a8a1eef336b3ed9de9537092202'),'SOKNA-LOCAL-231GL-CLEAN-WEB.zip':('LOCAL-A21-CLEAN-WEB-CANDIDATE-QA-ONLY.zip','707e64815c039224af1a8be6a55f89932e482f004c15a6a46913b41bfad1fa10'),'SOKNA-PUBLIC-35JC-UNIVERSAL-UPDATE.zip':('PUBLIC-A21-UPDATE-CANDIDATE-QA-ONLY.zip','7d7bd5b6943a5c860ca7fb7b750d1268540630f9fce27ccf5a559359674b61a2'),'SOKNA-PUBLIC-35JC-CLEAN-INSTALL.zip':('PUBLIC-A21-CLEAN-CANDIDATE-QA-ONLY.zip','641e70ebc45da13eaf538f93cdd7a50567b75109135b24b8fc7573b0349e5402')}
 a={}
 for n,(f,h) in inputs.items():
  a[n]=(delivery/f).read_bytes();assert H(a[n])==h,(n,H(a[n]))
 oldl=read(a['SOKNA-LOCAL-231GL-UNIVERSAL-UPDATE.zip']);oldp=read(a['SOKNA-PUBLIC-35JC-UNIVERSAL-UPDATE.zip']);Lc=read(a['SOKNA-LOCAL-231GL-CLEAN-WEB.zip']);Pc=read(a['SOKNA-PUBLIC-35JC-CLEAN-INSTALL.zip']);m=json.loads(oldl['release-set.json']);pm=json.loads(oldp['manifest.json'])
 assert oldl['artifacts/local/web.zip']==a['SOKNA-LOCAL-231GL-CLEAN-WEB.zip'];assert tree(Lc)==m['targets']['local']['managed_tree_sha256'];managed0={n[8:]:v for n,v in oldp.items() if n.startswith('payload/')};assert tree(managed0)==pm['target_managed_tree_sha256'] and all(Pc[n]==v for n,v in managed0.items())
 assert set(Lc)==set(L0) and set(Pc)==set(P0) and {n for n in L0 if L0[n]!=Lc[n]}==META|{'VERSION.txt'} and {n for n in P0 if P0[n]!=Pc[n]}==META|{'VERSION.txt','src/Update/emergency-owner-v1.json'}
 protected=set(Pc)-set(managed0);assert len(protected)==6 and OWNER in protected
 before={n:H(Pc[n]) for n in sorted(protected)};after={n:H(U[n]) for n in sorted(protected)};assert before[OWNER]==OLD and after[OWNER]==NEW and {n for n in protected if before[n]!=after[n]}=={OWNER}
 assert set(L)-set(L0)=={'tools/restore-business-backup.php'} and set(U)-set(P0)=={'database/migrations/0013_verified_takeover_binding.sql'}
 assert sql(L)==sql(L0) and len(sql(L))==93 and len(sql(U))==13 and all(sql(U)[n]==h for n,h in sql(P0).items())
 # Evidence that no version/update engine file changed. Recovery service has separate approved A23 changes.
 assert U['src/Update/RoutinePublicUpdateService.php']==P0['src/Update/RoutinePublicUpdateService.php'] and U['src/Update/EmergencyOwnerCandidate.php']==P0['src/Update/EmergencyOwnerCandidate.php'] and U['src/Emergency/PublicUpdateService.php']==P0['src/Emergency/PublicUpdateService.php']
 old_target=copy.deepcopy(m['targets']['local']);bases=m['supported_baselines']+[{'local':old_target}];assert len({x['local']['version'] for x in bases})==len(bases)
 # New Local CLI must be listed in the installed managed inventory.
 k='resources/local-managed-files-v1.json';i=json.loads(L[k]);assert set(i['files'])==set(L0);i['files']=sorted(L);L[k]=J(i)
 L=refresh(L,LV,LR,[x['local']['version'] for x in bases]);web=pack(L)
 target=old_target|{'version':LV,'release_id':LR,'managed_tree_sha256':tree(L),'managed_files':len(L)}
 m['targets']['local']=target;m['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web));m.update(release_set_id=LR,sequence=202611280002,supported_baselines=bases,status='QUARANTINED_RELEASE_CANDIDATE',for_install=False,production_pass=False,signature=None)
 m['qualification']={k:(False if isinstance(v,bool) else v) for k,v in m['qualification'].items()};m['qa_notes']={'scope':'A23-29 factory intake QA ONLY','source_sha256':SOURCE,'maintenance_core_ref':'f38c20083068e540d593876a2c527c51e0157f92','operator_install_allowed':False,'candidate_gate':'NEW_EXACT_PACKAGE_AND_OWNER_TRANSITION_RECEIPTS_REQUIRED'}
 lup=pack({'release-set.json':J(m),'artifacts/local/web.zip':web})
 pb=pm['compatibility']['supported_baselines']+[{'version':pm['version'],'managed_tree_sha256':pm['target_managed_tree_sha256']}];assert len({x['version'] for x in pb})==len(pb)
 k='src/Update/emergency-owner-v1.json';o=json.loads(U[k]);assert o['sha256']==H(U['src/Update/EmergencyOwnerCandidate.php']);o['release_version']=PV;U[k]=J(o);U=refresh(U,PV,PR,[x['version'] for x in pb],protected)
 managed={n:v for n,v in U.items() if n not in protected};assert len(managed)==253 and all(H(U[n])==h for n,h in after.items())
 pm.update(version=PV,target_managed_tree_sha256=tree(managed),files=[{'path':n,'sha256':H(v),'size':len(v)} for n,v in sorted(managed.items())]);pm['compatibility']['supported_baselines']=pb;pm['provenance'].update(build='A23-29 CC52 frozen QA intake',source_archive_sha256=SOURCE,origin_package_sha256=H(a['SOKNA-PUBLIC-35JC-UNIVERSAL-UPDATE.zip']),modified_files=sorted(n for n in managed if managed0.get(n)!=managed[n]),release_qualification='QUARANTINED_EXACT_CANDIDATE',qualification_qa_run='NOT_RUN_EXACT_NEW_BYTES',installation_authorized=False,offline_qualified=False,production_host_qualified=False,host_operator_note='DO NOT INSTALL. Separate owner transition required. Never place protected file in routine payload.')
 pup=pack({'manifest.json':J(pm)}|{'payload/'+n:v for n,v in managed.items()});pc=pack(U)
 # Owner bundle is an evidence-only candidate, NOT an installer or public PHP helper.
 # No live deployment mechanism, no web entrypoint and no relaxation of stable path checks.
 contract={'format':'sokna-a23-29-owner-transition-contract-v1','status':'OWNER_SCOPE_AUTHORIZED_TECHNICAL_PACKAGE_GATE_PENDING','approved_scope':OWNER,'owner_permission':'User confirmed the prior explicit proposal to accept this exact protected change through the independent owner route. No other protected or updater changes authorized.','old_sha256':OLD,'new_sha256':NEW,'source_sha256':SOURCE,'protected_before':before,'protected_after':after,'source_evidence_runs':[46134128796,46141940371,46142364836],'required_migration':'0013_verified_takeover_binding','preconditions':['exact trusted origin and all six protected file pins','verified independent DB/file backup and restore proof','quiescent Public writers and no unresolved lifecycle/authority operation','separate owner operation and readback; never Routine payload','managed schema migration and complete authority synchronization before reopening traffic'],'operator_transition_qualified':False,'production_authorized':False,'delivery_authorized':False,'rollback_rule':'No old-code rollback after DB forward boundary; maintain fence and use forward-safe recovery.'}
 owner=pack({'OWNER-CONTRACT.json':J(contract),'candidate/'+OWNER:U[OWNER]})
 files={'LOCAL-231GM-UPDATE-QA-NOT-INSTALL.zip':lup,'LOCAL-231GM-CLEAN-WEB-QA-NOT-INSTALL.zip':web,'PUBLIC-35JD-UPDATE-QA-NOT-INSTALL.zip':pup,'PUBLIC-35JD-CLEAN-QA-NOT-INSTALL.zip':pc,'PUBLIC-35JD-PROTECTED-OWNER-QA-NOT-INSTALL.zip':owner}
 for n,v in files.items():(out/n).write_bytes(v)
 assert read(lup)['artifacts/local/web.zip']==web and set(json.loads(L['resources/local-managed-files-v1.json'])['files'])==set(L)
 rr=read(pup);assert set(rr)=={'manifest.json'}|{'payload/'+n for n in managed} and not any('payload/'+n in rr for n in protected)
 assert all(H(rr['payload/'+r['path']])==r['sha256'] and len(rr['payload/'+r['path']])==r['size'] for r in pm['files'])
 assert read(pc)==U and all(H(read(pc)[n])==h for n,h in after.items());assert read(owner)['candidate/'+OWNER]==U[OWNER]
 receipt={'format':'sokna-a23-29-factory-intake-v1','status':'QUARANTINED_FROZEN_QA_CANDIDATES','source_sha256':SOURCE,'baseline_delivery_sha256':DELIVERY,'files':{n:{'sha256':H(v),'bytes':len(v)} for n,v in files.items()},'local':{'version':LV,'managed_count':len(L),'tree':tree(L),'migration_count':len(sql(L)),'baselines':bases},'public':{'version':PV,'managed_count':len(managed),'tree':tree(managed),'migration_count':len(sql(U)),'baselines':pb,'protected_before':before,'protected_after':after,'owner_transition_required':True},'owner_contract':contract,'factory_accepted':False,'exact_package_runtime_qa':'NOT_RUN','delivery_authorized':False,'production_authorized':False}
 (out/'A23-29-FACTORY-INTAKE-RECEIPT.json').write_bytes(J(receipt));(out/'A23-29-PROTECTED-OWNER-CONTRACT.json').write_bytes(J(contract));print(json.dumps(receipt,indent=2))
if __name__=='__main__':main()
