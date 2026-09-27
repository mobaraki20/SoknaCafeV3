<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use PDO;

final class AdminSearchProvider implements SearchProvider
{
    private const STATIC=[
        ['key'=>'نام مجموعه','terms'=>'نام مجموعه کافه سکنا','tab'=>'settings'],
        ['key'=>'شروع روز کاری','terms'=>'شروع روز کاری ساعت cutoff','tab'=>'settings'],
        ['key'=>'فراخوان همکار سالن','terms'=>'فراخوان همکار سالن گارسون waiter','tab'=>'settings'],
        ['key'=>'انبار','terms'=>'انبار ماژول قابلیت','tab'=>'modules'],
        ['key'=>'تأمین و خرید','terms'=>'تامین خرید ماژول قابلیت','tab'=>'modules'],
        ['key'=>'مالیات','terms'=>'مالیات ماژول قابلیت tax','tab'=>'modules'],
        ['key'=>'چاپ','terms'=>'چاپ ماژول قابلیت پرینت','tab'=>'modules'],
        ['key'=>'میزها و QR','terms'=>'میز qr کیوآر توکن سالن','tab'=>'tables'],
        ['key'=>'کاربران و دسترسی‌ها','terms'=>'کاربر دسترسی permission capability login','tab'=>'users'],
        ['key'=>'پرسنل','terms'=>'پرسنل کارکنان بدون ورود personnel','tab'=>'personnel'],
    ];
    public function __construct(private readonly PDO $pdo){}
    public function search(string $query,array $user,int $limit): array
    {
        if((string)($user['role']??'')!=='admin')return [];$limit=max(1,min(6,$limit));$prefix=SearchNormalizer::likePrefix($query);$out=[];
        $st=$this->pdo->prepare("SELECT id,display_name,personnel_code,job_title FROM personnel WHERE active=1 AND display_name LIKE ? ORDER BY CASE WHEN display_name=? THEN 0 ELSE 1 END,display_name LIMIT {$limit}");$st->execute([$prefix,$query]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'personnel:'.(int)$row['id'],'type'=>'personnel','title'=>(string)$row['display_name'],'subtitle'=>trim(((string)($row['job_title']??'')) . (((string)($row['personnel_code']??''))!==''?' · کد '.(string)$row['personnel_code']:'')),'context'=>'پرسنل','actions'=>[['label'=>'پرسنل','href'=>'/admin/?tab=personnel&personnel='.(int)$row['id']]],'score'=>90];
        $st=$this->pdo->prepare("SELECT id,display_name,username,role FROM users WHERE active=1 AND (display_name LIKE ? OR username LIKE ?) ORDER BY CASE WHEN display_name=? THEN 0 ELSE 1 END,display_name LIMIT {$limit}");$st->execute([$prefix,$prefix,$query]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'user:'.(int)$row['id'],'type'=>'user','title'=>(string)$row['display_name'],'subtitle'=>'حساب ورود · '.(string)$row['username'],'context'=>'کاربران','actions'=>[['label'=>'کاربران','href'=>'/admin/?tab=users&user='.(int)$row['id']]],'score'=>80];
        if(ctype_digit($query)){$st=$this->pdo->prepare('SELECT id,name,table_number,zone_label FROM cafe_tables WHERE active=1 AND table_number=? LIMIT 1');$st->execute([(int)$query]);}
        else{$st=$this->pdo->prepare("SELECT id,name,table_number,zone_label FROM cafe_tables WHERE active=1 AND name LIKE ? ORDER BY CASE WHEN name=? THEN 0 ELSE 1 END,table_number LIMIT {$limit}");$st->execute([$prefix,$query]);}
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out[]=['id'=>'table:'.(int)$row['id'],'type'=>'table','title'=>(string)$row['name'],'subtitle'=>'میز '.(int)$row['table_number'].(((string)($row['zone_label']??''))!==''?' · '.(string)$row['zone_label']:''),'context'=>'میز و QR','actions'=>[['label'=>'میزها و QR','href'=>'/admin/?tab=tables&table='.(int)$row['id']]],'score'=>85];
        foreach(self::STATIC as $entry){$hay=SearchNormalizer::normalize($entry['terms']);if(str_starts_with($hay,$query)||str_contains($hay,' '.$query))$out[]=['id'=>'setting:'.$entry['tab'].':'.sha1($entry['key']),'type'=>'setting','title'=>$entry['key'],'subtitle'=>'تنظیم یا قابلیت مدیریتی','context'=>'مدیریت','actions'=>[['label'=>'بازکردن','href'=>'/admin/?tab='.$entry['tab']]],'score'=>65];}
        return $out;
    }
}
