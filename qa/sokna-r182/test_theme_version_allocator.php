<?php
declare(strict_types=1);
require $argv[1] . '/src/Domain/GuestContent/GuestContentService.php';
$ref = new ReflectionMethod(\Sokna\Local\Domain\GuestContent\GuestContentService::class, 'nextBuiltinThemePatch');
$ref->setAccessible(true);
function check(bool $ok,string $name): void {if (!$ok) throw new RuntimeException('FAIL: '.$name); echo 'PASS '.$name."\n";}
$versions=[];
for($i=0;$i<400;$i++)$versions[]='2.0.'.$i;
check($ref->invoke(null,'2.0.0',$versions)==='2.0.400','400_VERSION_EXHAUSTION_RECOVERABLE');
check($ref->invoke(null,'2.0.0',['1.0.999','2.0.1','2.0.31','3.0.900'])==='2.0.32','MATCH_MAJOR_MINOR_ONLY');
check($ref->invoke(null,'2.0.9',['2.0.7','invalid'])==='2.0.10','BASE_PATCH_PRESERVED');
check($ref->invoke(null,'1.0.0',[])==='1.0.1','EMPTY_REGISTRY_NEXT_PATCH');
$inc=new ReflectionMethod(\Sokna\Local\Domain\GuestContent\GuestContentService::class,'incrementBuiltinThemePatch');
$inc->setAccessible(true);
check($inc->invoke(null,'2.0.400')==='2.0.401','INCREMENT_PATCH');
