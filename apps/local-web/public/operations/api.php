<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot'){$snapshot=$core->operationsWorkspace()->snapshot($user);if((string)($user['role']??'')==='admin')$snapshot['deferred_reviews']=$core->deferredReceipts()->pendingReviews();WebAction::json(['success'=>true,'snapshot'=>$snapshot]);}
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='inventory_create')WebAction::json(['success'=>true,'result'=>$core->inventory()->createItem($data,$user)]);
    if($action==='inventory_adjust')WebAction::json(['success'=>true,'result'=>$core->inventory()->recordManualAdjustment($data,$user)]);
    if($action==='count_start')WebAction::json(['success'=>true,'result'=>$core->inventoryCounts()->start($data,$user)]);
    if($action==='count_update')WebAction::json(['success'=>true,'result'=>$core->inventoryCounts()->updateLine($data,$user,false)]);
    if($action==='count_finalize')WebAction::json(['success'=>true,'result'=>$core->inventoryCounts()->finalize((int)($data['session_id']??0),$user)]);
    if($action==='count_cancel')WebAction::json(['success'=>true,'result'=>$core->inventoryCounts()->cancel((int)($data['session_id']??0),$user)]);
    if($action==='supply_need')WebAction::json(['success'=>true,'result'=>['need_id'=>$core->supply()->upsertNeed($data,$user)]]);
    if($action==='supply_prepare')WebAction::json(['success'=>true,'result'=>$core->supply()->prepare((string)($data['group_key']??''),isset($data['expected_uncommitted'])?(int)$data['expected_uncommitted']:null,$user)]);
    if($action==='supply_cancel')WebAction::json(['success'=>true,'result'=>$core->supply()->cancelUncommitted((string)($data['group_key']??''),isset($data['expected_uncommitted'])?(int)$data['expected_uncommitted']:null,$user)]);
    if($action==='supply_return')WebAction::json(['success'=>true,'result'=>$core->supply()->returnPreparing((string)($data['group_key']??''),(string)($data['outcome']??''),isset($data['expected_preparing'])?(int)$data['expected_preparing']:null,$user)]);
    if($action==='supply_receive')WebAction::json(['success'=>true,'result'=>$core->supply()->receive((string)($data['group_key']??''),$data,$user)]);
    if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'این عملیات فقط برای مدیر فعال است.'],403);
    if($action==='deferred_resolve'){$reviewId=(int)($data['review_id']??0);$decision=(string)($data['decision']??'');$reason=(string)($data['reason']??'');$kind=(string)($data['kind']??'');$result=match(true){str_starts_with($kind,'supply.')=>$core->supplyDeferred()->resolveReview($reviewId,$decision,$reason,$user),str_starts_with($kind,'inventory.')=>$core->inventoryDeferred()->resolveReview($reviewId,$decision,$reason,$user),$kind==='subscriber.payment'=>$core->subscriberPaymentDeferred()->resolveReview($reviewId,$decision,$reason,$user),$kind==='expense.create'=>$core->expenseDeferred()->resolveReview($reviewId,$decision,$reason,$user),default=>throw new \RuntimeException('Deferred review owner is not supported.')};WebAction::json(['success'=>true,'result'=>$result]);}
    if($action==='expense_create')WebAction::json(['success'=>true,'result'=>$core->expenses()->create($data,$user)]);
    if($action==='expense_reverse')WebAction::json(['success'=>true,'result'=>$core->expenses()->reverse((int)($data['expense_id']??0),(string)($data['reason']??''),(string)($data['source_request_id']??''),$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('operations web action: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات انجام نشد.'],500);}
