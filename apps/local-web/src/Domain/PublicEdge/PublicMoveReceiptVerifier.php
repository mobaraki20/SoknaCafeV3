<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use InvalidArgumentException;

/**
 * R257 Public owner receipt verification: no network access, no persistence,
 * no Local authority changes. A valid self-signed receipt must ALSO be bound
 * to an authenticated Local administrator's explicit confirmation before
 * PublicMoveApprovalMac may be created and R253 ledger may be updated.
 */
final class PublicMoveReceiptVerifier
{
    public const CONTRACT = 'sokna-public-move-owner-receipt-v1';
    private const FIELDS = [
        'contract','approval_receipt_id','operation_id','local_installation_id',
        'target_instance_id','target_base_url','nonce','challenge_sha256',
        'public_key_b64','issued_at','expires_at','authentication_method',
    ];

    /**
     * @return array<string,mixed> verified evidence, NEVER cutover authority
     */
    public static function verify(array $challenge, array $attestation, array $receiptEnvelope, int $now): array
    {
        if (array_keys($receiptEnvelope) !== ['receipt','signature_b64'] ||
            !is_array($receiptEnvelope['receipt']) ||
            !is_string($receiptEnvelope['signature_b64'])) {
            throw new InvalidArgumentException('owner_receipt_envelope_invalid');
        }
        $receipt=$receiptEnvelope['receipt'];
        if (array_keys($receipt)!==self::FIELDS ||
            $receipt['contract']!==self::CONTRACT ||
            !is_string($receipt['approval_receipt_id']) ||
            !preg_match('/^[a-z0-9_-]{12,128}$/D',$receipt['approval_receipt_id']) ||
            $receipt['authentication_method']!=='authenticated_public_owner_session') {
            throw new InvalidArgumentException('owner_receipt_shape_invalid');
        }
        $attestationPayload=$attestation['payload']??null;
        if (!is_array($attestationPayload)) throw new InvalidArgumentException('attestation_payload_missing');
        $binding=[
            'operation_id'=>'operation_id',
            'local_installation_id'=>'local_installation_id',
            'target_base_url'=>'target_base_url',
            'nonce'=>'nonce',
        ];
        foreach($binding as $r=>$c) {
            if (!is_string($receipt[$r]) || !is_string($challenge[$c]??null) ||
                !hash_equals($challenge[$c],$receipt[$r])) {
                throw new InvalidArgumentException('owner_receipt_challenge_mismatch');
            }
        }
        if (!is_string($receipt['target_instance_id']) ||
            !is_string($attestationPayload['target_instance_id']??null) ||
            !hash_equals($attestationPayload['target_instance_id'],$receipt['target_instance_id']) ||
            !is_string($receipt['public_key_b64']) ||
            !is_string($attestationPayload['public_key_b64']??null) ||
            !hash_equals($attestationPayload['public_key_b64'],$receipt['public_key_b64'])) {
            throw new InvalidArgumentException('owner_receipt_attestation_mismatch');
        }
        $challengeHash=hash('sha256',json_encode($challenge,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if (!is_string($receipt['challenge_sha256']) ||
            !hash_equals($challengeHash,$receipt['challenge_sha256'])) {
            throw new InvalidArgumentException('owner_receipt_challenge_hash_invalid');
        }
        if (!is_int($receipt['issued_at']) || !is_int($receipt['expires_at']) ||
            $receipt['issued_at']<$challenge['issued_at']-30 ||
            $receipt['issued_at']>$now+30 ||
            $receipt['expires_at']>$challenge['expires_at'] ||
            $receipt['expires_at']<=$now ||
            $receipt['expires_at']<=$receipt['issued_at']) {
            throw new InvalidArgumentException('owner_receipt_expired');
        }
        $key=base64_decode($receipt['public_key_b64'],true);
        $sig=base64_decode($receiptEnvelope['signature_b64'],true);
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
            !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||
            !sodium_crypto_sign_verify_detached($sig,self::canonical($receipt),$key)) {
            throw new InvalidArgumentException('owner_receipt_signature_invalid');
        }
        // The target signed the same key in a separate attestation. Its
        // independent Ed25519 signature is essential, not just matching fields.
        $asig=base64_decode((string)($attestation['signature_b64']??''),true);
        if (!is_string($asig)||strlen($asig)!==SODIUM_CRYPTO_SIGN_BYTES||
            !sodium_crypto_sign_verify_detached($asig,
                PublicMoveIdentityVerifier::canonical($attestationPayload),$key)) {
            throw new InvalidArgumentException('owner_attestation_signature_invalid');
        }
        return [
            'receipt_id'=>$receipt['approval_receipt_id'],
            'public_key_b64'=>$receipt['public_key_b64'],
            'target_instance_id'=>$receipt['target_instance_id'],
            'target_base_url'=>$receipt['target_base_url'],
            'operation_id'=>$receipt['operation_id'],
            'local_installation_id'=>$receipt['local_installation_id'],
            'nonce'=>$receipt['nonce'],
            'expires_at'=>$receipt['expires_at'],
            'valid_target_receipt'=>true,
            'local_admin_confirmed'=>false,
            'safe_to_cutover'=>false,
        ];
    }

    public static function canonical(array $receipt): string
    {
        if (array_keys($receipt)!==self::FIELDS)throw new InvalidArgumentException('owner_receipt_fields_invalid');
        return json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
}
