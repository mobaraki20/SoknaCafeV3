<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
/** Pure, bounded business-date projection. Never create operational events from notifications. */
final class PublicOperationalActivityProjector
{
    public static function build(array $events,string $businessDate,string $cutoff): array
    {
        $result=['format'=>'sokna-dashboard-operational-activity-v1','status'=>'unavailable',
            'business_date'=>$businessDate,'cutoff'=>$cutoff,'items'=>[]];
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$businessDate)!==1
            ||preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$cutoff)!==1)return $result;
        try{
            $tz=new \DateTimeZone('Asia/Tehran');
            $start=new \DateTimeImmutable($businessDate.' '.$cutoff.':00',$tz);
            if($start->format('Y-m-d')!==$businessDate)return $result;
            $end=$start->modify('+1 day');
        }catch(\Throwable){return $result;}
        $out=[];
        foreach($events as $event){
            if(!is_array($event))continue;
            $stamp=trim((string)($event['created_at']??''));
            $title=trim((string)($event['title']??''));
            if($stamp===''||$title==='')continue;
            try{$at=new \DateTimeImmutable($stamp,$tz);}catch(\Throwable){continue;}
            if($at<$start||$at>=$end)continue;
            $out[]=['event_key'=>(string)($event['event_key']??''),'created_at'=>$stamp,
                'title'=>$title,'body'=>(string)($event['body']??''),
                // Public has no historical invoice deep-link yet. Do not expose
                // broken Local-only links through this projected dashboard feed.
                'target_url'=>'/operator/?view=open'];
            if(count($out)>=8)break;
        }
        $result['status']='available';$result['items']=$out;
        return $result;
    }
}
