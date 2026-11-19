#!/usr/bin/env python3
"""Prepare SHA-pinned historical public fixtures and a target-specific R303 test script."""
import hashlib,os,pathlib,shutil
P=pathlib.Path
base=P(os.environ['R303_CI_ARTIFACTS']);run=P(os.environ['RUNNER_TEMP']);out=run/'r303-public-input';out.mkdir(exist_ok=True)
files=[
 ('5c887968b6fcec1126e2b1b3123552a9ba0ba2c7672d29774c05ec58fa5cd0e4','SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip'),
 ('5aef4cd781fbd450d3e454f324e294da6f4180bfe1e5eb1871cb4818dc20c93a','SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip'),
 ('692aa40939d788aea56efa4e3b62c46a568da0ce5c508cc87844aa63366b8892','SOKNA-R265-PUBLIC-35IF-EMERGENCY-BRIDGE-CODE-ONLY-QA.zip'),
 ('b6d4d2236ab3da126a4070d75802a9cac6719c139a188808d1712b8c1827bd35','SOKNA-R266-PUBLIC-35IG-SCHEMA-MOVE-CUMULATIVE-QA.zip'),
 ('63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f','SOKNA-R275-PUBLIC-35IJ-OWNER-DISCARD-CODE-ONLY-QA-DO-NOT-INSTALL.zip'),
 ('37242dabf6893bfc4bf26e8233485802c197ea8b742ae264b8aed9e0971f0d21','SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip'),
 ('a5c5743e1804dbd10282b399916ae0cd8399aa71dbf79bdf62a4deeef1291afe','SOKNA-R303-PUBLIC-35IL-EMERGENCY-UI-QA-NOT-INSTALL.zip')]
for digest,name in files:
 matches=[p for p in base.rglob('*.zip') if hashlib.sha256(p.read_bytes()).hexdigest()==digest]
 if len(matches)!=1:raise RuntimeError(f'R303_PUBLIC_INPUT_SHA_INVALID_{name}_{len(matches)}')
 shutil.copyfile(matches[0],out/name)
 print('PINNED_DISPOSABLE_PUBLIC_INPUT',name,digest)
repo=P(__file__).resolve().parents[2]
orig=(repo/'r276/tests/emergency_r275_to_r276.py').read_text()
updates={
'SOKNA-R276-PUBLIC-35IK-OWNER-SETUP-QA-DO-NOT-INSTALL.zip':'SOKNA-R303-PUBLIC-35IL-EMERGENCY-UI-QA-NOT-INSTALL.zip',
'effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d':'a5c5743e1804dbd10282b399916ae0cd8399aa71dbf79bdf62a4deeef1291afe',
'3.0.0-g3.6-workspace.35ik-r276-owner-setup-access':'3.0.0-g3.6-workspace.35il-r303-public-preparation-emergency-qa',
'35ik-r276-owner-setup-access':'35il-r303-public-preparation-emergency-qa',
'R276_PROTECTED_EMERGENCY_35IJ_TO_35IK_STAGE_ACTIVATE_COMMIT_PASS':'R303_PROTECTED_EMERGENCY_35IJ_TO_35IL_STAGE_ACTIVATE_COMMIT_PASS',
}
for old,new in updates.items():
 if old not in orig:raise RuntimeError('R303_EMERGENCY_TEST_SUBSTITUTION_NOT_FOUND '+old)
 orig=orig.replace(old,new)
script=run/'r303-public-http-qa.py';script.write_text(orig)
print('R303_EMERGENCY_HTTP_TEST_FROM_HISTORICAL_PINNED_FIXTURE',script)
