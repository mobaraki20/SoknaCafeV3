<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use PDO;
use Sokna\Local\Core\Auth;

final class CatalogSearchProvider implements SearchProvider
{
    public function __construct(private readonly PDO $pdo,private readonly Auth $auth){}

    public function search(string $query,array $user,int $limit): array
    {
        if((string)($user['role']??'')!=='admin'&&!$this->auth->hasCapability('orders_floor',$user))return [];
        $limit=max(1,min(8,$limit));$rows=[];$terms=SearchNormalizer::terms($query);
        if($terms===[])return [];

        [$where,$params]=$this->containsWhere('i.name',$terms);
        $sql="SELECT i.id,i.name,i.price,i.available,i.active,c.name category_name FROM items i JOIN categories c ON c.id=i.category_id WHERE i.active=1 AND {$where} ORDER BY CASE WHEN i.name=? THEN 0 WHEN i.name LIKE ? THEN 1 ELSE 2 END,i.name LIMIT {$limit}";
        $st=$this->pdo->prepare($sql);$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $actions=[];
            if((string)($user['role']??'')==='admin')$actions[]=['label'=>'کاتالوگ','href'=>'/catalog/?item='.(int)$row['id']];
            if($this->auth->hasCapability('orders_floor',$user)||((string)($user['role']??'')==='admin'))$actions[]=['label'=>'سفارش سریع','href'=>'/staff/?item='.(int)$row['id']];
            $rows[]=['id'=>'catalog:item:'.(int)$row['id'],'type'=>'catalog_item','title'=>(string)$row['name'],'subtitle'=>(string)$row['category_name'].' · '.number_format((int)$row['price']).' تومان'.((int)$row['available']===1?'':' · ناموجود'),'context'=>'منو / کاتالوگ','actions'=>$actions,'score'=>$this->score((string)$row['name'],$query,112)];
        }
        if((string)($user['role']??'')==='admin'){
            [$where,$params]=$this->containsWhere('name',$terms);
            $st=$this->pdo->prepare("SELECT id,name,audience FROM categories WHERE active=1 AND {$where} ORDER BY CASE WHEN name=? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END,name LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[]=['id'=>'catalog:category:'.(int)$row['id'],'type'=>'catalog_category','title'=>(string)$row['name'],'subtitle'=>'دسته‌بندی · '.((string)$row['audience']==='staff_only'?'فقط کارکنان':'مهمان و کارکنان'),'context'=>'کاتالوگ','actions'=>[['label'=>'دسته‌بندی‌ها','href'=>'/catalog/?tab=categories&category='.(int)$row['id']]],'score'=>$this->score((string)$row['name'],$query,90)];
            $st=$this->pdo->prepare("SELECT id,name,status FROM menus WHERE {$where} ORDER BY CASE WHEN name=? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END,name LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[]=['id'=>'catalog:menu:'.(int)$row['id'],'type'=>'catalog_menu','title'=>(string)$row['name'],'subtitle'=>'منو · '.(string)$row['status'],'context'=>'کاتالوگ','actions'=>[['label'=>'منوها','href'=>'/catalog/?tab=menus&menu='.(int)$row['id']]],'score'=>$this->score((string)$row['name'],$query,82)];
        }
        return $rows;
    }

    /** @param list<string> $terms @return array{0:string,1:list<string>} */
    private function containsWhere(string $column,array $terms): array
    {
        return [implode(' AND ',array_fill(0,count($terms),$column.' LIKE ?')),array_map([SearchNormalizer::class,'likeContains'],$terms)];
    }

    private function score(string $title,string $query,int $base): int
    {
        $title=SearchNormalizer::normalize($title);$query=SearchNormalizer::normalize($query);
        if($title===$query)return $base+24;
        if(str_starts_with($title,$query))return $base+14;
        return $base;
    }
}
