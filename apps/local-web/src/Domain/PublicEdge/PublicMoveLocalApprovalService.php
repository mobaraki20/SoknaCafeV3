<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * R257: Local authenticated operator explicitly confirms an already
 * authenticated and cryptographically attested destination owner receipt.
 * Resolver is a TRUSTED server-side Local session service. No browser-supplied
 * session claims, target grant MAC or remote "verified" flag is accepted.
 *
 * Leaves the ledger at "owner_approved"; it DOES NOT claim/cutover, update
 * the target URL, publish data, or enable guest intake.
 */
final class PublicMoveLocalApprovalService
{
    private readonly Closure $resolveLocalSession;

    public function __construct(
        private readonly PublicMoveApprovalLedger $ledger,
        callable $resolveLocalSession,
        private readonly string $localMacKey,
        private readonly string $localCsrfKey
    ) {
        $this->resolveLocalSession=Closure::fromCallable($resolveLocalSession);
        if (strlen($localMacKey)<32 || strlen($localCsrfKey)<32) {
            throw new InvalidArgumentException('local_approval_keys_required');
        }
    }

    public function issueCsrf(
        string $sessionToken,array $challenge,array $attestation,array $receipt,int $now
    ): string {
        $this->checkOperator($sessionToken,$challenge);
        PublicMoveReceiptVerifier::verify($challenge,$attestation,$receipt,$now);
        return $this->csrf($sessionToken,$challenge,$receipt);
    }

    public function confirm(
        string $sessionToken,string $csrf,array $challenge,array $attestation,array $receipt,int $now
    ): array {
        $this->checkOperator($sessionToken,$challenge);
        $proof=PublicMoveReceiptVerifier::verify($challenge,$attestation,$receipt,$now);
        $wanted=$this->csrf($sessionToken,$challenge,$receipt);
        if ($csrf==='' || !hash_equals($wanted,$csrf)) {
            throw new RuntimeException('local_operator_csrf_invalid');
        }
        $g=PublicMoveApprovalMac::seal([
            'provenance'=>'authenticated_target_admin_session',
            'status'=>'approved',
            'approval_receipt_id'=>$proof['receipt_id'],
            'expires_at'=>$proof['expires_at'],
            'public_key_b64'=>$proof['public_key_b64'],
            'target_instance_id'=>$proof['target_instance_id'],
            'target_base_url'=>$proof['target_base_url'],
            'local_installation_id'=>$proof['local_installation_id'],
            'operation_id'=>$proof['operation_id'],
            'nonce'=>$proof['nonce'],
        ],$this->localMacKey);
        $result=$this->ledger->acceptVerifiedApproval($challenge,$attestation,$g,$now,$this->localMacKey);
        return ['status'=>$result['status'],'operation_id'=>$challenge['operation_id'],
            'local_admin_confirmed'=>true,'target_owner_receipt_verified'=>true,
            'nonce_claimed'=>false,'safe_to_cutover'=>false];
    }

    private function checkOperator(string $token,array $challenge): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) {
            throw new RuntimeException('local_authenticated_operator_required');
        }
        // Production controller must obtain $token from its authenticated
        // HttpOnly cookie, not JSON/body/query, and provide real session resolver.
        $session=($this->resolveLocalSession)($token);
        if (!is_array($session) || ($session['role']??'')!=='admin' ||
            ($session['authenticated']??null)!==true ||
            !is_string($session['installation_id']??null) ||
            !is_string($challenge['local_installation_id']??null) ||
            !hash_equals($challenge['local_installation_id'],$session['installation_id'])) {
            throw new RuntimeException('local_admin_for_installation_required');
        }
    }

    private function csrf(string $token,array $challenge,array $receipt): string
    {
        $digest=hash('sha256',json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return rtrim(strtr(base64_encode(hash_hmac('sha256',
            implode("\n",['sokna-local-approval-v1',$token,
                (string)($challenge['local_installation_id']??''),
                (string)($challenge['operation_id']??''),
                (string)($challenge['nonce']??''),$digest]),
            $this->localCsrfKey,true)), '+/', '-_'), '=');
    }
}
