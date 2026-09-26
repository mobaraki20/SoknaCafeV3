<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Deferred;

use PDO;
use Throwable;

final class DeferredService
{
    private const TERMINAL = ['committed', 'needs_review', 'rejected'];
    private const KINDS = [
        'supply.need.create', 'supply.status.prepare', 'supply.status.return', 'supply.receipt',
        'inventory.waste', 'inventory.count_draft', 'subscriber.payment', 'expense.create',
    ];
    private const CAPABILITIES = [
        'supply.need.create' => 'supply.need.defer',
        'supply.status.prepare' => 'supply.manage.defer',
        'supply.status.return' => 'supply.manage.defer',
        'supply.receipt' => 'supply.manage.defer',
        'inventory.waste' => 'inventory.waste.defer',
        'inventory.count_draft' => 'inventory.count_draft.defer',
        'subscriber.payment' => 'subscriber.payment.defer',
        'expense.create' => 'expense.create.defer',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function enqueue(array $session, array $envelope): array
    {
        $installationId = trim((string)($session['installation_id'] ?? ''));
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $capabilities = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if ($installationId === '' || $projectionId === '') return $this->error(401, 'unauthorized');

        $envelope['actor_projection_id'] = $projectionId;
        unset($envelope['session_id']);
        $validation = $this->validateEnvelope($envelope);
        if ($validation['errors'] !== []) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_envelope', 'fields' => $validation['errors']]];
        }

        $kind = (string)$envelope['kind'];
        $requiredCapability = self::CAPABILITIES[$kind] ?? '';
        if (!in_array('*', $capabilities, true) && !in_array($requiredCapability, $capabilities, true)) {
            return $this->error(403, 'forbidden');
        }

        $requestId = (string)$envelope['request_id'];
        $canonical = self::canonicalJson($envelope);
        $requestHash = hash('sha256', $canonical);
        $occurredAt = gmdate('Y-m-d H:i:s', (int)$validation['occurred_ts']);

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT request_hash,state,result_json,error_code FROM deferred_work ' .
                'WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $select->execute([$installationId, $requestId]);
            $existing = $select->fetch(PDO::FETCH_ASSOC);
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
                'INSERT INTO deferred_work(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,occurred_at) ' .
                'VALUES(?,?,?,?,?,?,?,?)'
            );
            $insert->execute([$installationId, $requestId, $requestHash, $kind, $projectionId, $canonical, 'pending_sync', $occurredAt]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 202, 'body' => ['ok' => true, 'state' => 'pending_sync', 'deduplicated' => false]];
    }

    public function list(array $session, int $limit = 100): array
    {
        $installationId = trim((string)($session['installation_id'] ?? ''));
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $capabilities = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if ($installationId === '' || $projectionId === '') return $this->error(401, 'unauthorized');
        $limit = min(100, max(1, $limit));

        $sql = 'SELECT request_id,kind,actor_projection_id,state,occurred_at,error_code,created_at,updated_at ' .
               'FROM deferred_work WHERE installation_id=?';
        $params = [$installationId];
        if (!in_array('*', $capabilities, true)) {
            $sql .= ' AND actor_projection_id=?';
            $params[] = $projectionId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['status' => 200, 'body' => ['ok' => true, 'items' => is_array($items) ? $items : []]];
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
            'SELECT request_id,kind,actor_projection_id,state,occurred_at,result_json,error_code,created_at,updated_at ' .
            'FROM deferred_work WHERE installation_id=? AND request_id=? LIMIT 1'
        );
        $stmt->execute([$installationId, $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $this->error(404, 'not_found');
        if (!in_array('*', $capabilities, true) && !hash_equals($projectionId, (string)$row['actor_projection_id'])) {
            return $this->error(403, 'forbidden');
        }
        $row['ok'] = true;
        $row['result'] = self::decodeObject($row['result_json'] ?? null);
        unset($row['result_json']);
        return ['status' => 200, 'body' => $row];
    }

    public function claim(string $installationId, int $leaseSeconds = 30): array
    {
        $installationId = trim($installationId);
        $leaseSeconds = max(10, min(120, $leaseSeconds));
        $this->pdo->beginTransaction();
        try {
            $install = $this->pdo->prepare('SELECT active,remote_enabled FROM installations WHERE installation_id=? FOR UPDATE');
            $install->execute([$installationId]);
            $flags = $install->fetch(PDO::FETCH_ASSOC);
            if (!is_array($flags) || !(bool)$flags['active']) {
                $this->pdo->rollBack();
                return $this->error(409, 'installation_inactive');
            }
            if (!(bool)$flags['remote_enabled']) {
                $this->pdo->rollBack();
                return $this->error(409, 'remote_disabled');
            }

            $clear = $this->pdo->prepare(
                "UPDATE deferred_work SET lease_token=NULL,lease_expires_at=NULL WHERE installation_id=? " .
                "AND state='pending_sync' AND lease_expires_at IS NOT NULL AND lease_expires_at<UTC_TIMESTAMP()"
            );
            $clear->execute([$installationId]);

            $select = $this->pdo->prepare(
                "SELECT request_id,envelope_json FROM deferred_work WHERE installation_id=? AND state='pending_sync' " .
                'AND lease_token IS NULL ORDER BY occurred_at,created_at,request_id LIMIT 1 FOR UPDATE'
            );
            $select->execute([$installationId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return $this->error(404, 'empty_queue');
            }

            $leaseToken = bin2hex(random_bytes(32));
            $update = $this->pdo->prepare(
                "UPDATE deferred_work SET lease_token=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)," .
                "claimed_at=UTC_TIMESTAMP(),attempt_count=attempt_count+1 WHERE installation_id=? AND request_id=? AND state='pending_sync'"
            );
            $update->execute([$leaseToken, $leaseSeconds, $installationId, (string)$row['request_id']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'lease_token' => $leaseToken,
            'lease_seconds' => $leaseSeconds,
            'request' => self::decodeObject($row['envelope_json'] ?? null) ?? [],
        ]];
    }

    public function ack(string $installationId, array $ack): array
    {
        $requestId = trim((string)($ack['request_id'] ?? ''));
        $leaseToken = (string)($ack['lease_token'] ?? '');
        $state = (string)($ack['state'] ?? '');
        if ($requestId === '' || $leaseToken === '' || !in_array($state, self::TERMINAL, true)) {
            return $this->error(400, 'invalid_ack');
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT state,lease_token FROM deferred_work WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $select->execute([$installationId, $requestId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return $this->error(404, 'not_found');
            }
            $current = (string)$row['state'];
            if (in_array($current, self::TERMINAL, true)) {
                if ($current !== $state) {
                    $this->pdo->rollBack();
                    return $this->error(409, 'terminal_state_conflict');
                }
                $this->pdo->commit();
                return ['status' => 200, 'body' => ['ok' => true, 'state' => $current, 'deduplicated' => true]];
            }
            if ($current !== 'pending_sync' || !hash_equals((string)($row['lease_token'] ?? ''), $leaseToken)) {
                $this->pdo->rollBack();
                return $this->error(409, 'lease_conflict');
            }
            $this->writeTerminal($installationId, $requestId, $state, $ack);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['status' => 200, 'body' => ['ok' => true, 'state' => $state, 'deduplicated' => false]];
    }

    public function reconcile(string $installationId, array $body): array
    {
        $requestId = trim((string)($body['request_id'] ?? ''));
        $state = (string)($body['state'] ?? '');
        if ($requestId === '' || !in_array($state, ['committed', 'rejected'], true)) {
            return $this->error(400, 'invalid_reconcile');
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT state FROM deferred_work WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $select->execute([$installationId, $requestId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return $this->error(404, 'not_found');
            }
            $current = (string)$row['state'];
            if ($current === $state) {
                $this->pdo->commit();
                return ['status' => 200, 'body' => ['ok' => true, 'state' => $state, 'deduplicated' => true]];
            }
            if ($current !== 'needs_review') {
                $this->pdo->rollBack();
                return $this->error(409, 'state_conflict');
            }
            $this->writeTerminal($installationId, $requestId, $state, $body);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['status' => 200, 'body' => ['ok' => true, 'state' => $state, 'deduplicated' => false]];
    }

    public function periodStatus(string $installationId, string $fromDate, string $toDate): array
    {
        if (!self::validDate($fromDate) || !self::validDate($toDate) || $toDate < $fromDate) {
            return $this->error(400, 'invalid_period');
        }
        $counts = ['pending_sync' => 0, 'committed' => 0, 'needs_review' => 0, 'rejected' => 0];
        $stmt = $this->pdo->prepare(
            'SELECT state,COUNT(*) AS c FROM deferred_work WHERE installation_id=? AND occurred_at>=? ' .
            'AND occurred_at<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY state'
        );
        $stmt->execute([$installationId, $fromDate . ' 00:00:00', $toDate . ' 00:00:00']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state = (string)$row['state'];
            if (array_key_exists($state, $counts)) $counts[$state] = (int)$row['c'];
        }
        return ['status' => 200, 'body' => [
            'ok' => true,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'counts' => $counts,
            'blocking' => $counts['pending_sync'] + $counts['needs_review'],
        ]];
    }

    private function writeTerminal(string $installationId, string $requestId, string $state, array $body): void
    {
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($resultJson)) $resultJson = '{}';
        $errorCode = substr((string)($body['error_code'] ?? ''), 0, 80);
        $update = $this->pdo->prepare(
            'UPDATE deferred_work SET state=?,result_json=?,error_code=?,lease_token=NULL,lease_expires_at=NULL ' .
            'WHERE installation_id=? AND request_id=?'
        );
        $update->execute([$state, $resultJson, $errorCode, $installationId, $requestId]);
    }

    private function validateEnvelope(array $envelope): array
    {
        $errors = [];
        $requestId = trim((string)($envelope['request_id'] ?? ''));
        if ($requestId === '' || strlen($requestId) > 96 || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1) $errors[] = 'request_id';
        $kind = trim((string)($envelope['kind'] ?? ''));
        if (!in_array($kind, self::KINDS, true)) $errors[] = 'kind';
        $createdTs = strtotime(trim((string)($envelope['created_at'] ?? ''))) ?: 0;
        $occurredTs = strtotime(trim((string)($envelope['occurred_at'] ?? ''))) ?: 0;
        if ($createdTs <= 0) $errors[] = 'created_at';
        if ($occurredTs <= 0) $errors[] = 'occurred_at';
        if ($occurredTs > time() + 300) $errors[] = 'occurred_at_future';
        if (array_key_exists('expires_at', $envelope)) $errors[] = 'expires_at';
        if (!isset($envelope['payload']) || !is_array($envelope['payload'])) $errors[] = 'payload';
        if (isset($envelope['expected_version']) && !is_int($envelope['expected_version']) && !is_string($envelope['expected_version'])) $errors[] = 'expected_version';
        if (isset($envelope['expected_state']) && !is_string($envelope['expected_state'])) $errors[] = 'expected_state';
        if (trim((string)($envelope['actor_projection_id'] ?? '')) === '') $errors[] = 'actor_projection_id';
        return ['errors' => $errors, 'occurred_ts' => $occurredTs];
    }

    private static function canonicalJson(array $value): string
    {
        $json = json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) throw new \RuntimeException('Deferred canonical JSON encoding failed.');
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

    private static function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
