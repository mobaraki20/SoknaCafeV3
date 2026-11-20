#!/usr/bin/env python3
"""Exact R304 35im installed baseline -> R306 35in protected HTTP Emergency fixture."""
import hashlib,os,pathlib,shutil
P=pathlib.Path
base=P(os.environ['R306_CI_ARTIFACTS']);run=P(os.environ['RUNNER_TEMP']);out=run/'r306-public-input';out.mkdir(exist_ok=True)
files=[
 ('d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c','SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip'),
 ('37242dabf6893bfc4bf26e8233485802c197ea8b742ae264b8aed9e0971f0d21','SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip'),
 ('e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51','SOKNA-R306-PUBLIC-35IN-EMERGENCY-PREFLIGHT.zip')
]
for digest,name in files:
 matches=[p for p in base.rglob('*.zip') if hashlib.sha256(p.read_bytes()).hexdigest()==digest]
 if len(matches)!=1:raise RuntimeError(f'R306_PUBLIC_INPUT_SHA_INVALID_{name}_{len(matches)}')
 shutil.copyfile(matches[0],out/name)
 print('R306_PINNED_PUBLIC_INPUT',name,digest)
repo=P(__file__).resolve().parents[2]
orig=(repo/'r276/tests/emergency_r275_to_r276.py').read_text()
updates={
'SOKNA-R276-PUBLIC-35IK-OWNER-SETUP-QA-DO-NOT-INSTALL.zip':'SOKNA-R306-PUBLIC-35IN-EMERGENCY-PREFLIGHT.zip',
'effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d':'e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51',
'3.0.0-g3.6-workspace.35ik-r276-owner-setup-access':'3.0.0-g3.6-workspace.35in-r306-local-public-ui-parity',
'35ik-r276-owner-setup-access':'35in-r306-local-public-ui-parity',
'R276_PROTECTED_EMERGENCY_35IJ_TO_35IK_STAGE_ACTIVATE_COMMIT_PASS':'R306_PROTECTED_EMERGENCY_35IM_TO_35IN_STAGE_ACTIVATE_COMMIT_PASS',
}
for old,new in updates.items():
 if old not in orig:raise RuntimeError('R306_EMERGENCY_TARGET_SUBSTITUTION_MISSING '+old)
 orig=orig.replace(old,new)
direct_changes={
"unpack(R/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip')":"unpack(R/'SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip')",
"'active_version':'3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate'":"'active_version':'3.0.0-g3.6-workspace.35im-r304-controlled-preparation'",
"'active_managed_tree_sha256':'1a5d041fd63ec5425ed9f01f0eff63c86c90c5c76f26530b773784c74884c928'":"'active_managed_tree_sha256':'3055050385f5e3133e86bd58248e4935c5dd378826b371036ef35229734afe27'",
"if(count($a)!==8)throw new RuntimeException('Expected R249 eight migrations')":"if(count($a)!==11)throw new RuntimeException('Expected R304 eleven migrations')",
}
for old,new in direct_changes.items():
 if orig.count(old)!=1:raise RuntimeError('R306_DIRECT_R304_FIXTURE_EXPECTED_ONCE '+old)
 orig=orig.replace(old,new)
a=orig.index('    # HTTP rejection of legacy R262 while R249 is authoritative.')
b=orig.index('    r276Package=R/',a)
assert a<b and b-a>1700
orig=orig[:a]+'    # Direct R304 35im baseline: 244 original files and 11 migrations before protected HTTP upgrade.\n'+orig[b:]
script=run/'r306-public-direct-http-qa.py';script.write_text(orig)
print('R306_DIRECT_R304_TO_R306_PROTECTED_EMERGENCY_HTTP_FIXTURE',script)
