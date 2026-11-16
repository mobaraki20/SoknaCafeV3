#!/usr/bin/env python3
"""R263: clean install only, with protected static Public runtime files.

The real R262 update payload intentionally excludes src/Emergency and
resources/update-trust-v1.json to avoid replacing machine-bound credentials.
An EMPTY new host nevertheless needs the static code + empty trust template.
Do not add them to the cumulative update ZIP.
"""
import argparse
import hashlib
import io
import json
import pathlib
import re
import zipfile

SOURCE_PIN='156ddccf01e0755c2d94a6a88442523c02d1e6a63e815089be30a1e50f56616e'
OUTPUT='SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip'
H=lambda data:hashlib.sha256(data).hexdigest()
EXTRA={
 'src/Emergency/EmergencyAccessService.php',
 'src/Emergency/PairingSecretStore.php',
 'src/Emergency/PublicInitialPairingService.php',
 'src/Emergency/PublicTakeoverService.php',
 'src/Emergency/PublicUpdateException.php',
 'src/Emergency/PublicUpdateService.php',
 'resources/update-trust-v1.json'
}
def build(source,repo,out):
 source=pathlib.Path(source);repo=pathlib.Path(repo);out=pathlib.Path(out)
 assert H(source.read_bytes())==SOURCE_PIN,'NOT_PINNED_R262_CLEAN'
 with zipfile.ZipFile(source) as z:
  assert z.testzip() is None
  data={x:z.read(x) for x in z.namelist() if not x.endswith('/')}
 assert len(data)==244 and not set(data)&EXTRA,'WRONG_R262_CLEAN_SOURCE'
 before={x:H(v) for x,v in data.items()}
 for name in sorted(EXTRA):
  f=repo/'apps/public'/name
  assert f.is_file(),'MISSING_PROTECTED_STATIC_SOURCE: '+name
  data[name]=f.read_bytes()
 assert len(data)==251
 assert data['VERSION.txt'].decode().strip()=='3.0.0-g3.6-workspace.35id-r262-public-move'
 assert all(H(data[n])==digest for n,digest in before.items()),'MANAGED_R262_SOURCE_MODIFIED'
 assert not any(x in data for x in ['config.php','install.lock','emergency.php','.env'])
 assert not any(x.startswith('storage/') for x in data)
 trust=json.loads(data['resources/update-trust-v1.json'])
 assert trust.get('ed25519_public_keys')=={},'NONEMPTY_BUNDLED_RELEASE_TRUST'
 # The R262 PHP bootstrap must be COMPLETE on its own on a zero-file host.
 bootstrap=data['bootstrap.php'].decode()
 for path in re.findall(r"require_once __DIR__ \. '/([^']+)'",bootstrap):
  assert path in data, 'CLEAN_BOOTSTRAP_MISSING_REQUIRED_FILE:'+path
 assert len([p for p in data if p.startswith('database/migrations/') and p.endswith('.sql')])==11
 out.mkdir(parents=True,exist_ok=True)
 archive=out/OUTPUT
 with zipfile.ZipFile(archive,'w') as z:
  for name in sorted(data):
   info=zipfile.ZipInfo(name,(2026,11,16,0,0,0))
   info.compress_type=zipfile.ZIP_DEFLATED
   info.external_attr=0o100644<<16
   z.writestr(info,data[name],compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
 with zipfile.ZipFile(archive) as z:
  assert z.testzip() is None and len(z.namelist())==251
  assert all(H(z.read(n))==digest for n,digest in before.items())
 proof={
  'format':'sokna-r263-selfcontained-public-clean-v1',
  'base_r262_clean_sha256':SOURCE_PIN,
  'target_zip_sha256':H(archive.read_bytes()),
  'managed_r262_files_byte_preserved':244,
  'additional_protected_static_files':sorted(EXTRA),
  'total_files':251,'migrations':11,
  'private_user_config_included':False,'stored_credentials_included':False,
  'old_public_data_copied':False,'full_host_deployment_confirmed':False,
  'operator_production_install_allowed':False,
  'exact_release_version':data['VERSION.txt'].decode().strip(),
  'note':'Source ZIP for genuine fresh Public host; operator production approval is separate.'
 }
 (out/'R263-PUBLIC-CLEAN-SELFCONTAINED-PROOF.json').write_text(json.dumps(proof,indent=2,ensure_ascii=False)+'\n')
 print('R263_PUBLIC_SELFCONTAINED_CLEAN_PACKAGE_BUILT',json.dumps(proof))
if __name__=='__main__':
 p=argparse.ArgumentParser()
 for name in ['source','repo','out']:p.add_argument('--'+name,required=True)
 args=p.parse_args()
 build(args.source,args.repo,args.out)
