<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use RuntimeException;

final class BusinessClock
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $timezone = 'Asia/Tehran',
    ) {}

    /** @return array{business_date:string,shift_key:string,shift_label:string,cutoff:string} */
    public function assignment(DateTimeInterface|string|null $value = null): array
    {
        $tz = new DateTimeZone($this->timezone);
        if ($value instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($value)->setTimezone($tz);
        } elseif (is_string($value) && trim($value) !== '') {
            $dt = new DateTimeImmutable($value, $tz);
        } else {
            $dt = new DateTimeImmutable('now', $tz);
        }

        $cutoff = $this->cutoff();
        $cutoffMinutes = self::clockMinutes($cutoff);
        $clockMinutes = ((int)$dt->format('G')) * 60 + (int)$dt->format('i');
        $businessDate = $clockMinutes < $cutoffMinutes
            ? $dt->modify('-1 day')->format('Y-m-d')
            : $dt->format('Y-m-d');
        $offset = $clockMinutes - $cutoffMinutes;
        if ($offset < 0) $offset += 1440;

        foreach ($this->shifts($cutoff) as $shift) {
            if ($offset >= $shift['start_offset'] && $offset < $shift['end_offset']) {
                return [
                    'business_date'=>$businessDate,
                    'shift_key'=>$shift['key'],
                    'shift_label'=>$shift['label'],
                    'cutoff'=>$cutoff,
                ];
            }
        }
        return ['business_date'=>$businessDate,'shift_key'=>'outside','shift_label'=>'خارج از شیفت','cutoff'=>$cutoff];
    }

    private function cutoff(): string
    {
        $raw = $this->setting('business_day_cutoff', '04:00');
        try { return self::normalizeClock($raw); }
        catch (RuntimeException) { return '04:00'; }
    }

    /** @return list<array{key:string,label:string,start_offset:int,end_offset:int}> */
    private function shifts(string $cutoff): array
    {
        $raw = trim($this->setting('business_shifts_json', ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $candidate = is_array($decoded) ? $decoded : self::defaultShifts();
        try { return self::validateShifts($cutoff, $candidate); }
        catch (RuntimeException) { return self::validateShifts('04:00', self::defaultShifts()); }
    }

    private function setting(string $key, string $default): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    }

    private static function clockMinutes(string $clock): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($clock), $m)) throw new RuntimeException('ساعت معتبر نیست.');
        $h=(int)$m[1]; $min=(int)$m[2];
        if ($h<0||$h>23||$min<0||$min>59) throw new RuntimeException('ساعت معتبر نیست.');
        return $h*60+$min;
    }

    private static function normalizeClock(string $clock): string
    {
        $minutes=self::clockMinutes($clock);
        return sprintf('%02d:%02d', intdiv($minutes,60), $minutes%60);
    }

    private static function defaultShifts(): array
    {
        return [
            ['key'=>'shift_1','label'=>'صبح','start'=>'08:00','end'=>'16:00','active'=>true],
            ['key'=>'shift_2','label'=>'عصر','start'=>'16:00','end'=>'01:00','active'=>true],
        ];
    }

    private static function validateShifts(string $cutoff, array $candidate): array
    {
        $cutoffMinutes=self::clockMinutes($cutoff);
        $normalized=[]; $seen=[];
        foreach ($candidate as $index=>$shift) {
            if (!is_array($shift) || empty($shift['active'])) continue;
            if (count($normalized)>=3) throw new RuntimeException('حداکثر سه شیفت فعال مجاز است.');
            $key=preg_replace('/[^a-z0-9_\-]/i','',(string)($shift['key']??'')) ?: 'shift_legacy_'.($index+1);
            if (isset($seen[$key])) throw new RuntimeException('شناسه دو شیفت نمی‌تواند یکسان باشد.');
            $seen[$key]=true;
            $label=trim((string)($shift['label']??''));
            if ($label==='') throw new RuntimeException('برای هر شیفت فعال یک نام لازم است.');
            $start=self::normalizeClock((string)($shift['start']??''));
            $end=self::normalizeClock((string)($shift['end']??''));
            $startOffset=self::clockMinutes($start)-$cutoffMinutes; if($startOffset<0)$startOffset+=1440;
            $endOffset=self::clockMinutes($end)-$cutoffMinutes; if($endOffset<0)$endOffset+=1440;
            if($endOffset<=$startOffset)$endOffset+=1440;
            if($endOffset>1440) throw new RuntimeException('ساعت یک شیفت از مرز روز عملیاتی عبور می‌کند.');
            $normalized[]=['key'=>$key,'label'=>$label,'start_offset'=>$startOffset,'end_offset'=>$endOffset];
        }
        if(!$normalized) throw new RuntimeException('حداقل یک شیفت عملیاتی باید فعال باشد.');
        usort($normalized,static fn(array $a,array $b):int=>$a['start_offset']<=>$b['start_offset']);
        for($i=1,$n=count($normalized);$i<$n;$i++){
            if($normalized[$i]['start_offset']<$normalized[$i-1]['end_offset']) throw new RuntimeException('ساعت شیفت‌ها نباید هم‌پوشانی داشته باشد.');
        }
        return $normalized;
    }
}
