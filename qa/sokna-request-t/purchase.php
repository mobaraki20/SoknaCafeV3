// The opening-count fixture above has initialized the selected inventory item.
$supply=$core->supply();$inventory=$core->inventory();$before=$balance($itemId);$cost=345678;
$payload=['action'=>'supply_purchase_record','request_id'=>'ci-t-purchase-0001','item_id'=>$itemId,'purchase_unit_id'=>$unitId,'unit_count'=>'۲','total_cost'=>'۳۴۵۶۷۸','supplier'=>'QA supplier','delivered_to_cafe'=>false,'occurred_at'=>date('Y-m-d H:i:s')];
$record=$supply->recordPurchase($payload,$user);$a($record['delivered']===false,'purchase not delivered');
$a($balance($itemId)===$before,'no stock increase on purchase alone');
$stored=$rows("SELECT * FROM inventory_supply_purchases WHERE purchase_id=?",[$payload['request_id']])[0];
$a((int)$stored['quantity_base']===$quantity&&(int)$stored['total_cost']===$cost,'exact quantity and cost');
$receiptCount=(int)$one('SELECT COUNT(*) FROM inventory_supply_receipts WHERE inventory_item_id=?',[$itemId]);
$inventory->savePurchaseUnit(['purchase_unit_id'=>$unitId,'item_id'=>$itemId,'name'=>'QA edited pack','conversion_mode'=>'fixed','base_quantity'=>$pu['base_quantity']+7,'active'=>true],$user);
$del=$supply->deliverPurchase(['purchase_id'=>$payload['request_id'],'occurred_at'=>date('Y-m-d H:i:s')],$user);$id=(int)$del['receipt_id'];
$a($balance($itemId)===$before+$quantity,'exact delivered stock despite changed conversion');
$receipt=$rows('SELECT * FROM inventory_supply_receipts WHERE id=?',[$id])[0];
$a((int)$receipt['received_quantity_base']===$quantity&&(int)$receipt['total_cost']===$cost&&$receipt['purchase_unit_name_snapshot']===$pu['name']&&(int)$receipt['conversion_base_quantity_snapshot']===(int)$pu['base_quantity'],'historical snapshot preserved');
$again=$supply->deliverPurchase(['purchase_id'=>$payload['request_id']],$user);
$a($again['duplicate']===true&&(int)$again['receipt_id']===$id&&$balance($itemId)===$before+$quantity,'duplicate delivery safe');
$a((int)$one('SELECT COUNT(*) FROM inventory_supply_receipts WHERE inventory_item_id=?',[$itemId])===$receiptCount+1,'single receipt');
$doc=$core->reporting()->historicalSupplyReceipt($id,true);$hidden=$core->reporting()->historicalSupplyReceipt($id,false);
$a($doc['document']['total_cost']===$cost&&$doc['document']['received_quantity_base']===$quantity&&$doc['document']['purchase_unit_name_snapshot']===$pu['name'],'historical report exact');
$a(!array_key_exists('total_cost',$hidden['document'])&&$hidden['cost_visible']===false,'cost access boundary');
$payload['request_id']='ci-t-cancel-0002';$supply->recordPurchase($payload,$user);$supply->cancelPurchase(['purchase_id'=>$payload['request_id'],'reason'=>'QA undelivered purchase'], $user);
try{$supply->deliverPurchase(['purchase_id'=>$payload['request_id']],$user);throw new LogicException('cancelled purchase delivered');}catch(\Sokna\Local\Domain\Supply\SupplyException $e){$a($e->errorCode==='purchase_cancelled','wrong cancellation state');}
$a($balance($itemId)===$before+$quantity,'cancelled purchase does not affect stock');
echo json_encode(['status'=>'PASS','family'=>$kind,'verified_conversion'=>$spec,'quantity_base'=>$quantity,'frozen_unit'=>true,'purchase_separate_from_delivery'=>true,'duplicate_safe'=>true,'historical_report'=>true,'cost_privacy'=>true,'cancelled_undelivered'=>true],JSON_THROW_ON_ERROR)."\n";
