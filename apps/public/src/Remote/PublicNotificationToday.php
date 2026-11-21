<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Remote;

/** Fail-closed day-scoped bell preview. Full inbox remains a separate historic view. */
final class PublicNotificationToday
{
    public static function preview(array $payload,array $meta,?\DateTimeImmutable $now=null): array
    {
        $clock=is_array($payload['business_clock']??null)?$payload['business_clock']:[];
        $day=(string)($clock['business_date']??'');$cutoff=(string)($clock['cutoff']??'');
        $unavailable=['today_items'=>[],'today_unread'=>null,'today_available'=>false,
            'today_status'=>'unavailable','today_business_date'=>$day,
            'today_message'=>'اعلان‌های امروز هنوز با لوکال همگام نشده‌اند.'];
        if(!self::validDay($day)||preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$cutoff)!==1
           ||($meta['stale']??true)!==false||!is_array($payload['items']??null))return $unavailable;
        try{
            $tz=new \DateTimeZone('Asia/Tehran');
            $at=($now??new \DateTimeImmutable('now',$tz))->setTimezone($tz);
            $start=new \DateTimeImmutable($day.' '.$cutoff.':00',$tz);
            $today=$at<$at->setTime((int)substr($cutoff,0,2),(int)substr($cutoff,3,2))?$at->modify('-1 day')->format('Y-m-d'):$at->format('Y-m-d');
            if($today!==$day)return $unavailable;
            $end=$start->modify('+1 day');
        }catch(\Throwable){return $unavailable;}
        $items=[];$unread=0;
        foreach($payload['items'] as $row){
            if(!is_array($row))continue;
            $stamp=trim((string)($row['created_at']??''));
            $title=trim((string)($row['title']??''));
            if($stamp===''||$title==='')continue;
            try{$when=new \DateTimeImmutable($stamp,$tz);}catch(\Throwable){continue;}
            if($when<$start||$when>=$end)continue;
            if(trim((string)($row['read_at']??''))==='')$unread++;
            if(count($items)<8)$items[]=['title'=>$title,'body'=>(string)($row['body']??''),
                'created_at'=>$stamp,'read_at'=>(string)($row['read_at']??''),
                'target_url'=>(string)($row['target_url']??'')];
        }
        return ['today_items'=>$items,'today_unread'=>$unread,'today_available'=>true,
            'today_status'=>'fresh','today_business_date'=>$day,'today_message'=>''];
    }
    private static function validDay(string $s):bool
    {return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$s,$m)===1
        &&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
}
