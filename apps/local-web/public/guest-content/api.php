<?php
declare(strict_types=1);
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);
try{
    $service=$core->guestContent();$publisher=$core->publicEdgePublisher();
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $snapshot=$service->snapshot($user);$snapshot['public_sync']=$publisher->guestPublicationState();WebAction::json(['success'=>true,'snapshot'=>$snapshot]);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='theme_save')WebAction::json(['success'=>true,'result'=>$service->saveThemeDraft($data,$user)]);
    if($action==='theme_rollback')WebAction::json(['success'=>true,'result'=>$service->rollbackThemeRevision((int)($data['revision']??0),$user)]);
    if($action==='copy_save')WebAction::json(['success'=>true,'result'=>$service->saveCopyDraft($data,$user)]);
    if($action==='public_settings_save')WebAction::json(['success'=>true,'result'=>$service->savePublicSettingsDraft($data,$user)]);
    if($action==='identity_save')WebAction::json(['success'=>true,'result'=>$service->saveBrandIdentityDraft((string)($data['media_key']??''),is_array($data['variants']??null)?$data['variants']:[],$user)]);
    if($action==='identity_reset')WebAction::json(['success'=>true,'result'=>$service->resetBrandIdentityDraft($user)]);
    if($action==='publish'){
        $result=$service->publishDraft($user);
        try{$public=$publisher->syncGuestPublication();WebAction::json(['success'=>true,'result'=>$result,'public_sync'=>$public['state']??$publisher->guestPublicationState()]);}
        catch(PublicEdgeSyncException $e){WebAction::json(['success'=>true,'result'=>$result,'public_sync'=>$publisher->guestPublicationState(),'sync_error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);}
        catch(Throwable $e){$id=substr($core->observability()->correlationId(),0,12);$core->observability()->logEvent('error','guest.publish_public_sync_unexpected',['exception'=>get_class($e),'message'=>$e->getMessage()],$id);WebAction::json(['success'=>true,'result'=>$result,'public_sync'=>$publisher->guestPublicationState(),'sync_error'=>['code'=>'public_sync_internal','message'=>'نسخه محلی منتشر شد، اما ارسال به وب عمومی کامل نشد.','support_id'=>$id]]);}
    }
    if($action==='public_sync')WebAction::json(['success'=>true,'public_sync'=>$publisher->syncGuestPublication()]);
    if($action==='media_assign')WebAction::json(['success'=>true,'result'=>$service->assignMediaToItem((int)($data['item_id']??0),(string)($data['media_key']??''),$user)]);
    if($action==='media_focal')WebAction::json(['success'=>true,'result'=>$service->updateFocal((string)($data['media_key']??''),(float)($data['focal_x']??0.5),(float)($data['focal_y']??0.5),$user)]);
    if($action==='media_alt')WebAction::json(['success'=>true,'result'=>$service->updateAlt((string)($data['media_key']??''),(string)($data['alt_text']??''),$user)]);
    if($action==='media_archive')WebAction::json(['success'=>true,'result'=>$service->archiveMedia((string)($data['media_key']??''),$user)]);
    if($action==='media_gc')WebAction::json(['success'=>true,'result'=>$service->garbageCollect($user,(bool)($data['dry_run']??true))]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);WebAction::unexpected($core,$e,'guest.content_api_failed','عملیات محتوای مهمان انجام نشد.');}
