<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use PDO;
use Sokna\Local\Core\Auth;

final class InventorySearchProvider implements SearchProvider
{
    private const CAPS=['inventory_view','inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision'];
    public function __construct(private readonly PDO $pdo,private readonly Auth $auth){}
    public function search(string $query,array $user,int $limit): array
    {
        if(!$this->allowed($user))return [];$limit=max(1,min(8,$limit));$terms=SearchNormalizer::terms($query);if($terms===[])return [];
        $where=implode(' AND ',array_fill(0,count($terms),'(i.name LIKE ? OR i.item_code LIKE ?)'));$params=[];
        foreach($terms as $term){$like=SearchNormalizer::likeContains($term);$params[]=$like;$params[]=$like;}
        $st=$this->pdo->prepare("SELECT i.id,i.name,i.item_code,i.base_unit,i.review_status,COALESCE(b.quantity_base,0) quantity_base FROM inventory_items i LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id WHERE i.active=1 AND {$where} ORDER BY CASE WHEN i.name=? THEN 0 WHEN i.name LIKE ? THEN 1 ELSE 2 END,i.name LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'inventory:item:'.(int)$row['id'],'type'=>'inventory_item','title'=>(string)$row['name'],'subtitle'=>'موجودی '.(string)$row['quantity_base'].' '.(string)$row['base_unit'].((string)$row['review_status']==='ready'?'':' · نیازمند بررسی'),'context'=>'انبار','actions'=>[['label'=>'انبار','href'=>'/operations/?tab=inventory&inventory_item='.(int)$row['id']],['label'=>'تأمین / خرید','href'=>'/operations/?tab=supply&inventory_item='.(int)$row['id']]],'score'=>$this->score((string)$row['name'],$query,102)];
        return $out;
    }
    private function score(string $title,string $query,int $base): int{$title=SearchNormalizer::normalize($title);$query=SearchNormalizer::normalize($query);return $title===$query?$base+22:(str_starts_with($title,$query)?$base+12:$base);}
    private function allowed(array $user): bool{if((string)($user['role']??'')==='admin')return true;foreach(self::CAPS as $cap)if($this->auth->hasCapability($cap,$user))return true;return false;}
}
