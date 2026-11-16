<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * R257: durable, exactly-one per operation destination owner consent receipt.
 * This method is INTERNAL ONLY. The caller MUST have just received $proof
 * from PublicMoveIndependentOwnerBridge::attest() using a server-resolved
 * authenticated Public owner session and valid CSRF/Origin.
 * Never wire create() directly to an arbitrary client-supplied proof JSON.
 *
 * This signature is TARGET evidence. It cannot itself establish that the
 * Local operator consented, and cannot authorize cutover/remote order intake.
 */
final class PublicMoveConsentReceiptStore
{
    private const FIELDS=[
        'contract','approval_receipt_id','operation_id','local_installation_id',
        'target_instance_id','target_base_url','nonce','challenge_sha256',
        'public_key_b64','issued_at','expires_at','authentication_method',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicMoveInstanceKeyStore $keys,
    ) {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('pdo_exception_mode_required');
        }
    }

    public function create(array $challenge,array $serverDerivedOwnerProof,string $publicInstanceId,int $now): array
    {
        if ($now<=0 ||
            ($serverDerivedOwnerProof['owner_session_authenticated']??null)!==true ||
            ($serverDerivedOwnerProof['owner_public_instance_id']??null)!==$publicInstanceId ||
            ($serverDerivedOwnerProof['local_installation_id']??null)!==($challenge['local_installation_id']??null) ||
            ($serverDerivedOwnerProof['operation_id']??null)!==($challenge['operation_id']??null) ||
            ($serverDerivedOwnerProof['safe_to_cutover']??null)!==false ||
            ($serverDerivedOwnerProof['owner_grant_issued']??null)!==false ||
            ($serverDerivedOwnerProof['nonce_claimed']??null)!==false) {
            throw new RuntimeException('server_authenticated_target_owner_required');
        }
        $attestation=$serverDerivedOwnerProof['attestation']??null;
        if (!is_array($attestation)||!is_array($attestation['payload']??null)) {
            throw new InvalidArgumentException('target_attestation_missing');
        }
        $payload=$attestation['payload'];
        foreach(['operation_id','local_installation_id','target_base_url','nonce'] as $field) {
            if (!is_string($challenge[$field]??null) || ($payload[$field]??null)!==$challenge[$field]) {
                throw new InvalidArgumentException('receipt_challenge_binding_invalid');
            }
        }
        if (!is_string($payload['target_instance_id']??null) ||
            !hash_equals($publicInstanceId,$payload['target_instance_id']) ||
            !is_int($challenge['expires_at']??null) ||
            !is_int($challenge['issued_at']??null) ||
            $challenge['expires_at']<=$now ||
            $challenge['expires_at']-$challenge['issued_at']>300 ||
            $challenge['expires_at']-$challenge['issued_at']<30) {
            throw new InvalidArgumentException('receipt_target_or_expiry_invalid');
        }
        $secret=$this->keys->load($publicInstanceId);
        $key=sodium_crypto_sign_publickey_from_secretkey($secret);
        $keyB64=base64_encode($key);
        if (($payload['public_key_b64']??null)!==$keyB64) {
            throw new InvalidArgumentException('receipt_public_key_mismatch');
        }
        $sig=base64_decode((string)($attestation['signature_b64']??''),true);
        if (!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||
            !sodium_crypto_sign_verify_detached($sig,json_encode($payload,
                JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key)) {
            throw new InvalidArgumentException('receipt_attestation_not_signed_by_target');
        }

        $challengeSha=hash('sha256',json_encode($challenge,
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if ($this->pdo->inTransaction()) throw new RuntimeException('nested_transaction_not_allowed');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT public_instance_id,challenge_sha256,nonce,receipt_json,expires_epoch FROM public_move_owner_receipts WHERE operation_id=? FOR UPDATE');
            $q->execute([$challenge['operation_id']]);
            $existing=$q->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['public_instance_id'],$publicInstanceId)||
                    !hash_equals((string)$existing['challenge_sha256'],$challengeSha)||
                    !hash_equals((string)$existing['nonce'],(string)$challenge['nonce'])||
                    (int)$existing['expires_epoch']<=$now) {
                    throw new RuntimeException('receipt_operation_conflict_or_expired');
                }
                $found=json_decode((string)$existing['receipt_json'],true,32,JSON_THROW_ON_ERROR);
                $this->pdo->commit();
                return ['receipt_envelope'=>$found,'attestation'=>$attestation,
                    'already_recorded'=>true,'safe_to_cutover'=>false];
            }
            $receipt=[
                'contract'=>'sokna-public-move-owner-receipt-v1',
                'approval_receipt_id'=>'owner-'.bin2hex(random_bytes(12)),
                'operation_id'=>$challenge['operation_id'],
                'local_installation_id'=>$challenge['local_installation_id'],
                'target_instance_id'=>$publicInstanceId,
                'target_base_url'=>$challenge['target_base_url'],
                'nonce'=>$challenge['nonce'],
                'challenge_sha256'=>$challengeSha,
                'public_key_b64'=>$keyB64,
                'issued_at'=>$now,
                'expires_at'=>$challenge['expires_at'],
                'authentication_method'=>'authenticated_public_owner_session',
            ];
            if (array_keys($receipt)!==self::FIELDS) throw new RuntimeException('receipt_shape_changed');
            $canonical=json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $envelope=['receipt'=>$receipt,'signature_b64'=>base64_encode(sodium_crypto_sign_detached($canonical,$secret))];
            $q=$this->pdo->prepare('INSERT INTO public_move_owner_receipts (operation_id,public_instance_id,local_installation_id,nonce,challenge_sha256,receipt_json,expires_epoch) VALUES(?,?,?,?,?,?,?)');
            $q->execute([$challenge['operation_id'],$publicInstanceId,$challenge['local_installation_id'],
                $challenge['nonce'],$challengeSha,
                json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                $challenge['expires_at']]);
            $this->pdo->commit();
            return ['receipt_envelope'=>$envelope,'attestation'=>$attestation,
                'already_recorded'=>false,'safe_to_cutover'=>false];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** Readback for the exact issued operation; not a public unauthenticated endpoint. */
    public function status(string $operationId,int $now): array
    {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$operationId)) {
            throw new InvalidArgumentException('invalid_operation_id');
        }
        $q=$this->pdo->prepare('SELECT expires_epoch FROM public_move_owner_receipts WHERE operation_id=?');
        $q->execute([$operationId]);$x=$q->fetchColumn();
        return ['status'=>$x===false?'not_found':((int)$x>$now?'owner_approved':'expired'),
            'safe_to_cutover'=>false];
    }
}
