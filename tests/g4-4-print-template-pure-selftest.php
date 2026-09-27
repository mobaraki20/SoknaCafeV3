<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
use Sokna\Local\Domain\Printing\PrintTemplatePackageService;
use Sokna\Local\Domain\Printing\PrintException;
function g44(bool $ok,string $m): void{if(!$ok)throw new RuntimeException($m);}
$rc=new ReflectionClass(PrintTemplatePackageService::class);$svc=$rc->newInstanceWithoutConstructor();$m=$rc->getMethod('canonicalPackage');$m->setAccessible(true);
$valid=json_decode(file_get_contents(dirname(__DIR__).'/docs/examples/print-templates/customer-clean-1.0.0.soknaprint'),true,32,JSON_THROW_ON_ERROR);$canonical=$m->invoke($svc,$valid);g44($canonical['format']===PrintTemplatePackageService::FORMAT,'format drift');g44($canonical['document_kind']==='customer','kind drift');g44($canonical['template']['design']['item_layout']==='responsive-receipt','layout drift');
$bad=$valid;$bad['script']='alert(1)';$blocked=false;try{$m->invoke($svc,$bad);}catch(ReflectionException $e){throw $e;}catch(Throwable $e){$blocked=str_contains($e->getMessage(),'contract قالب چاپ مجاز نیست');}g44($blocked,'unknown executable top-level field accepted');
$bad=$valid;$bad['template']['design']['css']='body{}';$blocked=false;try{$m->invoke($svc,$bad);}catch(Throwable $e){$blocked=true;}g44($blocked,'arbitrary design field accepted');
$bad=$valid;$bad['template']['base_font_size']=500;$blocked=false;try{$m->invoke($svc,$bad);}catch(Throwable $e){$blocked=true;}g44($blocked,'unsafe font range accepted');
$bad=$valid;$bad['template']['design']['section_order'][]='shell';$blocked=false;try{$m->invoke($svc,$bad);}catch(Throwable $e){$blocked=true;}g44($blocked,'unknown renderer section accepted');
echo "G4.4 Print Template pure contract: OK\n";
