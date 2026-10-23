<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Canonical application time authority.
 *
 * Absolute event time is represented as UTC. User-facing calendar/time is
 * projected into the configured application timezone (Asia/Tehran by default).
 * Business-day assignment remains a separate domain concern owned by
 * Orders\BusinessClock.
 */
final class TimeAuthority
{
    private readonly DateTimeZone $utc;
    private readonly DateTimeZone $local;

    public function __construct(private readonly string $timezone = 'Asia/Tehran')
    {
        $this->utc = new DateTimeZone('UTC');
        $this->local = new DateTimeZone($timezone);
    }

    public function timezone(): string { return $this->timezone; }

    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }

    public function nowUtcIso(): string
    {
        return $this->nowUtc()->format('Y-m-d\TH:i:s\Z');
    }

    public function nowUtcDb(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s');
    }

    public function localNow(): DateTimeImmutable
    {
        return $this->nowUtc()->setTimezone($this->local);
    }

    public function localIso(DateTimeInterface|string|null $value = null): string
    {
        return $this->instant($value)->setTimezone($this->local)->format('Y-m-d\TH:i:sP');
    }

    public function civilDate(DateTimeInterface|string|null $value = null): string
    {
        return $this->instant($value)->setTimezone($this->local)->format('Y-m-d');
    }

    /**
     * Parse a true instant. Bare SQL datetime is considered UTC deliberately;
     * cross-component/event APIs must never send a bare datetime.
     */
    public function instant(DateTimeInterface|string|null $value = null): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($this->utc);
        }
        if ($value === null || trim((string)$value) === '') return $this->nowUtc();
        $raw = trim((string)$value);
        $hasOffset = (bool)preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw);
        return (new DateTimeImmutable($raw, $hasOffset ? null : $this->utc))->setTimezone($this->utc);
    }

    /** Convert a local wall-clock form value into canonical UTC DB format. */
    public function localInputToUtcDb(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') return $this->nowUtcDb();
        $normalized = str_replace('T', ' ', $raw);
        $formats = ['!Y-m-d H:i:s', '!Y-m-d H:i'];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $normalized, $this->local);
            $errors=DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors===false || (!$errors['warning_count']&&!$errors['error_count']))) return $dt->setTimezone($this->utc)->format('Y-m-d H:i:s');
        }
        throw new \InvalidArgumentException('invalid_local_datetime');
    }

    /** Convert a canonical UTC SQL datetime into an unambiguous transport value. */
    public function utcDbToIso(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') return '';
        try {
            return (new DateTimeImmutable($raw, $this->utc))->setTimezone($this->utc)->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Diagnostic snapshot compares PHP/server clock with the database clock.
     * Database epoch is used only as evidence; PHP UTC remains the transport clock.
     */
    public function diagnostics(PDO $pdo): array
    {
        $serverEpoch = microtime(true);
        $server = $this->nowUtc();
        $databaseUtc = '';
        $databaseEpoch = null;
        try {
            $row = $pdo->query("SELECT DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%dT%H:%i:%s.%fZ') utc_now, UNIX_TIMESTAMP() epoch_now")->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $databaseUtc = (string)($row['utc_now'] ?? '');
                $databaseEpoch = isset($row['epoch_now']) ? (float)$row['epoch_now'] : null;
            }
        } catch (Throwable) {}
        $skew = $databaseEpoch === null ? null : round($databaseEpoch - $serverEpoch, 3);
        return [
            'timezone'=>$this->timezone,
            'server_utc'=>$server->format('Y-m-d\TH:i:s\Z'),
            'iran_local'=>$server->setTimezone($this->local)->format('Y-m-d\TH:i:sP'),
            'civil_date'=>$server->setTimezone($this->local)->format('Y-m-d'),
            'database_utc'=>$databaseUtc,
            'database_server_skew_seconds'=>$skew,
            'aligned'=>$skew === null ? null : abs($skew) <= 5.0,
        ];
    }
}
