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
        $limit=max(1,min(6,$limit));$prefix=SearchNormalizer::likePrefix($query);$rows=[];
        $sql="SELECT i.id,i.name,i.price,i.available,i.active,c.name category_name FROM items i JOIN categories c ON c.id=i.category_id WHERE i.active=1 AND i.name LIKE ? ORDER BY CASE WHEN i.name=? THEN 0 ELSE 1 END,i.name LIMIT {$limit}";
        $st=$this->pdo->prepare($sql);$st->execute([$prefix,$query]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $actions=[];
            if((string)($user['role']??'')==='admin')$actions[]=['label'=>'کاتالوگ','href'=>'/catalog/?item='.(int)$row['id']];
            if($this->auth->hasCapability('orders_floor',$user)||((string)($user['role']??'')==='admin'))$actions[]=['label'=>'سفارش سریع','href'=>'/staff/?item='.(int)$row['id']];
            $rows[]=['id'=>'catalog:item:'.(int)$row['id'],'type'=>'catalog_item','title'=>(string)$row['name'],'subtitle'=>(string)$row['category_name'].' · '.number_format((int)$row['price']).' تومان'.((int)$row['available']===1?'':' · ناموجود'),'context'=>'منو / کاتالوگ','actions'=>$actions,'score'=>100];
        }
        if((string)($user['role']??'')==='admin'){
            $st=$this->pdo->prepare("SELECT id,name,audience FROM categories WHERE active=1 AND name LIKE ? ORDER BY CASE WHEN name=? THEN 0 ELSE 1 END,name LIMIT {$limit}");$st->execute([$prefix,$query]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[]=['id'=>'catalog:category:'.(int)$row['id'],'type'=>'catalog_category','title'=>(string)$row['name'],'subtitle'=>'دسته‌بندی · '.((string)$row['audience']==='staff_only'?'فقط کارکنان':'مهمان و کارکنان'),'context'=>'کاتالوگ','actions'=>[['label'=>'دسته‌بندی‌ها','href'=>'/catalog/?tab=categories&category='.(int)$row['id']]],'score'=>80];
            $st=$this->pdo->prepare("SELECT id,name,status FROM menus WHERE name LIKE ? ORDER BY CASE WHEN name=? THEN 0 ELSE 1 END,name LIMIT {$limit}");$st->execute([$prefix,$query]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[]=['id'=>'catalog:menu:'.(int)$row['id'],'type'=>'catalog_menu','title'=>(string)$row['name'],'subtitle'=>'منو · '.(string)$row['status'],'context'=>'کاتالوگ','actions'=>[['label'=>'منوها','href'=>'/catalog/?tab=menus&menu='.(int)$row['id']]],'score'=>70];
        }
        return $rows;
    }
}
