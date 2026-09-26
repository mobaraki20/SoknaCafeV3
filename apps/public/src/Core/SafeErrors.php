<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use Throwable;

final class SafeErrors
{
    public static function response(int $status, string $code, ?string $correlationId = null): array
    {
        $status = max(400, min(599, $status));
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code) !== 1) $code = 'public_error';
        return [
            'status' => $status,
            'body' => [
                'ok' => false,
                'error' => $code,
                'correlation_id' => self::correlationId($correlationId),
            ],
        ];
    }

    public static function fromThrowable(Throwable $error, ?string $correlationId = null): array
    {
        // Never expose exception class/message/trace or connection details to the caller.
        return self::response(500, 'public_internal_error', $correlationId);
    }

    public static function correlationId(?string $candidate = null): string
    {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $candidate) === 1) {
            return $candidate;
        }
        return bin2hex(random_bytes(16));
    }
}
