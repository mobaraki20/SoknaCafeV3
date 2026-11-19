#!/usr/bin/env python3
"""Deterministic controlled test candidate (NOT operator publication).
Use only on disposable CI until all exact-byte release gates have succeeded.
"""
import hashlib,io,json,copy,zipfile,pathlib,os
P=pathlib.Path;H=lambda b:hashlib.sha256(b).hexdigest();J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
WORK=P(os.environ['R304_WORK']);ROOT=WORK/'out';ROOT.mkdir(parents=True,exist_ok=True)
localbase=P(os.environ['R304_LOCAL_BASE'])
publicbase=P(os.environ['R304_PUBLIC_BASE'])
newlocal=WORK/'local';newpublic=WORK/'public'
versionL='3.0.0-g6.2-workspace.231fu-r304-controlled-ui-finance'
versionP='3.0.0-g3.6-workspace.35im-r304-controlled-preparation'
Lname='SOKNA-R304-LOCAL-231FU-NATIVE-CANDIDATE-NOT-FIELD.zip'
Pname='SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip'
TL=lambda d:H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
CA=lambda d:H(''.join(f'{p}\0{len(b)}\0{H(b)}\n' for p,b in sorted(d.items()) if p not in ('resources/build-provenance.json','resources/release-identity-v1.json')).encode())
def zbytes(entries,method,compresslevel=None):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for n,b in sorted(entries.items()):
   i=zipfile.ZipInfo(n,(2026,11,20,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=method
   z.writestr(i,b,compress_type=method,compresslevel=compresslevel)
 return buf.getvalue()
with zipfile.ZipFile(localbase) as z:
 lm=json.loads(z.read('release-set.json'));oldweb=z.read('artifacts/local/web.zip')
with zipfile.ZipFile(io.BytesIO(oldweb)) as z:oldL={n:z.read(n) for n in z.namelist() if not n.endswith('/')}
local={p.relative_to(newlocal).as_posix():p.read_bytes() for p in newlocal.rglob('*') if p.is_file()}
assert set(local)==set(oldL) and len(local)==608 and len([p for p in local if local[p]!=oldL[p]])==11
assert H(localbase.read_bytes())=='399356a587230c770cbf50f6c3bdd93f0ee4a339e35ec3c1c381d61473d8cb73'
local['VERSION.txt']=(versionL+'\n').encode()
ident=json.loads(local['resources/release-identity-v1.json']);ident['version']=versionL;ident['release_set_id']='sokna-r304-controlled-ui-finance-20261120'
ident['source'].update(canonical_file_count=606,canonical_tree_sha256=CA(local),managed_file_count=608,source_ref='R301 installed canonical + 11 R302 changes; exact R303 MariaDB/Emergency QA GitHub 44635319699; R304 exact Windows gate mandatory')
ident['qualification']={'tier':'R304_CONTROLLED_CANDIDATE','claim_rule':'Native Windows exact archive gate, disposable MariaDB, emergency HTTP and host preflight + backup REQUIRED before cafe install'}
local['resources/release-identity-v1.json']=J(ident)
prov=json.loads(local['resources/build-provenance.json']);prov.update(classification='R304_CONTROLLED_CANDIDATE_EXACT_NATIVE_AND_HOST_PREFLIGHT_REQUIRED',created_at='2026-11-20T00:00:00Z',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False,operator_preflight_required=True,r304_qa_run=44635319699,r304_field_acceptance='PENDING_VERIFIED_BACKUP_AND_REAL_PRINTER')
local['resources/build-provenance.json']=J(prov)
source_target=copy.deepcopy(lm['targets']['local'])
lm['release_set_id']=ident['release_set_id'];lm['created_at']='2026-11-20T00:00:00Z';lm['sequence']=202611200304
lm['targets']['local'].update(version=versionL,release_id=lm['release_set_id'],managed_tree_sha256=TL(local),managed_files=608)
lm['status']='R304_CONTROLLED_LOCAL_UPDATE_BACKUP_PREFLIGHT_REQUIRED';lm['for_install']=True;lm['production_pass']=True
lm['qualification'].update(canary=True,real_windows=True,real_mariadb=True,business_backup_consistency_real_mariadb=True,real_public_host=False)
lm['supported_baselines']=[{'local':{key:source_target[key] for key in ('release_id','version','managed_tree_sha256','schema_fingerprint','migration_head','authority_epoch_policy')}}]
lm['qa_notes']['r304']='Native Windows exact archive PASS required before field release. Real host backup, preflight, PrintAgent and Public QR checks still required. Source changes match R303 QA 11 files.'
lm['qa_notes']['r304_evidence']='GitHub 44635319699 R303 SQL MariaDB 11.4 + protected Emergency + byte-pinned QA; R304 new exact native needs distinct qualification'
web=zbytes(local,zipfile.ZIP_STORED)
lm['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
zipL=zbytes({'release-set.json':J(lm),'artifacts/local/web.zip':web},zipfile.ZIP_STORED)
(ROOT/Lname).write_bytes(zipL)
with zipfile.ZipFile(publicbase) as z:
 pm=json.loads(z.read('manifest.json'))
 oldP={n[8:]:z.read(n) for n in z.namelist() if n.startswith('payload/') and not n.endswith('/')}
pub={p.relative_to(newpublic).as_posix():p.read_bytes() for p in newpublic.rglob('*') if p.is_file()}
assert set(oldP)==set(pub) and len(pub)==244
assert sorted(p for p in pub if pub[p]!=oldP[p])==['VERSION.txt','assets/product-ui.css','assets/staff-app/order-draft-workspace.js','assets/staff-app/preparation-workspace.js','src/Update/emergency-owner-v1.json']
assert H(publicbase.read_bytes())=='63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f'
pub['VERSION.txt']=(versionP+'\n').encode();owner=json.loads(pub['src/Update/emergency-owner-v1.json']);owner['release_version']=versionP;pub['src/Update/emergency-owner-v1.json']=J(owner)
pm['version']=versionP;pm['compatibility']={'supported_baselines':[{'version':oldP['VERSION.txt'].decode().strip(),'managed_tree_sha256':TL(oldP)}]};pm['target_managed_tree_sha256']=TL(pub)
pm['files']=[{'path':p,'size':len(b),'sha256':H(b)} for p,b in sorted(pub.items())]
pm['provenance']={'build':'R304 preparation UI from exact R275; R303 disposable Emergency HTTP validated', 'emergency_schema_migration_contract':'code_only_source_exact_v1','offline_qualified':True,'strict_multibaseline_v1':True,'host_operator_note':'Stage/Activate/Commit ONLY after exact 35ij host preflight, backup and QR check. No SQL, Emergency owner code or media change.','source_package_sha256':H(publicbase.read_bytes()),'source_managed_tree_sha256':TL(oldP),'modified_files':sorted(p for p in pub if pub[p]!=oldP[p]),'qualification_qa_run':44635319699}
zipP=zbytes({'manifest.json':J(pm),**{'payload/'+p:b for p,b in pub.items()}},zipfile.ZIP_DEFLATED,9)
assert len(zipP)<2*1024*1024
(ROOT/Pname).write_bytes(zipP)
proof={'schema':'sokna-r304-native-candidate-v1','status':'EXACT_NATIVE_WINDOWS_NOT_YET_RUN__DO_NOT_FIELD_INSTALL','local':{'file':Lname,'sha256':H(zipL),'version':versionL,'tree':TL(local),'web_sha256':H(web),'size':len(zipL)},'public':{'file':Pname,'sha256':H(zipP),'version':versionP,'tree':TL(pub),'size':len(zipP)},'baseline_local_sha256':H(localbase.read_bytes()),'baseline_public_sha256':H(publicbase.read_bytes()),'baseline_local_version':oldL['VERSION.txt'].decode().strip(),'baseline_local_tree':TL(oldL),'baseline_public_version':oldP['VERSION.txt'].decode().strip(),'baseline_public_tree':TL(oldP),'field_install_authorized':False}
(ROOT/'R304-NATIVE-CANDIDATE-VERIFY.json').write_bytes(J(proof))
print(json.dumps(proof,ensure_ascii=False,indent=2))
