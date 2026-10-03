<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

use Sokna\PublicEdge\Core\CanonicalJson;
use Sokna\PublicEdge\Realtime\RealtimeService;

final class GuestCompatibilityService
{
    public function __construct(
        private readonly GuestRuntimeService $runtime,
        private readonly RealtimeService $realtime,
    ) {
    }

    public function createOrder(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $state = $this->runtime->actionState($bundle);
        if (($state['enabled'] ?? false) !== true) return $this->localUnavailable();

        $client = trim((string)($data['client_token'] ?? ''));
        $device = trim((string)($data['device_token'] ?? ''));
        if (strlen($client) < 16 || strlen($client) > 80 || strlen($device) > 80) {
            return $this->response(422, ['success' => false, 'code' => 'invalid_order', 'message' => 'اطلاعات سفارش معتبر نیست.']);
        }
        $payload = [
            'table_token' => (string)$table['token'],
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => $device,
            'client_token' => $client,
            'customer_note' => (string)($data['customer_note'] ?? ''),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
        $requestId = self::requestId('guest_order.submit', [
            'client_token' => $client,
            'table_token' => (string)$table['token'],
        ]);
        return $this->relay($installationId, 'guest_order.submit', $payload, $requestId, true, 45, 12000);
    }

    public function guestOrders(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $table = $context['table'];

        $action = trim((string)($data['action'] ?? 'list'));
        $base = [
            'table_token' => (string)$table['token'],
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => trim((string)($data['device_token'] ?? '')),
        ];
        if ($base['device_token'] === '' || strlen($base['device_token']) > 80) {
            return $this->response(422, ['success' => false, 'code' => 'invalid_context', 'message' => 'اطلاعات میز یا دستگاه معتبر نیست.']);
        }

        if ($action === 'list') {
            $requestId = self::requestId('guest_order.list', [
                'table_token' => $base['table_token'],
                'device_token' => $base['device_token'],
            ], false);
            return $this->relay($installationId, 'guest_order.list', $base + ['action' => 'list'], $requestId, false, 30, 7000);
        }

        if (!in_array($action, ['update', 'cancel'], true)) {
            return $this->response(422, ['success' => false, 'code' => 'invalid_action', 'message' => 'عملیات سفارش معتبر نیست.']);
        }

        $payload = $base + [
            'action' => $action,
            'order_code' => trim((string)($data['order_code'] ?? '')),
            'expected_signature' => trim((string)($data['expected_signature'] ?? '')),
            'customer_note' => (string)($data['customer_note'] ?? ''),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
        $kind = $action === 'update' ? 'order.edit' : 'order.cancel';
        $requestId = self::requestId($kind, [
            'order_code' => $payload['order_code'],
            'device_token' => $payload['device_token'],
            'expected_signature' => $payload['expected_signature'],
            'customer_note' => $payload['customer_note'],
            'items' => $payload['items'],
        ]);
        return $this->relay($installationId, $kind, $payload, $requestId, false, 45, 12000);
    }

    public function orderQuote(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $state = $this->runtime->actionState($bundle);
        if (($state['enabled'] ?? false) !== true || ($state['local_fresh'] ?? false) !== true) {
            return $this->response(503, [
                'success' => false,
                'code' => 'local_unavailable',
                'message' => 'پیش‌نمایش مالی سفارش فعلاً در دسترس نیست؛ چند لحظه بعد دوباره تلاش کنید.',
            ]);
        }

        $payload = [
            'quote_mode' => (string)($data['quote_mode'] ?? 'create'),
            'table_token' => (string)$table['token'],
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => trim((string)($data['device_token'] ?? '')),
            'client_token' => trim((string)($data['client_token'] ?? '')),
            'order_code' => trim((string)($data['order_code'] ?? '')),
            'expected_signature' => trim((string)($data['expected_signature'] ?? '')),
            'customer_note' => (string)($data['customer_note'] ?? ''),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
        $requestId = self::requestId('guest_order.quote', [
            'mode' => $payload['quote_mode'],
            'table_token' => $payload['table_token'],
            'device_token' => $payload['device_token'],
            'order_code' => $payload['order_code'],
            'expected_signature' => $payload['expected_signature'],
            'items' => $payload['items'],
        ], false);
        return $this->relay($installationId, 'guest_order.quote', $payload, $requestId, false, 30, 8000);
    }

    public function orderStatus(string $installationId, array $data): array
    {
        $payload = [
            'order_code' => trim((string)($data['order_code'] ?? '')),
            'client_token' => trim((string)($data['client_token'] ?? '')),
        ];
        $requestId = self::requestId('guest_order.status', $payload, false);
        return $this->relay($installationId, 'guest_order.status', $payload, $requestId, false, 30, 7000);
    }

    public function tableContext(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];

        $payload = [
            'table_token' => (string)$table['token'],
            'device_token' => trim((string)($data['device_token'] ?? '')),
        ];
        $state = $this->runtime->actionState($bundle);
        if (($state['local_fresh'] ?? false) === true) {
            $requestId = self::requestId('guest_table.context', [
                'table_token' => $payload['table_token'],
                'device_token' => $payload['device_token'],
            ], false);
            $relay = $this->relay($installationId, 'guest_table.context', $payload, $requestId, false, 30, 6500);
            if (($relay['status'] ?? 500) === 200 && (($relay['body']['success'] ?? false) === true)) return $relay;
        }

        $availability = is_array($bundle['availability'] ?? null) ? $bundle['availability'] : [];
        $acceptance = is_array($availability['order_acceptance'] ?? null)
            ? $availability['order_acceptance']
            : ['cafe' => false, 'kitchen' => false, 'bar' => false];
        $messages = is_array($availability['order_acceptance_messages'] ?? null)
            ? $availability['order_acceptance_messages']
            : [
                'cafe' => 'سفارش‌گیری فعلاً در دسترس نیست.',
                'kitchen' => 'آشپزخانه فعلاً در دسترس نیست.',
                'bar' => 'بار فعلاً در دسترس نیست.',
            ];
        if (($state['enabled'] ?? false) !== true) {
            $acceptance = ['cafe' => false, 'kitchen' => false, 'bar' => false];
            $messages['cafe'] = 'ارتباط زنده با کافه موقتاً در دسترس نیست؛ منو همچنان قابل مشاهده است.';
        }
        $tableStates = is_array($availability['tables'] ?? null) ? $availability['tables'] : [];
        $tableState = is_array($tableStates[(string)(int)($table['id'] ?? 0)] ?? null)
            ? $tableStates[(string)(int)($table['id'] ?? 0)]
            : [];
        $session = is_array($tableState['session'] ?? null) ? $tableState['session'] : null;
        $pending = is_array($tableState['pending_session'] ?? null) ? $tableState['pending_session'] : null;
        $activeCall = is_array($tableState['active_call'] ?? null) ? $tableState['active_call'] : null;

        return $this->response(200, [
            'success' => true,
            'table' => [
                'id' => (int)($table['id'] ?? 0),
                'name' => (string)($table['name'] ?? ''),
                'code' => (string)($table['code'] ?? ''),
            ],
            'session' => ($state['enabled'] ?? false) && $session ? [
                'token' => (string)($session['token'] ?? ''),
                'started_at' => (string)($session['started_at'] ?? ''),
                'status' => (string)($session['status'] ?? 'active'),
            ] : null,
            'pending_session' => ($state['enabled'] ?? false) && $pending ? [
                'token' => (string)($pending['token'] ?? ''),
                'started_at' => (string)($pending['started_at'] ?? ''),
                'status' => (string)($pending['status'] ?? 'pending'),
            ] : null,
            'can_order' => (bool)($state['enabled'] ?? false) && !empty($acceptance['cafe']),
            'order_acceptance' => $acceptance,
            'order_acceptance_messages' => $messages,
            'station_states' => is_array($availability['station_states'] ?? null) ? $availability['station_states'] : [],
            'station_state_hash' => (string)($availability['station_state_hash'] ?? ''),
            'waiter_enabled' => (bool)($state['enabled'] ?? false) && !empty($availability['waiter_enabled_table']),
            'active_call' => ($state['enabled'] ?? false) ? $activeCall : null,
            'late_join' => false,
            'requires_operator_confirmation' => !empty($bundle['snapshot']['features']['table_sessions_enabled']) && $session === null,
            'degraded' => true,
        ]);
    }

    public function waiterCall(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, true);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $action = trim((string)($data['action'] ?? 'status'));
        $isPublic = trim((string)($data['table_token'] ?? '')) === '' && (int)($data['public_table_id'] ?? 0) > 0;

        $payload = [
            'table_token' => (string)$table['token'],
            'public_context' => $isPublic,
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => trim((string)($data['device_token'] ?? '')),
            'client_token' => trim((string)($data['client_token'] ?? '')),
            'call_code' => trim((string)($data['call_code'] ?? '')),
        ];

        if ($action === 'create') {
            $state = $this->runtime->actionState($bundle);
            $allowed = $isPublic ? !empty($bundle['availability']['waiter_enabled_public']) : !empty($bundle['availability']['waiter_enabled_table']);
            if (($state['enabled'] ?? false) !== true) return $this->localUnavailable();
            if (!$allowed) return $this->response(409, ['success' => false, 'code' => 'waiter_disabled', 'message' => 'فراخوان گارسون فعلاً فعال نیست.']);
            $client = $payload['client_token'];
            if (strlen($client) < 16 || strlen($client) > 80) {
                return $this->response(422, ['success' => false, 'code' => 'invalid_request', 'message' => 'درخواست کامل نیست.']);
            }
            $requestId = self::requestId('waiter_call.create', [
                'client_token' => $client,
                'table_token' => $payload['table_token'],
            ]);
            return $this->relay($installationId, 'waiter_call.create', $payload, $requestId, false, 45, 12000);
        }

        if ($action === 'cancel') {
            $requestId = self::requestId('waiter_call.cancel', [
                'call_code' => $payload['call_code'],
                'client_token' => $payload['client_token'],
                'table_token' => $payload['table_token'],
            ]);
            return $this->relay($installationId, 'waiter_call.cancel', $payload, $requestId, false, 30, 8000);
        }

        if ($action === 'status') {
            $requestId = self::requestId('waiter_call.status', [
                'call_code' => $payload['call_code'],
                'table_token' => $payload['table_token'],
            ], false);
            return $this->relay($installationId, 'waiter_call.status', $payload, $requestId, false, 30, 7000);
        }

        return $this->response(422, ['success' => false, 'code' => 'invalid_action', 'message' => 'عملیات فراخوان معتبر نیست.']);
    }

    public function metric(): array
    {
        return $this->response(200, ['success' => true]);
    }

    public static function requestId(string $kind, array $identity, bool $stable = true): string
    {
        $prefix = preg_replace('/[^a-z0-9]+/i', '-', strtolower($kind)) ?: 'guest';
        $seed = CanonicalJson::encode($identity);
        if (!$stable) $seed .= '|' . bin2hex(random_bytes(12));
        return substr($prefix, 0, 30) . ':' . substr(hash('sha256', $seed), 0, 56);
    }

    private function relay(
        string $installationId,
        string $kind,
        array $payload,
        string $requestId,
        bool $requireOrderIntake,
        int $ttlSeconds,
        int $waitMilliseconds,
    ): array {
        $bundle = $this->runtime->bundle($installationId);
        if ($bundle === [] || empty($bundle['snapshot'])) {
            return $this->response(503, ['success' => false, 'code' => 'guest_menu_unpublished', 'message' => 'منوی عمومی در دسترس نیست.']);
        }
        if (!$this->liveState($bundle, $requireOrderIntake)) return $this->localUnavailable();

        $device = trim((string)($payload['device_token'] ?? ''));
        $client = trim((string)($payload['client_token'] ?? ''));
        $actorSeed = $device !== '' ? $device : ($client !== '' ? $client : $requestId);
        $actorProjectionId = 'guest:' . substr(hash('sha256', trim($installationId) . '|' . $actorSeed), 0, 32);
        $now = time();
        $envelope = [
            'request_id' => $requestId,
            'kind' => $kind,
            'created_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + max(10, min(120, $ttlSeconds))),
            'actor_projection_id' => $actorProjectionId,
            'payload' => $payload,
        ];

        $queued = $this->realtime->enqueueGuest($installationId, $envelope);
        if (($queued['body']['ok'] ?? false) !== true) {
            return $this->response((int)($queued['status'] ?? 409), [
                'success' => false,
                'code' => (string)($queued['body']['error'] ?? 'relay_rejected'),
                'message' => 'درخواست قابل ثبت نیست.',
            ]);
        }

        $session = [
            'installation_id' => trim($installationId),
            'projection_id' => $actorProjectionId,
            'capabilities' => [],
        ];
        $deadline = microtime(true) + (max(0, $waitMilliseconds) / 1000);
        do {
            $result = $this->realtime->result($session, $requestId);
            if (($result['status'] ?? 500) === 200 && ($result['body']['terminal'] ?? false) === true) {
                return $this->terminalResponse((array)$result['body']);
            }
            if (microtime(true) >= $deadline) break;
            usleep(120000);
        } while (true);

        return $this->response(504, [
            'success' => false,
            'code' => 'relay_timeout',
            'message' => 'نتیجه درخواست هنوز قطعی نیست؛ دوباره همان عملیات را امتحان کنید.',
        ]);
    }

    private function terminalResponse(array $body): array
    {
        $state = (string)($body['state'] ?? '');
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        $code = (string)($body['error_code'] ?? '');

        if ($state === 'committed') return $this->response(200, $result);
        if ($state === 'expired') {
            return $this->response(504, [
                'success' => false,
                'code' => 'request_expired',
                'message' => 'زمان پاسخ کافه تمام شد؛ دوباره تلاش کنید.',
            ]);
        }
        if ($result === []) {
            $result = ['success' => false, 'code' => $code !== '' ? $code : 'request_rejected', 'message' => 'درخواست تأیید نشد.'];
        }
        return $this->response($this->errorStatus((string)($result['code'] ?? $code)), $result);
    }

    private function bundleAndTable(string $installationId, array $data, bool $allowPublicId): array
    {
        $bundle = $this->runtime->bundle(trim($installationId));
        if ($bundle === [] || empty($bundle['snapshot'])) {
            return ['response' => $this->response(503, ['success' => false, 'code' => 'guest_menu_unpublished', 'message' => 'منوی عمومی در دسترس نیست.'])];
        }
        $table = $this->findTable((array)$bundle['snapshot'], $data, $allowPublicId);
        if ($table === null) {
            $isPublic = trim((string)($data['table_token'] ?? '')) === '' && (int)($data['public_table_id'] ?? 0) > 0;
            return ['response' => $this->response(404, [
                'success' => false,
                'code' => 'invalid_qr',
                'message' => $isPublic ? 'میز انتخاب‌شده فعال نیست.' : 'کد میز معتبر نیست.',
            ])];
        }
        return ['bundle' => $bundle, 'table' => $table];
    }

    private function findTable(array $snapshot, array $data, bool $allowPublicId): ?array
    {
        $token = trim((string)($data['table_token'] ?? ''));
        if ($token !== '') {
            foreach (($snapshot['tables'] ?? []) as $row) {
                if (is_array($row) && isset($row['token']) && hash_equals((string)$row['token'], $token)) return $row;
            }
            return null;
        }
        if ($allowPublicId) {
            $id = (int)($data['public_table_id'] ?? 0);
            foreach (($snapshot['tables'] ?? []) as $row) {
                if (is_array($row) && (int)($row['id'] ?? 0) === $id) return $row;
            }
        }
        return null;
    }

    private function liveState(array $bundle, bool $requireOrderIntake): bool
    {
        $heartbeat = strtotime((string)($bundle['heartbeat_at'] ?? '')) ?: 0;
        $fresh = $heartbeat >= time() - 15;
        $remote = (bool)($bundle['remote_enabled'] ?? false);
        $intake = (bool)($bundle['order_intake_enabled'] ?? false);
        return $remote && $fresh && (!$requireOrderIntake || $intake);
    }

    private function errorStatus(string $code): int
    {
        return match ($code) {
            'invalid_qr', 'order_not_found' => 404,
            'not_owner' => 403,
            'page_expired' => 419,
            'ordering_paused' => 423,
            'rate_limited' => 429,
            'invalid_context', 'invalid_order', 'invalid_tracking', 'invalid_action' => 422,
            'items_unavailable', 'prices_changed', 'service_unavailable', 'session_inactive',
            'order_changed', 'order_not_editable', 'pending_order_exists', 'itemized_settlement_active',
            'settlement_pending', 'takeaway_not_allowed' => 409,
            default => 409,
        };
    }

    private function localUnavailable(): array
    {
        return $this->response(503, [
            'success' => false,
            'code' => 'local_unavailable',
            'message' => 'ارتباط زنده با کافه موقتاً در دسترس نیست.',
        ]);
    }

    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
