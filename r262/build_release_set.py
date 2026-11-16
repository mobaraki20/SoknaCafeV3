#!/usr/bin/env python3
"""R262 exact cumulative Local candidate; never change updater or Windows services.
Build after exact R241 source preservation and real Local→Public E2E pass.
CI native Windows must qualify the *exact* output SHA before operator release.
"""
from __future__ import annotations
import argparse,copy,hashlib,io,json,pathlib,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
VERSION='3.0.0-g6.2-workspace.231fg-r262-public-move-qa'
RELEASE_ID='sokna-r262-local-universal-public-replacement-20261116'
NAME='SOKNA-R262-LOCAL-231FG-CUMULATIVE-PUBLIC-MOVE-NATIVE-UAT-UNQUALIFIED.zip'
EXCLUDE={'resources/build-provenance.json','resources/release-identity-v1.json'}
def J(o):return (json.dumps(o,sort_keys=True,separators=(',',':'),ensure_ascii=False)+'\n').encode()
def tree(d):return H(''.join(n+'\0'+H(b)+'\n' for n,b in sorted(d.items())).encode())
def canonical(d):return H(''.join(n+'\0'+str(len(b))+'\0'+H(b)+'\n' for n,b in sorted(d.items()) if n not in EXCLUDE).encode())
def zip_d(files):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w') as z:
  for n,b in sorted(files.items()):
   i=zipfile.ZipInfo(n,(2026,11,16,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16
   z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return out.getvalue()
def build(r241,merged,out):
 r241=pathlib.Path(r241);out=pathlib.Path(out);merged=pathlib.Path(merged)
 assert H(r241.read_bytes())=='2ec84cff9481ca0350dea9401c5b1bb1437f29e7e96f1d1c80f0739f8c7c992b','INCORRECT_R241_BASE'
 with zipfile.ZipFile(r241) as z:
  m=json.loads(z.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
   original={n:w.read(n) for n in w.namelist()}
 d={p.relative_to(merged).as_posix():p.read_bytes() for p in merged.rglob('*') if p.is_file()}
 assert len(original)==593 and len(d)==604
 assert d['VERSION.txt'].decode().strip()==VERSION
 assert [x for x in original if x not in d]==[]
 assert all(original[x]==d[x] for x in original if x.startswith('database/migrations/'))
 assert 'database/migrations/0082_public_move_owner_approval.sql' in d
 files=json.loads(d['resources/local-managed-files-v1.json'])
 files['files']=sorted(d)
 d['resources/local-managed-files-v1.json']=J(files)
 prov=json.loads(d['resources/build-provenance.json'])
 prov.update(classification='R262_NATIVE_WINDOWS_TEST_PENDING_DO_NOT_INSTALL',release_set_id=RELEASE_ID,version=VERSION,
             source='Exact pinned R241 Windows-qualified 593 files plus 7 targeted Public move edits, 10 new isolated classes and forward-only 0082 SQL',
             full_business_acceptance=False,installed_host_accepted=False,production_host_install_verified=False,
             r262_native_windows_scope='PENDING_FULL_ACTIVATE_READBACK_FROM_ACTUAL_R241_NO_REAL_CAFE')
 d['resources/build-provenance.json']=J(prov)
 ident=json.loads(d['resources/release-identity-v1.json'])
 ident['version']=VERSION;ident['release_set_id']=RELEASE_ID
 ident['baseline']['source_versions']=sorted({m['targets']['local']['version'],*(x['local']['version'] for x in m['supported_baselines'])})
 ident['source']['canonical_tree_sha256']=canonical(d)
 ident['source']['managed_file_count']=len(d)
 ident['source']['canonical_file_count']=len(d)-len(EXCLUDE)
 ident['source']['source_ref']='R241 actual 231ff + real Public R249 owner-approved replacement R262'
 ident['qualification']['tier']='R262_NATIVE_WINDOWS_CI_PENDING_HOST_NO_GO'
 d['resources/release-identity-v1.json']=J(ident)
 assert ident['source']['canonical_tree_sha256']==canonical(d)
 assert sorted(json.loads(d['resources/local-managed-files-v1.json'])['files'])==sorted(d)
 inner=zip_d(d)
 old_target=copy.deepcopy(m['targets']['local'])
 m['release_set_id']=RELEASE_ID;m['sequence']=202611160262;m['status']='R262_NATIVE_WINDOWS_PENDING_HOST_ACCEPTANCE'
 m['created_at']='2026-11-16T08:00:00Z'
 # Native stage testing can only run when the manifest accepts staging.
 # Operator release remains explicitly blocked regardless of this CI fixture.
 m['for_install']=True;m['production_pass']=True
 m['supported_baselines'].append({'local':old_target,'public':None})
 m['targets']['local'].update(version=VERSION,release_id=RELEASE_ID,managed_files=len(d),managed_tree_sha256=tree(d),
                             migration_head='0082_public_move_owner_approval',authority_epoch_policy='preserved')
 m['database']['local']['migration_dag'].append('0082_public_move_owner_approval')
 m['artifacts']['local-web'].update(sha256=H(inner),size_bytes=len(inner))
 m['qa_notes']['r262_new_source']='Only 7 modified R241 base files plus 11 additions; all 81 historical SQL files unchanged'
 m['qa_notes']['r262_native_windows']='PENDING';m['qa_notes']['r262_public_host_install']='NOT_DEPLOYED'
 m['qa_notes']['release_process']='R262 final SHA must pass real Native Windows and actual host readback before any operator installation. No changes to update engine.'
 assert len(m['supported_baselines'])==2
 payload=zip_d({'release-set.json':J(m),'artifacts/local/web.zip':inner})
 out.mkdir(parents=True,exist_ok=True);p=out/NAME;p.write_bytes(payload)
 proof={'format':'sokna-r262-source-and-release-set-candidate-v1','candidate_sha256':H(payload),
        'inner_sha256':H(inner),'source_tree_sha256':tree(d),'canonical_tree_sha256':canonical(d),
        'managed_files':len(d),'migrations':82,'historical_sql_byte_identical':True,
        'supported_baselines':[x['local']['version'] for x in m['supported_baselines']],
        'unqualified':True,'operator_install_allowed':False,'native_windows_gate':'NOT_RUN','host_gate':'NOT_RUN',
        'release_set_id':RELEASE_ID,'version':VERSION,'path':str(p)}
 (out/'R262-RELEASE-BUILD-PROOF.json').write_text(json.dumps(proof,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(proof,ensure_ascii=False,indent=2))
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--parent',required=True);p.add_argument('--merged',required=True);p.add_argument('--out',required=True)
 a=p.parse_args();build(a.parent,a.merged,a.out)