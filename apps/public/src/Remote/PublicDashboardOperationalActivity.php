<?php
/**
 * Read-only current business-day activity used by Public Staff.
 * Historical notifications are intentionally excluded from this stream.
 */
declare(strict_types=1);
namespace Sokna\PublicEdge\Remote;
final class PublicDashboardOperationalActivity
{
    /** @return array{items:array,error:?array} */
    public static function forCurrentDay(array $projection,string $day,string $cutoff,bool $stale): array
    {
        $missing=['items'=>[],'error'=>['message'=>'فعالیت‌های عملیاتی روز کاری جاری هنوز از لوکال همگام نشده‌اند.']];
        if($stale||($projection['format']??'')!=='sokna-dashboard-operational-activity-v1'
            ||($projection['status']??'')!=='available'
            ||($projection['business_date']??'')!==$day
            ||($projection['cutoff']??'')!==$cutoff
            ||!is_array($projection['items']??null)
            ||preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)!==1
            ||preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$cutoff)!==1)return $missing;
        try{
            $tz=new \DateTimeZone('Asia/Tehran');
            $start=new \DateTimeImmutable($day.' '.$cutoff.':00',$tz);
            if($start->format('Y-m-d')!==$day)return $missing;
            $end=$start->modify('+1 day');
        }catch(\Throwable){return $missing;}
        $out=[];
        foreach($projection['items'] as $event){
            if(!is_array($event))continue;
            $title=trim((string)($event['title']??''));
            $stamp=trim((string)($event['created_at']??''));
            if($title===''||$stamp==='')continue;
            try{$at=new \DateTimeImmutable($stamp,$tz);}catch(\Throwable){continue;}
            if($at<$start||$at>=$end)continue;
            $out[]=['title'=>$title,'body'=>(string)($event['body']??''),
                'created_at'=>$stamp,'target_url'=>'/operator/?view=open'];
            if(count($out)>=5)break;
        }
        return ['items'=>$out,'error'=>null];
    }
}
