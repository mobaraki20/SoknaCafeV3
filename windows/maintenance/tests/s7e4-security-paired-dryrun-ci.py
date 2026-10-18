#!/usr/bin/env python3
"""F1-S7E4: executable negative contracts for backup custody & Public pairing.

CI ONLY. Neither verifier returns authority, changes files, talks to Public, nor
validates production key custody. Exact staged signature verification remains
owned by ReleaseSetVerifierStager / independent Public Maintenance Kernel.
"""
from __future__ import annotations

import copy
import hashlib
import hmac
import json
import os
import re


class Rejected(Exception):
    pass


def require(ok: bool, reason: str) -> None:
    if not ok:
        raise Rejected(reason)


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def public_backup_receipt(doc: dict, sample_cipher: bytes, verify_key: bytes) -> dict:
    """A negative-only policy check, NOT a trusted remote-vault implementation."""
    require(doc.get('format') == 'sokna-business-backup-evidence-v1', 'BACKUP_FORMAT')
    require(re.fullmatch(r'[0-9a-f]{32}', str(doc.get('backup_id', ''))) is not None, 'BACKUP_ID')
    require(doc.get('backup_kind') == 'business_backup', 'BACKUP_KIND')
    require(doc.get('state') == 'REMOTE_VERIFIED', 'BACKUP_NOT_REMOTE_VERIFIED')
    require(doc.get('consistency') == 'transactionally_consistent', 'BACKUP_INCONSISTENT')
    require(doc.get('excluded_machine_authority') is True, 'BACKUP_MACHINE_AUTHORITY_PRESENT')
    require(doc.get('payload_format') == 'ciphertext_only', 'BACKUP_PLAINTEXT')
    require(doc.get('ciphertext_sha256') == digest(sample_cipher) and len(sample_cipher) > 32,
            'BACKUP_CIPHERTEXT_MISMATCH')
    require(doc.get('algorithm') == 'AES-256-GCM', 'BACKUP_AEAD_REQUIRED')
    require(doc.get('random_unique_dek_verified') is True, 'BACKUP_NON_UNIQUE_DEK')
    require(re.fullmatch(r'[-A-Za-z0-9_.]{1,64}', str(doc.get('key_id', ''))) is not None,
            'BACKUP_KEY_ID')
    require(doc.get('remote_vault_has_decrypt_key') is False, 'BACKUP_REMOTE_CAN_DECRYPT')
    require(doc.get('local_scheduled_backup_unattended') is True, 'BACKUP_REQUIRES_MANUAL_PASSWORD')
    require(doc.get('local_key_protection') in ('windows_dpapi', 'protected_service_key_store'),
            'BACKUP_LOCAL_KEY_NOT_PROTECTED')
    require(doc.get('independent_recovery_key_proof') is True,
            'BACKUP_INDEPENDENT_RECOVERY_KEY_NOT_VERIFIED')
    require(doc.get('last_recovery_drill_verified') is True, 'BACKUP_RECOVERY_NOT_PROVED')
    require(doc.get('vault_outside_webroot') is True, 'BACKUP_WEB_EXPOSED')
    require(doc.get('upload_role') == 'append_only' and doc.get('upload_may_delete') is False,
            'BACKUP_UPLOAD_CAN_DELETE')
    require(doc.get('preserves_last_known_good') is True, 'BACKUP_RETENTION_UNSAFE')
    require(doc.get('provider_independent') is False and doc.get('protection_tier') == 'R1',
            'BACKUP_TIER_MISLABELED')
    require(doc.get('storage_separate_from_app_release') is True, 'BACKUP_RELEASE_OVERWRITE')
    require(doc.get('receipt_authority') == 'remote_vault_kernel', 'BACKUP_FAKE_REMOTE_RECEIPT')
    require(doc.get('key_material_in_artifact') is False, 'BACKUP_KEY_IN_ARTIFACT')
    require(doc.get('recovery_material_in_application_tree') is False, 'BACKUP_KEY_IN_APP')
    receipt = doc.get('receipt')
    require(isinstance(receipt, dict) and set(receipt) == {'backup_id', 'cipher_sha256', 'signature'},
            'BACKUP_RECEIPT_FIELDS')
    require(receipt['backup_id'] == doc['backup_id'] and
            receipt['cipher_sha256'] == doc['ciphertext_sha256'], 'BACKUP_RECEIPT_BINDING')
    body = (receipt['backup_id'] + '\n' + receipt['cipher_sha256']).encode()
    expected = hmac.new(verify_key, b'SOKNA-REMOTE-VAULT-CI-ONLY\n' + body,
                        hashlib.sha256).hexdigest()
    require(hmac.compare_digest(str(receipt['signature']), expected), 'BACKUP_RECEIPT_SIGNATURE')
    # This is an untrusted, test-only statement of preflight eligibility.
    return {'cipher_sha256': digest(sample_cipher), 'production_key_custody_qualified': False,
            'real_remote_provider_qualified': False, 'panel_update_ready': False}


