<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Realtime;

use PDO;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Throwable;

final class RealtimeService
{
    private const TERMINAL = ['committed', 'rejected', 'expired', 'cancelled', 'unknown_review'];
    private const KINDS = [
        'guest_order.submit', 'guest_order.list', 'guest_order.status', 'guest_table.context',
        'waiter_call.create', 'waiter_call.status', 'waiter_call.cancel',
        'order.edit', 'order.cancel', 'settlement.commit', 'preparation.mutate',
        'table_draft.get', 'table_draft.create', 'table_draft.edit', 'table_draft.finalize', 'table_draft.cancel',
    ];
    private const CAPABILITIES = [
        'guest_order.submit' => 'guest.order.submit',
        'guest_order.list' => 'guest.order.submit',
        'guest_order.status' => 'guest.order.submit',
        'guest_table.context' => 'guest.order.submit',
        'waiter_call.create' => 'guest.waiter_call.create',
        'waiter_call.status' => 'guest.waiter_call.create',
        'waiter_call.cancel' => 'guest.waiter_call.create',
        'order.edit' => 'orders.mutate',
        'order.cancel' => 'orders.mutate',
        'settlement.commit' => 'finance.settle',
        'preparation.mutate' => 'preparation.mutate',
        'table_draft.get' => 'orders.table_draft',
        'table_draft.create' => 'orders.table_draft',
        'table_draft.edit' => 'orders.table_draft',
        'table_draft.finalize' => 'orders.table_draft',
        'table_draft.cancel' => 'orders.table_draft',
    ];

    public function __construct(private readonly PDO $pdo, private readonly ConnectivityService $connectivity)
    {
    }

    public function enqueue(array $session, array $envelope): array
    {
        $installationId = trim((string)($session['installation_id'] ?? ''));
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $capabilities = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if ($installationId === '' || $projectionId === '') {
            return $this->error(401, 'unauthorized');
        }

        $envelope['actor_projection_id'] = $projectionId;
        unset($envelope['session_id']);
        $validation = $this->validateEnvelope($envelope);
        if ($validation['errors'] !== []) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_envelope', 'fields' => $validation['errors']]];
        }

        $kind = (string)$envelope['kind'];
        $flags = $this->installationFlags($installationId);
        if ($flags === null || !$flags['active'] || !$flags['remote_enabled']) {
            return $this->error(409, 'remote_disabled');
        }
        if (in_array($kind, ['guest_order.submit', 'waiter_call.create'], true) && !$flags['order_intake_enabled']) {
            return $this->error(409, 'order_intake_disabled');
        }
        $requiredCapability = self::CAPABILITIES[$kind] ?? '';
        if (!in_array('*', $capabilities, true) && !in_array($requiredCapability, $capabilities, true)) {
            return $this->error(403, 'forbidden');
        }
        if (str_starts_with($kind, 'table_draft.')) {
            $connectivity = $this->connectivity->status($installationId, 45);
            if (($connectivity['local_fresh'] ?? false) !== true) {
                return ['status' => 503, 'body' => ['ok' => false, 'error' => 'local_unavailable', 'connectivity' => $connectivity]];
            }
        }

