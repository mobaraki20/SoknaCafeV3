#!/usr/bin/env python3
"""Build exact R298 Local native qualification candidate from approved R297 QA bytes.
Never treat generation alone as operator authorization. No live host activity.
"""
import io,json,hashlib,zipfile,pathlib,os,copy
BASE=pathlib.Path(os.environ['SOKNA_R298_WORKDIR'])
SOURCE=BASE/'output/SOKNA-R297-LOCAL-231FO-CUMULATIVE-CANDIDATE-NOT-INSTALL.zip'
OUTPUT=BASE/'r298-out';OUTPUT.mkdir(parents=True,exist_ok=True)
PIN='dc91ddc34d97e0ae500d9f61bd8151c8be531fec11d9f6fcdf3b938ca6f2ceaf'
VER='3.0.0-g6.2-workspace.231fp-r298-cumulative-native-candidate'
RID='sokna-r298-cumulative-native-qualification-20261119'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode('utf-8')
TREE=lambda d:H(''.join(n+'\0'+H(v)+'\n' for n,v in sorted(d.items())).encode())
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
CANON=lambda d:H(''.join(n+'\0'+str(len(v))+'\0'+H(v)+'\n' for n,v in sorted(d.items()) if n not in EXCLUDE).encode())
def zpack(rows):
  b=io.BytesIO()
  with zipfile.ZipFile(b,'w') as z:
    for name,blob in sorted(rows.items()):
      info=zipfile.ZipInfo(name,(2026,11,19,13,30,0));info.compress_type=zipfile.ZIP_STORED;info.create_system=3;info.external_attr=0o100644<<16
      z.writestr(info,blob)
  return b.getvalue()
assert H(SOURCE.read_bytes())==PIN, 'R297 SHA mismatch'
with zipfile.ZipFile(SOURCE) as outer:
  assert outer.testzip() is None
  manifest=json.loads(outer.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
    assert inner.testzip() is None
    files={n:inner.read(n) for n in inner.namelist()}
assert len(files)==607 and TREE(files)=='44f62caccfc87d18283556d75db05e29a4aa3cbc7077bbe37f50e716a59d3c1d'
pre_sql={k:H(v) for k,v in files.items() if k.startswith('database/migrations/')}
assert len(pre_sql)==83
assert set(json.loads(files['resources/local-managed-files-v1.json'])['files'])==set(files)
assert manifest['database']['local']['migration_dag'][-1]=='0083_remaining_account_discount'
assert manifest['targets']['local']['migration_head']=='0083_remaining_account_discount'
assert not manifest['for_install'] and not manifest['production_pass']
new=files.copy()
new['VERSION.txt']=(VER+'\n').encode()
prov=json.loads(new['resources/build-provenance.json'])
prov.update(version=VER,release_set_id=RID,classification='R298_NATIVE_LAB_CANDIDATE_FIELD_NOT_VERIFIED',r298_native_ci='RUN_REQUIRED_ON_EXACT_ZIP',production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False)
new['resources/build-provenance.json']=J(prov)
ident=json.loads(new['resources/release-identity-v1.json'])
ident.update(version=VER,release_set_id=RID)
ident['source'].update(canonical_tree_sha256=CANON(new),managed_file_count=607,canonical_file_count=605,source_ref='Exact pinned R297 cumulative 607-file source plus release identity, no SQL/finance changes')
ident['qualification']['tier']='R298_NATIVE_WINDOWS_LAB_CANDIDATE_FIELD_NOT_VERIFIED'
new['resources/release-identity-v1.json']=J(ident)
assert ident['source']['canonical_tree_sha256']==CANON(new)
assert set(json.loads(new['resources/local-managed-files-v1.json'])['files'])==set(new)
assert all(H(new[k])==v for k,v in pre_sql.items())
manifest=copy.deepcopy(manifest)
assert all(b['local']['version']!=VER for b in manifest['supported_baselines'])
manifest['release_set_id']=RID
manifest['sequence']=202611190298
manifest['created_at']='2026-11-19T13:30:00Z'
manifest['status']='R298_LOCAL_NATIVE_WINDOWS_LAB_CANDIDATE_FIELD_PENDING'
# QA candidate may invoke update engine in disposable Windows CI.
# This does not grant authority to publish or install on an operational host.
manifest['for_install']=True;manifest['production_pass']=True
manifest['qa_notes']['operator_install_allowed']=False
manifest['qa_notes']['r298_native']='UNVERIFIED_PENDING_RUN_ON_THIS_EXACT_ARCHIVE'
manifest['qa_notes']['r298_field']='REAL_CAFE_HOST_BACKUP_AND_READBACK_NOT_PROVEN; PAIRING_AND_PRINTER_PROOFS_PENDING'
manifest['qa_notes']['r297_windows']='SUPERSEDED_BY_EXACT_R298_NATIVE_CANDIDATE_GATE_PENDING'
manifest['qualification']['real_windows']=True
manifest['qualification']['real_public_host']=False
manifest['qualification']['clean_machine_remote_restore']=False
manifest['targets']['local'].update(version=VER,release_id=RID,managed_tree_sha256=TREE(new),managed_files=len(new))
web=zpack(new)
manifest['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
blob=zpack({'release-set.json':J(manifest),'artifacts/local/web.zip':web})
name='SOKNA-R298-LOCAL-231FP-CUMULATIVE-NATIVE-QUALIFICATION-CANDIDATE.zip'
out=OUTPUT/name;out.write_bytes(blob)
with zipfile.ZipFile(out) as z:
 assert z.testzip() is None
 r=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  check={n:inner.read(n) for n in inner.namelist()}
 assert TREE(check)==r['targets']['local']['managed_tree_sha256']
 assert CANON(check)==ident['source']['canonical_tree_sha256']
proof={'schema':'sokna-r298-native-qualification-candidate-v1','candidate':'NOT_FOR_FIELD_INSTALL_UNTIL_NATIVE_AND_HOST_ACCEPTANCE','source_sha256':PIN,'candidate_sha256':H(blob),'candidate_web_sha256':H(web),'managed_tree_sha256':TREE(new),'files':len(new),'migrations':len(pre_sql),'historical_and_new_sql_unchanged':True,'native_windows_maintenance':'PENDING','real_public_and_print':'PENDING','field_approval':'NOT_GRANTED','version':VER,'release_set_id':RID}
(OUTPUT/'R298-CANDIDATE-PROOF.json').write_bytes(J(proof))
print(json.dumps(proof,indent=2))
