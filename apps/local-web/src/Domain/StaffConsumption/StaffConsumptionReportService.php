<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use DateInterval;
use DateTimeImmutable;
use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;

final class StaffConsumptionReportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
    ) {}

    public function report(array $filters,array $user): array
    {
        $this->assertReporter($user);[$from,$to]=$this->range($filters['from']??null,$filters['to']??null);
        $personnelId=(int)($filters['personnel_id']??0);$personnelId=$personnelId>0?$personnelId:null;
        $where="sc.status='posted' AND sc.business_date BETWEEN ? AND ?";$params=[$from,$to];
        if($personnelId!==null){$where.=' AND sc.consumer_personnel_id=?';$params[]=$personnelId;}

        $q=$this->pdo->prepare("SELECT COUNT(*) document_count,COALESCE(SUM(sc.menu_value_amount),0) menu_value_amount,COALESCE(SUM(sc.benefit_amount),0) benefit_amount,COALESCE(SUM(sc.discount_amount),0) discount_amount,COALESCE(SUM(sc.payable_amount),0) payable_amount,COALESCE(SUM(sc.known_cost_amount),0) known_cost_amount FROM staff_consumptions sc WHERE {$where}");
        $q->execute($params);$summary=$q->fetch(PDO::FETCH_ASSOC)?:[];

        $waiverWhere="l.occurred_at>=? AND l.occurred_at<? AND l.entry_type IN('waiver','waiver_reversal')";
        $fromDt=$from.' 00:00:00';$toExclusive=(new DateTimeImmutable($to))->add(new DateInterval('P1D'))->format('Y-m-d').' 00:00:00';$waiverParams=[$fromDt,$toExclusive];
        if($personnelId!==null){$waiverWhere.=' AND l.personnel_id=?';$waiverParams[]=$personnelId;}
        $q=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN l.entry_type='waiver' THEN -l.amount_delta ELSE -l.amount_delta END),0) waiver_amount,COUNT(*) waiver_event_count FROM staff_account_ledger l WHERE {$waiverWhere}");
        $q->execute($waiverParams);$waiver=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $summary['waiver_amount']=(int)($waiver['waiver_amount']??0);$summary['waiver_event_count']=(int)($waiver['waiver_event_count']??0);

        $groupParams=$params;
        $q=$this->pdo->prepare("SELECT sc.consumer_personnel_id,sc.consumer_name_snapshot consumer_name,COUNT(*) document_count,SUM(sc.menu_value_amount) menu_value_amount,SUM(sc.benefit_amount) benefit_amount,SUM(sc.payable_amount) payable_amount,SUM(sc.known_cost_amount) known_cost_amount FROM staff_consumptions sc WHERE {$where} GROUP BY sc.consumer_personnel_id,sc.consumer_name_snapshot ORDER BY menu_value_amount DESC,consumer_name");
        $q->execute($groupParams);$byConsumer=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->pdo->prepare("SELECT sc.recorded_by_user_id,u.display_name recorder_name,COUNT(*) document_count,SUM(sc.menu_value_amount) menu_value_amount,SUM(sc.benefit_amount) benefit_amount,SUM(sc.payable_amount) payable_amount FROM staff_consumptions sc JOIN users u ON u.id=sc.recorded_by_user_id WHERE {$where} GROUP BY sc.recorded_by_user_id,u.display_name ORDER BY document_count DESC,recorder_name");
        $q->execute($groupParams);$byRecorder=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->pdo->prepare("SELECT sc.public_code,sc.consumer_name_snapshot consumer_name,u.display_name recorder_name,sc.business_date,sc.menu_value_amount,sc.benefit_amount,sc.discount_amount,sc.payable_amount,sc.known_cost_amount,(SELECT GROUP_CONCAT(CONCAT(l.item_name_snapshot,' × ',l.quantity) ORDER BY l.id SEPARATOR '، ') FROM staff_consumption_lines l WHERE l.consumption_id=sc.id) items_summary FROM staff_consumptions sc JOIN users u ON u.id=sc.recorded_by_user_id WHERE {$where} ORDER BY sc.business_date DESC,sc.id DESC LIMIT 250");
        $q->execute($groupParams);$rows=$q->fetchAll(PDO::FETCH_ASSOC);

        return ['success'=>true,'range'=>['from'=>$from,'to'=>$to,'personnel_id'=>$personnelId],'summary'=>$summary,'by_consumer'=>$byConsumer,'by_recorder'=>$byRecorder,'rows'=>$rows];
    }

    private function assertReporter(array $user): void
    {
        $fresh=$this->identity->findActiveById((int)($user['id']??0));
        if($fresh===null||!$this->capabilities->has('staff_consumption_reports',$fresh))throw new StaffConsumptionException('forbidden','دسترسی گزارش مصرف پرسنل فعال نیست.',403);
    }

    private function range(mixed $from,mixed $to): array
    {
        $from=$this->date($from,date('Y-m-01'));$to=$this->date($to,date('Y-m-d'));
        if($to<$from)throw new StaffConsumptionException('report_range_invalid','بازه گزارش معتبر نیست.',422);
        $a=new DateTimeImmutable($from);$b=new DateTimeImmutable($to);$days=(int)$a->diff($b)->format('%a');
        if($days>365)throw new StaffConsumptionException('report_range_too_large','بازه گزارش حداکثر ۳۶۶ روز است.',422);
        return [$from,$to];
    }

    private function date(mixed $value,string $default): string
    {
        $v=trim((string)($value??''));if($v==='')return $default;$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if($dt===false||$dt->format('Y-m-d')!==$v)throw new StaffConsumptionException('report_date_invalid','تاریخ گزارش معتبر نیست.',422);return $v;
    }
}
