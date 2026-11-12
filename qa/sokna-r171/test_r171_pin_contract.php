<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || count($argv)!==2)exit(88);
$local=$argv[1];
require $local.'/src/Domain/Admin/AdminControlService.php';
$method=(new ReflectionClass('Sokna\\Local\\Domain\\Admin\\AdminControlService'))->getMethod('validStaffPassword');
$pin=implode('',array_map('chr',[49,50,51,52]));
$valid=function(string $password,string $role,array $caps) use ($method):bool{return $method->invoke(null,$password,$role,$caps);};
if(!$valid($pin,'operator',['inventory_manage','expenses_record']))exit(1);
if($valid($pin,'admin',['inventory_manage']))exit(2);
if($valid($pin,'operator',['inventory_manage','remote_access']))exit(3);
if($valid('12345','operator',['inventory_manage']))exit(4);
if($valid('12a4','operator',['inventory_manage']))exit(5);
if(!$valid('some-strong-password','admin',['remote_access']))exit(6);
if(!$valid('some-strong-password','operator',['remote_access']))exit(7);
$ui=file_get_contents($local.'/public/admin/index.php');
if(strpos($ui,'name="password" type="password" minlength="4"')===false)exit(8);
echo "PASS R171 four-digit local-only PIN; admin and remote enforced 8+; UI/server alignment\n";
