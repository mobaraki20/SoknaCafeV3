#!/usr/bin/env python3
"""Deterministic R301 controlled Local build from exact R300 code QA, no live host access.
The R301 artifact may be staged by maintenance only after exact native and factory gates;
production field acceptance remains pending until end-to-end actual café readback.
"""
import os,io,json,hashlib,zipfile,copy,pathlib
D=pathlib.Path(os.environ.get('SOKNA_R301_WORKDIR','/mnt/data/sokna_r301'))
SOURCE=D/'source/SOKNA-R300-LOCAL-231FR-PUBLIC-MOVE-QA-NOT-FIELD-INSTALL.zip'
OUT=D/'output';OUT.mkdir(parents=True,exist_ok=True)
PIN='40be5ce560c699fab1c86522ee61c12198f30ab248674df74388924ed5faeb1a'
PIN_TREE='b43dca4463c0c62e42e4298de4b6c148478912ec6cc2c4d5dcec32def8e4b6d5'
VER='3.0.0-g6.2-workspace.231fs-r301-public-move-controlled-install'
RID='sokna-r301-public-move-controlled-release-20261119'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
TREE=lambda d:H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
CANON=lambda d:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in EXCLUDE).encode())
def pack(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for n,v in sorted(d.items()):
   info=zipfile.ZipInfo(n,(2026,11,19,19,0,0));info.compress_type=zipfile.ZIP_STORED
   info.create_system=3;info.external_attr=0o100644<<16;z.writestr(info,v)
 return b.getvalue()
assert H(SOURCE.read_bytes())==PIN,'EXACT_PINNED_R300_ARTIFACT_NOT_FOUND'
with zipfile.ZipFile(SOURCE) as z:
 assert z.testzip() is None
 m=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
  assert w.testzip() is None
  old={n:w.read(n) for n in w.namelist()}
assert len(old)==608 and TREE(old)==PIN_TREE
assert m['targets']['local']['version']=='3.0.0-g6.2-workspace.231fr-r300-public-move-hardening'
assert m['targets']['local']['migration_head']=='0083_remaining_account_discount'
assert set(json.loads(old['resources/local-managed-files-v1.json'])['files'])==set(old)
old_sql={n:H(v) for n,v in old.items() if n.startswith('database/migrations/')}
assert len(old_sql)==83
n=old.copy();n['VERSION.txt']=(VER+'\n').encode()
p=json.loads(n['resources/build-provenance.json'])
p.update(version=VER,release_set_id=RID,
 classification='R301_CONTROLLED_INSTALL_SHA_PINNED_NATIVE_GATES_REQUIRED_CAFE_FIELD_ACCEPTANCE_PENDING',
 source_r300_sha256=PIN, operator_preflight_required=True, production_host_install_verified=False,
 installed_host_accepted=False, full_business_acceptance=False,
 r301_exact_native_windows='PENDING',r301_exact_r275_public_qa='PENDING')
n['resources/build-provenance.json']=J(p)
i=json.loads(n['resources/release-identity-v1.json'])
i.update(version=VER,release_set_id=RID)
i['source'].update(canonical_tree_sha256=CANON(n),managed_file_count=608,canonical_file_count=606,
 source_ref='Exact factory verified R300 transfer diagnostics and health classification; 83 migrations unchanged; no Public artifact')
i['qualification']['tier']='R301_NATIVE_WINDOWS_AND_FACTORY_GATES_REQUIRED_BEFORE_CAFE_FIELD'
n['resources/release-identity-v1.json']=J(i)
assert CANON(n)==i['source']['canonical_tree_sha256']
assert set(json.loads(n['resources/local-managed-files-v1.json'])['files'])==set(n)
assert all(H(n[x])==h for x,h in old_sql.items())
assert all(n[x]==v for x,v in old.items() if x not in {'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}),'UNEXPECTED_PRODUCT_LOGIC_MUTATION'
meta=copy.deepcopy(m)
meta.update(release_set_id=RID,sequence=202611190301,created_at='2026-11-19T19:00:00Z',
 status='R301_CONTROLLED_LOCAL_UPDATE_BACKUP_PREFLIGHT_REQUIRED',for_install=True,production_pass=True)
meta['qa_notes'].update(operator_install_allowed=True,
 r301='Exact patch R299 to R300 source; only metadata and VERSION changed for controlled install',
 r301_field='Only if authenticated Windows Maintenance preflight, recoverable backup and snapshot succeed; real Public owner+FullSync+QR acceptance remains separate',
 r301_public='Public R275 untouched; do not assume menu is a working guest order relay',
 r301_windows='EXACT_FINAL_R301_NATIVE_TEST_AND_FACTORY_BOTH_MANDATORY',
 r301_r300_qa='R300 exact R299->R300 Windows and R300/R275 root+subfolder+resync real HTTP CI separately verified')
meta['qualification']['real_windows']=True
meta['qualification']['real_mariadb']=True
meta['qualification']['real_public_host']=False
meta['qualification']['clean_machine_remote_restore']=False
meta['targets']['local'].update(version=VER,release_id=RID,managed_tree_sha256=TREE(n),managed_files=608)
web=pack(n)
meta['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
blob=pack({'release-set.json':J(meta),'artifacts/local/web.zip':web})
name='SOKNA-R301-LOCAL-231FS-PUBLIC-MOVE-CONTROLLED-INSTALL.zip'
f=OUT/name;f.write_bytes(blob)
with zipfile.ZipFile(f) as z:
 assert z.testzip() is None
 v=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
  assert w.testzip() is None
  final={t:w.read(t) for t in w.namelist()}
 assert TREE(final)==v['targets']['local']['managed_tree_sha256']
 assert CANON(final)==i['source']['canonical_tree_sha256']
assert all(H(final[x])==h for x,h in old_sql.items())
proof={'schema':'sokna-r301-controlled-release-candidate-v1',
 'source_sha256':PIN,'source_tree_sha256':PIN_TREE,'package':name,'sha256':H(blob),
 'web_sha256':H(web),'managed_tree_sha256':TREE(n),
 'version':VER,'release_id':RID,'files':608,'migrations':83,'sql_unchanged':True,
 'R301_exact_native_windows':'PENDING','R301_factory':'PENDING',
 'R301_R275_full_http_resync':'PENDING','production_host_modified':False,
 'field_after_install_QR_acceptance':'PENDING'}
(OUT/'R301-INTEGRITY-AND-GATES.json').write_bytes(J(proof))
print(json.dumps(proof,ensure_ascii=False,indent=2))
