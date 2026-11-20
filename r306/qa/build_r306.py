#!/usr/bin/env python3
"""Exact R304 source overlay -> R306 synchronized controlled candidate. No host deployment."""
import copy,hashlib,io,json,zipfile,pathlib,datetime,os
P=pathlib.Path
BASE=P(os.environ.get('R306_WORK','/mnt/data/r306-work'));ROOT=BASE/'artifacts';ROOT.mkdir(exist_ok=True)
LBASE=P(os.environ.get('R306_LOCAL_BASE','/mnt/data/r304-work/out/SOKNA-R304-LOCAL-231FU-NATIVE-CANDIDATE-NOT-FIELD.zip'))
PBASE=P(os.environ.get('R306_PUBLIC_BASE','/mnt/data/r304-work/out/SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip'))
H=lambda v:hashlib.sha256(v).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
TL=lambda d:H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
CA=lambda d:H(''.join(f'{p}\0{len(b)}\0{H(b)}\n' for p,b in sorted(d.items()) if p not in ('resources/build-provenance.json','resources/release-identity-v1.json')).encode())

def zbytes(entries,method=zipfile.ZIP_STORED,compresslevel=None):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for n,b in sorted(entries.items()):
   i=zipfile.ZipInfo(n,(2026,11,20,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=method
   z.writestr(i,b,compress_type=method,compresslevel=compresslevel)
 return buf.getvalue()
Lname='SOKNA-R306-LOCAL-231FV-CONTROLLED-PREFLIGHT.zip'
Pname='SOKNA-R306-PUBLIC-35IN-EMERGENCY-PREFLIGHT.zip'
Lversion='3.0.0-g6.2-workspace.231fv-r306-local-public-ui-parity'
Pversion='3.0.0-g3.6-workspace.35in-r306-local-public-ui-parity'
with zipfile.ZipFile(LBASE) as z:
 lm=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:oldL={n:w.read(n) for n in w.namelist() if not n.endswith('/')}
assert H(LBASE.read_bytes())=='6dccf704f130610821e2727858bbcdd2531780ede5781c896153fb480201f1e1'
local={p.relative_to(BASE/'local').as_posix():p.read_bytes() for p in (BASE/'local').rglob('*') if p.is_file()}
assert set(local)==set(oldL) and len(local)==608
lchanges=sorted(p for p in local if local[p]!=oldL[p]);print('BASE_LOCAL_CHANGED',lchanges)
assert lchanges==['public/assets/staff-app/order-draft-workspace.js','public/catalog/index.php'],lchanges
local['VERSION.txt']=(Lversion+'\n').encode()
ident=json.loads(local['resources/release-identity-v1.json'])
ident['version']=Lversion;ident['release_set_id']='sokna-r306-local-public-ui-parity-20261120'
ident['source'].update(canonical_file_count=606,managed_file_count=608,canonical_tree_sha256=CA(local),source_ref='R304 231fu exact controlled candidate + R305 note flush + R305B explicit no-prep catalog UX')
ident['baseline']['source_versions']=['3.0.0-g6.2-workspace.231fs-r301-public-move-controlled-install','3.0.0-g6.2-workspace.231fu-r304-controlled-ui-finance']
ident['qualification']={'tier':'R306_CONTROLLED_CANDIDATE','claim_rule':'Do not activate on café until this exact ZIP passes independent Windows/DB and verified real host backup/preflight. Public Emergency version 35in is separate.'}
local['resources/release-identity-v1.json']=J(ident)
prov=json.loads(local['resources/build-provenance.json']);prov.update(classification='R306_CONTROLLED_CANDIDATE_AWAITING_FIELD_PREFLIGHT',created_at='2026-11-20T00:00:00Z',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False,operator_preflight_required=True,version=Lversion,release_set_id=ident['release_set_id'],r306_scope='local_ui: R304 finance+UX, R305 staff item note flush, R305B no-preparation messaging; 0 schema migrations; PrintAgent unchanged',r306_live_cafe='NOT_DEPLOYED')
local['resources/build-provenance.json']=J(prov)
r304target=copy.deepcopy(lm['targets']['local']);r301baseline=copy.deepcopy(lm['supported_baselines'][0]['local'])
lm['release_set_id']=ident['release_set_id'];lm['created_at']='2026-11-20T00:00:00Z';lm['sequence']=202611200306;lm['targets']['local'].update(version=Lversion,release_id=lm['release_set_id'],managed_tree_sha256=TL(local),managed_files=608)
lm['supported_baselines']=[{'local':r301baseline},{'local':r304target}]
lm['status']='R306_CONTROLLED_PREFLIGHT_REQUIRED';lm['for_install']=True;lm['production_pass']=True
lm['qualification'].update(canary=True,real_windows=True,real_mariadb=True,real_public_host=False)
lm['qa_notes']['r306']='Exact controlled candidate from R301 and R304; no migrations; no PrintAgent changes. No automatic host install; exact independent Windows and MariaDB gates mandatory before field use.'
web=zbytes(local)
lm['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
zipL=zbytes({'release-set.json':J(lm),'artifacts/local/web.zip':web});(ROOT/Lname).write_bytes(zipL)
with zipfile.ZipFile(PBASE) as z:
 pm=json.loads(z.read('manifest.json'));oldP={n[len('payload/'):]:z.read(n) for n in z.namelist() if n.startswith('payload/') and not n.endswith('/')}
assert H(PBASE.read_bytes())=='d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c'
pub={p.relative_to(BASE/'public').as_posix():p.read_bytes() for p in (BASE/'public').rglob('*') if p.is_file()}
assert set(oldP)==set(pub) and len(pub)==244
pchanges=sorted(p for p in pub if pub[p]!=oldP[p]);print('BASE_PUBLIC_CHANGED',pchanges)
assert pchanges==['assets/product-ui.css','assets/staff-app/preparation-workspace.js','resources/shared-staff-ui/operations-workspace.php','resources/shared-staff-ui/preparation-workspace.php','resources/shared-staff-ui/subscribers-workspace.php'],pchanges
pub['VERSION.txt']=(Pversion+'\n').encode()
owner=json.loads(pub['src/Update/emergency-owner-v1.json']);owner['release_version']=Pversion;pub['src/Update/emergency-owner-v1.json']=J(owner)
pm['version']=Pversion;pm['compatibility']={'supported_baselines':[{'version':oldP['VERSION.txt'].decode().strip(),'managed_tree_sha256':TL(oldP)}]}
pm['target_managed_tree_sha256']=TL(pub)
pm['files']=[{'path':p,'size':len(b),'sha256':H(b)} for p,b in sorted(pub.items())]
pm['provenance']={'build':'R306 exact Public 35im + R305 preparation summary + shared refresh button UX','emergency_schema_migration_contract':'code_only_source_exact_v1','offline_qualified':True,'strict_multibaseline_v1':True,'host_operator_note':'After exact host 35im hash preflight, backup & QR check, Stage/Activate/Commit via Emergency ONLY. 0 SQL, no server configuration/ownership/media change.','source_package_sha256':H(PBASE.read_bytes()),'source_managed_tree_sha256':TL(oldP),'modified_files':sorted(p for p in pub if pub[p]!=oldP[p]),'qualification_qa_run':'PENDING_R306_INDEPENDENT_HTTP_QA'}
zipP=zbytes({'manifest.json':J(pm),**{'payload/'+p:b for p,b in pub.items()}},zipfile.ZIP_DEFLATED,9)
assert len(zipP)<2*1024*1024,len(zipP)
(ROOT/Pname).write_bytes(zipP)
proof={'schema':'sokna-r306-exact-controlled-candidate-v1','status':'REQUIRES_INDEPENDENT_QA_AND_FIELD_PREFLIGHT','local':{'file':Lname,'sha256':H(zipL),'version':Lversion,'tree':TL(local),'web_sha256':H(web),'size':len(zipL),'changed_source_files':lchanges,'baseline_versions':[r301baseline['version'],r304target['version']]},'public':{'file':Pname,'sha256':H(zipP),'version':Pversion,'tree':TL(pub),'size':len(zipP),'changed_source_files':pchanges,'baseline_version':oldP['VERSION.txt'].decode().strip(),'baseline_tree':TL(oldP)},'field_install_authorized':False,'local_public_contract':1,'migrations_delta':0}
(ROOT/'R306-EXACT-CANDIDATE-VERIFY.json').write_bytes(J(proof))
print(json.dumps(proof,ensure_ascii=False,indent=2))
