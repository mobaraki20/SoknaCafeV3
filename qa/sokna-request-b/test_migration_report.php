<?php
declare(strict_types=1);
$root=getenv('RUNNER_TEMP').'/local';
require $root.'/bootstrap.php';
$core=sokna_local_bootstrap(require dirname($root).'/config.php');
$p=$core->database();
$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$a=function($ok,$m){if(!$ok)throw new RuntimeException($m);};
$rows=fn($s)=>$p->query($s)->fetchAll(PDO::FETCH_ASSOC);
$immutable=[];
foreach(['inventory_movements','inventory_supply_receipts','order_items','orders','inventory_balances','inventory_recipe_versions','inventory_recipe_components','items'] as $t)$immutable[$t]=$rows("SELECT * FROM $t ORDER BY 1");
$before=$rows('SELECT * FROM inventory_purchase_units ORDER BY id');
$a($core->migrations()->migrate()===['0091_verified_purchase_unit_reconciliation'],'migration order');
foreach($immutable as $t=>$v)$a($rows("SELECT * FROM $t ORDER BY 1")===$v,"changed historical $t");
$after=[];foreach($rows('SELECT * FROM inventory_purchase_units ORDER BY id') as $v)$after[$v['id']]=$v;
$deactivated=0;
foreach($before as $v){$a(isset($after[$v['id']]),'unit removed');$n=$after[$v['id']];if($v['active']!=$n['active']){$a($v['active']==1&&$n['active']==0,'wrong active transition');$deactivated++;}unset($v['active'],$n['active'],$v['updated_at'],$n['updated_at']);$a($v===$n,'existing unit overwritten');}
foreach([['INV-019',100],['INV-052',10800],['INV-076',24],['INV-028',10],['INV-029',5],['INV-112',1000],['INV-113',1000]] as [$code,$qty]){
$s=$p->prepare("SELECT COUNT(*) FROM inventory_purchase_units u JOIN inventory_items i ON i.id=u.inventory_item_id WHERE i.item_code=? AND u.active=1 AND u.conversion_mode='fixed' AND u.base_quantity=?");$s->execute([$code,$qty]);$a((int)$s->fetchColumn()>=1,'missing unit '.$code);
}
$a($core->migrations()->migrate()===[],'migration replay');
$r=$core->reporting()->report('2026-11-24','2026-11-24');
foreach(['summary','daily','destinations','top_items','tenders','operations','inventory','margin'] as $k)$a(array_key_exists($k,$r),'report missing '.$k);
$current=$core->reporting()->currentViewWorkbook('2026-11-24','2026-11-24');
$a(str_starts_with($current,"PK"),'current workbook invalid');
$x=$core->reporting()->analyticalWorkbook('2026-11-24','2026-11-24');
file_put_contents(getenv('RUNNER_TEMP').'/analytical.xlsx',$x);
$guest=$core->publicProjectionBuilder()->guestPublish();$json=json_encode($guest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$a(str_contains($json,'بلک موهیتو'),'black mojito missing from projection');
echo json_encode(['migration'=>'PASS','deactivated_exact_duplicates'=>$deactivated,'new_units'=>count($after)-count($before),'historical_rows_preserved'=>true,'report_executed'=>true,'public_projection'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
