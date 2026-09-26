<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use DateTimeImmutable;
use PDO;
use PDOException;
use Sokna\Local\Domain\Expenses\ExpenseException;

final class FinancialPeriodIdentityService
{
    public function __construct(private readonly PDO $pdo) {}

    public function forDateTx(string $date,int $actorUserId=0): array
    {
        $this->requireTx();
        $day=$this->normalizeDay($date);
        $stmt=$this->pdo->prepare(
            'SELECT * FROM financial_periods WHERE ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$day]);$period=$stmt->fetch(PDO::FETCH_ASSOC);
        if(is_array($period))return $period;

        $bounds=self::boundsForDate($day);
        $insert=$this->pdo->prepare(
            "INSERT INTO financial_periods(title,start_date,end_date,status,opened_by_user_id) VALUES(?,?,?,'open',?)"
        );
        try{
            $insert->execute([
                $bounds['title'],$bounds['start_date'],$bounds['end_date'],$actorUserId>0?$actorUserId:null
            ]);
        }catch(PDOException $e){
            if((string)$e->getCode()!=='23000')throw $e;
        }
        $stmt->execute([$day]);$period=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($period))throw new ExpenseException('period_unavailable','دوره مالی متناسب با تاریخ ساخته نشد.',500);
        return $period;
    }

    public function byIdTx(int $periodId): array
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare('SELECT * FROM financial_periods WHERE id=? FOR UPDATE');
        $stmt->execute([$periodId]);$period=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($period))throw new ExpenseException('period_not_found','دوره مالی پیدا نشد.',404);
        return $period;
    }

    public function assertAccepts(array $period,string $occurredAt,bool $allowClosedPeriod=false): void
    {
        $day=substr($occurredAt,0,10);
        if($day<(string)$period['start_date']||$day>(string)$period['end_date'])
            throw new ExpenseException('period_mismatch','تاریخ هزینه با دوره مالی انتخاب‌شده هم‌خوان نیست.',409);
        if((string)$period['status']==='closed'&&!$allowClosedPeriod)
            throw new ExpenseException('closed_financial_period','دوره مالی این هزینه بسته شده است؛ اصلاح عادی روی دوره بسته مجاز نیست.',409,[
                'financial_period_id'=>(int)$period['id']
            ]);
    }

    public static function boundsForDate(string $date): array
    {
        $time=strtotime($date);
        if($time===false)throw new ExpenseException('invalid_date','تاریخ دوره مالی معتبر نیست.',422);
        [$jy]=self::gregorianToJalali((int)date('Y',$time),(int)date('n',$time),(int)date('j',$time));
        [$sy,$sm,$sd]=self::jalaliToGregorian($jy,1,1);
        [$ey,$em,$ed]=self::jalaliToGregorian($jy+1,1,1);
        $end=(new DateTimeImmutable(sprintf('%04d-%02d-%02d',$ey,$em,$ed)))->modify('-1 day');
        return [
            'jalali_year'=>$jy,
            'title'=>'سال مالی '.$jy,
            'start_date'=>sprintf('%04d-%02d-%02d',$sy,$sm,$sd),
            'end_date'=>$end->format('Y-m-d'),
        ];
    }

    public static function gregorianToJalali(int $gy,int $gm,int $gd): array
    {
        $gdm=[0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2=$gm>2?$gy+1:$gy;
        $days=355666+(365*$gy)+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$gdm[$gm-1];
        $jy=-1595+(33*intdiv($days,12053));$days%=12053;
        $jy+=4*intdiv($days,1461);$days%=1461;
        if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;}
        if($days<186){$jm=1+intdiv($days,31);$jd=1+($days%31);}
        else{$jm=7+intdiv($days-186,30);$jd=1+(($days-186)%30);}
        return [$jy,$jm,$jd];
    }

    public static function jalaliToGregorian(int $jy,int $jm,int $jd): array
    {
        $jy+=1595;
        $days=-355668+(365*$jy)+(intdiv($jy,33)*8)+intdiv(($jy%33)+3,4)+$jd;
        $days+=$jm<7?($jm-1)*31:(($jm-7)*30)+186;
        $gy=400*intdiv($days,146097);$days%=146097;
        if($days>36524){
            $gy+=100*intdiv(--$days,36524);$days%=36524;
            if($days>=365)$days++;
        }
        $gy+=4*intdiv($days,1461);$days%=1461;
        if($days>365){$gy+=intdiv($days-1,365);$days=($days-1)%365;}
        $gd=$days+1;
        $leap=(($gy%4===0)&&($gy%100!==0))||($gy%400===0);
        $months=[0,31,$leap?29:28,31,30,31,30,31,31,30,31,30,31];
        $gm=1;while($gm<=12&&$gd>$months[$gm]){$gd-=$months[$gm];$gm++;}
        return [$gy,$gm,$gd];
    }

    private function normalizeDay(string $date): string
    {
        $ts=strtotime(trim($date));
        if($ts===false)throw new ExpenseException('invalid_date','تاریخ دوره مالی معتبر نیست.',422);
        return date('Y-m-d',$ts);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Financial Period identity operation requires an open transaction.');
    }
}
