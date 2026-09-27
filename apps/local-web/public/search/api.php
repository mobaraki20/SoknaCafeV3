<?php
declare(strict_types=1);

use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=$core->auth()->currentUser();
if($user===null)WebAction::json(['success'=>false,'code'=>'unauthenticated','message'=>'نشست شما پایان یافته؛ دوباره وارد شوید.'],401);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')WebAction::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش درخواست معتبر نیست.'],405);
try{
    $query=(string)($_GET['q']??'');
    WebAction::json(['success'=>true,'search'=>$core->globalSearch()->search($query,$user,14)]);
}catch(\Throwable $e){
    error_log('global search: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'search_unavailable','message'=>'جست‌وجو در حال حاضر انجام نشد.'],500);
}
