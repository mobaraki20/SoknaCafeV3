#!/usr/bin/env python3
"""Prepare SHA-pinned historical public fixtures and a target-specific R304 test script."""
import hashlib,os,pathlib,shutil
P=pathlib.Path
base=P(os.environ['R304_CI_ARTIFACTS']);run=P(os.environ['RUNNER_TEMP']);out=run/'r304-public-input';out.mkdir(exist_ok=True)
files=[
 ('63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f','SOKNA-R275-PUBLIC-35IJ-OWNER-DISCARD-CODE-ONLY-QA-DO-NOT-INSTALL.zip'),
 ('37242dabf6893bfc4bf26e8233485802c197ea8b742ae264b8aed9e0971f0d21','SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip'),
 ('d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c','SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip')
]
for digest,name in files:
 matches=[p for p in base.rglob('*.zip') if hashlib.sha256(p.read_bytes()).hexdigest()==digest]
 if len(matches)!=1:raise RuntimeError(f'R304_PUBLIC_INPUT_SHA_INVALID_{name}_{len(matches)}')
 shutil.copyfile(matches[0],out/name)
 print('PINNED_DISPOSABLE_PUBLIC_INPUT',name,digest)
repo=P(__file__).resolve().parents[2]
orig=(repo/'r276/tests/emergency_r275_to_r276.py').read_text()
updates={
'SOKNA-R276-PUBLIC-35IK-OWNER-SETUP-QA-DO-NOT-INSTALL.zip':'SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip',
'effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d':'d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c',
'3.0.0-g3.6-workspace.35ik-r276-owner-setup-access':'3.0.0-g3.6-workspace.35im-r304-controlled-preparation',
'35ik-r276-owner-setup-access':'35im-r304-controlled-preparation',
'R276_PROTECTED_EMERGENCY_35IJ_TO_35IK_STAGE_ACTIVATE_COMMIT_PASS':'R304_PROTECTED_EMERGENCY_35IJ_TO_35IM_STAGE_ACTIVATE_COMMIT_PASS',
}
for old,new in updates.items():
 if old not in orig:raise RuntimeError('R304_EMERGENCY_TEST_SUBSTITUTION_NOT_FOUND '+old)
 orig=orig.replace(old,new)
# Replace the historical R249→R265→R266→R275 bootstrap chain with the
# exact installed R275 file tree and a real 11-migration schema. This makes the
# test directly exercise the actual upgrade from 35ij to 35im.
direct_changes={
"unpack(R/'SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip')": "unpack(R/'SOKNA-R275-PUBLIC-35IJ-OWNER-DISCARD-CODE-ONLY-QA-DO-NOT-INSTALL.zip')",
"'active_version':'3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate'": "'active_version':'3.0.0-g3.6-workspace.35ij-r275-operator-confirm-public-discard'",
"'active_managed_tree_sha256':'1a5d041fd63ec5425ed9f01f0eff63c86c90c5c76f26530b773784c74884c928'": "'active_managed_tree_sha256':'91d27d4ddb0fdd0640227b8efed461cde94c7efc3c578d52623056dcb859d57c'",
"if(count($a)!==8)throw new RuntimeException('Expected R249 eight migrations')": "if(count($a)!==11)throw new RuntimeException('Expected R275 eleven migrations')"
}
for old,new in direct_changes.items():
 if orig.count(old)!=1:raise RuntimeError('R304_DIRECT_R275_FIXTURE_EXPECTED_ONCE '+old)
 orig=orig.replace(old,new)
a=orig.index('    # HTTP rejection of legacy R262 while R249 is authoritative.')
b=orig.index('    r276Package=R/',a)
assert a<b and b-a>1700
orig=orig[:a]+'    # R275 baseline bytes and 11 migrations pre-seeded before protected HTTP test.\n'+orig[b:]
script=run/'r304-public-direct-http-qa.py';script.write_text(orig)
print('R304_DIRECT_R275_TO_R304_PROTECTED_EMERGENCY_HTTP_FIXTURE',script)

print('R304_EMERGENCY_HTTP_TEST_FROM_HISTORICAL_PINNED_FIXTURE',script)
