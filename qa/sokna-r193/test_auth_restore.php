<?php
declare(strict_types=1);
foreach(['Config','Observability','Session','IdentityRepository','Capabilities','Auth','RememberDeviceStore'] as $class){
  require_once __DIR__.'/../local/src/Core/'.$class.'.php';
}
use Sokna\Local\Core\{Auth,IdentityRepository,Capabilities,RememberDeviceStore};
function eq(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
final class StubUsers implements IdentityRepository {
  public bool $active=true; public string $pw;
  public function __construct(){ $this->pw=password_hash('secret', PASSWORD_DEFAULT); }
  public function findActiveById(int $userId): ?array {return $this->active && $userId===11?['id'=>11,'username'=>'worker','display_name'=>'Worker','role'=>'operator','active'=>1]:null;}
  public function findActiveByUsername(string $username): ?array {return $this->active && $username==='worker'?['id'=>11,'username'=>'worker','password_hash'=>$this->pw,'display_name'=>'Worker','role'=>'operator','active'=>1]:null;}
  public function capabilitiesForUser(int $userId): array {return [];}
  public function preparationAreasForUser(int $userId): array {return [];}
}
$_SESSION=[];$_COOKIE=[];$_SERVER=['HTTP_HOST'=>'localhost','SERVER_PORT'=>80,'REMOTE_ADDR'=>'127.0.0.1'];
$base=sys_get_temp_dir().'/r191-auth-'.bin2hex(random_bytes(5));$store=new RememberDeviceStore($base);$users=new StubUsers();
$token=$store->issue(11,$users->pw,$_SERVER);$_COOKIE[RememberDeviceStore::COOKIE]=$token;
$a=new Auth($users,new Capabilities($users),600,null,null,$store);
$user=$a->currentUser();eq(($user['id']??0)===11,'persisted device restores active user');
eq(!array_key_exists('password_hash',$user),'session user has no password hash');
$_SESSION=[];$users->pw=password_hash('changed',PASSWORD_DEFAULT);
$a=new Auth($users,new Capabilities($users),600,null,null,$store);eq($a->currentUser()===null,'password change revokes remembered login');
$token=$store->issue(11,$users->pw,$_SERVER);$_COOKIE[RememberDeviceStore::COOKIE]=$token;
$_SESSION=[];$users->active=false;
$a=new Auth($users,new Capabilities($users),600,null,null,$store);eq($a->currentUser()===null,'disabled account cannot restore');
$_SESSION=[];$_SERVER=['HTTP_HOST'=>'192.168.0.12','SERVER_PORT'=>80];$users->active=true;
$a=new Auth($users,new Capabilities($users),600,null,null,$store);eq($a->currentUser()===null,'LAN HTTP restore disabled');
foreach(glob($base.'/auth/remembered-devices/*')?:[] as $f)unlink($f);@rmdir($base.'/auth/remembered-devices');@rmdir($base.'/auth');@rmdir($base);
echo 'PASS R191 auth restore tests'.PHP_EOL;
