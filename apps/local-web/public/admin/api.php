<?php
declare(strict_types=1);

use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,[]);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot')WebAction::json(['success'=>true,'snapshot'=>$core->adminControls()->snapshot($user)]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='user_save')WebAction::json(['success'=>true,'result'=>$core->adminControls()->saveUser($data,$user)]);
    if($action==='personnel_save')WebAction::json(['success'=>true,'result'=>$core->adminControls()->savePersonnel($data,$user)]);
    if($action==='settings_save')WebAction::json(['success'=>true,'result'=>$core->adminControls()->saveSettings($data,$user)]);
    if($action==='module_toggle'){
        $key=(string)($data['module_key']??'');$enabled=(bool)($data['enabled']??false);
        $result=$key==='tax'?$core->tax()->setEnabled($enabled,$user):$core->adminControls()->setModule($key,$enabled,$user);
        WebAction::json(['success'=>true,'result'=>$result]);
    }
    if($action==='table_save')WebAction::json(['success'=>true,'result'=>$core->adminControls()->saveTable($data,$user)]);
    if($action==='tables_bulk_create')WebAction::json(['success'=>true,'result'=>$core->adminControls()->bulkCreateTables($data,$user)]);
    if($action==='qr_rotate')WebAction::json(['success'=>true,'result'=>$core->adminControls()->rotateQr((int)($data['table_id']??0),$user,false)]);
    if($action==='qr_restore')WebAction::json(['success'=>true,'result'=>$core->adminControls()->rotateQr((int)($data['table_id']??0),$user,true)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('admin controls web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات مدیریتی انجام نشد.'],500);
}
