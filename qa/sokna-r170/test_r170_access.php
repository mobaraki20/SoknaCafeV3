<?php
declare(strict_types=1);
error_reporting(E_ALL);
$root=getenv('SOKNA_R170_LOCAL') ?: dirname(__DIR__).'/r170-source/local';
require $root.'/src/Core/IdentityRepository.php';
require $root.'/src/Core/Capabilities.php';
require $root.'/src/Domain/Expenses/ExpenseException.php';
require $root.'/src/Domain/Expenses/ExpenseService.php';
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Expenses\ExpenseService;
final class MockIdentity implements IdentityRepository {
    public function findActiveById(int $userId): ?array {
      return match($userId){1=>['id'=>1,'role'=>'admin','active'=>1],2=>['id'=>2,'role'=>'operator','active'=>1],3=>['id'=>3,'role'=>'operator','active'=>1],4=>null,default=>null};
    }
    public function findActiveByUsername(string $username): ?array {return null;}
    public function capabilitiesForUser(int $userId): array {return $userId===2?['inventory_view','inventory_manage','expenses_record']:($userId===3?['inventory_view']:[]);}
    public function preparationAreasForUser(int $userId): array {return [];}
}
$identity=new MockIdentity();$caps=new Capabilities($identity);
assert(in_array('expenses_record',Capabilities::DEFINITIONS,true));
$cls=new ReflectionClass(ExpenseService::class);$expenses=$cls->newInstanceWithoutConstructor();
foreach(['identity'=>$identity,'capabilities'=>$caps] as $field=>$val){$p=$cls->getProperty($field);$p->setValue($expenses,$val);}
function requireCase(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
foreach([[1,true],[2,true],[3,false],[4,false]] as [$id,$should]){
  $user=['id'=>$id,'role'=>$id===1?'admin':'operator'];
  requireCase($expenses->canRecord($user)===$should,"canRecord identity $id");
  try{$actor=$expenses->assertRecorder($user);requireCase($should,"Unauthorized actor $id accepted");requireCase($actor['id']===$id,'Fresh identity mismatch');}
  catch(\Sokna\Local\Domain\Expenses\ExpenseException $e){requireCase(!$should,"Authorized actor $id rejected");}
}
$method=$cls->getMethod('assertAdmin');
foreach([[1,true],[2,false],[3,false]] as [$id,$should]){
 try{$method->invoke($expenses,['id'=>$id]);requireCase($should,"Non-admin $id got correction rights");}
 catch(\Sokna\Local\Domain\Expenses\ExpenseException $e){requireCase(!$should,"Admin $id rejected");}
}
requireCase(!$caps->has('cashier_accounts',['id'=>2,'role'=>'operator']),'Accidental cashier rights');
requireCase(!$caps->has('remote_access',['id'=>2,'role'=>'operator']),'Accidental internet access');
requireCase(!$caps->has('shift_supervision',['id'=>2,'role'=>'operator']),'Accidental shift supervisor');
requireCase(!$caps->has('expenses_record',['id'=>3,'role'=>'operator']),'Non-finance employee granted expense');
echo "PASS expense recording role gate; admin-only reversals; no cashier, remote or admin role elevation\n";