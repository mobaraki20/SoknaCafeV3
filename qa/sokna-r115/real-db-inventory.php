<?php
declare(strict_types=1);
// Uses the unchanged R115 backend archive reconstructed by the workflow.
$root='/tmp/sokna-r115-exact/local';
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='Sokna\\Local\\';
    if(!str_starts_with($class,$prefix))return;
    $path=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r115_full;charset=utf8mb4','sokna','sokna',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$actorId=(int)$pdo->query("SELECT id FROM users WHERE username='qa'")->fetchColumn();
if($actorId<1)throw new RuntimeException('No QA administrator');
$identity=new class($actorId) implements Sokna\Local\Core\IdentityRepository{
    public function __construct(private readonly int $id){}
    public function findActiveById(int $userId):?array{return $userId===$this->id?['id'=>$userId,'role'=>'admin','active'=>1,'display_name'=>'QA']:null;}
    public function findActiveByUsername(string $username):?array{return null;}
    public function capabilitiesForUser(int $userId):array{return [];}
    public function preparationAreasForUser(int $userId):array{return [];}
};
$caps=new Sokna\Local\Core\Capabilities($identity);
$inventory=new Sokna\Local\Domain\Inventory\InventoryService($pdo,$identity,$caps);
$count=new Sokna\Local\Domain\Inventory\InventoryCountService($pdo,$inventory);
$actor=['id'=>$actorId,'role'=>'admin'];
$pdo->exec("INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,active) VALUES('QA-A','ingredient A','ingredient','count','bar',1),('QA-B','ingredient B','ingredient','count','bar',1)");
$idA=(int)$pdo->query("SELECT id FROM inventory_items WHERE item_code='QA-A'")->fetchColumn();
$idB=(int)$pdo->query("SELECT id FROM inventory_items WHERE item_code='QA-B'")->fetchColumn();
$session1=$count->start(['session_type'=>'opening','title'=>'QA day 1'],$actor);
if((int)$session1['line_count']!==2)throw new RuntimeException('Opening day 1 did not contain two items');
$sid1=(int)$session1['session_id'];
$q=$pdo->prepare("SELECT id,updated_at FROM inventory_count_lines WHERE session_id=? AND inventory_item_id=?");$q->execute([$sid1,$idA]);$lineA=$q->fetch();
if(!is_array($lineA))throw new RuntimeException('No first ingredient line');
$count->updateLine(['session_id'=>$sid1,'line_id'=>(int)$lineA['id'],'expected_version'=>(string)$lineA['updated_at'],'actual_major'=>'0'],$actor);
$day1=$count->finalize($sid1,$actor);
if((int)$day1['counted_lines']!==1|| (int)$day1['uncounted_lines']!==1)throw new RuntimeException('Partial first day unexpected');
if((int)$pdo->query("SELECT COUNT(*) FROM inventory_opening_activations WHERE inventory_item_id=$idA")->fetchColumn()!==1)throw new RuntimeException('Counted zero was not activated');
if((int)$pdo->query("SELECT COUNT(*) FROM inventory_opening_activations WHERE inventory_item_id=$idB")->fetchColumn()!==0)throw new RuntimeException('Uncounted item activated');
if((int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=$idA")->fetchColumn()!==0)throw new RuntimeException('Zero balance wrong');
$initialized=(string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='inventory_initialized'")->fetchColumn();
if(in_array(strtolower($initialized),['1','true','yes'],true))throw new RuntimeException('Inventory should not be marked complete');
try{
    $inventory->recordManualAdjustment(['item_id'=>$idB,'kind'=>'waste','quantity_major'=>'1','request_id'=>'qa-uncounted-block'],$actor);
    throw new RuntimeException('Uncounted material was writable');
}catch(Sokna\Local\Domain\Inventory\InventoryException $e){
    if($e->errorCode!=='inventory_item_not_counted')throw $e;
}
$session2=$count->start(['session_type'=>'opening','title'=>'QA day 2'],$actor);
if((int)$session2['line_count']!==1)throw new RuntimeException('Day two did not isolate uncounted material');
$sid2=(int)$session2['session_id'];
$q->execute([$sid2,$idB]);$lineB=$q->fetch();
$count->updateLine(['session_id'=>$sid2,'line_id'=>(int)$lineB['id'],'expected_version'=>(string)$lineB['updated_at'],'actual_major'=>'10'],$actor);
$day2=$count->finalize($sid2,$actor);
if((int)$day2['counted_lines']!==1)throw new RuntimeException('Day two finalization failed');
$after=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=$idB")->fetchColumn();
if($after!==10)throw new RuntimeException('Day two opening quantity mismatch: '.$after);
$done=(string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='inventory_initialized'")->fetchColumn();
if(!in_array(strtolower($done),['1','true','yes'],true))throw new RuntimeException('Inventory was not completed after all materials');
$waste=$inventory->recordManualAdjustment(['item_id'=>$idB,'kind'=>'waste','quantity_major'=>'1','request_id'=>'qa-day2-waste'],$actor);
$remaining=(int)$pdo->query("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=$idB")->fetchColumn();
if($remaining!==9||!isset($waste['movement_id']))throw new RuntimeException('Post-activation usage did not subtract material');
echo "PASS R115 real MariaDB: day1 zero+unknown, day2 activation, uncounted write blocked, movement subtracts stock\n";
