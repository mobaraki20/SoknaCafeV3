<?php
declare(strict_types=1);
$root=getenv('SOKNA_SOURCE_ROOT') ?: __DIR__.'/local';
require_once $root.'/src/Domain/Orders/OrderStaffActionException.php';
require_once $root.'/src/Domain/Orders/OrderStaffActionService.php';
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
$object=(new ReflectionClass(OrderStaffActionService::class))->newInstanceWithoutConstructor();
$guard=new ReflectionMethod(OrderStaffActionService::class,'assertCorrectionReceiptTotalsSafe');
$assert=static function(bool $hasFinance,bool $changesFinancial,bool $shouldBlock)use($guard,$object){
    $record=['finance_allocations'=>$hasFinance,'paid_by_item'=>[22=>1], 'receipt_count'=>1];
    try {$guard->invoke($object,$record,$changesFinancial);}
    catch(OrderStaffActionException $error){
        if(!$shouldBlock||$error->errorCode!=='receipt_allocation_rebalance_required')throw new RuntimeException('UNEXPECTED_GUARD '.$error->errorCode);
        return;
    }
    if($shouldBlock)throw new RuntimeException('EXPECTED_GUARD_MISSING');
};
$assert(false,false,false);$assert(false,true,false);
$assert(true,false,false);$assert(true,true,true);
$paid=new ReflectionMethod(OrderStaffActionService::class,'assertSinglePaidOrderRowSafe');
try{$paid->invoke($object,22,1,1,false,true);throw new RuntimeException('PAID_NOTE_NOT_LOCKED');}
catch(OrderStaffActionException $e){if($e->errorCode!=='paid_item_identity_locked')throw $e;}
try{$paid->invoke($object,22,1,0,false,false);throw new RuntimeException('PAID_QUANTITY_FLOOR_NOT_ENFORCED');}
catch(OrderStaffActionException $e){if($e->errorCode!=='paid_quantity_floor')throw $e;}
echo json_encode(['result'=>'PASS','checks'=>6,'source'=>'actual R307->R308 OrderStaffActionService; no database or financial ledger written'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
