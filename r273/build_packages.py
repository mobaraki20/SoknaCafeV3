#!/usr/bin/env python3
"""R273 exact R262 Local + R266 Public, offline reproducible QA package builder.
The resulting ZIPs are source-exact TEST CANDIDATES, NOT automatically Production Ready.
"""
from pathlib import Path
import hashlib,io,json,zipfile,copy
R=Path('/mnt/data/sokna_r273'); OUT=R/'output';OUT.mkdir(exist_ok=True)
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def tree(d):return H(''.join(n+'\0'+H(b)+'\n' for n,b in sorted(d.items())).encode())
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
def canonical(d):return H(''.join(n+'\0'+str(len(b))+'\0'+H(b)+'\n' for n,b in sorted(d.items()) if n not in EXCLUDE).encode())
def zip_d(d):
 buf=io.BytesIO()
 with zipfile.ZipFile(buf,'w') as z:
  for n,b in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,17,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return buf.getvalue()
# Local exact R262
lb=Path('/mnt/data/extracted-SOKNA-R262-LOCAL-231FG-CUMULATIVE-PUBLIC-MOVE-NATIVE-UAT-UNQUALIFIED.zip')
assert H(lb.read_bytes())=='3d12ea375a307e58c8e36b2036a4fea9dec9ef05330f4a046234102c32164b8c'
with zipfile.ZipFile(lb) as z:
 lm=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:ld={n:w.read(n) for n in w.namelist()}
ls={p.relative_to(R/'local').as_posix():p.read_bytes() for p in (R/'local').rglob('*') if p.is_file()}
assert set(ld)==set(ls) and len(ls)==604
allowedLocal={'VERSION.txt','public/integrations/api.php','public/assets/connections-workspace.js','src/Domain/PublicEdge/PublicMoveSimpleReplacement.php','src/Domain/PublicEdge/PublicEdgePublisherService.php','src/Domain/PublicEdge/PublicEdgeSyncClient.php','resources/build-provenance.json','resources/release-identity-v1.json'}
changedLocal={n for n in ls if ld[n]!=ls[n]}
assert changedLocal.issubset(allowedLocal),sorted(changedLocal-allowedLocal)
assert all(ld[n]==ls[n] for n in ls if n.startswith('database/migrations/') or n.startswith('src/Domain/Orders/') or n.startswith('src/Domain/Printing/'))
verL='3.0.0-g6.2-workspace.231fj-r273-existing-public-owner-safe-move';ridL='sokna-r273-local-existing-public-owner-transfer-20261117'
ls['VERSION.txt']=(verL+'\n').encode()
managed=json.loads(ls['resources/local-managed-files-v1.json']);managed['files']=sorted(ls);ls['resources/local-managed-files-v1.json']=J(managed)
prov=json.loads(ls['resources/build-provenance.json']);prov.update(classification='R273_SOURCE_QA_NO_FIELD_DEPLOY',release_set_id=ridL,version=verL,
 source='Exact R262 Local 231fg + owner-first existing Public transfer code-only edits; no Windows or SQL change',full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False)
ls['resources/build-provenance.json']=J(prov)
ident=json.loads(ls['resources/release-identity-v1.json']);ident.update(version=verL,release_set_id=ridL)
ident['source'].update(canonical_tree_sha256=canonical(ls),managed_file_count=len(ls),canonical_file_count=len(ls)-len(EXCLUDE),source_ref='R262 exact 231fg cafe Local existing Public paired R266 owner flow')
ident['qualification']['tier']='R273_E2E_QA_PENDING_HOST_NO_GO';ls['resources/release-identity-v1.json']=J(ident)
assert ident['source']['canonical_tree_sha256']==canonical(ls)
linner=zip_d(ls);lmeta=copy.deepcopy(lm)
lmeta.update(release_set_id=ridL,sequence=202611170273,status='R273_E2E_QA_PENDING_HOST_NO_GO',created_at='2026-11-17T11:40:00Z')
lmeta['supported_baselines']=[{'local':copy.deepcopy(lm['targets']['local']),'public':None}]
lmeta['targets']['local'].update(version=verL,release_id=ridL,managed_tree_sha256=tree(ls),managed_files=len(ls))
lmeta['artifacts']['local-web'].update(sha256=H(linner),size_bytes=len(linner))
lmeta['qa_notes'].update(r273_scope='Public R266 existing paired with exact same Local ID, owner-first, no Emergency code entry',r273_full_browser='PENDING',r273_field='NOT_DEPLOYED')
lmeta['for_install']=True;lmeta['production_pass']=True # CI/native-stage flags are not an operator approval.
lzip=zip_d({'release-set.json':J(lmeta),'artifacts/local/web.zip':linner})
lname='SOKNA-R273-LOCAL-231FJ-EXISTING-PUBLIC-OWNER-TRANSFER-QA-DO-NOT-INSTALL.zip';(OUT/lname).write_bytes(lzip)
# Public exact R266, code-only Emergency and Routine single package
pb=Path('/mnt/data/r268/02-SOKNA-R266-PUBLIC-35IG-MIGRATION-UPDATE.zip')
assert H(pb.read_bytes())=='b6d4d2236ab3da126a4070d75802a9cac6719c139a188808d1712b8c1827bd35'
with zipfile.ZipFile(pb) as z:
 pm=json.loads(z.read('manifest.json'));pd={n.removeprefix('payload/'):z.read(n) for n in z.namelist() if n.startswith('payload/') and not n.endswith('/')}
