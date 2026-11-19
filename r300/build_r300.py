#!/usr/bin/env python3
"""Reproducible R300 qualification-only Local package from exact installed R299.

No API connections, no real host mutation, no migration changes. An operator
cannot treat this artifact as a field-install release without separate gates.
"""
from pathlib import Path
import os,io,json,hashlib,zipfile,tempfile,subprocess,copy

HERE=Path(__file__).resolve().parent
D=Path(os.environ.get('SOKNA_R300_WORKDIR','/mnt/data/sokna_r300'))
SOURCE=D/'source/SOKNA-R299-LOCAL-231FQ-CUMULATIVE-CONTROLLED-INSTALL.zip'
PATCH=HERE/'patches/R299-TO-R300-PUBLIC-MOVE-HARDENING.patch'
if not PATCH.exists():PATCH=HERE/'R299-TO-R300-PUBLIC-MOVE-HARDENING.patch'
OUTPUT=D/'output';OUTPUT.mkdir(parents=True,exist_ok=True)
PIN='8a897f3ac9215e06d72f67be292b11cf17263a0ab687d9b59d8bc341ac818507'
PATCH_PIN='f7dc82d6cbc98a85a8d3754fb2109177d34613f2bb9dfdb4d71424f7916d536a'
BASE_TREE='04e4bf1cc5c9c860835f4081a9f7f847f5fd9d9979f3d8558c5fcd3cee79517f'
VER='3.0.0-g6.2-workspace.231fr-r300-public-move-hardening'
RID='sokna-r300-public-move-hardening-20261119'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode('utf-8')
TREE=lambda d:H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
CANON=lambda d:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in EXCLUDE).encode())
def pack(rows):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for path,data in sorted(rows.items()):
   info=zipfile.ZipInfo(path,(2026,11,19,18,30,0))
   info.compress_type=zipfile.ZIP_STORED
   info.create_system=3
   info.external_attr=0o100644<<16
   z.writestr(info,data)
 return b.getvalue()
