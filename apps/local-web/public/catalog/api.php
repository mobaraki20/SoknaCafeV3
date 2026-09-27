<?php
declare(strict_types=1);

use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,[]);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot')WebAction::json(['success'=>true,'snapshot'=>$core->catalogAdmin()->snapshot($user)]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='menu_save')WebAction::json(['success'=>true,'result'=>$core->catalogAdmin()->saveMenu($data,$user)]);
    if($action==='category_save')WebAction::json(['success'=>true,'result'=>$core->catalogAdmin()->saveCategory($data,$user)]);
    if($action==='item_save')WebAction::json(['success'=>true,'result'=>$core->catalogAdmin()->saveItem($data,$user)]);
    if($action==='item_state')WebAction::json(['success'=>true,'result'=>$core->catalogAdmin()->setItemState((int)($data['id']??0),(string)($data['field']??''),(bool)($data['enabled']??false),$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('catalog admin web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات کاتالوگ انجام نشد.'],500);
}