def preflight_public_pair(doc: dict) -> dict:
    """Strict compatibility gate: no Public write/commit or distributed atomicity claim."""
    require(doc.get('format') == 'sokna-public-pair-inspection-v1', 'PAIR_FORMAT')
    require(doc.get('release_set_signature_verified') is True and
            doc.get('artifacts_verified') is True, 'PAIR_SIGNATURE')
    require(doc.get('public_kernel_signature_verified') is True, 'PAIR_KERNEL_UNTRUSTED')
    require(doc.get('public_installed_from') == 'authoritative_kernel_journal',
            'PAIR_CACHED_PUBLIC_IDENTITY')
    require(doc.get('local_installed_from') == 'authoritative_supervisor_journal',
            'PAIR_CACHED_LOCAL_IDENTITY')
    require(doc.get('exact_local_base_verified') is True and
            doc.get('exact_public_base_verified') is True, 'PAIR_BASE_MISMATCH')
    require(doc.get('install_generation_verified') is True, 'PAIR_EPOCH_UNVERIFIED')
    require(doc.get('package_sequence') > doc.get('prior_sequence') >= 0,
            'PAIR_ANTI_ROLLBACK')
    require(doc.get('same_signed_release_set_id') is True, 'PAIR_RELEASE_ID_MISMATCH')
    require(doc.get('public_kernel_capability_verified') is True,
            'PAIR_PUBLIC_KERNEL_NOT_QUALIFIED')
    require(doc.get('authority_epoch') == doc.get('expected_authority_epoch'),
            'PAIR_AUTHORITY_EPOCH_MISMATCH')
    require(doc.get('old_epoch_queue_quarantined') is True, 'PAIR_REPLAYABLE_OLD_QUEUE')
    mode = doc.get('release_type')
    require(mode in ('public_only', 'local_only', 'paired'), 'PAIR_RELEASE_MODE')
    if mode != 'local_only':
        require(doc.get('public_update_ready') is True, 'PAIR_PUBLIC_STAGE_NOT_READY')
    if mode != 'public_only':
        require(doc.get('local_update_ready') is True, 'PAIR_LOCAL_STAGE_NOT_READY')
    require(doc.get('wan_reachable') is True, 'PAIR_WAN_UNREACHABLE')
    require(doc.get('resume_journal_present') is True, 'PAIR_JOURNAL_MISSING')
    require(doc.get('declared_protocol_window') is True, 'PAIR_PROTOCOL_INCOMPATIBLE')
    require(doc.get('guest_mutation_policy') in ('queue', 'read_only', 'block'),
            'PAIR_GUEST_WRITES_UNFENCED')
    require(doc.get('staff_mutation_policy') in ('queue', 'read_only', 'block'),
            'PAIR_STAFF_WRITES_UNFENCED')
    if 'queue' in (doc.get('guest_mutation_policy'), doc.get('staff_mutation_policy')):
        require(doc.get('queue_guarantee_verified') is True, 'PAIR_QUEUE_GUARANTEE_MISSING')
    require(doc.get('local_drain_observed') is True, 'PAIR_LOCAL_NOT_DRAINED')
    require(doc.get('forward_only_after_db_intent') is True,
            'PAIR_UNSAFE_POST_DDL_ROLLBACK')
    require(doc.get('automatic_db_rollback') is False, 'PAIR_DB_ROLLBACK_REQUESTED')
    require(doc.get('ui_activation_allowed') is False, 'PAIR_UI_ACTIVATION_REQUESTED')
    require(doc.get('credential_grants_delete') is False, 'PAIR_VAULT_DELETE_CREDENTIAL')
    edges = doc.get('compatible_pairs')
    require(isinstance(edges, list) and all(isinstance(x, list) and len(x) == 2 for x in edges),
            'PAIR_EDGE_FORMAT')
    current_l, current_p = doc.get('local_current'), doc.get('public_current')
    target_l, target_p = doc.get('local_target'), doc.get('public_target')
    require(all(isinstance(x, str) and len(x) > 0 for x in
                [current_l, current_p, target_l, target_p]), 'PAIR_EMPTY_IDENTITY')
    require([target_l, target_p] in edges, 'PAIR_TARGET_PAIR_UNDECLARED')
    if mode == 'public_only':
        require(current_l == target_l and [current_l, target_p] in edges,
                'PAIR_PUBLIC_ONLY_LOCAL_CHANGED')
    elif mode == 'local_only':
        require(current_p == target_p and [target_l, current_p] in edges,
                'PAIR_LOCAL_ONLY_PUBLIC_CHANGED')
    else:
        order = doc.get('order')
        require(order in ('public_first', 'local_first', 'coordinated'), 'PAIR_ORDER_UNDECLARED')
        require(isinstance(doc.get('max_stagger_seconds'), int) and
                0 < doc['max_stagger_seconds'] <= 86400, 'PAIR_STAGGER_UNBOUNDED')
        if order == 'public_first':
            require([current_l, target_p] in edges,
                    'PAIR_PUBLIC_FIRST_INTERMEDIATE_UNSAFE')
        elif order == 'local_first':
            require([target_l, current_p] in edges,
                    'PAIR_LOCAL_FIRST_INTERMEDIATE_UNSAFE')
        else:
            require(doc.get('public_fenced') is True and
                    doc.get('public_mutation_intake_blocked') is True and
                    doc.get('both_endpoints_stage_verified') is True and
                    doc.get('operator_recovery_if_disconnected') is True,
                    'PAIR_COORDINATED_FENCE_MISSING')
    # A plan is NEVER signed authorization to execute or proof of real Public capability.
    return {'preflight_only': True, 'public_modified': False,
            'public_release_kernel_qualified': False, 'panel_update_ready': False}


