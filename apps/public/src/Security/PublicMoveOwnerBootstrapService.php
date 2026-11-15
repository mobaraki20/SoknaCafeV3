<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * R255: independent owner credentials for an unpaired Public installation.
 *
 * NO HTTP ROUTE is exposed by this service.
 * A trusted, local installer/setup process alone may invoke stageClaim().
 * Never invoke stageClaim() from an unauthenticated web request.
 *
 * The owner belongs to the destination PUBLIC INSTANCE, not to a Local
 * installation whose identity may be unknown until a later migration.
 * The one-time setup token is returned only to the trusted setup process;
 * database state stores a digest and never the token.
 */
final class PublicMoveOwnerBootstrapService
{
    public function __construct(private readonly PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('pdo_exception_mode_required');
        }
    }

    /** @return array{instance_id:string,setup_token:string,expires_at:int} */
    public function stageClaim(string $instanceId, int $now, int $ttlSeconds=900): array
    {
        self::id($instanceId);
        if ($now <= 0 || $ttlSeconds < 180 || $ttlSeconds > 1800) {
            throw new InvalidArgumentException('invalid_setup_claim_window');
        }
        $token = bin2hex(random_bytes(32));
        $digest = self::tokenDigest($instanceId, $token);
        $this->transaction(function () use ($instanceId,$now,$ttlSeconds,$digest) {
            $this->lockSlot($instanceId);
            if ($this->hasOwner($instanceId)) throw new RuntimeException('owner_already_initialized');
            $q=$this->pdo->prepare(
                'SELECT consumed_at,expires_epoch FROM public_move_owner_setup_claims WHERE public_instance_id=? FOR UPDATE'
            );
            $q->execute([$instanceId]);
            $old=$q->fetch(PDO::FETCH_ASSOC);
            if (is_array($old) && $old['consumed_at']===null && (int)$old['expires_epoch'] > $now) {
                throw new RuntimeException('setup_claim_already_active');
            }
            $q=$this->pdo->prepare(
                'INSERT INTO public_move_owner_setup_claims(public_instance_id,token_sha256,expires_epoch,used_attempts,consumed_at)'.
                ' VALUES(?,?,?,0,NULL) ON DUPLICATE KEY UPDATE token_sha256=VALUES(token_sha256),'.
                ' expires_epoch=VALUES(expires_epoch),used_attempts=0,consumed_at=NULL'
            );
            $q->execute([$instanceId,$digest,$now+$ttlSeconds]);
            return true;
        });
        return ['instance_id'=>$instanceId,'setup_token'=>$token,'expires_at'=>$now+$ttlSeconds];
    }

    /**
     * One-time owner setup with claim supplied by the trusted normal setup UI.
     * Does not grant migration and does not return a session token.
     */
    public function initialize(string $instanceId,string $setupToken,string $password,int $now): array
    {
        self::id($instanceId);
        self::token($setupToken);
        self::password($password);
        if ($now<=0) throw new InvalidArgumentException('invalid_time');
        $result=$this->transaction(function()use($instanceId,$setupToken,$password,$now) {
            $this->lockSlot($instanceId);
            if ($this->hasOwner($instanceId)) throw new RuntimeException('owner_already_initialized');
            $q=$this->pdo->prepare(
                'SELECT token_sha256,expires_epoch,used_attempts,consumed_at FROM public_move_owner_setup_claims WHERE public_instance_id=? FOR UPDATE'
            );
            $q->execute([$instanceId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['consumed_at']!==null ||
                (int)$row['expires_epoch'] <= $now || (int)$row['used_attempts']>=5) {
                throw new RuntimeException('setup_claim_unavailable');
            }
            if (!hash_equals((string)$row['token_sha256'],self::tokenDigest($instanceId,$setupToken))) {
                $this->pdo->prepare(
                    'UPDATE public_move_owner_setup_claims SET used_attempts=used_attempts+1 WHERE public_instance_id=?'
                )->execute([$instanceId]);
                return ['accepted'=>false];
            }
            $hash=password_hash($password, PASSWORD_ARGON2ID);
            if (!is_string($hash)||$hash==='') throw new RuntimeException('owner_password_hash_failed');
            $this->pdo->prepare(
                'INSERT INTO public_move_owner_accounts(public_instance_id,password_hash,failed_attempts,locked_until_epoch) VALUES(?,?,0,0)'
            )->execute([$instanceId,$hash]);
            $this->pdo->prepare(
                'UPDATE public_move_owner_setup_claims SET consumed_at=UTC_TIMESTAMP(6) WHERE public_instance_id=?'
            )->execute([$instanceId]);
            return ['accepted'=>true];
        });
        if (!$result['accepted']) throw new RuntimeException('setup_claim_invalid');
        return ['owner_created'=>true,'public_instance_id'=>$instanceId,
            'must_login'=>true,'safe_to_cutover'=>false,'order_intake_changed'=>false];
    }

    /** @return array{ok:bool,token?:string,expires_at?:int} */
    public function login(string $instanceId,string $password,int $now): array
    {
        self::id($instanceId);
        if ($now<=0 || strlen($password)>1024) throw new InvalidArgumentException('invalid_login_input');
        return $this->transaction(function()use($instanceId,$password,$now) {
            $this->lockSlot($instanceId);
            $q=$this->pdo->prepare(
                'SELECT password_hash,failed_attempts,locked_until_epoch FROM public_move_owner_accounts WHERE public_instance_id=? FOR UPDATE'
            );
            $q->execute([$instanceId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int)$row['locked_until_epoch']>$now) return ['ok'=>false];
            if (!password_verify($password,(string)$row['password_hash'])) {
                $attempts=(int)$row['failed_attempts']+1;
                $lock=$attempts>=5?$now+900:0;
                $this->pdo->prepare(
                    'UPDATE public_move_owner_accounts SET failed_attempts=?,locked_until_epoch=? WHERE public_instance_id=?'
                )->execute([$attempts,$lock,$instanceId]);
                return ['ok'=>false];
            }
            $this->pdo->prepare(
                'UPDATE public_move_owner_accounts SET failed_attempts=0,locked_until_epoch=0 WHERE public_instance_id=?'
            )->execute([$instanceId]);
            $token=bin2hex(random_bytes(32));
            $expiry=$now+1800; // Shorter than staff sessions: setup/migration-only owner.
            $this->pdo->prepare(
                'INSERT INTO public_move_owner_sessions(token_sha256,public_instance_id,expires_epoch,revoked_at)'.
                ' VALUES(?,?,?,NULL)'
            )->execute([hash('sha256',$token),$instanceId,$expiry]);
            return ['ok'=>true,'token'=>$token,'expires_at'=>$expiry];
        });
    }

    /** Session must be resolved on the SERVER, never received as trusted JSON. */
    public function resolve(string $token,int $now): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token) || $now<=0) return null;
        $q=$this->pdo->prepare(
            'SELECT public_instance_id,expires_epoch FROM public_move_owner_sessions '.
            'WHERE token_sha256=? AND revoked_at IS NULL AND expires_epoch>? LIMIT 1'
        );
        $q->execute([hash('sha256',$token),$now]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return ['public_instance_id'=>(string)$row['public_instance_id'],
            'role'=>'owner','authenticated_by'=>'public_move_owner_password',
            'expires_at'=>(int)$row['expires_epoch']];
    }

    public function logout(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return;
        $this->pdo->prepare(
            'UPDATE public_move_owner_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE token_sha256=?'
        )->execute([hash('sha256',$token)]);
    }

    /** Recovery must be an authenticated separate workflow; never reuse setup claim. */
    public function initialized(string $instanceId): bool
    {
        self::id($instanceId);
        return $this->hasOwner($instanceId);
    }

    private function hasOwner(string $id): bool
    {
        $q=$this->pdo->prepare('SELECT 1 FROM public_move_owner_accounts WHERE public_instance_id=? LIMIT 1');
        $q->execute([$id]);return $q->fetchColumn()!==false;
    }

    private function lockSlot(string $id): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO public_move_owner_slots(public_instance_id) VALUES(?)')->execute([$id]);
        $q=$this->pdo->prepare('SELECT public_instance_id FROM public_move_owner_slots WHERE public_instance_id=? FOR UPDATE');
        $q->execute([$id]);
        if ($q->fetchColumn()===false) throw new RuntimeException('owner_slot_missing');
    }

    private function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('nested_transaction_not_allowed');
        $this->pdo->beginTransaction();
        try{$res=$fn();$this->pdo->commit();return $res;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private static function id(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$id)) {
            throw new InvalidArgumentException('invalid_public_instance_id');
        }
    }

    private static function token(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new InvalidArgumentException('invalid_setup_token_format');
    }

    private static function password(string $password): void
    {
        if (strlen($password)<12 || strlen($password)>256) throw new InvalidArgumentException('invalid_owner_password_length');
    }

    private static function tokenDigest(string $id,string $token): string
    {
        return hash('sha256',"sokna-public-owner-setup-v1\n".$id."\n".$token);
    }
}
