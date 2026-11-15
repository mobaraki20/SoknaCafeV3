#!/usr/bin/env python3
"""Offline exact R201 -> R239 payload replay for disposable CI only.

The generated QA package remains NON-INSTALLABLE.  The optional EPHEMERAL
Windows-stage harness package never leaves CI and must never be distributed.
"""
import argparse, base64, copy, gzip, hashlib, io, json, pathlib, subprocess, tempfile, zipfile

Q_SHA='1dab7e39905a48179eeb19f9dbd88bc51a02af098eaf780a93168e3a4d34b2e1'
BASE_SHA='911ea673dc1863bee3ce372dbc692602aa3a32439d2fc9ccba69dc2a6c243897'
SOURCE_TREE='139899065a6d40e1fcf814020c52951fed40445f626190c8819a07fdd6a66e25'
VERSION='3.0.0-g6.2-workspace.231fd-r239-authority-recovery-qa'

def H(data):return hashlib.sha256(data).hexdigest()
def J(v):return (json.dumps(v,sort_keys=True,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def zipbytes(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,'w') as z:
  for name,value in sorted(d.items()):
   info=zipfile.ZipInfo(name,(2026,11,15,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
   z.writestr(info,value,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 return b.getvalue()
def tree(d):return H(''.join(k+'\0'+H(v)+'\n' for k,v in sorted(d.items())).encode())

def replay(base,parts,out):
 assert H(base.read_bytes())==BASE_SHA,'R201_BASELINE_SHA_MISMATCH'
 with zipfile.ZipFile(base) as z:
  manifest=json.loads(z.read('release-set.json'))
  with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as w:
   old={n:w.read(n) for n in w.namelist()}
 assert len(old)==590 and tree(old)==manifest['targets']['local']['managed_tree_sha256'],'R201_MANAGED_IDENTITY_INVALID'
 parts=sorted(q for p in parts for q in (p.parent.glob(p.name) if '*' in p.name else [p]))
 assert len(parts)==5,'EXPECTED_FIVE_PINNED_PATCH_CHUNKS'
 patch=gzip.decompress(base64.b64decode(''.join(''.join(p.read_text().split()) for p in parts)))
 assert H(patch)=='480b7bdcb74979e3003e97185bcbb78c24dc0eb3186e8cb0ffdfe0698b9fccc3','PINNED_PATCH_SHA_INVALID'
 with tempfile.TemporaryDirectory(prefix='sokna-r239-replay-') as td:
  work=pathlib.Path(td)
  for name,data in old.items():
   if name.startswith('/') or '..' in pathlib.PurePosixPath(name).parts:raise RuntimeError('ZIP_TRAVERSAL')
   p=work/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
  patch_file=work.parent/'r239-qa-apply.patch';patch_file.write_bytes(patch)
  try:
   subprocess.run(['git','apply','--binary',str(patch_file)],cwd=work,check=True)
  finally:patch_file.unlink(missing_ok=True)
  d={p.relative_to(work).as_posix():p.read_bytes() for p in work.rglob('*') if p.is_file()}
 assert len(d)==593 and tree(d)==SOURCE_TREE and d['VERSION.txt'].decode().strip()==VERSION,'R239_MANAGED_TREE_INVALID'
 # Keep exactly the qualified R239 QA outer contract; no production claims.
 m=copy.deepcopy(manifest)
 m.update(release_set_id='sokna-r239-local-public-recovery-qa-only-20261115',release_type='local_only',sequence=202611150239,
          created_at='2026-11-15T09:15:00Z',status='R239_QA_CONTRACT_ONLY_DO_NOT_INSTALL',for_install=False,production_pass=False)
 m['qualification']={k:False if k in ('real_windows','real_public_host','clean_machine_remote_restore','recovery_console_web_down','remote_vault_real_host','business_backup_consistency_real_mariadb') else v for k,v in m['qualification'].items()}
 m['qualification']['canary']=True;m['qualification']['real_mariadb']=True
 m['qa_notes']={
  'R239_tested_real_mariadb_11_4':'https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43698272021',
  'scope':'ONLY_RECOVERY_CODE_MARIADB_TESTED_NOT_FULL_APP_OR_WINDOWS_PACKAGE',
  'origin_installed_local':'R201_231fa_SUPPORT_SNAPSHOT',
  'public_host':'R233_35hz_QA_IN_USE_NO_EMERGENCY_OWNER',
  'windows_stage_activate_readback_rollback':'NOT_TESTED',
  'public_https_end_to_end':'NOT_TESTED',
  'legacy_emergency_owner':'35ch imported for QA, unsafe without release trust key and owner verification',
  'additional_install_safety':'NO_PRODUCTION_DEPLOYMENT_OR_USER_INSTALL'}
 m['supported_baselines']=[{'local':copy.deepcopy(manifest['targets']['local']),'public':None}]
 m['targets']['local'].update(managed_files=len(d),managed_tree_sha256=tree(d),version=VERSION,
      release_id='sokna-r239-authority-recovery-qa-not-installable',authority_epoch_policy='safe_credential_rotation_and_fenced_resync')
 inner=zipbytes(d)
 m['artifacts']['local-web'].update(sha256=H(inner),size_bytes=len(inner))
 qa=zipbytes({'release-set.json':J(m),'artifacts/local/web.zip':inner})
 assert H(qa)==Q_SHA,'QA_ZIP_NOT_BYTE_IDENTICAL'
 out.mkdir(parents=True,exist_ok=True)
 (out/'R239-QA-BYTE-IDENTICAL.zip').write_bytes(qa)
 checks={'source_files':593,'source_tree_sha256':SOURCE_TREE,'r201_sha256':BASE_SHA,'qa_package_sha256':Q_SHA,'target_version':VERSION,
         'ci_stage_mutation':False,'qa_only':True}
 # Only an isolated disposable machine is allowed to use the next variant;
 # never upload, publish, or offer it to the operator as an installer.
 ci=copy.deepcopy(m)
 ci['for_install']=True;ci['production_pass']=True;ci['status']='R239_WINDOWS_EPHEMERAL_CI_STAGE'
 for k in ('real_windows','business_backup_consistency_real_mariadb','recovery_console_web_down'):ci['qualification'][k]=True
 ci['qa_notes']['ephemeral'] = 'CI_ONLY_ON_DISPOSABLE_WINDOWS_RUNNER_NO_EXPORT_NO_USER_INSTALL'
 b=zipbytes({'release-set.json':J(ci),'artifacts/local/web.zip':inner})
 (out/'EPHEMERAL-WINDOWS-R239-STAGE.zip').write_bytes(b)
 (out/'CI-SHA256.txt').write_text(H(b)+'\n')
 (out/'R239-PROOF.json').write_text(json.dumps(checks,indent=2)+'\n')
 print('PASS_PINNED_SOURCE_REPLAY_R239',json.dumps(checks))

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--base',type=pathlib.Path,required=True);p.add_argument('--parts',type=pathlib.Path,nargs='+',required=True);p.add_argument('--out',type=pathlib.Path,required=True)
 a=p.parse_args();replay(a.base,a.parts,a.out)