def fixture_backup():
    payload = b'ciphertext-mock-' + os.urandom(128)
    verify_key = b'SOKNA-mock-vault-receipt-key-NOT-PRODUCTION'
    d = dict(format='sokna-business-backup-evidence-v1', backup_id='a' * 32,
             backup_kind='business_backup', state='REMOTE_VERIFIED',
             consistency='transactionally_consistent', excluded_machine_authority=True,
             payload_format='ciphertext_only', ciphertext_sha256=digest(payload),
             algorithm='AES-256-GCM', random_unique_dek_verified=True,
             key_id='ci-recipient-v1', remote_vault_has_decrypt_key=False,
             local_scheduled_backup_unattended=True, local_key_protection='windows_dpapi',
             independent_recovery_key_proof=True, last_recovery_drill_verified=True,
             vault_outside_webroot=True, upload_role='append_only', upload_may_delete=False,
             preserves_last_known_good=True, provider_independent=False,
             protection_tier='R1', storage_separate_from_app_release=True,
             receipt_authority='remote_vault_kernel', key_material_in_artifact=False,
             recovery_material_in_application_tree=False)
    body = (d['backup_id'] + '\n' + d['ciphertext_sha256']).encode()
    d['receipt'] = dict(backup_id=d['backup_id'], cipher_sha256=d['ciphertext_sha256'],
                        signature=hmac.new(verify_key, b'SOKNA-REMOTE-VAULT-CI-ONLY\n'+body,
                                           hashlib.sha256).hexdigest())
    return d, payload, verify_key


