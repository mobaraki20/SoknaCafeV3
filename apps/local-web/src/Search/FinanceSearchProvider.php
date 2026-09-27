<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use PDO;
use Sokna\Local\Core\Auth;

final class FinanceSearchProvider implements SearchProvider
{
    public function __construct(private readonly PDO $pdo,private readonly Auth $auth){}
    public function search(string $query,array $user,int $limit): array
    {
        if((string)($user['role']??'')!=='admin'&&!$this->auth->hasCapability('cashier_accounts',$user))return [];$limit=max(1,min(6,$limit));$prefix=SearchNormalizer::likePrefix($query);
        $st=$this->pdo->prepare("SELECT id,invoice_number,table_name_snapshot,total,status,destination,settled_at FROM settlement_records WHERE invoice_number LIKE ? ORDER BY CASE WHEN invoice_number=? THEN 0 ELSE 1 END,settled_at DESC LIMIT {$limit}");$st->execute([$prefix,$query]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'finance:invoice:'.(int)$row['id'],'type'=>'invoice','title'=>'فاکتور '.(string)$row['invoice_number'],'subtitle'=>(string)$row['table_name_snapshot'].' · '.number_format((int)$row['total']).' تومان · '.(string)$row['status'],'context'=>'مالی / فاکتور','actions'=>[['label'=>'مشاهده فاکتور','href'=>'/finance/?tab=receipts&invoice='.rawurlencode((string)$row['invoice_number'])]],'score'=>110];
        return $out;
    }
}
