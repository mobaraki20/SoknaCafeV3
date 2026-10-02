<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use PDO;
use Sokna\Local\Core\Auth;

final class SubscriberSearchProvider implements SearchProvider
{
    public function __construct(private readonly PDO $pdo,private readonly Auth $auth){}
    public function search(string $query,array $user,int $limit): array
    {
        if((string)($user['role']??'')!=='admin'&&!$this->auth->hasCapability('cashier_accounts',$user))return [];$limit=max(1,min(8,$limit));$terms=SearchNormalizer::terms($query);if($terms===[])return [];
        $clauses=[];$params=[];foreach($terms as $term){$like=SearchNormalizer::likeContains($term);$clauses[]='(name LIKE ? OR mobile_normalized LIKE ?)';$params[]=$like;$params[]=$like;}
        $where=implode(' AND ',$clauses);$st=$this->pdo->prepare("SELECT id,name,mobile,active FROM subscribers WHERE ({$where}) ORDER BY active DESC,CASE WHEN name=? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END,name LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'subscriber:'.(int)$row['id'],'type'=>'subscriber','title'=>(string)$row['name'],'subtitle'=>(string)$row['mobile'].((int)$row['active']===1?'':' · غیرفعال'),'context'=>'مشتری / حساب','actions'=>[['label'=>'حساب مشتری','href'=>'/subscribers/?q='.rawurlencode((string)$row['mobile'])]],'score'=>90];
        return $out;
    }
}
