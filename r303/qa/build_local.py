from zipfile import ZipFile,ZipInfo,ZIP_STORED
from pathlib import Path
import hashlib,json,copy,io,os
SHA=lambda b:hashlib.sha256(b).hexdigest()
work=Path(os.environ['R303_CI_WORK']);root=work/'local'
base=Path(os.environ['R303_CI_BASE_LOCAL'])
with ZipFile(base) as z:
 release=json.loads(z.read('release-set.json'));old_zip=z.read('artifacts/local/web.zip')
with ZipFile(io.BytesIO(old_zip)) as z:old={n:z.read(n) for n in z.namelist() if not n.endswith('/')}
new={x.relative_to(root).as_posix():x.read_bytes() for x in root.rglob('*') if x.is_file()}
assert len(old)==len(new)==608 and set(old)==set(new)
TREE=lambda d:SHA(''.join(f'{p}\0{SHA(b)}\n' for p,b in sorted(d.items())).encode())
CANON=lambda d:SHA(''.join(f'{p}\0{len(b)}\0{SHA(b)}\n' for p,b in sorted(d.items()) if p not in ('resources/build-provenance.json','resources/release-identity-v1.json')).encode())
assert TREE(old)==release['targets']['local']['managed_tree_sha256']=='159b20c33d9b8d92a0a53f5ab1a91bcf8ca60e6a446b836636d66dbd4213193a'
initial_changes=sorted(p for p in old if old[p]!=new[p]); assert len(initial_changes)==11,initial_changes
assert all(not p.startswith('database/migrations/') for p in initial_changes)
version='3.0.0-g6.2-workspace.231ft-r303-ui-and-report-qa'
new['VERSION.txt']=(version+'\n').encode()
JB=lambda v:(json.dumps(v,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
identity=json.loads(new['resources/release-identity-v1.json'])
identity['version']=version;identity['release_set_id']='sokna-r303-ui-and-report-qa-20261120'
identity['source'].update(canonical_file_count=606,canonical_tree_sha256=CANON(new),managed_file_count=608,source_ref='R301 exact ZIP source + 11 R302 QA changes; MariaDB and field tests PENDING')
identity['qualification']={'tier':'QA_ONLY_NOT_INSTALL','claim_rule':'No cafe install until MariaDB, Public Emergency, native Windows and factory exact-byte gates pass'}
new['resources/release-identity-v1.json']=JB(identity)
provenance=json.loads(new['resources/build-provenance.json']);provenance.update({'classification':'R303_QA_NOT_INSTALLABLE','created_at':'2026-11-20T00:00:00Z','full_business_acceptance':False,'installed_host_accepted':False,'production_host_install_verified':False,'operator_preflight_required':True,'r303_status':'QA_ONLY_MARIADB_NATIVE_WINDOWS_PRINT_PUBLIC_PENDING','r303_changed_files':initial_changes,'r303_migrations_delta':0})
new['resources/build-provenance.json']=JB(provenance)
release['release_set_id']='sokna-r303-ui-and-report-qa-20261120';release['created_at']='2026-11-20T00:00:00Z';release['sequence']=202611200303
release['targets']['local'].update(version=version,release_id=release['release_set_id'],managed_tree_sha256=TREE(new),managed_files=608)
release['status']='QA_ONLY_MARIADB_WINDOWS_EMERGENCY_GATES_PENDING';release['for_install']=False;release['production_pass']=False
release['qa_notes']['r303']='11 R302 UI/report changes plus version/provenance, no migrations; tests local JS/PHP/synthetic SQL; not installable'
release['qualification'].update(real_mariadb=False,real_windows=False,real_public_host=False,canary=False,business_backup_consistency_real_mariadb=False)
release['supported_baselines']=[{'local':{'version':old['VERSION.txt'].decode().strip(),'managed_tree_sha256':TREE(old)}}]
release['signature']=None

def zipbytes(data):
 buf=io.BytesIO()
 with ZipFile(buf,'w') as z:
  for p,b in sorted(data.items()):
   i=ZipInfo(p,(2026,11,20,0,0,0));i.compress_type=ZIP_STORED;i.create_system=3;i.external_attr=0o100644<<16
   z.writestr(i,b,compress_type=ZIP_STORED)
 return buf.getvalue()
web=zipbytes(new)
release['artifacts']['local-web'].update(sha256=SHA(web),size_bytes=len(web))
outer=zipbytes({'release-set.json':JB(release),'artifacts/local/web.zip':web})
out=work/'out/SOKNA-R303-LOCAL-231FT-QA-NOT-INSTALL.zip';out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(outer)
with ZipFile(out) as z:
 assert z.testzip() is None
 manifest=json.loads(z.read('release-set.json'))
 assert manifest['for_install'] is False and manifest['production_pass'] is False
 assert SHA(z.read('artifacts/local/web.zip'))==manifest['artifacts']['local-web']['sha256']
 with ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
  assert w.testzip() is None
  newfiles={p:w.read(p) for p in w.namelist() if not p.endswith('/')}
  assert TREE(newfiles)==manifest['targets']['local']['managed_tree_sha256'] and len(newfiles)==608
  assert CANON(newfiles)==json.loads(newfiles['resources/release-identity-v1.json'])['source']['canonical_tree_sha256']
  changed=sorted(p for p in old if old[p]!=newfiles[p]);assert sorted(changed)==sorted(initial_changes+['VERSION.txt','resources/release-identity-v1.json','resources/build-provenance.json'])
proof={'schema':'sokna-r303-local-qa-candidate-v1','status':'QA_ONLY_DO_NOT_INSTALL','source_package':base.name,'source_sha256':SHA(base.read_bytes()),'source_web_sha256':SHA(old_zip),'source_tree':TREE(old),'target_version':version,'target_zip':out.name,'target_sha256':SHA(outer),'target_web_sha256':SHA(web),'target_tree':TREE(new),'managed_files':608,'changed_files':changed,'migrations_modified':False,'migrations_count':83,'php_js_unit_tests':'PASS','SQLite_query_shape':'PASS_NOT_MARIADB','native_windows':'NOT_RUN','real_mariadb':'NOT_RUN','Public_emergency_qa':'STATIC_AND_BROWSER_ONLY','field_install':'NOT_DONE'}
(work/'out/SOKNA-R303-LOCAL-QA-VERIFY.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
print('Local Candidate',out,'sha',proof['target_sha256'],'changed',len(changed))
