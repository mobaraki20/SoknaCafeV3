#!/usr/bin/env python3
"""Deterministic R241 release candidate derived from byte-pinned R240 Rev3.
Requires independent Windows validation of these exact final bytes before issuing to operator.
Only changes version/release metadata, preserving all R240-tested business and recovery code.
"""
import argparse,collections,copy,hashlib,io,json,pathlib,zipfile
SRC_SHA='39e8374b20fbd90c39ecdfe9d352943350abfe9c327a999ddcb6501b3a545e1a'
FINAL_VERSION='3.0.0-g6.2-workspace.231ff-r241-connection-recovery'
RELEASE_ID='sokna-r241-local-cafe-231fa-authority-recovery-20261115'
NAME='SOKNA-R241-LOCAL-231FA-CUMULATIVE-CONNECTION-RECOVERY.zip'
EXCLUDE={'resources/release-identity-v1.json','resources/build-provenance.json'}
def H(b):return hashlib.sha256(b).hexdigest()
def J(x):return (json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def Z(d):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w') as z:
  for n,b in sorted(d.items()):
   i=zipfile.ZipInfo(n,(2026,11,15,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return out.getvalue()
def tree(d):return H(''.join(n+'\0'+H(b)+'\n' for n,b in sorted(d.items())).encode())
def canonical(d):return H(''.join(n+'\0'+str(len(b))+'\0'+H(b)+'\n' for n,b in sorted(d.items()) if n not in EXCLUDE).encode())
def build(parent,out):
 assert H(parent.read_bytes())==SRC_SHA,'INVALID_R240_PARENT'
 with zipfile.ZipFile(parent) as z:
  assert z.testzip() is None
  m=json.loads(z.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as src:
   assert src.testzip() is None
   d={n:src.read(n) for n in src.namelist()}
 assert len(d)==593 and d['VERSION.txt'].decode().strip()=='3.0.0-g6.2-workspace.231fe-r240-managed-inventory-recovery-qa'
 orig=copy.deepcopy(d)
 d['VERSION.txt']=(FINAL_VERSION+'\n').encode()
 ident=json.loads(d['resources/release-identity-v1.json']);prov=json.loads(d['resources/build-provenance.json'])
 prov['classification']='R241_NATIVE_RELEASE_CONTROLLED_OPERATOR_INSTALL_PENDING_HOST_ACCEPTANCE'
 prov['release_set_id']=RELEASE_ID;prov['version']=FINAL_VERSION
 prov['full_business_acceptance']=False;prov['installed_host_accepted']=False;prov['production_host_install_verified']=False
 prov['r241_native_windows_scope']='NATIVE_CI_PREPARE_STAGE_ACTIVATE_HTTP_LEDGER_NO_CAFE_HOST_AUTO_DEPLOY'
 prov['source']='Pinned original R201 231fa + R239 authority recovery + R240 inventory and release identity'
 d['resources/build-provenance.json']=J(prov)
 ident['version']=FINAL_VERSION;ident['release_set_id']=RELEASE_ID
 ident['source']['canonical_tree_sha256']=canonical(d)
 ident['source']['managed_file_count']=len(d)
 ident['source']['canonical_file_count']=len(d)-len(EXCLUDE)
 ident['source']['source_ref']='R201 exact 231fa + R239 real MariaDB recovery + R240 native Windows pass + R241 release metadata'
 d['resources/release-identity-v1.json']=J(ident)
 assert ident['source']['canonical_tree_sha256']==canonical(d)
 assert set(n for n in d if orig[n]!=d[n])=={'VERSION.txt','resources/build-provenance.json','resources/release-identity-v1.json'}
 assert len(json.loads(d['resources/local-managed-files-v1.json'])['files'])==len(d)==593
 assert sorted(json.loads(d['resources/local-managed-files-v1.json'])['files'])==sorted(d)
 inner=Z(d)
 m=copy.deepcopy(m)
 m['release_set_id']=RELEASE_ID
 m['sequence']=202611150241
 m['status']='R241_OPERATOR_CONTROLLED_RELEASE'
 m['for_install']=True;m['production_pass']=True
 m['created_at']='2026-11-15T10:15:00Z'
 m['targets']['local'].update(version=FINAL_VERSION,release_id=RELEASE_ID,managed_files=593,managed_tree_sha256=tree(d),authority_epoch_policy='preserved')
 m['artifacts']['local-web'].update(sha256=H(inner),size_bytes=len(inner))
 m['qualification'].update(real_windows=True,real_mariadb=True,business_backup_consistency_real_mariadb=True,recovery_console_web_down=True)
 m['qa_notes']['release_process']='Native Windows stage/activate/HTTP/readback and isolated MariaDB recovery proven; host installation and actual business DB acceptance pending operator run'
 m['qa_notes']['base']='Exact R201 231fa release id/tree only; fail-closed for unknown baselines'
 m['qa_notes']['business_backup_evidence']='Inherited R185/R186 exact business backup and 81-migration CI suite; no production cafe data copied'
 m['qa_notes']['emergency']='Public Emergency bootstrap is separate; do not pair until public hotfix is verified'
 m['qa_notes']['r241_final_ci']='Exact final ZIP requires independent Windows qualification before operator delivery'
 assert m['database']['local']['rollback_after_boundary']=='forbidden'
 assert len(m['supported_baselines'])==1
 assert m['supported_baselines'][0]['local']['version']=='3.0.0-g6.2-workspace.231fa-r201-cash-refund-cancel-canary'
 out.mkdir(exist_ok=True,parents=True)
 payload=Z({'release-set.json':J(m),'artifacts/local/web.zip':inner})
 f=out/NAME;f.write_bytes(payload)
 with zipfile.ZipFile(f) as z:
  assert z.testzip() is None
  man=json.loads(z.read('release-set.json'))
  assert man['for_install'] and man['production_pass']
  assert H(z.read('artifacts/local/web.zip'))==man['artifacts']['local-web']['sha256']
 proof={'release_zip_sha256':H(payload),'release_set_id':RELEASE_ID,'version':FINAL_VERSION,'managed_files':593,'source_tree_sha256':tree(d),'canonical_sha256':canonical(d),'prior_source_unchanged':590,'new_release_for_install':True,'needs_exact_zip_windows_test':True,'previous_r240_sha256':SRC_SHA,'host_acceptance':False}
 (out/'R241-RELEASE-BUILD-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(proof,ensure_ascii=False))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--parent',type=pathlib.Path,required=True);p.add_argument('--out',type=pathlib.Path,required=True);a=p.parse_args();build(a.parent,a.out)