        $requestId = (string)$envelope['request_id'];
        $canonical = self::canonicalJson($envelope);
        $requestHash = hash('sha256', $canonical);
        $expiresAt = gmdate('Y-m-d H:i:s', (int)$validation['expires_ts']);

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT request_hash,state,result_json,error_code FROM realtime_requests ' .
                'WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $existingStmt->execute([$installationId, $requestId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                    $this->pdo->rollBack();
                    return $this->error(409, 'request_id_conflict');
                }
                $this->pdo->commit();
                return ['status' => 200, 'body' => [
                    'ok' => true,
                    'state' => (string)$existing['state'],
                    'deduplicated' => true,
                    'result' => self::decodeObject($existing['result_json'] ?? null),
                    'error_code' => (string)($existing['error_code'] ?? ''),
                ]];
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO realtime_requests(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,expires_at) ' .
                'VALUES(?,?,?,?,?,?,?,?)'
            );
            $insert->execute([$installationId, $requestId, $requestHash, $kind, $projectionId, $canonical, 'queued', $expiresAt]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 202, 'body' => ['ok' => true, 'state' => 'queued', 'deduplicated' => false]];
    }

    public function claim(string $installationId, int $leaseSeconds = 20): array
    {
        $installationId = trim($installationId);
        $leaseSeconds = min(60, max(5, $leaseSeconds));
        $flags = $this->installationFlags($installationId);
        if ($flags === null || !$flags['active'] || !$flags['remote_enabled']) {
            return $this->error(409, 'remote_disabled');
        }

        $this->pdo->beginTransaction();
        try {
            $expire = $this->pdo->prepare(
                "UPDATE realtime_requests SET state='expired',lease_token_hash=NULL,lease_expires_at=NULL " .
                "WHERE installation_id=? AND state='queued' AND expires_at<=UTC_TIMESTAMP()"
            );
            $expire->execute([$installationId]);

            $select = $this->pdo->prepare(
                "SELECT id,envelope_json FROM realtime_requests WHERE installation_id=? AND " .
                "((state='queued' AND expires_at>UTC_TIMESTAMP()) OR (state='claimed' AND lease_expires_at<UTC_TIMESTAMP())) " .
                'ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            $select->execute([$installationId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return $this->error(404, 'empty_queue');
            }

            $leaseToken = bin2hex(random_bytes(24));
            $update = $this->pdo->prepare(
                "UPDATE realtime_requests SET state='claimed',lease_token_hash=?," .
                'lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),claimed_at=UTC_TIMESTAMP() WHERE id=?'
            );
            $update->execute([hash('sha256', $leaseToken), $leaseSeconds, (int)$row['id']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'request' => self::decodeObject($row['envelope_json'] ?? null),
            'lease_token' => $leaseToken,
            'lease_seconds' => $leaseSeconds,
        ]];
    }

    public function ack(string $installationId, array $ack): array
    {
        $requestId = trim((string)($ack['request_id'] ?? ''));
        $leaseToken = (string)($ack['lease_token'] ?? '');
        $state = (string)($ack['state'] ?? '');
        if ($requestId === '' || $leaseToken === '' || !self::isTerminal($state)) {
            return $this->error(400, 'invalid_ack');
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT state,lease_token_hash FROM realtime_requests WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $select->execute([$installationId, $requestId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return $this->error(404, 'not_found');
            }
            if (self::isTerminal((string)$row['state'])) {
                $this->pdo->commit();
                return ['status' => 200, 'body' => ['ok' => true, 'state' => (string)$row['state'], 'deduplicated' => true]];
            }
            $storedHash = (string)($row['lease_token_hash'] ?? '');
            if ((string)$row['state'] !== 'claimed' || $storedHash === '' || !hash_equals($storedHash, hash('sha256', $leaseToken))) {
                $this->pdo->rollBack();
                return $this->error(409, 'lease_conflict');
            }

            $result = is_array($ack['result'] ?? null) ? $ack['result'] : [];
            $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($resultJson)) $resultJson = '{}';
            $update = $this->pdo->prepare(
                'UPDATE realtime_requests SET state=?,result_json=?,error_code=?,lease_token_hash=NULL,lease_expires_at=NULL ' .
                'WHERE installation_id=? AND request_id=?'
            );
            $update->execute([$state, $resultJson, (string)($ack['error_code'] ?? ''), $installationId, $requestId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 200, 'body' => ['ok' => true, 'state' => $state, 'deduplicated' => false]];
    }

    public function result(array $session, string $requestId): array
    {
        $requestId = trim($requestId);
        if ($requestId === '') return $this->error(400, 'request_id_required');
        $installationId = trim((string)($session['installation_id'] ?? ''));
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $capabilities = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if ($installationId === '' || $projectionId === '') return $this->error(401, 'unauthorized');

        $stmt = $this->pdo->prepare(
            'SELECT actor_projection_id,state,result_json,error_code,updated_at FROM realtime_requests ' .
            'WHERE installation_id=? AND request_id=? LIMIT 1'
        );
        $stmt->execute([$installationId, $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $this->error(404, 'not_found');
        if (!in_array('*', $capabilities, true) && !hash_equals($projectionId, (string)$row['actor_projection_id'])) {
            return $this->error(403, 'forbidden');
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'state' => (string)$row['state'],
            'terminal' => self::isTerminal((string)$row['state']),
            'result' => self::decodeObject($row['result_json'] ?? null),
            'error_code' => (string)($row['error_code'] ?? ''),
            'updated_at' => (string)$row['updated_at'],
        ]];
    }

    private function installationFlags(string $installationId): ?array
    {
        if ($installationId === '') return null;
        $stmt = $this->pdo->prepare(
            'SELECT active,remote_enabled,order_intake_enabled FROM installations WHERE installation_id=? LIMIT 1'
        );
        $stmt->execute([$installationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return [
            'active' => (bool)$row['active'],
            'remote_enabled' => (bool)$row['remote_enabled'],
            'order_intake_enabled' => (bool)$row['order_intake_enabled'],
        ];
    }

    private function validateEnvelope(array $envelope): array
    {
        $errors = [];
        $requestId = trim((string)($envelope['request_id'] ?? ''));
        if ($requestId === '' || strlen($requestId) > 96 || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1) $errors[] = 'request_id';
        $kind = trim((string)($envelope['kind'] ?? ''));
        if (!in_array($kind, self::KINDS, true)) $errors[] = 'kind';
        $createdTs = strtotime(trim((string)($envelope['created_at'] ?? ''))) ?: 0;
        $expiresTs = strtotime(trim((string)($envelope['expires_at'] ?? ''))) ?: 0;
        if ($createdTs <= 0) $errors[] = 'created_at';
        if ($expiresTs <= 0 || ($createdTs > 0 && $expiresTs <= $createdTs)) $errors[] = 'expires_at';
        if (!isset($envelope['payload']) || !is_array($envelope['payload'])) $errors[] = 'payload';
        if (isset($envelope['expected_version']) && !is_int($envelope['expected_version']) && !is_string($envelope['expected_version'])) $errors[] = 'expected_version';
        if (isset($envelope['expected_state']) && !is_string($envelope['expected_state'])) $errors[] = 'expected_state';
        if (trim((string)($envelope['actor_projection_id'] ?? '')) === '') $errors[] = 'actor_projection_id';
        return ['errors' => $errors, 'expires_ts' => $expiresTs];
    }

    private static function canonicalJson(array $value): string
    {
        $json = json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) throw new \RuntimeException('Realtime canonical JSON encoding failed.');
        return $json;
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([self::class, 'normalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::normalize($child);
        return $value;
    }

    private static function decodeObject(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
