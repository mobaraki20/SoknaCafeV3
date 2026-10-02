<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use PDO;

final class AdminSearchProvider implements SearchProvider
{
    private const STATIC=[
        ['key'=>'نام مجموعه','terms'=>'نام مجموعه کافه سکنا برند عنوان هویت','tab'=>'settings'],
        ['key'=>'عنوان زیر نام مجموعه','terms'=>'عنوان زیر لوگو زیر نام برند توضیح سامانه','tab'=>'settings'],
        ['key'=>'شروع روز کاری','terms'=>'شروع روز کاری ساعت cutoff تنظیم زمان','tab'=>'settings'],
        ['key'=>'فراخوان همکار سالن','terms'=>'فراخوان همکار سالن گارسون waiter اعلان','tab'=>'settings'],
        ['key'=>'انبار','terms'=>'انبار ماژول قابلیت موجودی','tab'=>'modules'],
        ['key'=>'تأمین و خرید','terms'=>'تامین تأمین خرید ماژول قابلیت','tab'=>'modules'],
        ['key'=>'مالیات','terms'=>'مالیات ماژول قابلیت tax','tab'=>'modules'],
        ['key'=>'چاپ','terms'=>'چاپ ماژول قابلیت پرینت','tab'=>'modules'],
        ['key'=>'میزها و QR','terms'=>'میز qr کیوآر توکن سالن شماره میز','tab'=>'tables'],
        ['key'=>'کاربران و دسترسی‌ها','terms'=>'کاربر دسترسی permission capability login رمز حساب ورود','tab'=>'users'],
        ['key'=>'پرسنل','terms'=>'پرسنل کارکنان بدون ورود personnel سمت کد پرسنلی','tab'=>'personnel'],
    ];
    public function __construct(private readonly PDO $pdo){}
    public function search(string $query,array $user,int $limit): array
    {
        if((string)($user['role']??'')!=='admin')return [];$limit=max(1,min(8,$limit));$terms=SearchNormalizer::terms($query);if($terms===[])return [];$out=[];
        [$where,$params]=$this->multiFieldWhere(['display_name','personnel_code','job_title'],$terms);
        $st=$this->pdo->prepare("SELECT id,display_name,personnel_code,job_title FROM personnel WHERE active=1 AND {$where} ORDER BY CASE WHEN display_name=? THEN 0 WHEN display_name LIKE ? THEN 1 ELSE 2 END,display_name LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'personnel:'.(int)$row['id'],'type'=>'personnel','title'=>(string)$row['display_name'],'subtitle'=>trim(((string)($row['job_title']??'')) . (((string)($row['personnel_code']??''))!==''?' · کد '.(string)$row['personnel_code']:'')),'context'=>'پرسنل','actions'=>[['label'=>'پرسنل','href'=>'/admin/?tab=personnel&personnel='.(int)$row['id']]],'score'=>94];
        [$where,$params]=$this->multiFieldWhere(['display_name','username'],$terms);
        $st=$this->pdo->prepare("SELECT id,display_name,username,role FROM users WHERE active=1 AND {$where} ORDER BY CASE WHEN display_name=? THEN 0 WHEN display_name LIKE ? THEN 1 ELSE 2 END,display_name LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'user:'.(int)$row['id'],'type'=>'user','title'=>(string)$row['display_name'],'subtitle'=>'حساب ورود · '.(string)$row['username'],'context'=>'کاربران','actions'=>[['label'=>'کاربران','href'=>'/admin/?tab=users&user='.(int)$row['id']]],'score'=>88];
        if(ctype_digit($query)){$st=$this->pdo->prepare('SELECT id,name,table_number,zone_label FROM cafe_tables WHERE active=1 AND table_number=? LIMIT 1');$st->execute([(int)$query]);}
        else{[$where,$params]=$this->multiFieldWhere(['name','zone_label'],$terms);$st=$this->pdo->prepare("SELECT id,name,table_number,zone_label FROM cafe_tables WHERE active=1 AND {$where} ORDER BY CASE WHEN name=? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END,table_number LIMIT {$limit}");$st->execute([...$params,$query,SearchNormalizer::likePrefix($query)]);}
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'table:'.(int)$row['id'],'type'=>'table','title'=>(string)$row['name'],'subtitle'=>'میز '.(int)$row['table_number'].(((string)($row['zone_label']??''))!==''?' · '.(string)$row['zone_label']:''),'context'=>'میز و QR','actions'=>[['label'=>'میزها و QR','href'=>'/admin/?tab=tables&table='.(int)$row['id']]],'score'=>92];
        foreach(self::STATIC as $entry){if(SearchNormalizer::matches($entry['key'].' '.$entry['terms'],$query))$out[]=['id'=>'setting:'.$entry['tab'].':'.sha1($entry['key']),'type'=>'setting','title'=>$entry['key'],'subtitle'=>'تنظیم یا قابلیت مدیریتی','context'=>'مدیریت','actions'=>[['label'=>'بازکردن','href'=>'/admin/?tab='.$entry['tab']]],'score'=>104];}
        return $out;
    }

    /** @param list<string> $fields @param list<string> $terms @return array{0:string,1:list<string>} */
    private function multiFieldWhere(array $fields,array $terms): array
    {
        $groups=[];$params=[];
        foreach($terms as $term){$groups[]='('.implode(' OR ',array_map(static fn(string $f): string=>$f.' LIKE ?',$fields)).')';$like=SearchNormalizer::likeContains($term);foreach($fields as $_)$params[]=$like;}
        return [implode(' AND ',$groups),$params];
    }
}
