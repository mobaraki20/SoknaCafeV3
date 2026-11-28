"""Adapt the existing CC54 Local and CC52 Public frozen exact archive builders.
Candidate admission only. Historical unchanged Maintenance-core qualification is
not a new product, field, or distribution acceptance. Exact independent gates
and receipt are required before operator handoff. No updater code changed.
"""
import hashlib,json,zipfile,io,sys,pathlib,stat
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
tree=lambda d:H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
canonical=lambda d,ex:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in ex).encode())
SOURCE='a1740cb594053c85c810f1641aeba0024cc3636ffab90b994c26935018ee75f1'
LT='e9d4a0e7f524d78dba676990cfbc83ec36ce9fd4d6548c8d3ab3cf8852c23728'
PT='515d993be47e0e7fd67614955d348ebcc17e80f87ed4b595ac8ae0343b25272c'
LO='79ea97f6be0577c9c724865f0fe271cff8dbb3bf078f12825fa91c6b091a5b45'
PO='936f665e4641b3ccaef1c78ff37caa2c395cdb3c3723328999b40105f5cdabb8'
PC='09fd9b7f7b968fa8721a5944c82970f702180c82b77e2b66c37104c87d1b725f'
CORE='f38c20083068e540d593876a2c527c51e0157f92'
STAMP='2026-11-28T00:00:00Z'
LV='3.0.0-g6.2-workspace.231gl-a21-postrelease-20261128'; LR='sokna-local-231gl-a21-postrelease-20261128'
PV='3.0.0-g3.6-workspace.35jc-a21-postrelease-20261128'; PR='sokna-public-35jc-a21-postrelease-20261128'
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
   i=zipfile.ZipInfo(n,(2026,11,28,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
   z.writestr(i,v,compresslevel=9)
 raw=b.getvalue();assert read(raw)==d;return raw
def load(p,h):
 b=p.read_bytes();assert H(b)==h,(p,H(b));return b
def source(root,n,t):
 d={p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()};assert len(d)==n and tree(d)==t
 return d
def sql(d):return {n:H(v) for n,v in d.items() if n.startswith('database/migrations/') and n.endswith('.sql')}
def refresh(d,version,rid,baselines,protected=()):
 d=d.copy();d['VERSION.txt']=(version+'\n').encode();k='resources/release-identity-v1.json';i=json.loads(d[k]);ex=set(i['source']['digest_excludes'])|set(protected)
 i.update(version=version,release_set_id=rid,qualification={'tier':'EXACT_CANDIDATE_GATE_PENDING','claim_rule':'Not authorized for distribution or field installation; exact independent candidate acceptance receipt required.'});i['baseline']['source_versions']=baselines;i['source'].update(canonical_tree_sha256=canonical(d,ex),source_ref='SHA256:'+SOURCE+' A21 accepted source');d[k]=J(i)
 k='resources/build-provenance.json';v=json.loads(d[k]);v.update(version=version,release_set_id=rid,consolidated_source_sha256=SOURCE,source='sha256:'+SOURCE,created_at=STAMP,classification='EXACT_CANDIDATE_GATE_PENDING',scope='A21 accepted source; exact package gates and separate delivery receipt pending',operator_warning='QA candidate; do not install without independent exact-package delivery receipt',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False);d[k]=J(v);return d
def main():
 src,inputs,out=map(P,sys.argv[1:]);out.mkdir(parents=True,exist_ok=True)
 local=source(src/'local',626,LT);public=source(src/'public',258,PT)
 oldL=load(inputs/'local/SOKNA-LOCAL-231GK-CAFE-CONTROLLED-UPDATE.zip',LO);outer=read(oldL);m=json.loads(outer['release-set.json']);L0=read(outer['artifacts/local/web.zip']);baseline=m['targets']['local'].copy()
 oldr=json.loads((inputs/'local/DELIVERY-RECEIPT.json').read_text());oldnative=json.loads((inputs/'local/matrix-receipt.json').read_text(encoding='utf-8-sig'))
 assert oldr['package_sha256']==LO and oldr['controlled_operator_delivery_authorized'] is True and not oldr['production_host_accepted'] and oldnative['status']=='PASS' and oldnative['candidate_sha256']==LO and oldnative['migrations']==93 and oldnative['independent_restore'] and oldnative['panel_activation_tested']
 assert baseline['managed_tree_sha256']==tree(L0) and len(sql(local))==93 and sql(local)==sql(L0) and set(local)==set(L0)
 assert m['qa_notes']['maintenance_core_ref']==CORE and m['for_install'] and m['production_pass'] and m['qualification']['real_windows'] and m['qualification']['real_mariadb'] and m['qualification']['business_backup_consistency_real_mariadb'] and m['qa_notes']['cafe_production_approved'] is False
 assert not any(n.startswith(('src/Domain/Update/','src/Domain/Recovery/','src/Domain/Maintenance/')) and local[n]!=L0[n] for n in local)
 # Keep previous allowed origin and add the exact previous delivered tree. Every
 # listed origin is required in the new matrix. No inference from version only.
 bases=m['supported_baselines']+[{'local':baseline}];versions=[x['local']['version'] for x in bases]
 L=refresh(local,LV,LR,versions);assert {n for n in L if L[n]!=local[n]}=={'VERSION.txt','resources/release-identity-v1.json','resources/build-provenance.json'}
 web=pack(L);target=baseline|{'version':LV,'release_id':LR,'managed_tree_sha256':tree(L)}
 m['targets']['local']=target;m['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web));m.update(release_set_id=LR,sequence=202611280001,created_at=STAMP,supported_baselines=bases)
 # Same historical Maintenance core admission prerequisites, exactly as CC54.
 # No new product/field qualification is asserted and no delivery authorization.
 m['qa_notes']={'scope':'CONTROLLED_CAFE_OPERATOR_ONLY_FIELD_ACCEPTANCE_PENDING','cafe_production_approved':False,'field_install_verified':False,'operator_install_allowed':True,'qualification_scope':'Historical identical Maintenance Core qualification only; new exact candidate matrix and independent delivery receipt required. Not general production qualification.','maintenance_core_ref':CORE,'historical_operator_package_sha256':LO,'source_sha256':SOURCE,'current_web_sha256':H(web),'installation':'DO NOT INSTALL before independent exact delivery receipt, backup and native preflight. No automatic deployment.'}
 lup=pack({'release-set.json':J(m),'artifacts/local/web.zip':web});(out/'LOCAL-A21-UPDATE-CANDIDATE-QA-ONLY.zip').write_bytes(lup);(out/'LOCAL-A21-CLEAN-WEB-CANDIDATE-QA-ONLY.zip').write_bytes(web)
 po=read(load(inputs/'public/SOKNA-PUBLIC-35JB-CONTROLLED-UPDATE.zip',PO));pm=json.loads(po.pop('manifest.json'));P0=read(load(inputs/'public/SOKNA-PUBLIC-35JB-CONTROLLED-CLEAN.zip',PC));managed0={n[8:]:v for n,v in po.items()};assert len(managed0)==252 and all(P0[n]==v for n,v in managed0.items());protected={n:H(v) for n,v in P0.items() if n not in managed0};assert len(protected)==6 and all(H(public[n])==h for n,h in protected.items()) and set(public)==set(P0) and len(sql(public))==12 and sql(public)==sql(P0)
 assert tree(managed0)==pm['target_managed_tree_sha256'];oldpr=json.loads((inputs/'public/DELIVERY-RECEIPT.json').read_text());assert oldpr['update_sha256']==PO and oldpr['clean_sha256']==PC and oldpr['controlled_operator_delivery_authorized'] and not oldpr['production_host_accepted']
 pb=pm['compatibility']['supported_baselines']+[{'version':pm['version'],'managed_tree_sha256':pm['target_managed_tree_sha256']}]
 # Owner metadata changes before canonical digest, never owner code.
 P1=public.copy();k='src/Update/emergency-owner-v1.json';o=json.loads(P1[k]);assert o['sha256']==H(P1['src/Update/EmergencyOwnerCandidate.php']);o['release_version']=PV;P1[k]=J(o);P1=refresh(P1,PV,PR,[x['version'] for x in pb],protected)
 assert {n for n in P1 if P1[n]!=public[n]}=={'VERSION.txt','resources/release-identity-v1.json','resources/build-provenance.json','src/Update/emergency-owner-v1.json'}
 managed={n:v for n,v in P1.items() if n not in protected};assert len(managed)==252 and all(H(P1[n])==h for n,h in protected.items())
 pm.update(version=PV,target_managed_tree_sha256=tree(managed),files=[{'path':n,'sha256':H(v),'size':len(v)} for n,v in sorted(managed.items())]);pm['compatibility']['supported_baselines']=pb;pm['provenance'].update(build='A21 exact post-release candidate; CC52 factory recipe',release_qualification='EXACT_FINAL_GATE_PENDING',qualification_qa_run='EXACT_FINAL_DELIVERY_RECEIPT_REQUIRED',installation_authorized=False,production_host_qualified=False,offline_qualified=False,host_operator_note='DO NOT INSTALL until separately verified exact candidate receipt, preflight and backup.')
 up=pack({'manifest.json':J(pm)}|{'payload/'+n:v for n,v in managed.items()});cl=pack(P1);(out/'PUBLIC-A21-UPDATE-CANDIDATE-QA-ONLY.zip').write_bytes(up);(out/'PUBLIC-A21-CLEAN-CANDIDATE-QA-ONLY.zip').write_bytes(cl)
 result={'status':'PENDING_EXACT_FINAL_GATE','source_sha256':SOURCE,'delivery_authorized':False,'production_authorized':False,'local':{'sha256':H(lup),'web_sha256':H(web),'target':target,'baselines':bases,'core_ref':CORE},'public':{'update_sha256':H(up),'clean_sha256':H(cl),'target':PV,'tree':tree(managed),'protected':protected,'baselines':pb}}
 (out/'factory-build-receipt.json').write_bytes(J(result));print(json.dumps(result,indent=2))
if __name__=='__main__':main()
