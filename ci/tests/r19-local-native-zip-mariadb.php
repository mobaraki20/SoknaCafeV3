<?php
declare(strict_types=1);
/** R19 CI-only: exact Local R17 ZIP native Php ZipArchive + disposable MariaDB. No host or activation. */
require_once dirname(__DIR__,2).'/apps/local-web/bootstrap.php';
use Sokna\Local\Core\Migrations;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Update\LocalUpdateService;
use Sokna\Local\Domain\Update\LocalUpdateException;
function must(bool $v,string $message): void {if(!$v)throw new RuntimeException($message);}
function reject(callable $fn,string $expected): void {
    try{$fn();}catch(LocalUpdateException $e){must($e->errorCode===$expected,"expected {$expected}, got {$e->errorCode}");return;}
    throw new RuntimeException("Did not reject {$expected}");
}
function removeTree(string $path): void {
    if(!is_dir($path))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($path);
}
$pkg=(string)(getenv('R19_LOCAL_R17_ZIP')?:'/tmp/sokna-r17-local.zip');
$want='9525ecbfb256a62b782b768e510dccab5bc81941c2eb93fa56f5c704b403b5f4';
must(extension_loaded('zip')&&class_exists(ZipArchive::class),'Real native ZipArchive is mandatory');
must(in_array('mysql',PDO::getAvailableDrivers(),true),'Real PDO_mysql is mandatory');
must(is_file($pkg)&&hash_file('sha256',$pkg)===$want,'Exact R17 package SHA256 mismatch');
$zip=new ZipArchive();must($zip->open($pkg)===true,'Native ZipArchive cannot open package');
$manifest=json_decode((string)$zip->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
must(($manifest['component']??null)==='local'&&count($manifest['files']??[])===534,'Local manifest identity/count failed');
$expected=[];$migrations=[];
foreach($manifest['files'] as $entry){
    $path=(string)$entry['path'];
    must($path!==''&&!str_starts_with($path,'/')&&!str_contains($path,'..')&&!str_contains($path,'\\'),'Unsafe payload path');
    must(!isset($expected[$path]),'Duplicate package entry');
    $expected[$path]=true;
    $stream=$zip->getStream('payload/'.$path);must(is_resource($stream),'Missing payload stream '.$path);
    $ctx=hash_init('sha256');$size=0;
    while(!feof($stream)){$chunk=fread($stream,1048576);must(is_string($chunk),'Read failed');$size+=strlen($chunk);hash_update($ctx,$chunk);}
    fclose($stream);
    must($size===(int)$entry['size']&&hash_final($ctx)===$entry['sha256'],'Payload hash mismatch '.$path);
    if(preg_match('#^database/migrations/(\d{4}_[a-z0-9_]+)\.sql$#D',$path,$m))$migrations[$m[1]]=$entry['sha256'];
}
$actual=[];for($i=0;$i<$zip->numFiles;$i++){
    $name=(string)$zip->getNameIndex($i);must($name==='manifest.json'||str_starts_with($name,'payload/'),'Unexpected ZIP member');
    if(str_starts_with($name,'payload/'))$actual[substr($name,8)]=true;
}
$zip->close();must(count($actual)===534&&array_keys($actual)===array_keys($expected),'Extra/missing payload entries');
must(count($migrations)===76,'Expected 76 migration SQL files in exact R17 ZIP');
$dsn='mysql:host='.(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1').';port='.(getenv('SOKNA_TEST_DB_PORT')?:'3306').';dbname='.(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2').';charset=utf8mb4';
$pdo=new PDO($dsn,getenv('SOKNA_TEST_DB_USER')?:'sokna',getenv('SOKNA_TEST_DB_PASS')?:'sokna',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$source=dirname(__DIR__,2).'/apps/local-web/database/migrations';
$catalog=new Migrations($pdo,$source);
$applied=$catalog->appliedVersions();must(count($applied)===76,'MariaDB is not fully migrated to R17 76-file baseline');
foreach($applied as $version){must(isset($migrations[$version]),'Missing applied migration in R17 ZIP: '.$version);must(hash_file('sha256',$source.'/'.$version.'.sql')===$migrations[$version],'Applied migration drift: '.$version);}
$tmp=sys_get_temp_dir().'/sokna-r19-local-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
function svc(string $base,string $version,PDO $pdo,string $installedDir,string $release='v3',string $policy='optional_until_release_qualification'): LocalUpdateService {
    foreach(['pkg/database/migrations','Web/public','data'] as $rel)mkdir($base.'/'.$rel,0700,true);
    file_put_contents($base.'/pkg/VERSION.txt',$version."\n");
    file_put_contents($base.'/Web/bootstrap.php','<?php /* test baseline */');
    foreach(glob($installedDir.'/*.sql') as $p)copy($p,$base.'/pkg/database/migrations/'.basename($p));
    file_put_contents($base.'/compat.json',json_encode(['release_line'=>$release,'components'=>['local'=>['requires'=>['runtime_contract'=>'>=1.0.0 <2.0.0','print_server_protocol'=>4]]]],JSON_THROW_ON_ERROR));
    file_put_contents($base.'/trust.json',json_encode(['signature_policy'=>$policy,'ed25519_public_keys'=>[]],JSON_THROW_ON_ERROR));
    return new LocalUpdateService(new Observability($base.'/data'),new Migrations($pdo,$installedDir),$base.'/pkg',$base.'/Web',$base.'/compat.json',$base.'/trust.json');
}
$pass=[];
try{
    foreach(['older'=>'3.0.0-g6.2-workspace.231bs','newer'=>'3.0.0-g6.2-workspace.231bt-r16-sourceqa'] as $name=>$version){
        $service=svc($tmp.'/'.$name,$version,$pdo,$source);
        $st=$service->stageUploadedZip($pkg,1);
        must($st['package_sha256']===$want,'Stage failed package hash check');
        must($service->currentVersion()===$version,'Staging modified active version');
        $snap=$service->snapshot();must(($snap['staged']['version']??'')===$manifest['version'],'Stage readback mismatch');
        must(($st['database_preflight']['pending_count']??-1)===0,'Unexpected pending DB migrations');
        $pass[]='exact_r17_native_stage_'.$name;
    }
    // Native Zip tamper without fixing the manifest; must fail closed.
    $bad=$tmp.'/tampered.zip';copy($pkg,$bad);$z=new ZipArchive();must($z->open($bad)===true,'Mutant ZIP open');
    $z->addFromString('payload/VERSION.txt',"tamper\n");$z->close();
    $tamper=svc($tmp.'/tamper','3.0.0-g6.2-workspace.231bs',$pdo,$source);
    reject(fn()=>$tamper->stageUploadedZip($bad,1),'hash_mismatch');$pass[]='tampered_zip_denied';
    $wrong=svc($tmp.'/wrong-line','3.0.0-g6.2-workspace.231bs',$pdo,$source,'wrong-line');
    reject(fn()=>$wrong->stageUploadedZip($pkg,1),'incompatible_release_line');$pass[]='wrong_release_line_denied';
    $signed=svc($tmp.'/requires-sign','3.0.0-g6.2-workspace.231bs',$pdo,$source,'v3','required');
    reject(fn()=>$signed->stageUploadedZip($pkg,1),'signature_required');$pass[]='missing_required_signature_denied';
    $drift=svc($tmp.'/drift','3.0.0-g6.2-workspace.231bs',$pdo,$source);
    $first=$applied[0];file_put_contents($tmp.'/drift/pkg/database/migrations/'.$first.'.sql',"\nUNAUTHORIZED",FILE_APPEND);
    reject(fn()=>$drift->stageUploadedZip($pkg,1),'migration_history_changed');$pass[]='applied_migration_drift_denied';
}finally{removeTree($tmp);}
echo json_encode(['status'=>'PASS','native_zip'=>true,'mariadb'=>true,'source_package_sha256'=>$want,'file_count'=>534,'migration_count'=>76,'scenario_count'=>count($pass),'scenarios'=>$pass,'two_real_independent_host_baselines'=>false,'activation_tested'=>false,'release_installable'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