assert H(SOURCE.read_bytes())==PIN,'PINNED_R299_SOURCE_SHA_MISMATCH'
assert H(PATCH.read_bytes())==PATCH_PIN,'PATCH_SHA_MISMATCH'
with zipfile.ZipFile(SOURCE) as outer:
 assert outer.testzip() is None
 manifest=json.loads(outer.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  original={n:inner.read(n) for n in inner.namelist()}
assert len(original)==607 and TREE(original)==BASE_TREE,'PINNED_R299_MANAGED_TREE_MISMATCH'
assert manifest['targets']['local']['version']=='3.0.0-g6.2-workspace.231fq-r299-cumulative-controlled-install'
assert manifest['targets']['local']['migration_head']=='0083_remaining_account_discount'
old_sql={n:H(v) for n,v in original.items() if n.startswith('database/migrations/')}
assert len(old_sql)==83
with tempfile.TemporaryDirectory(prefix='sokna-r300-') as td:
 base=Path(td)
 for name,data in original.items():
  p=base/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
 subprocess.run(['patch','--batch','--forward','-p1','-i',str(PATCH)],cwd=base,check=True,stdout=subprocess.DEVNULL)
 modified={p.relative_to(base).as_posix():p.read_bytes() for p in base.rglob('*') if p.is_file()}
assert len(modified)==608,'MANAGED_FILE_ADDITION_NOT_EXACT'
expected={
 'public/integrations/api.php':'070d9d04117895596b24fd6acd6cfb76ca00b6d722848255ec8efa4e3a1a7187',
 'src/Domain/PublicEdge/PublicMoveUiInspectionService.php':'37b50d5d5c30bd906fd221fc025d70658e07473a056746c4963f5cbca7cd1388',
 'src/Domain/PublicEdge/PublicMoveFailureDiagnostic.php':'b3f520ea44f919b786ab4828fbb5b470c7f3d28b2f8f5a9de6d857b8ab6b72a5',
}
assert all(H(modified[n])==v for n,v in expected.items()),'PATCH_TARGET_SHA_MISMATCH'
assert all(H(modified[n])==h for n,h in old_sql.items()),'HISTORICAL_SQL_MODIFIED'
assert all(modified[n]==v for n,v in original.items() if n not in expected),'UNEXPECTED_SOURCE_CHANGE'

new=modified.copy()
new['VERSION.txt']=(VER+'\n').encode()
inv=json.loads(new['resources/local-managed-files-v1.json'])
inv['files']=sorted(new)
new['resources/local-managed-files-v1.json']=J(inv)
prov=json.loads(new['resources/build-provenance.json'])
prov.update(version=VER,release_set_id=RID,
 classification='R300_QA_ONLY_NATIVE_WINDOWS_AND_FULL_TRANSFER_FIELD_GATE_PENDING',
 r300_source='R299_EXACT_INSTALLED_8a897f3ac9',r300_change_scope='Public Move diagnostics + read-only health; no transfer authorization change',
 r300_installed_host_pass=False,production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False)
new['resources/build-provenance.json']=J(prov)
ident=json.loads(new['resources/release-identity-v1.json'])
ident.update(version=VER,release_set_id=RID)
ident['source'].update(canonical_tree_sha256=CANON(new),managed_file_count=608,canonical_file_count=606,
 source_ref='Exact SHA-pinned R299 Local plus audited R300 transfer hardening patch; Public R275 byte unchanged; SQL83 unchanged')
ident['qualification']['tier']='R300_QA_ONLY_NATIVE_WINDOWS_AND_REAL_CAFE_ACCEPTANCE_PENDING'
new['resources/release-identity-v1.json']=J(ident)
assert ident['source']['canonical_tree_sha256']==CANON(new)
assert set(json.loads(new['resources/local-managed-files-v1.json'])['files'])==set(new)
assert all(H(new[n])==h for n,h in old_sql.items())
manifest=copy.deepcopy(manifest)
old_target=copy.deepcopy(manifest['targets']['local'])
assert not any(b.get('local',{}).get('version')==old_target['version'] for b in manifest['supported_baselines'])
manifest['supported_baselines'].append({'local':old_target,'public':None})
manifest.update(release_set_id=RID,sequence=202611190300,created_at='2026-11-19T18:30:00Z',
 status='R300_NATIVE_QUALIFICATION_CANDIDATE_FIELD_HOLD',for_install=True,production_pass=True)
manifest['qa_notes'].update(operator_install_allowed=False,
 r300='R299 exact installed source, 608 files including 1 safe diagnostic module; no public, print, finance or SQL mutation',
 r300_field='NO_GO_FOR_CAFE_INSTALL_UNTIL_EXACT_PACKAGE_NATIVE_WINDOWS_AND_R299_R275_REAL_HTTP_TESTS_PASS',
 r300_evidence='R299/R275 existing paired E2E: GitHub 44554104686, disposable only; this package not field-tested')
manifest['qualification']['real_windows']=True # inherited qualification permits disposable native engine; no field approval
manifest['qualification']['real_public_host']=False
manifest['targets']['local'].update(version=VER,release_id=RID,managed_tree_sha256=TREE(new),managed_files=608)
web=pack(new)
manifest['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
blob=pack({'release-set.json':J(manifest),'artifacts/local/web.zip':web})
name='SOKNA-R300-LOCAL-231FR-PUBLIC-MOVE-QA-NOT-FIELD-INSTALL.zip'
out=OUTPUT/name;out.write_bytes(blob)
with zipfile.ZipFile(out) as z:
 assert z.testzip() is None
 m=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  final={n:inner.read(n) for n in inner.namelist()}
 assert TREE(final)==m['targets']['local']['managed_tree_sha256']
 assert CANON(final)==ident['source']['canonical_tree_sha256']
assert set(original)-set(final)==set()
assert set(final)-set(original)=={'src/Domain/PublicEdge/PublicMoveFailureDiagnostic.php'}
assert all(H(final[n])==h for n,h in old_sql.items())
proof={'schema':'sokna-r300-transfer-hardened-candidate-v1','source_package_sha256':PIN,
 'patch_sha256':PATCH_PIN,'package':name,'sha256':H(blob),'web_sha256':H(web),'managed_tree_sha256':TREE(final),
 'version':VER,'release_set_id':RID,'managed_files':608,'migrations':83,'historical_sql_unchanged':True,
 'operator_install_allowed':False,'production_host_modified':False,
 'exact_final_zip_native_windows':'PENDING','exact_final_zip_r299_r275_http_mariadb':'PENDING','public_host_field_validation':'PENDING'}
(OUTPUT/'R300-CANDIDATE-PROOF.json').write_bytes(J(proof))
(D/'source-tree').mkdir(parents=True,exist_ok=True)
for n,v in final.items():
 p=D/'source-tree'/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(v)
print(json.dumps(proof,indent=2))
