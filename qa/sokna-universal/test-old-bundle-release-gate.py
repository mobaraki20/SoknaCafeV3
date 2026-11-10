#!/usr/bin/env python3
"""Fail-closed regression: the 2026-11-09 three-file collection is NOT a
universal transactional installer. QA only; reads exact tracked ZIPs.
Do not allow the successful tests of independent components to release it.
"""
import hashlib,io,json,pathlib,zipfile
P=pathlib.Path(__file__).resolve().parents[1]/'sokna-public-two-route'
sha=lambda b:hashlib.sha256(b).hexdigest()
def inspected(path):
 with zipfile.ZipFile(path) as z:
  assert z.testzip() is None
  m=json.loads(z.read('manifest.json'))
  version=z.read('payload/VERSION.txt').decode().strip()
  owner=json.loads(z.read('payload/src/Update/emergency-owner-v1.json'))
  impl=sha(z.read('payload/src/Update/EmergencyOwnerCandidate.php'))
 return {'zip':sha(path.read_bytes()),'manifest_version':m['version'],
  'source_version':version,'owner_version':owner['release_version'],
  'owner_sha_valid':owner['sha256']==impl}
old=inspected(P/'r149.zip')
fixed=inspected(P/'candidate.zip')
assert old['zip']=='8bcd797b25584d122733242ab16c5b2679503f62aea4d5bca97d4458db52d913'
assert old['manifest_version']==old['source_version']
assert old['owner_version']!=old['source_version'],'STALE_OWNER_REGRESSION_NOT_REPRODUCED'
assert fixed['manifest_version']==fixed['source_version']==fixed['owner_version']
assert fixed['owner_sha_valid'] is True,'FIXED_OWNER_CODE_NOT_BOUND'
# Values extracted from original 85,195,272-byte Library ZIP verified by
# the independent byte-level audit. The original archive is not reuploaded.
archived={
 'outer_sha256':'679028f92b03e57f4eb729824fd575b6c56401ace0858ff16fd74abbcb14a848',
 'public_sha256':'8bcd797b25584d122733242ab16c5b2679503f62aea4d5bca97d4458db52d913',
 'maintenance_sha256':'3a4c6e1c09174bf79aa29115588a2f6ba8eddfa58929d54a61a528cbd4feca2a',
 'components_are_separate':True,
 'shared_commit_or_restore_proof':False,
 'maintenance_recovery_baseline':'0.1.3',
 'last_observed_cafe_recovery':'0.1.1',
 'maintenance_production_approved':False,
}
blockers=[
 'NOT_A_TRANSACTIONAL_UNIVERSAL_INSTALLER' if archived['components_are_separate'] else None,
 'UNIVERSAL_SHARED_COMMIT_ROLLBACK_PROOF_ABSENT' if not archived['shared_commit_or_restore_proof'] else None,
 'MAINTENANCE_OBSERVED_RECOVERY_BASELINE_UNSUPPORTED' if archived['maintenance_recovery_baseline']!=archived['last_observed_cafe_recovery'] else None,
 'MAINTENANCE_NOT_PRODUCTION_QUALIFIED' if not archived['maintenance_production_approved'] else None,
 'PUBLIC_OWNER_RELEASE_VERSION_STALE' if old['owner_version']!=old['source_version'] else None,
]
blockers=sorted(x for x in blockers if x)
assert len(blockers)==5,'ONE_OF_FIVE_DEPLOYMENT_BLOCKERS_NOT_DETECTED'
assert archived['public_sha256']==old['zip'],'ARCHIVED_PUBLIC_SOURCE_NOT_THE_TESTED_ONE'
assert archived['public_sha256']!=fixed['zip'],'CORRECTED_PUBLIC_IS_NOT_IN_OLD_BUNDLE'
report={'format':'sokna-universal-release-qa-negative-v1','scope':'OLD_BUNDLE_RELEASE_BLOCKER',
 'old_bundle_sha256':archived['outer_sha256'],'public_original':old,'public_corrected_qa':fixed,
 'blockers':blockers,'release_authorized':False,'field_approval':False,
 'host_access_required_for_this_test':False,
 'next_gate':'Cross-component prepare, shared lease, coordinated activate/commit and recovery on both starting versions, plus physical backup approval'}
path=pathlib.Path(__file__).with_name('release-blockers-safe.json')
path.write_text(json.dumps(report,ensure_ascii=False,sort_keys=True,indent=2)+'\n')
print('PASS OLD_UNIVERSAL_BUNDLE_REJECTED_WITH_5_EVIDENCED_BLOCKERS')
print('PASS CORRECTED_PUBLIC_OWNER_EXISTS_ONLY_IN_SEPARATE_QA_CANDIDATE')
print(json.dumps({'blockers':blockers,'release_authorized':False},sort_keys=True))
