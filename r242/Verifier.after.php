<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use PDO;
use PDOException;
use Throwable;

final class SignedLocalRequestVerifier
{
    public const PROTOCOL = 'sokna-relay-v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $installationSecrets,
        private readonly int $clockSkewSeconds = 300,
        private readonly string $pairingEncryptionKeyBase64 = '',
    ) {
    }

    public function verify(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
    ): array {
        $installationId = trim($installationId);
        if ($installationId === '' || $this->installationRevoked($installationId)) {
            return ['ok' => false, 'status' => 401, 'error' => $installationId === '' ? 'unknown_installation' : 'installation_revoked'];
        }
        $secret = $this->databaseSecret($installationId);
        // PairingSecretStore is the rotation authority. Static config is legacy fallback only
        // when no database pairing secret exists, otherwise an old config secret could bypass
        // an authority-epoch credential fence after restore.
        if ($secret === '') $secret = (string)($this->installationSecrets[$installationId] ?? '');
        if ($secret === '') {
            return ['ok' => false, 'status' => 401, 'error' => 'unknown_installation'];
        }

        if (!$this->signatureValid($secret, $method, $path, $timestamp, $nonce, $body, $signature)) {
            return ['ok' => false, 'status' => 401, 'error' => 'bad_signature'];
        }

        $authority=$this->authorityCheck($installationId,$path,$body);
        if(($authority['ok']??false)!==true)return $authority;

        $ttl = max(60, max(30, $this->clockSkewSeconds) * 2);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM request_nonces WHERE expires_at < UTC_TIMESTAMP()')->execute();
            $insert = $this->pdo->prepare(
                'INSERT INTO request_nonces(installation_id,nonce,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))'
            );
            try {
                $insert->execute([$installationId, trim($nonce), $ttl]);
            } catch (PDOException $e) {
                if ($this->isDuplicateKey($e)) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'status' => 409, 'error' => 'replay_detected'];
                }
                throw $e;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'status' => 200, 'installation_id' => $installationId, 'authority_epoch'=>(int)($authority['authority_epoch']??1)];
    }

    public function signatureBase(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return implode("\n", [
            self::PROTOCOL,
            strtoupper(trim($method)),
            '/' . ltrim($path, '/'),
            trim($timestamp),
            trim($nonce),
            hash('sha256', $body),
        ]);
    }

    public function sign(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return hash_hmac('sha256', $this->signatureBase($method, $path, $timestamp, $nonce, $body), $secret);
    }


    private function authorityCheck(string $installationId,string $path,string $body): array
    {
        try{
            $q=$this->pdo->prepare('SELECT authority_epoch,authority_state,authority_epoch_required FROM installations WHERE installation_id=? LIMIT 1');$q->execute([$installationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))return ['ok'=>false,'status'=>401,'error'=>'unknown_installation'];
            $current=max(1,(int)($row['authority_epoch']??1));$state=(string)($row['authority_state']??'active');$required=(bool)($row['authority_epoch_required']??false);
            $decoded=json_decode($body,true);$provided=is_array($decoded)&&array_key_exists('authority_epoch',$decoded)?(int)$decoded['authority_epoch']:0;
            if($required&&$provided<=0)return ['ok'=>false,'status'=>409,'error'=>'authority_epoch_required'];
            // Read-only recovery status may use exactly the previous epoch once,
            // after the owner-signed destructive reset advanced Public authority.
            $priorGoLiveStatus=$path==='/api/v1/local/go-live/status' && $state==='resync_required' && $provided===$current-1;
            if($provided>0&&$provided!==$current&&!$priorGoLiveStatus)return ['ok'=>false,'status'=>409,'error'=>'authority_epoch_mismatch'];
            if($state==='reenroll_pending'&&$path!=='/api/v1/local/authority/fence')return ['ok'=>false,'status'=>409,'error'=>'authority_reenrollment_required'];
            if($state==='resync_required'){
                $safe=['/api/v1/local/installation','/api/v1/local/auth-projections','/api/v1/local/guest/','/api/v1/local/read-model','/api/v1/local/read-models','/api/v1/local/heartbeat','/api/v1/local/authority/activate','/api/v1/local/go-live/status'];
                // Recovery must prove the newly rotated secret/epoch without activating intake.
                // This exact read-only endpoint is intentionally allowed while resync is fenced.
                $allowed=$path==='/api/v1/local/authority/status';
                foreach($safe as $prefix)if(str_starts_with($path,$prefix)){$allowed=true;break;}
                if(!$allowed)return ['ok'=>false,'status'=>409,'error'=>'authority_resync_required'];
            }
            return ['ok'=>true,'authority_epoch'=>$current];
        }catch(PDOException $e){
            // Bootstrap compatibility is intentionally limited to the signed status reconciliation plus two routine-update lifecycle
            // endpoints that must remain callable after target files switch but before migration
            // 0007_authority_epoch_fencing has created the new authority columns. This is not a
            // general auth bypass: signature, installation identity and nonce checks still apply,
            // and only the exact missing-authority-column MariaDB error is accepted.
            if($this->authorityBootstrapCompatAllowed($path,$e))return ['ok'=>true,'authority_epoch'=>1,'authority_bootstrap_compat'=>true];
            return ['ok'=>false,'status'=>503,'error'=>'authority_state_unavailable'];
        }catch(Throwable){return ['ok'=>false,'status'=>503,'error'=>'authority_state_unavailable'];}
    }

    private function authorityBootstrapCompatAllowed(string $path,PDOException $e): bool
    {
        if(!in_array($path,['/api/v1/local/update/status','/api/v1/local/update/v2/commit','/api/v1/local/update/v2/rollback',
            '/api/v1/local/update/v3/commit','/api/v1/local/update/v3/rollback'],true))return false;
        $sqlState=(string)$e->getCode();$driverCode=(int)($e->errorInfo[1]??0);$message=strtolower($e->getMessage());
        if($sqlState!=='42S22'||$driverCode!==1054)return false;
        foreach(['authority_epoch','authority_state','authority_epoch_required'] as $column)if(str_contains($message,$column))return true;
        return false;
    }

    private function signatureValid(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
    ): bool {
        if ($signature === '' || trim($nonce) === '') return false;
        $ts = ctype_digit(trim($timestamp)) ? (int)trim($timestamp) : (strtotime($timestamp) ?: 0);
        if ($ts <= 0 || abs(time() - $ts) > max(30, $this->clockSkewSeconds)) return false;
        $expected = $this->sign($secret, $method, $path, $timestamp, $nonce, $body);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    private function installationRevoked(string $installationId): bool
    {
        try {
            $q=$this->pdo->prepare('SELECT revoked_at FROM installations WHERE installation_id=? LIMIT 1');$q->execute([$installationId]);$v=$q->fetchColumn();return $v!==false && $v!==null && trim((string)$v)!=='';
        } catch (Throwable) { return false; }
    }

    private function databaseSecret(string $installationId): string
    {
        if ($installationId === '' || $this->pairingEncryptionKeyBase64 === '' || !function_exists('sodium_crypto_secretbox_open')) return '';
        try {
            $r=$this->pdo->prepare('SELECT i.revoked_at,s.secret_ciphertext FROM installations i LEFT JOIN installation_pairing_secrets s ON s.installation_id=i.installation_id WHERE i.installation_id=? LIMIT 1');
            $r->execute([$installationId]);$row=$r->fetch(PDO::FETCH_ASSOC);
            if (!$row || !empty($row['revoked_at']) || empty($row['secret_ciphertext'])) return '';
            $key=base64_decode($this->pairingEncryptionKeyBase64,true);$raw=base64_decode((string)$row['secret_ciphertext'],true);
            if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return '';
            $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key);
            return is_string($plain)?$plain:'';
        } catch (Throwable) { return ''; }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $sqlState = (string)$e->getCode();
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' && $driverCode === 1062;
    }
}