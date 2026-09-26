<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use PDO;
use PDOException;

final class SignedLocalRequestVerifier
{
    public const PROTOCOL = 'sokna-relay-v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $installationSecrets,
        private readonly int $clockSkewSeconds = 300,
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
        $secret = (string)($this->installationSecrets[$installationId] ?? '');
        if ($installationId === '' || $secret === '') {
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

    private function isDuplicateKey(PDOException $e): bool
    {
        $sqlState = (string)$e->getCode();
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        return $sqlState === '23000' && $driverCode === 1062;
    }
}
