<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Remote;

use PDO;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Sokna\PublicEdge\Core\CanonicalJson;
use Throwable;

final class RemoteReadModelService
{
    private const MODEL_CAPABILITIES = [
        'operations' => 'operations.read',
        'preparation' => 'preparation.read',
        'inventory' => 'inventory.read',
        'inventory_cost' => 'inventory.cost.read',
        'reports' => 'reports.read',
        'notifications' => 'notifications.read',
        'deferred_context' => 'deferred.context',
        'table_draft_context' => 'orders.table_draft',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectivityService $connectivity,
        private readonly ?StaffPushService $staffPush = null,
    ) {
    }

    public function sync(string $installationId, array $body): array
    {
        $installationId = trim($installationId);
        $models = $body['models'] ?? null;
        if ($installationId === '' || !is_array($models)) {
            return $this->error(400, 'invalid_read_model_payload');
        }

        $synced = 0;
        $unchanged = 0;
        $notificationPayloads = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($models as $model) {
                if (!is_array($model)) continue;

                $format = trim((string)($model['format'] ?? ''));
                $modelKey = trim((string)($model['model_key'] ?? ''));
                $sourceVersion = strtolower(trim((string)($model['source_version'] ?? '')));
                $generatedAt = trim((string)($model['generated_at'] ?? ''));
                $payload = $model['payload'] ?? null;
                $generatedTs = strtotime($generatedAt);

                if ($format !== 'sokna-remote-read-v1'
                    || !array_key_exists($modelKey, self::MODEL_CAPABILITIES)
                    || !preg_match('/^[a-f0-9]{64}$/', $sourceVersion)
                    || !is_array($payload)
                    || $generatedTs === false) {
                    continue;
                }

                $expected = CanonicalJson::sha256($payload);
                if (!hash_equals($expected, $sourceVersion)) continue;

                $check = $this->pdo->prepare(
                    'SELECT source_version FROM remote_read_models WHERE installation_id=? AND model_key=? FOR UPDATE'
                );
                $check->execute([$installationId, $modelKey]);
                $oldVersion = $check->fetchColumn();
                if ($oldVersion !== false && hash_equals((string)$oldVersion, $sourceVersion)) {
                    $this->pdo->prepare(
                        'UPDATE remote_read_models SET last_sync_at=UTC_TIMESTAMP() WHERE installation_id=? AND model_key=?'
                    )->execute([$installationId, $modelKey]);
                    $unchanged++;
                    continue;
                }

                $payloadJson = json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
                $stmt = $this->pdo->prepare(
                    'INSERT INTO remote_read_models(installation_id,model_key,source_version,payload_json,generated_at,last_sync_at) '
                    . 'VALUES(?,?,?,?,?,UTC_TIMESTAMP()) '
                    . 'ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),payload_json=VALUES(payload_json),'
                    . 'generated_at=VALUES(generated_at),last_sync_at=UTC_TIMESTAMP()'
                );
                $stmt->execute([
                    $installationId,
                    $modelKey,
                    $sourceVersion,
                    $payloadJson,
                    gmdate('Y-m-d H:i:s', $generatedTs),
                ]);
                $synced++;
                if ($modelKey === 'notifications') $notificationPayloads[] = $payload;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        if ($this->staffPush !== null) {
            foreach ($notificationPayloads as $payload) $this->staffPush->queueProjectedNotifications($installationId, $payload);
            if ($notificationPayloads !== []) $this->staffPush->processPending(50, $installationId);
        }

        return ['status' => 200, 'body' => ['ok' => true, 'synced' => $synced, 'unchanged' => $unchanged]];
    }

    public function read(array $session, string $modelKey, int $staleSeconds = 90): array
    {
        $modelKey = trim($modelKey);
        $required = self::MODEL_CAPABILITIES[$modelKey] ?? null;
        if ($required === null) return $this->error(404, 'unknown_model');
        if (!$this->hasCapability($session, $required)) return $this->error(403, 'forbidden');

        $installationId = trim((string)($session['installation_id'] ?? ''));
        if ($installationId === '') return $this->error(401, 'unauthorized');

        $stmt = $this->pdo->prepare(
            'SELECT source_version,payload_json,generated_at,last_sync_at '
            . 'FROM remote_read_models WHERE installation_id=? AND model_key=? LIMIT 1'
        );
        $stmt->execute([$installationId, $modelKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $this->error(404, 'model_unavailable');

        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        if ($modelKey === 'preparation') $payload = $this->filterPreparation($payload, $session);
        if ($modelKey === 'deferred_context') $payload = $this->filterDeferredContext($payload, $session);
        if ($modelKey === 'notifications') $payload = $this->filterNotifications($payload, $session);

        $connectivity = $this->connectivity->status($installationId, 45);
        $lastSync = strtotime((string)($row['last_sync_at'] ?? '') . ' UTC') ?: 0;
        $stale = $lastSync < time() - max(30, $staleSeconds)
            || ($connectivity['local_fresh'] ?? false) !== true;

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'model_key' => $modelKey,
                'source_version' => (string)$row['source_version'],
                'generated_at' => (string)$row['generated_at'],
                'last_sync_at' => (string)$row['last_sync_at'],
                'stale' => $stale,
                'connectivity' => $connectivity,
                'payload' => $payload,
            ],
        ];
    }

    private function hasCapability(array $session, string $capability): bool
    {
        $caps = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if (in_array('*', $caps, true) || in_array($capability, $caps, true)) return true;
        return $capability === 'preparation.read' && in_array('preparation.monitor', $caps, true);
    }

    private function filterPreparation(array $payload, array $session): array
    {
        $caps = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if (in_array('*', $caps, true) || in_array('preparation.monitor', $caps, true)) return $payload;

        $areas = is_array($session['preparation_areas'] ?? null)
            ? array_values(array_map('strval', $session['preparation_areas']))
            : [];
        $allow = static fn(array $row): bool => in_array(
            (string)($row['area'] ?? $row['area_key'] ?? ''),
            $areas,
            true,
        );
        $payload['tasks'] = array_values(array_filter(
            is_array($payload['tasks'] ?? null) ? $payload['tasks'] : [],
            static fn(mixed $row): bool => is_array($row) && $allow($row),
        ));
        $payload['adjustments'] = array_values(array_filter(
            is_array($payload['adjustments'] ?? null) ? $payload['adjustments'] : [],
            static fn(mixed $row): bool => is_array($row) && $allow($row),
        ));
        return $payload;
    }

    private function filterDeferredContext(array $payload, array $session): array
    {
        $caps = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if (in_array('*', $caps, true)) return $payload;
        $has = static fn(string $cap): bool => in_array($cap, $caps, true);

        if (!$has('supply.need.defer') && !$has('supply.manage.defer')) $payload['supply_groups'] = [];
        if (!$has('supply.need.defer')
            && !$has('supply.manage.defer')
            && !$has('inventory.waste.defer')
            && !$has('inventory.count_draft.defer')) {
            $payload['inventory_items'] = [];
        }
        if (!$has('inventory.count_draft.defer')) $payload['count_drafts'] = [];
        if (!$has('subscriber.payment.defer')) $payload['subscribers'] = [];
        if (!$has('expense.create.defer')) $payload['expense_categories'] = [];
        return $payload;
    }

    private function filterNotifications(array $payload, array $session): array
    {
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $rows = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $payload['items'] = array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) && hash_equals($projectionId, (string)($row['projection_id'] ?? ''))));
        foreach ($payload['items'] as &$row) unset($row['projection_id']);
        unset($row);
        return $payload;
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
