#!/usr/bin/env python3
"""Deterministic final R299 Local release candidate. Publish only after exact-native and factory gates pass."""
import io,json,hashlib,zipfile,pathlib,os,copy
D=pathlib.Path(os.environ.get('SOKNA_R299_WORKDIR','/mnt/data/sokna_release_review'))
SOURCE=D/'r298-out/SOKNA-R298-LOCAL-231FP-CUMULATIVE-NATIVE-QUALIFICATION-CANDIDATE.zip'
OUTPUT=D/'r299-out';OUTPUT.mkdir(parents=True,exist_ok=True)
PIN='a6326bdd1fa3e5a720379ee1716406ac62b08206bb9f878d6dcb854e7d2d3568'
VER='3.0.0-g6.2-workspace.231fq-r299-cumulative-controlled-install'
RID='sokna-r299-cumulative-controlled-release-20261119'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
TREE=lambda d:H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
CANON=lambda d:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in EXCLUDE).encode())
def pack(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for name,data in sorted(d.items()):
   info=zipfile.ZipInfo(name,(2026,11,19,14,15,0));info.compress_type=zipfile.ZIP_STORED;info.create_system=3;info.external_attr=0o100644<<16
   z.writestr(info,data)
 return b.getvalue()
assert H(SOURCE.read_bytes())==PIN,'Exact R298 source mismatch'
with zipfile.ZipFile(SOURCE) as outer:
 assert outer.testzip() is None
 meta=json.loads(outer.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  old={n:inner.read(n) for n in inner.namelist()}
assert len(old)==607 and TREE(old)=='f8f55e046a70f6366c2f51c467157b10257313141cf0375d42eb601e98a47d18'
assert meta['targets']['local']['migration_head']=='0083_remaining_account_discount'
old_migrations={k:H(v) for k,v in old.items() if k.startswith('database/migrations/')}
assert len(old_migrations)==83
new=old.copy();new['VERSION.txt']=(VER+'\n').encode()
prov=json.loads(new['resources/build-provenance.json'])
prov.update(version=VER,release_set_id=RID,classification='R299_CONTROLLED_INSTALL_NATIVE_CI_QUALIFIED_FIELD_ACCEPTANCE_REQUIRED',r298_native_ci='PASS_EXACT_R298_WINDOWS_MAINTENANCE_44539271420',r299_exact_native_ci='RELEASE_GATE_PENDING',production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False)
new['resources/build-provenance.json']=J(prov)
ident=json.loads(new['resources/release-identity-v1.json'])
ident.update(version=VER,release_set_id=RID)
ident['source'].update(canonical_tree_sha256=CANON(new),managed_file_count=607,canonical_file_count=605,source_ref='Exact R297 immutable financial source, R298 verified native + source metadata R299; no SQL/business logic modifications')
ident['qualification']['tier']='R299_CONTROLLED_INSTALL_NATIVE_CI_HOST_BACKUP_AND_FIELD_ACCEPTANCE_REQUIRED'
new['resources/release-identity-v1.json']=J(ident)
assert ident['source']['canonical_tree_sha256']==CANON(new)
assert set(json.loads(new['resources/local-managed-files-v1.json'])['files'])==set(new)
assert all(H(new[k])==v for k,v in old_migrations.items())
meta=copy.deepcopy(meta)
meta['release_set_id']=RID;meta['sequence']=202611190299;meta['created_at']='2026-11-19T14:15:00Z'
meta['status']='R299_LOCAL_CONTROLLED_INSTALL_NATIVE_VERIFIED_BACKUP_PREFLIGHT_REQUIRED'
meta['for_install']=True;meta['production_pass']=True
meta['qa_notes']['operator_install_allowed']=True
meta['qa_notes']['r299']='Version specific controlled Local upgrade from declared exact supported baselines; refuses mutation without backup + recovery snapshot.'
meta['qa_notes']['r299_windows']='RUN_EXACT_NATIVE_WINDOWS_R299_BEFORE_OPERATOR_DELIVERY'
meta['qa_notes']['r299_field']='NOT_AUTODEPLOYED; PRECHECK_INSTALLED_IDENTITY_AND_BACKUP_REQUIRED; PUBLIC_AND_PRINT_HARDWARE_FIELD_ACCEPTANCE_PENDING'
meta['qualification']['real_windows']=True
meta['qualification']['real_mariadb']=True
meta['qualification']['real_public_host']=False
meta['qualification']['clean_machine_remote_restore']=False
meta['targets']['local'].update(version=VER,release_id=RID,managed_tree_sha256=TREE(new),managed_files=len(new))
web=pack(new)
meta['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
out_bytes=pack({'release-set.json':J(meta),'artifacts/local/web.zip':web})
filename='SOKNA-R299-LOCAL-231FQ-CUMULATIVE-CONTROLLED-INSTALL.zip'
out=OUTPUT/filename;out.write_bytes(out_bytes)
with zipfile.ZipFile(out) as z:
 assert z.testzip() is None
 m=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  actual={n:inner.read(n) for n in inner.namelist()}
 assert TREE(actual)==m['targets']['local']['managed_tree_sha256']
 assert CANON(actual)==ident['source']['canonical_tree_sha256']
 assert all(H(actual[k])==v for k,v in old_migrations.items())
result={'schema':'sokna-r299-release-candidate-proof-v1','package':filename,'source_sha256':PIN,'sha256':H(out_bytes),'web_sha256':H(web),'managed_tree_sha256':TREE(new),'version':VER,'release_id':RID,'managed_files':607,'migrations':83,'migrations_unchanged':True,'supported_baselines':[x['local']['version'] for x in meta['supported_baselines']],'native_windows_final_zip':'PENDING','shared_factory_final_zip':'PENDING','production_host_mutated':False,'pre_update_backup_required':True,'field_acceptance':'PENDING'}
(OUTPUT/'R299-RELEASE-CANDIDATE-PROOF.json').write_bytes(J(result))
print(json.dumps(result,indent=2))