def fixture_pair():
    return dict(format='sokna-public-pair-inspection-v1',
                release_set_signature_verified=True, artifacts_verified=True,
                public_kernel_signature_verified=True,
                public_installed_from='authoritative_kernel_journal',
                local_installed_from='authoritative_supervisor_journal',
                exact_local_base_verified=True, exact_public_base_verified=True,
                install_generation_verified=True, package_sequence=24, prior_sequence=23,
                same_signed_release_set_id=True, public_kernel_capability_verified=True,
                authority_epoch='epoch-2', expected_authority_epoch='epoch-2',
                old_epoch_queue_quarantined=True, public_update_ready=True,
                local_update_ready=True, wan_reachable=True, resume_journal_present=True,
                declared_protocol_window=True, guest_mutation_policy='block',
                staff_mutation_policy='block', queue_guarantee_verified=False,
                local_drain_observed=True,
                forward_only_after_db_intent=True, automatic_db_rollback=False,
                ui_activation_allowed=False, credential_grants_delete=False,
                release_type='paired', local_current='local-a', public_current='public-a',
                local_target='local-b', public_target='public-b',
                order='public_first', max_stagger_seconds=900,
                compatible_pairs=[['local-a','public-a'],['local-a','public-b'],
                                  ['local-b','public-b']], public_fenced=True,
                public_mutation_intake_blocked=True,
                both_endpoints_stage_verified=True,
                operator_recovery_if_disconnected=True)


def check_failure(fn, name):
    try:
        fn()
    except Rejected:
        return
    raise AssertionError('FAIL_OPEN_' + name)


