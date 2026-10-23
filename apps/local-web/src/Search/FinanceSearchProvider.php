<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use Sokna\Local\UI\MoneyDisplay;

use PDO;
use Sokna\Local\Core\Auth;

final class FinanceSearchProvider implements SearchProvider
{
    public function __construct(private readonly PDO $pdo,private readonly Auth $auth){}
    public function search(string $query,array $user,int $limit): array
    {
        if((string)($user['role']??'')!=='admin'&&!$this->auth->hasCapability('cashier_accounts',$user))return [];$limit=max(1,min(8,$limit));$like=SearchNormalizer::likeContains($query);
        $st=$this->pdo->prepare("SELECT id,invoice_number,table_name_snapshot,total,status,destination,settled_at FROM settlement_records WHERE invoice_number LIKE ? OR table_name_snapshot LIKE ? ORDER BY CASE WHEN invoice_number=? THEN 0 WHEN invoice_number LIKE ? THEN 1 ELSE 2 END,settled_at DESC LIMIT {$limit}");$st->execute([$like,$like,$query,SearchNormalizer::likePrefix($query)]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$status=self::settlementStatusLabel((string)$row['status']);$out[]=['id'=>'finance:invoice:'.(int)$row['id'],'type'=>'invoice','title'=>'فاکتور '.MoneyDisplay::digits((string)$row['invoice_number']),'subtitle'=>MoneyDisplay::digits((string)$row['table_name_snapshot']).' · '.MoneyDisplay::money((int)$row['total']).' · '.$status,'context'=>'مالی / فاکتور','actions'=>[['label'=>'مشاهده فاکتور','href'=>'/finance/?tab=receipts&invoice='.rawurlencode((string)$row['invoice_number'])]],'score'=>110];}
        return $out;
    }

    private static function settlementStatusLabel(string $status): string
    {
        return match($status){
            'completed'=>'تکمیل‌شده',
            'reversal'=>'برگشت‌شده',
            'void','cancelled'=>'لغوشده',
            'pending'=>'در انتظار',
            default=>'ثبت‌شده',
        };
    }
}
