<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['inventory_view','inventory_cost_view','inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='inventory_movements'){
            $rows=$core->operationsWorkspace()->movementHistory($_GET,(int)($_GET['limit']??200));
            $permissions=$core->inventory()->permissions($user);
            $filtered=\Sokna\Shared\OperationsSnapshotPolicy::apply(['inventory'=>['recent_movements'=>$rows]],(bool)$permissions['can_view_cost']);
            WebAction::json(['success'=>true,'movements'=>$filtered['inventory']['recent_movements'],'history_scope'=>'local_query']);
        }
        if($action==='snapshot'){$snapshot=$core->operationsWorkspace()->snapshot($user);if((string)($user['role']??'')==='admin'){$reviews=$core->snapshotGuard()->capture('operations','deferred_reviews',[],'بررسی عملیات‌های معوق فعلاً در دسترس نیست.',fn():array=>$core->deferredReceipts()->pendingReviews());$snapshot['deferred_reviews']=$reviews['value'];if($reviews['error']!==null)$snapshot['errors']['deferred_reviews']=$reviews['error'];}WebAction::json(['success'=>true,'snapshot'=>$snapshot]);}
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if(!in_array($action,['expense_reverse','deferred_resolve'],true)){
        $operation=trim((string)($data['operation_id']??''));
        if(!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$operation))WebAction::json(['success'=>false,'code'=>'operation_id_required','message'=>'شناسه ثبت معتبر نیست؛ صفحه را تازه کن.'],422);
        $created=trim((string)($data['operation_created_at']??''));$ts=strtotime($created);
        if($ts===false||$ts>time()+300)WebAction::json(['success'=>false,'code'=>'invalid_operation_time','message'=>'زمان ثبت معتبر نیست.'],422);
        unset($data['csrf_token'],$data['operation_created_at']);
        $envelope=['request_id'=>$operation,'kind'=>'operations.mutate','actor_projection_id'=>'user:'.(int)$user['id'],'created_at'=>$created,'expires_at'=>gmdate('c',$ts+180),'payload'=>$data];
        $receipt=$core->lanStaffRealtimeReceipts()->dispatchAtomic('local-operations',$envelope,fn()=>$core->operationsCommands()->dispatch($envelope));
        if($receipt['state']==='committed')WebAction::json($receipt['result']);
        $result=(array)($receipt['result']??[]);WebAction::json(['success'=>false,'code'=>$receipt['error_code']??'operation_pending','message'=>$result['message']??'نتیجه ثبت هنوز قطعی نیست.','details'=>$result['details']??[]],$receipt['state']==='rejected'?409:503);
    }
    if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'این عملیات فقط برای مدیر فعال است.'],403);
    if($action==='deferred_resolve'){$reviewId=(int)($data['review_id']??0);$decision=(string)($data['decision']??'');$reason=(string)($data['reason']??'');$kind=(string)($data['kind']??'');$result=match(true){$kind==='operations.daily'=>$core->dailyDeferred()->resolveReview($reviewId,$decision,$reason,$user),str_starts_with($kind,'supply.')=>$core->supplyDeferred()->resolveReview($reviewId,$decision,$reason,$user),str_starts_with($kind,'inventory.')=>$core->inventoryDeferred()->resolveReview($reviewId,$decision,$reason,$user),$kind==='subscriber.payment'=>$core->subscriberPaymentDeferred()->resolveReview($reviewId,$decision,$reason,$user),$kind==='expense.create'=>$core->expenseDeferred()->resolveReview($reviewId,$decision,$reason,$user),default=>throw new \RuntimeException('Deferred review owner is not supported.')};WebAction::json(['success'=>true,'result'=>$result]);}
    if($action==='expense_reverse')WebAction::json(['success'=>true,'result'=>$core->expenses()->reverse((int)($data['expense_id']??0),(string)($data['reason']??''),(string)($data['source_request_id']??''),$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);WebAction::unexpected($core,$e,'operations.web_action_failed','عملیات این بخش کامل نشد. دوباره تلاش کنید.');}
