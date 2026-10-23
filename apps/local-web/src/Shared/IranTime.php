<?php
declare(strict_types=1);
namespace Sokna\Shared;

/** Explicit boundaries: Local business SQL is Tehran; Public/system SQL is UTC.
 * Wire instants always carry an offset. Civil dates and clock-only values never shift.
 */
final class IranTime
{
    public const ZONE = 'Asia/Tehran';
    public static function parse(string $value, string $basis = self::ZONE): \DateTimeImmutable
    {
        $raw=trim($value);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?$/D',$raw))throw new \InvalidArgumentException('invalid_datetime');
        try{$dt=new \DateTimeImmutable($raw,new \DateTimeZone($basis));}catch(\Throwable $e){throw new \InvalidArgumentException('invalid_datetime',0,$e);}
        $errors=\DateTimeImmutable::getLastErrors();
        if(is_array($errors)&&($errors['warning_count']||$errors['error_count']))throw new \InvalidArgumentException('invalid_datetime');
        return $dt;
    }
    public static function iso(string $value,string $basis=self::ZONE): string
    {
        if(trim($value)==='')return $value;
        return self::parse($value,$basis)->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d\TH:i:s.uP');
    }
    public static function epoch(string $value,string $basis='UTC'): int|false
    {
        try{return self::parse($value,$basis)->getTimestamp();}catch(\Throwable){return false;}
    }
    public static function wireEpoch(string $value): int
    {
        if(!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i',trim($value)))return 0;
        try{return self::parse($value,'UTC')->getTimestamp();}catch(\Throwable){return 0;}
    }
    public static function localForm(string $value,string $basis=self::ZONE): string
    {return self::parse($value,$basis)->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d\TH:i');}
    public static function utcDb(string $value,string $basis=self::ZONE): string
    {return self::parse($value,$basis)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    public static function localDb(string $value,string $basis=self::ZONE): string
    {return self::parse($value,$basis)->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i:s');}
    public static function digits(string|int|float $value): string
    {return strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',','=>'٬','.'=>'٫']);}
    public static function display(string $value,string $basis=self::ZONE): string
    {
        if(trim($value)==='')return '—';
        try{$dt=self::parse($value,$basis)->setTimezone(new \DateTimeZone(self::ZONE));}catch(\Throwable){return '—';}
        [$jy,$jm,$jd]=self::jalali((int)$dt->format('Y'),(int)$dt->format('n'),(int)$dt->format('j'));
        return self::digits(sprintf('%04d/%02d/%02d · %s',$jy,$jm,$jd,$dt->format('H:i')));
    }
    /** Only timestamp-shaped values under declared time field names are serialized.
     * Opaque signed/hash-bearing payloads keep byte-level contract ownership.
     */
    public static function transport(array $data,string $basis=self::ZONE): array
    {
        $sealed=isset($data['content_hash'])||isset($data['source_version'])||isset($data['signature']);
        $utcKeys=['starts_at','ends_at','published_at','archived_at','read_at','delivered_at_utc','dispatched_at','server_utc','database_utc'];
        foreach($data as $key=>$value){
            if(is_array($value)){if($sealed&&in_array($key,['payload','snapshot','manifest'],true))continue;$data[$key]=self::transport($value,$basis);continue;}
            if(!is_string($key)||!is_string($value)||!preg_match('/(?:_at|_at_utc|_time|_utc)$|^(?:last_activity|started_at|ended_at|valid_from|valid_until|effective_from)$/D',$key))continue;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/D',$value))continue;
            try{$data[$key]=self::iso($value,in_array($key,$utcKeys,true)||str_ends_with($key,'_utc')?'UTC':$basis);}catch(\InvalidArgumentException){}
        }
        return $data;
    }
    private static function jalali(int $gy,int $gm,int $gd): array
    {
        $gdm=[0,31,59,90,120,151,181,212,243,273,304,334];$gy2=$gm>2?$gy+1:$gy;
        $days=355666+365*$gy+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$gdm[$gm-1];
        $jy=-1595+33*intdiv($days,12053);$days%=12053;$jy+=4*intdiv($days,1461);$days%=1461;
        if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;}
        return $days<186?[$jy,1+intdiv($days,31),1+$days%31]:[$jy,7+intdiv($days-186,30),1+($days-186)%30];
    }
}
