#!/usr/bin/env python3
"""Repackage only after a successful native Windows QA gate. No new app logic.
Byte-exact R277 QA source -> metadata-qualified controlled-install candidate.
The app tree identity and outer manifest both re-hashed deterministically.
"""
from pathlib import Path
import hashlib, json, zipfile,io,os,copy
root=Path(os.environ.get('R277_NATIVE_ROOT','/tmp/sokna-r277-native'))
qa=root/'SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-QA-DO-NOT-INSTALL.zip'
assert hashlib.sha256(qa.read_bytes()).hexdigest()=='46381c48eb0266b861e27aa020bbef9a19da325d26b41304e10139b0fee747c5'
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda o:(json.dumps(o,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
with zipfile.ZipFile(qa) as outer:
 release=json.loads(outer.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  old={n:inner.read(n) for n in inner.namelist()}
assert len(old)==604
new=old.copy()
provenance=json.loads(old['resources/build-provenance.json'])
provenance.update(classification='R277_NATIVE_WINDOWS_ACCEPTED_FIELD_PENDING',production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False)
new['resources/build-provenance.json']=J(provenance)
identity=json.loads(old['resources/release-identity-v1.json'])
identity['qualification']['tier']='R277_NATIVE_WINDOWS_ACCEPTED_CONTROLLED_INSTALL'
new['resources/release-identity-v1.json']=J(identity)
# Identity canonical digest excludes the two mutable self-referential metadata files.
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
canonical=H(''.join(p+'\0'+str(len(v))+'\0'+H(v)+'\n' for p,v in sorted(new.items()) if p not in EXCLUDE).encode())
assert canonical==identity['source']['canonical_tree_sha256']
assert sorted(p for p in old if old[p]!=new[p])==sorted(EXCLUDE)
def z(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as zz:
  for name,payload in sorted(d.items()):
   zi=zipfile.ZipInfo(name,(2026,11,18,0,0,0));zi.compress_type=zipfile.ZIP_STORED;zi.external_attr=0o100644<<16
   zz.writestr(zi,payload,compress_type=zipfile.ZIP_STORED)
 return b.getvalue()
tree=H(''.join(n+'\0'+H(content)+'\n' for n,content in sorted(new.items())).encode())
release['status']='R277_LOCAL_NATIVE_WINDOWS_VERIFIED_CONTROLLED_INSTALL'
release['targets']['local']['managed_tree_sha256']=tree
release['qa_notes']['operator_install_allowed']=True
release['qa_notes']['r277']='Verified exact R275 managed source, R277 finance source, safe discount and native Windows Maintenance Phase Stage + Activate tested in QA. Cafe live acceptance pending.'
release['qa_notes']['r277_field']='MUST_VERIFY_ON_REAL_CAFE_BEFORE_PRODUCTION_CLOSE'
release['qa_notes']['windows_stage_activate_readback_rollback']='STAGE_ACTIVATE_HTTP_DB82_PASS; ROLLBACK_NOT_SIMULATED'
web=z(new)
release['artifacts']['local-web'].update(sha256=H(web),size_bytes=len(web))
assert release['targets']['local']['managed_files']==604
name='SOKNA-R277-LOCAL-231FM-CUMULATIVE-FINANCE-SAFETY-INSTALL-READY.zip'
out=root/name
out.write_bytes(z({'release-set.json':J(release),'artifacts/local/web.zip':web}))
with zipfile.ZipFile(out) as outer:
 assert outer.testzip() is None
 assert json.loads(outer.read('release-set.json'))['targets']['local']['managed_tree_sha256']==tree
 with zipfile.ZipFile(io.BytesIO(outer.read('artifacts/local/web.zip'))) as inner:
  assert inner.testzip() is None
  assert len(inner.namelist())==604
(root/'target.json').write_text(json.dumps(release['targets']['local']))
proof={'from_version':release['supported_baselines'][-1]['local']['version'],'to_version':release['targets']['local']['version'],'final_package':name,'final_sha256':H(out.read_bytes()),'final_managed_tree':tree,'qa_parent_sha256':H(qa.read_bytes()),'sql_migrations':82,'source_logic_delta':'NONE','field_accepted':False}
(root/'r277-release-proof.json').write_text(json.dumps(proof,indent=2,ensure_ascii=False)+'\n')
print('R277_NATIVE_RELEASE_CANDIDATE_SHA256',proof['final_sha256'])
print('R277_NATIVE_RELEASE_CANDIDATE_TREE',tree)
print('R277_NATIVE_RELEASE_CANDIDATE_READY_FOR_EXACT_NATIVE_GATE')