ps={p.relative_to(R/'public').as_posix():p.read_bytes() for p in (R/'public').rglob('*') if p.is_file()}
assert set(pd)==set(ps) and len(ps)==244
allowedPublic={'VERSION.txt','assets/sokna-public-move.js','src/Http/PublicHttpKernel.php','src/Authority/AuthorityEpochService.php','src/Realtime/RealtimeService.php','src/Security/PublicMoveReplacementGate.php'}
changedPublic={n for n in ps if pd[n]!=ps[n]}
assert changedPublic.issubset(allowedPublic),sorted(changedPublic-allowedPublic)
assert all(pd[n]==ps[n] for n in ps if n.startswith('database/migrations/') or n in ('emergency.php','emergency-next.php','config.php','install.lock'))
verP='3.0.0-g3.6-workspace.35ih-r273-existing-public-owner-safe-move';ps['VERSION.txt']=(verP+'\n').encode()
pmanifest=copy.deepcopy(pm)
pmanifest.update(version=verP,target_managed_tree_sha256=tree(ps))
pmanifest['compatibility']['supported_baselines']=[{'version':pm['version'],'managed_tree_sha256':pm['target_managed_tree_sha256']}]
pmanifest['provenance'].update(build='R273 exact R266 code-only owner-and-Local staged transfer; no SQL change',offline_qualified=False,host_operator_note='QA CANDIDATE; full HTTP/local Windows host acceptance required before cafe install',emergency_schema_migration_contract='code_only_source_exact_v1')
pmanifest['files']=[{'path':n,'sha256':H(b),'size':len(b)} for n,b in sorted(ps.items())]
pzip=zip_d({'manifest.json':J(pmanifest),**{'payload/'+n:b for n,b in ps.items()}})
pname='SOKNA-R273-PUBLIC-35IH-OWNER-TRANSFER-CODE-ONLY-QA-DO-NOT-INSTALL.zip';(OUT/pname).write_bytes(pzip)
proof={'format':'sokna-r273-source-compare-v1','operator_install_allowed':False,'status':'E2E_PENDING_NOT_FOR_CAFE',
 'source':{'local262_sha256':H(lb.read_bytes()),'public266_sha256':H(pb.read_bytes())},
 'local':{'package':lname,'sha256':H(lzip),'inner_sha256':H(linner),'managed_tree_sha256':tree(ls),'changed_vs_R262':sorted(changedLocal),'migrations_byte_exact':True},
 'public':{'package':pname,'sha256':H(pzip),'managed_tree_sha256':tree(ps),'changed_vs_R266':sorted(changedPublic),'migrations_byte_exact':True},
 'quality_gates':{'php_js_lint':'PENDING','owner_browser_real_http':'PENDING','mariadb_11':'PENDING','windows_maintenance':'PENDING','public_emergency_code_only':'PENDING','on_host_readback':'NOT_RUN'},
 'user_host_modified':False,'cafe_installation_performed':False,'main_updated':False}
(OUT/'R273-SOURCE-BUILD-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
print(json.dumps(proof,ensure_ascii=False,indent=2))
