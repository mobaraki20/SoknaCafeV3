<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/apps/local-web/src/Domain/Update/LocalUpdateException.php';
require_once dirname(__DIR__).'/apps/local-web/src/Domain/Update/UpdateMigrationPolicy.php';
require_once dirname(__DIR__).'/apps/local-web/src/Domain/Update/LegacySlotUpdateGuard.php';

use Sokna\Local\Domain\Update\LocalUpdateException;
use Sokna\Local\Domain\Update\UpdateMigrationPolicy;
use Sokna\Local\Domain\Update\LegacySlotUpdateGuard;

function g23p(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL G2.3 pure: {$message}\n");exit(1);}}
function g23reject(callable $action,string $code):void{
    try{$action();}catch(LocalUpdateException $e){g23p($e->errorCode===$code,"expected {$code}, received {$e->errorCode}");return;}
    g23p(false,"unsafe operation accepted instead of {$code}");
}
function g23delete(string $root):void{
    if(!file_exists($root))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $node){if($node->isDir())@rmdir($node->getPathname());else @unlink($node->getPathname());}
    @rmdir($root);
}
$tmp=sys_get_temp_dir().'/sokna-g23-policy-'.bin2hex(random_bytes(5));mkdir($tmp.'/Web',0700,true);
try{
    $f1="CREATE TABLE g23_a(id INT PRIMARY KEY);";
    $f2="CREATE TABLE g23_b(id INT PRIMARY KEY);";
    $versions=['0001_g23_a'=>hash('sha256',$f1),'0002_g23_b'=>hash('sha256',$f2)];
    file_put_contents($tmp.'/0001_g23_a.sql',$f1);
    file_put_contents($tmp.'/0002_g23_b.sql',$f2);
    $manifest=['database'=>['policy'=>UpdateMigrationPolicy::POLICY,'migrations'=>[
        ['version'=>'0001_g23_a','sha256'=>$versions['0001_g23_a']],
        ['version'=>'0002_g23_b','sha256'=>$versions['0002_g23_b']],
    ]]];
    g23p(UpdateMigrationPolicy::catalogFromManifest($manifest)===$versions,'catalog ordering/hash changed');
    $old=UpdateMigrationPolicy::assertInstalledLineage(['0001_g23_a'],$versions,$tmp);
    $new=UpdateMigrationPolicy::assertInstalledLineage(array_keys($versions),$versions,$tmp);
    g23p($old['pending_count']===1&&$new['pending_count']===0,'one target must support older and newer migration baselines');
    g23reject(fn()=>UpdateMigrationPolicy::assertInstalledLineage(array_keys($versions),['0001_g23_a'=>$versions['0001_g23_a']],$tmp),'schema_downgrade_unsupported');
    g23reject(fn()=>UpdateMigrationPolicy::assertInstalledLineage(array_keys($versions),['0001_g23_a'=>str_repeat('f',64),'0002_g23_b'=>$versions['0002_g23_b']],$tmp),'migration_history_changed');
    $bad=$manifest;
    $bad['database']['migrations'][]=$bad['database']['migrations'][0];
    g23reject(fn()=>UpdateMigrationPolicy::catalogFromManifest($bad),'database_contract_invalid');
    $bad=$manifest;$bad['database']['policy']='ignore-migration-history';
    g23reject(fn()=>UpdateMigrationPolicy::catalogFromManifest($bad),'database_policy_missing');
    g23p(!LegacySlotUpdateGuard::isSlotManaged($tmp.'/Web'),'legacy test web incorrectly considered slot-managed');
    mkdir($tmp.'/Web.release.3.0.0',0700,true);
    g23p(LegacySlotUpdateGuard::isSlotManaged($tmp.'/Web'),'release-slot installation must block legacy updater');
    g23reject(fn()=>LegacySlotUpdateGuard::requireLegacyLayout($tmp.'/Web'),'MAINTENANCE_SLOT_OWNED');
}finally{g23delete($tmp);}
echo "G2.3 Local universal migration/slot policy pure self-test: OK\n";
