<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Reporting;
use DateTimeImmutable;use PDO;
final class ReportingService
{
    public function __construct(private readonly PDO $pdo){}
    public function report(?string $from=null,?string $to=null): array
    {
        [$from,$to]=$this->range($from,$to);$p=[$from,$to];
        $summary=$this->one("SELECT COALESCE(SUM(CASE WHEN status='completed' THEN total ELSE -total END),0) net_sales,SUM(status='completed') completed_settlements,SUM(status='reversal') reversals,COALESCE(AVG(CASE WHEN status='completed' THEN total END),0) avg_ticket FROM settlement_records WHERE business_date BETWEEN ? AND ?",$p);
        $orders=$this->one("SELECT COUNT(*) order_count,SUM(status='cancelled') cancelled_orders,SUM(order_source='guest') guest_orders,SUM(order_source='staff') staff_orders FROM orders WHERE business_date BETWEEN ? AND ?",$p);
        $expenses=$this->one("SELECT COALESCE(SUM(CASE WHEN status='reversal' THEN -amount ELSE amount END),0) net_expenses FROM expenses WHERE DATE(occurred_at) BETWEEN ? AND ?",$p);
        $daily=$this->all("SELECT business_date,COUNT(*) settlement_count,COALESCE(SUM(CASE WHEN status='completed' THEN total ELSE -total END),0) net_sales FROM settlement_records WHERE business_date BETWEEN ? AND ? GROUP BY business_date ORDER BY business_date",$p);
        $destinations=$this->all("SELECT destination,COUNT(*) settlement_count,COALESCE(SUM(CASE WHEN status='completed' THEN total ELSE -total END),0) net_sales FROM settlement_records WHERE business_date BETWEEN ? AND ? GROUP BY destination ORDER BY net_sales DESC",$p);
        $top=$this->all("SELECT l.item_name_snapshot item_name,SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END) quantity,COALESCE(SUM(CASE WHEN r.status='completed' THEN l.final_amount ELSE -l.final_amount END),0) net_sales FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id WHERE r.business_date BETWEEN ? AND ? GROUP BY l.item_name_snapshot ORDER BY net_sales DESC LIMIT 20",$p);
        $waiter=$this->one("SELECT COUNT(*) waiter_calls,SUM(status='completed') completed_waiter_calls FROM waiter_calls WHERE business_date BETWEEN ? AND ?",$p);
        return ['range'=>['from'=>$from,'to'=>$to],'summary'=>['net_sales'=>(int)($summary['net_sales']??0),'completed_settlements'=>(int)($summary['completed_settlements']??0),'reversals'=>(int)($summary['reversals']??0),'avg_ticket'=>(int)round((float)($summary['avg_ticket']??0)),'order_count'=>(int)($orders['order_count']??0),'cancelled_orders'=>(int)($orders['cancelled_orders']??0),'guest_orders'=>(int)($orders['guest_orders']??0),'staff_orders'=>(int)($orders['staff_orders']??0),'net_expenses'=>(int)($expenses['net_expenses']??0),'waiter_calls'=>(int)($waiter['waiter_calls']??0),'completed_waiter_calls'=>(int)($waiter['completed_waiter_calls']??0)],'daily'=>$daily,'destinations'=>$destinations,'top_items'=>$top,'generated_at'=>gmdate('c')];
    }
    public function remoteSummary(): array{$to=gmdate('Y-m-d');$from=gmdate('Y-m-d',time()-6*86400);$r=$this->report($from,$to);return ['range'=>$r['range'],'summary'=>$r['summary'],'daily'=>$r['daily'],'top_items'=>array_slice($r['top_items'],0,8),'generated_at'=>$r['generated_at']];}
    private function range(?string $from,?string $to): array{$to=$this->date($to?:gmdate('Y-m-d'));$from=$this->date($from?:$to);$a=new DateTimeImmutable($from);$b=new DateTimeImmutable($to);if($a>$b)throw new ReportingException('invalid_range','بازه گزارش معتبر نیست.');if($a->diff($b)->days>365)throw new ReportingException('range_too_large','حداکثر بازه گزارش ۳۶۶ روز است.');return [$from,$to];}
    private function date(string $v): string{$d=DateTimeImmutable::createFromFormat('!Y-m-d',trim($v));if(!$d||$d->format('Y-m-d')!==trim($v))throw new ReportingException('invalid_date','تاریخ گزارش معتبر نیست.');return $d->format('Y-m-d');}
    private function one(string $sql,array $p): array{$q=$this->pdo->prepare($sql);$q->execute($p);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:[];}
    private function all(string $sql,array $p): array{$q=$this->pdo->prepare($sql);$q->execute($p);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];}
}
