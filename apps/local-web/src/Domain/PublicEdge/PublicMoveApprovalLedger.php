<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * R253: transaction-scoped one-time authorization ledger, with a single active
 * authorization operation per Local installation. This ledger authorizes only
 * an ownership handoff *preflight*, NEVER an actual migration or cutover.
 *
 * Its tables are supplied by the schema *fixture* in r253; do not install
 * that SQL or silently create tables on a live host. An audited migration and
 * authenticated owner-approval backend are separate release gates.
 */
final class PublicMoveApprovalLedger
{
    private const CHALLENGE_FIELDS = [
        'contract','operation_id','local_installation_id','source_public_instance_id',
        'source_base_url','target_base_url','nonce','issued_at','expires_at',
    ];

    public function __construct(private readonly PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('pdo_exception_mode_required');
        }
    }

    /** @param array<string,mixed> $challenge */
    public function begin(array $challenge, int $now): array
    {
        self::validateChallenge($challenge, $now);
        $local = $challenge['local_installation_id'];
        $operation = $challenge['operation_id'];
        $json = self::json($challenge);
        return $this->atomic(function () use ($local, $operation, $challenge, $json) {
            $slot = $this->lockSlot($local);
            if ($slot !== '' && !hash_equals($slot, $operation)) {
                throw new RuntimeException('another_migration_already_active');
            }
            $q = $this->pdo->prepare('SELECT challenge_sha256, status FROM public_move_auth_operations WHERE operation_id=? FOR UPDATE');
            $q->execute([$operation]);
            $existing = $q->fetch(PDO::FETCH_ASSOC);
            $digest = hash('sha256', $json);
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['challenge_sha256'], $digest)) {
                    throw new RuntimeException('operation_id_conflict');
                }
                if (!in_array((string)$existing['status'], ['issued', 'owner_approved'], true)) {\n                    throw new RuntimeException('operation_terminal_cannot_restart');\n                }\n                return self::result($operation, (string)$existing['status'], false);
            }
            $q = $this->pdo->prepare('INSERT INTO public_move_auth_operations (operation_id,local_installation_id,challenge_sha256,challenge_json,nonce,status,expires_epoch) VALUES (?,?,?,?,?,\'issued\',?)');
            $q->execute([$operation,$local,$digest,$json,$challenge['nonce'],$challenge['expires_at']]);
            $q = $this->pdo->prepare('UPDATE public_move_auth_slots SET operation_id=? WHERE local_installation_id=?');
            $q->execute([$operation,$local]);
            return self::result($operation, 'issued', false);
        });
    }

    /**
     * @param array<string,mixed> $challenge
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $trustedOwnerGrant Must be MAC-sealed by an authenticated Local service.
     */
    public function acceptVerifiedApproval(array $challenge, array $envelope, array $trustedOwnerGrant, int $now, string $localOnlyKey): array
    {
        self::validateChallenge($challenge, $now);
        $operation = $challenge['operation_id'];
        $local = $challenge['local_installation_id'];
        $digest = hash('sha256', self::json($challenge));
        return $this->atomic(function () use ($operation,$local,$challenge,$digest,$envelope,$trustedOwnerGrant,$now,$localOnlyKey) {
            $this->requireSlot($local, $operation);
            $row = $this->lockOperation($operation, $local);
            if (!hash_equals((string)$row['challenge_sha256'], $digest) ||
                (int)$row['expires_epoch'] <= $now ||
                !hash_equals((string)$row['nonce'], (string)$challenge['nonce'])) {
                throw new RuntimeException('challenge_not_current');
            }
            // This function does not accept self-asserted `verified:true` from a browser.
            // The cryptographic verifier validates the signed target response AND the
            // independently MAC-sealed Local approval record before mutation.
            $proof = PublicMoveIdentityVerifier::verify($challenge, $envelope, $trustedOwnerGrant, $now, $localOnlyKey);
            if ($proof['safe_to_cutover'] !== false || $proof['nonce_claim_required'] !== true) {
                throw new RuntimeException('invalid_preflight_proof');
            }
            $approvalDigest = hash('sha256', self::json($envelope) . "\n" . self::json($trustedOwnerGrant));
            $status = (string)$row['status'];
            if ($status === 'owner_approved') {
                if (!hash_equals((string)$row['approval_sha256'], $approvalDigest)) {
                    throw new RuntimeException('conflicting_approval_receipt');
                }
                return self::result($operation,'owner_approved',false);
            }
            if ($status !== 'issued') throw new RuntimeException('approval_not_accepting_new_proof');
            $q=$this->pdo->prepare('UPDATE public_move_auth_operations SET status=\'owner_approved\', approval_sha256=?, approval_receipt_id=?, approved_at=UTC_TIMESTAMP(6) WHERE operation_id=? AND status=\'issued\'');
            $q->execute([$approvalDigest,$proof['approval_receipt_id'],$operation]);
            if ($q->rowCount() !== 1) throw new RuntimeException('approval_race_rejected');
            return self::result($operation,'owner_approved',false);
        });
    }

    /** Claim is exactly-once, and does not grant permission to switch traffic. */
    public function claimOnce(string $local, string $operation, string $nonce, int $now): array
    {
        self::validId($local);
        self::validId($operation);
        if (!preg_match('/^[0-9a-f]{48}$/D', $nonce)) throw new InvalidArgumentException('invalid_nonce');
        return $this->atomic(function () use ($local,$operation,$nonce,$now) {
            $this->requireSlot($local,$operation);
            $row=$this->lockOperation($operation,$local);
            if (!hash_equals((string)$row['nonce'],$nonce)) throw new RuntimeException('nonce_not_for_operation');
            if ((int)$row['expires_epoch'] <= $now) throw new RuntimeException('approval_expired');
            if ((string)$row['status'] !== 'owner_approved') throw new RuntimeException('approval_not_claimable');
            $q=$this->pdo->prepare('UPDATE public_move_auth_operations SET status=\'claimed\', claimed_at=UTC_TIMESTAMP(6) WHERE operation_id=? AND status=\'owner_approved\'');
            $q->execute([$operation]);
            if ($q->rowCount() !== 1) throw new RuntimeException('nonce_claim_race_rejected');
            return self::result($operation,'claimed',true);
        });
    }

    /** Cancellable only before a preflight claim. A claim needs explicit recovery semantics. */
    public function cancelBeforeClaim(string $local, string $operation): array
    {
        self::validId($local);
        self::validId($operation);
        return $this->atomic(function () use ($local,$operation) {
            $this->requireSlot($local,$operation);
            $row=$this->lockOperation($operation,$local);
            $phase=(string)$row['status'];
            if (!in_array($phase,['issued','owner_approved','canceled'],true)) {
                throw new RuntimeException('claimed_operation_requires_recovery');
            }
            if ($phase!=='canceled') {
                $q=$this->pdo->prepare('UPDATE public_move_auth_operations SET status=\'canceled\' WHERE operation_id=?');
                $q->execute([$operation]);
            }
            $q=$this->pdo->prepare('UPDATE public_move_auth_slots SET operation_id=NULL WHERE local_installation_id=? AND operation_id=?');
            $q->execute([$local,$operation]);
            return self::result($operation,'canceled',false);
        });
    }

    /** Read-only, deliberately never includes the nonce, owner receipt MAC or secrets. */
    public function status(string $local, string $operation): array
    {
        self::validId($local);
        self::validId($operation);
        $q=$this->pdo->prepare('SELECT status,expires_epoch FROM public_move_auth_operations WHERE local_installation_id=? AND operation_id=?');
        $q->execute([$local,$operation]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('operation_not_found');
        return self::result($operation,(string)$row['status'],(string)$row['status']==='claimed')+
            ['expires_epoch'=>(int)$row['expires_epoch']];
    }

    private function requireSlot(string $local,string $operation): void
    {
        $slot=$this->lockSlot($local);
        if ($slot==='' || !hash_equals($slot,$operation)) throw new RuntimeException('operation_not_current_for_installation');
    }

    private function lockSlot(string $local): string
    {
        $q=$this->pdo->prepare('INSERT IGNORE INTO public_move_auth_slots (local_installation_id,operation_id) VALUES (?,NULL)');
        $q->execute([$local]);
        $q=$this->pdo->prepare('SELECT operation_id FROM public_move_auth_slots WHERE local_installation_id=? FOR UPDATE');
        $q->execute([$local]);
        $v=$q->fetchColumn();
        if ($v===false) throw new RuntimeException('slot_not_found');
        return $v===null?'':(string)$v;
    }

    private function lockOperation(string $operation,string $local): array
    {
        $q=$this->pdo->prepare('SELECT operation_id,local_installation_id,challenge_sha256,nonce,status,approval_sha256,expires_epoch FROM public_move_auth_operations WHERE operation_id=? AND local_installation_id=? FOR UPDATE');
        $q->execute([$operation,$local]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('operation_not_found');
        return $row;
    }

    private function atomic(callable $fn): array
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('nested_transaction_not_allowed');
        $this->pdo->beginTransaction();
        try {
            $result=$fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function validateChallenge(array $c,int $now): void
    {
        if (array_keys($c)!==self::CHALLENGE_FIELDS ||
            ($c['contract']??null)!==PublicMoveIdentityVerifier::CONTRACT ||
            !is_int($c['issued_at']) || !is_int($c['expires_at']) ||
            $c['issued_at']>$now+30 || $c['expires_at']<=$now ||
            $c['expires_at']-$c['issued_at']<30 || $c['expires_at']-$c['issued_at']>300 ||
            !is_string($c['nonce']) || !preg_match('/^[0-9a-f]{48}$/D',$c['nonce'])) {
            throw new InvalidArgumentException('invalid_or_expired_challenge');
        }
        self::validId($c['operation_id']);self::validId($c['local_installation_id']);
        if (!is_string($c['source_public_instance_id']) ||
            ($c['source_public_instance_id']!=='' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$c['source_public_instance_id'])) ||
            !is_string($c['source_base_url']) || !is_string($c['target_base_url']) ||
            ($c['source_base_url']!=='' && PublicMoveDecisionEngine::canonicalUrl($c['source_base_url'])!==$c['source_base_url']) ||
            PublicMoveDecisionEngine::canonicalUrl($c['target_base_url'])!==$c['target_base_url'] ||
            $c['source_base_url']===$c['target_base_url']) {
            throw new InvalidArgumentException('invalid_challenge_identity_or_url');
        }
    }

    private static function validId(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$id)) {
            throw new InvalidArgumentException('invalid_migration_identity');
        }
    }

    private static function json(array $v): string
    {
        return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private static function result(string $operation,string $status,bool $claimed): array
    {
        return [
            'operation_id'=>$operation,
            'status'=>$status,
            'nonce_claimed'=>$claimed,
            'safe_to_cutover'=>false,
            'local_binding_changed'=>false,
            'public_order_intake_changed'=>false,
        ];
    }
}
