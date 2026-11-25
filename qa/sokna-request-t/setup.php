// Verify one owner-confirmed conversion per base-unit family in the seeded DB.
$kind=getenv('SOKNA_PURCHASE_MODE');
$spec=match($kind){'weight'=>['INV-019','g',100],'count'=>['INV-076','count',24],'volume'=>['INV-005','ml',12000],default=>throw new LogicException('unknown fixture')};
$units=$rows("SELECT u.*,i.base_unit FROM inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id WHERE i.item_code=? AND i.base_unit=? AND i.active=1 AND u.active=1 AND u.conversion_mode='fixed' AND u.review_status='ready' AND u.base_quantity=? ORDER BY u.id",$spec);
$a(count($units)>=1,'confirmed conversion missing '.json_encode($spec));
$pu=$units[0];$itemId=(int)$pu['inventory_item_id'];$unitId=(int)$pu['id'];$quantity=(int)$pu['base_quantity']*2;
if(!in_array($itemId,array_map(fn($r)=>(int)$r['inventory_item_id'],$ingredients),true))$ingredients[]=['inventory_item_id'=>$itemId,'base_unit'=>$spec[1]];
