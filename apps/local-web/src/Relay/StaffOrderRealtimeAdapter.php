<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
use Sokna\Local\Domain\Orders\OrderStaffActionService;

final class StaffOrderRealtimeAdapter
{
    public function __construct(
        private readonly IdentityRepository $identity,
        private readonly OrderStaffActionService $actions,
    ) {}

    public function dispatch(array $envelope): array
    {
        if (trim((string)($envelope['kind'] ?? '')) !== 'order.staff_status') {
            throw new OrderStaffActionException('unsupported_kind', 'عملیات سفارش راه‌دور معتبر نیست.', 422);
        }

        $projection = trim((string)($envelope['actor_projection_id'] ?? ''));
        if (!preg_match('/^user:(\d+)$/', $projection, $match)) {
            throw new OrderStaffActionException('actor_invalid', 'هویت کاربر راه‌دور معتبر نیست.', 403);
        }
        $actor = $this->identity->findActiveById((int)$match[1]);
        if ($actor === null) {
            throw new OrderStaffActionException('actor_invalid', 'حساب کاربری راه‌دور فعال نیست.', 403);
        }

        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
        $orderId = (int)($payload['order_id'] ?? 0);
        $status = trim((string)($payload['status'] ?? ''));
        if ($orderId < 1 || !in_array($status, ['accounted', 'cancelled'], true)) {
            throw new OrderStaffActionException('invalid_action', 'فقط تأیید یا رد سفارش منتظر از راه‌دور مجاز است.', 422);
        }

        return $this->actions->changeStatus(['order_id' => $orderId, 'status' => $status], $actor);
    }
}
