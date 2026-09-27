<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')WebAction::json(['success'=>true,'snapshot'=>$core->guestContent()->snapshot($user)]);
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    $service=$core->guestContent();
    if($action==='theme_save')WebAction::json(['success'=>true,'result'=>$service->saveThemeDraft($data,$user)]);
    if($action==='copy_save')WebAction::json(['success'=>true,'result'=>$service->saveCopyDraft($data,$user)]);
    if($action==='publish')WebAction::json(['success'=>true,'result'=>$service->publishDraft($user)]);
    if($action==='media_assign')WebAction::json(['success'=>true,'result'=>$service->assignMediaToItem((int)($data['item_id']??0),(string)($data['media_key']??''),$user)]);
    if($action==='media_alt')WebAction::json(['success'=>true,'result'=>$service->updateAlt((string)($data['media_key']??''),(string)($data['alt_text']??''),$user)]);
    if($action==='media_archive')WebAction::json(['success'=>true,'result'=>$service->archiveMedia((string)($data['media_key']??''),$user)]);
    if($action==='media_gc')WebAction::json(['success'=>true,'result'=>$service->garbageCollect($user,(bool)($data['dry_run']??true))]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('guest content api: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات محتوای مهمان انجام نشد.'],500);}
