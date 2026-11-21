<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')WebAction::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش معتبر نیست.'],405);
    $action=(string)($_GET['action']??'report');
    if($action==='order_history'){
        WebAction::requireAny($core,['cashier_accounts','shift_supervision']);
        $id=(int)($_GET['order_id']??0);
        WebAction::json(['success'=>true,'historical_order'=>$core->reporting()->historicalOrder($id)]);
    }
    if($action==='item_lookup'){
        WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
        WebAction::json(['success'=>true,'items'=>$core->reporting()->itemSalesLookup((string)($_GET['q']??''))]);
    }
    if($action==='item_groups'){
        WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
        WebAction::json(['success'=>true,'groups'=>$core->reporting()->itemSalesGroups()]);
    }
    if($action==='item_sales_group'){
        WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
        $type=(string)($_GET['group_type']??'');$groupId=filter_var($_GET['group_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!in_array($type,['category','family'],true)||$groupId===false)WebAction::json(['success'=>false,'code'=>'invalid_group','message'=>'دسته یا خانواده نامعتبر است.'],422);
        WebAction::json(['success'=>true,'item_sales'=>$core->reporting()->itemSalesGroupReport((string)($_GET['from']??''),(string)($_GET['to']??''),$type,(int)$groupId)]);
    }
    if($action==='item_sales_keyword'){
        WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
        WebAction::json(['success'=>true,'item_sales'=>$core->reporting()->itemSalesKeywordReport((string)($_GET['from']??''),(string)($_GET['to']??''),(string)($_GET['q']??''))]);
    }
    if($action==='item_sales'){
        WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
        $idsRaw=trim((string)($_GET['ids']??''));
        if($idsRaw===''||!preg_match('/^\d+(,\d+){0,29}$/D',$idsRaw))WebAction::json(['success'=>false,'code'=>'invalid_ids','message'=>'از ۱ تا ۳۰ محصول را انتخاب کنید.'],422);
        WebAction::json(['success'=>true,'item_sales'=>$core->reporting()->itemSalesReport((string)($_GET['from']??''),(string)($_GET['to']??''),explode(',',$idsRaw))]);
    }
    if($action==='pricing'){
        $viewer=WebAction::requireAny($core,['shift_supervision']);
        if(!$core->auth()->hasCapability('inventory_cost_view',$viewer))WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'دسترسی مشاهده هزینه مواد فعال نیست.'],403);
        WebAction::json(['success'=>true,'pricing'=>$core->reporting()->catalogPricingReport()]);
    }
    if($action==='activity'){
        WebAction::requireAny($core,['shift_supervision']);
        $actorRaw=trim((string)($_GET['actor_id']??''));$actorId=$actorRaw!==''?(int)$actorRaw:null;
        WebAction::json(['success'=>true,'activity'=>$core->reporting()->activityReport((string)($_GET['from']??''),(string)($_GET['to']??''),$actorId,(string)($_GET['family']??''),(string)($_GET['q']??''),(int)($_GET['limit']??40),(int)($_GET['offset']??0))]);
    }
    WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
    WebAction::json(['success'=>true,'report'=>$core->reporting()->report((string)($_GET['from']??''),(string)($_GET['to']??''))]);
}catch(Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('reports api: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'گزارش ساخته نشد.'],500);}