def main():
    # Explicit matrices, plus deterministic cross-dimensional negative combinations.
    backup, payload, verify = fixture_backup()
    assert public_backup_receipt(backup, payload, verify)['panel_update_ready'] is False
    pair = fixture_pair()
    assert preflight_public_pair(pair)['public_modified'] is False
    # Supported non-paired releases must NOT require an unrelated component's stage.
    assert preflight_public_pair(dict(pair, release_type='public_only',
                                    local_target=pair['local_current'],
                                    local_update_ready=False))['panel_update_ready'] is False
    assert preflight_public_pair(dict(pair, release_type='local_only',
                                    public_target=pair['public_current'],
                                    public_update_ready=False,
                                    compatible_pairs=pair['compatible_pairs'] +
                                    [['local-b','public-a']]))['panel_update_ready'] is False
    assert preflight_public_pair(dict(pair, order='coordinated'))['public_modified'] is False
    bad_backup = {
        'format': 'bad', 'backup_id':'!', 'backup_kind':'system_snapshot',
        'state':'REMOTE_PENDING', 'consistency':'none', 'excluded_machine_authority':False,
        'payload_format':'plaintext', 'ciphertext_sha256':'0'*64,
        'algorithm':'AES-256-CBC', 'random_unique_dek_verified':False,
        'key_id':'../escape', 'remote_vault_has_decrypt_key':True,
        'local_scheduled_backup_unattended':False,'local_key_protection':'plain_file',
        'independent_recovery_key_proof':False,'last_recovery_drill_verified':False,
        'vault_outside_webroot':False,'upload_role':'all_permissions','upload_may_delete':True,
        'preserves_last_known_good':False,'provider_independent':True,'protection_tier':'R2',
        'storage_separate_from_app_release':False,
        'receipt_authority':'browser_cache','key_material_in_artifact':True,
        'recovery_material_in_application_tree':True}
    bad_pair = {
        'format':'bad','release_set_signature_verified':False,'artifacts_verified':False,
        'public_kernel_signature_verified':False,'public_installed_from':'health_cache',
        'local_installed_from':'browser_cache','exact_local_base_verified':False,
        'exact_public_base_verified':False,'install_generation_verified':False,
        'package_sequence':22,'prior_sequence':24,'same_signed_release_set_id':False,
        'public_kernel_capability_verified':False,'authority_epoch':'epoch-1',
        'old_epoch_queue_quarantined':False,'public_update_ready':False,
        'local_update_ready':False,'wan_reachable':False,'resume_journal_present':False,
        'declared_protocol_window':False,'guest_mutation_policy':'allow',
        'staff_mutation_policy':'allow','local_drain_observed':False,
        'forward_only_after_db_intent':False,'automatic_db_rollback':True,
        'ui_activation_allowed':True,'credential_grants_delete':True,
        'release_type':'invalid','order':'unsafe','max_stagger_seconds':0,
        'compatible_pairs': [], 'local_target':'','public_target':''}
    cases = 0
    for key, value in bad_backup.items():
        doc = copy.deepcopy(backup)
        doc[key] = value
        check_failure(lambda doc=doc: public_backup_receipt(doc,payload,verify),'BACKUP_'+key)
        cases += 1
    for key,value in bad_pair.items():
        doc = copy.deepcopy(pair)
        doc[key] = value
        check_failure(lambda doc=doc: preflight_public_pair(doc),'PAIR_'+key)
        cases += 1
    # Explicit negative tests not expressible as a one-field mutation.
    for fn, name in [
        (lambda: public_backup_receipt(backup,payload+b'\0',verify), 'CIPHER_TAMPER'),
        (lambda: public_backup_receipt(backup,payload,b'wrong-verify-key'), 'RECEIPT_KEY'),
        (lambda: preflight_public_pair(dict(pair, compatible_pairs=[['local-b','public-b']])),
         'MISSING_INTERMEDIATE'),
        (lambda: preflight_public_pair(dict(pair, release_type='public_only')),
         'PUBLIC_ONLY_MUTATES_LOCAL'),
        (lambda: preflight_public_pair(dict(pair, release_type='local_only')),
         'LOCAL_ONLY_MUTATES_PUBLIC'),
        (lambda: preflight_public_pair(dict(pair,order='local_first')),
         'MISSING_LOCAL_FIRST_EDGE'),
        (lambda: preflight_public_pair(dict(pair,order='coordinated',public_fenced=False)),
         'COORDINATED_UNFENCED'),
        (lambda: preflight_public_pair(dict(pair,order='coordinated',public_mutation_intake_blocked=False)),
         'COORDINATED_UNBLOCKED'),
        (lambda: preflight_public_pair(dict(pair,order='coordinated',both_endpoints_stage_verified=False)),
         'COORDINATED_NO_TWO_STAGES'),
        (lambda: preflight_public_pair(dict(pair,order='coordinated',operator_recovery_if_disconnected=False)),
         'COORDINATED_NO_RECOVERY'),
        (lambda: preflight_public_pair(dict(pair,guest_mutation_policy='queue')),
         'QUEUE_WITHOUT_GUARANTEE')]:
        check_failure(fn,name)
        cases += 1
    # At least 80 additional negative mutations. NOT 80 real system faults.
    for i in range(40):
        doc = copy.deepcopy(pair)
        doc['wan_reachable'] = False
        doc['max_stagger_seconds'] = 10 + i
        check_failure(lambda doc=doc: preflight_public_pair(doc),'WAN_UNAVAILABLE_'+str(i))
        cases += 1
    for i in range(40):
        doc = copy.deepcopy(backup)
        doc['key_id'] = '../escape-' + str(i)
        check_failure(lambda doc=doc: public_backup_receipt(doc,payload,verify),'KEY_ESCAPE_'+str(i))
        cases += 1
    assert cases >= 100
    print(json.dumps({'format':'sokna-s7e4-contract-negative-ci-v1','result':'PASS',
                      'rejected_negative_contract_cases':cases,
                      'real_fault_matrix_cases_qualified':0,
                      'production_key_custody_qualified':False,
                      'actual_public_kernel_qualified':False,
                      'public_modified':False,'db_mutation':False,
                      'panel_update_ready':False},sort_keys=True))


if __name__ == '__main__':
    main()