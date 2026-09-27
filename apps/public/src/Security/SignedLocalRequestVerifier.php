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
        $secret = (string)($this->installationSecrets[$installationId] ?? '');
        if ($secret === '') $secret = $this->databaseSecret($installationId);
        if ($secret === '') {
            return ['ok' => false, 'status' => 401, 'error' => 'unknown_installation'];
        }

        if (!$this->signatureValid($secret, $method, $path, $timestamp, $nonce, $body, $signature)) {
            return ['ok' => false, 'status' => 401, 'error' => 'bad_signature'];
        }

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

        return ['ok' => true, 'status' => 200, 'installation_id' => $installationId];
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
