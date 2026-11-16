<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use InvalidArgumentException;
use RuntimeException;

/**
 * R259: one small operator flow for requesting and confirming destination
 * ownership. Challenge state is durable on Local, approvals are signed by the
 * destination and independently confirmed by the REAL Local administrator.
 *
 * Explicitly DOES NOT transfer data or change authority. No browser-provided
 * success flag, Local identity, session or nonce is trusted.
 */
final class PublicMoveLocalFlow
{
    public function __construct(
        private readonly PublicMoveApprovalLedger $ledger,
        private readonly PublicMoveLocalApprovalService $approval,
        private readonly string $localInstallationId,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$localInstallationId)) {
            throw new InvalidArgumentException('local_installation_id_invalid');
        }
    }

    public function begin(
        string $targetUrl,string $sourceUrl,string $verifiedSourceInstanceId,
        int $now,?string $priorOperationId=null
    ): array {
        $target=PublicMoveDecisionEngine::canonicalUrl($targetUrl);
        $source=PublicMoveDecisionEngine::canonicalUrl($sourceUrl);
        if ($target===null || $source===null || hash_equals($source,$target)) {
            throw new InvalidArgumentException('move_target_url_invalid');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$verifiedSourceInstanceId)) {
            throw new InvalidArgumentException('source_identity_not_verified');
        }
        if (is_string($priorOperationId) && $priorOperationId!=='') {
            try {
                $prior=$this->ledger->pendingChallenge($this->localInstallationId,$priorOperationId,$now);
                if (hash_equals($prior['target_base_url'],$target) &&
                    hash_equals($prior['source_base_url'],$source) &&
                    hash_equals($prior['source_public_instance_id'],$verifiedSourceInstanceId)) {
                    return self::result($prior,true);
                }
                throw new RuntimeException('another_migration_already_active');
            } catch (RuntimeException $e) {
                if ($e->getMessage()!=='move_challenge_not_active')throw $e;
                // Expired preflight can be replaced transactionally in ledger.
            }
        }
        $challenge=PublicMoveIdentityVerifier::challenge(
            'move-'.bin2hex(random_bytes(12)),
            $this->localInstallationId,$verifiedSourceInstanceId,$source,$target,$now,300
        );
        $this->ledger->begin($challenge,$now);
        return self::result($challenge,false);
    }

    public function confirm(
        string $operationId,string $trustedLocalSessionToken,
        array $attestation,array $receipt,int $now
    ): array {
        $challenge=$this->ledger->pendingChallenge($this->localInstallationId,$operationId,$now);
        // Strict schema, two Ed25519 signatures and exact Local challenge readback.
        // The separately authenticated Local session is not supplied by browser.
        $csrf=$this->approval->issueCsrf($trustedLocalSessionToken,$challenge,$attestation,$receipt,$now);
        $accepted=$this->approval->confirm($trustedLocalSessionToken,$csrf,$challenge,$attestation,$receipt,$now);
        return [
            'status'=>$accepted['status'],
            'target_ownership_confirmed'=>true,
            'local_admin_confirmed'=>true,
            'safe_to_cutover'=>false,
            'transfer_started'=>false,
            'message'=>'مالکیت مقصد تأیید شد. تا پایان انتقال امن اطلاعات، اتصال فعلی حفظ می‌شود.',
        ];
    }

    public function cancel(string $operationId): array
    {
        return $this->ledger->cancelBeforeClaim($this->localInstallationId,$operationId);
    }

    private static function result(array $challenge,bool $resumed): array
    {
        return [
            'status'=>'awaiting_destination_owner',
            'challenge'=>$challenge,
            'operation_id'=>$challenge['operation_id'],
            'resumed'=>$resumed,
            'expires_at'=>$challenge['expires_at'],
            'safe_to_cutover'=>false,
            'transfer_started'=>false,
        ];
    }
}
