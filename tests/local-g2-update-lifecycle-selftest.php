<?php
declare(strict_types=1);

/**
 * G2.3 pre-deployment security qualification for the current universal Local ZIP.
 * It stages the SAME signed-policy-compatible file on two independent code baselines,
 * validates lineage, and rejects tampering/downgrade/delta mismatch. It intentionally
 * never activates a synthetic three-file Web tree: real activation, migration failure
 * recovery, and encrypted restore have their own M10/Windows release qualification.
 */
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
use Sokna\Local\Core\Migrations;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Update\LocalUpdateService;
use Sokna\Local\Domain\Update\LocalUpdateException;

function g23(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL G2.3 {$message}\n");exit(1);}}
function g23deny(callable $operation,string $code):void{
    try{$operation();}catch(LocalUpdateException $e){g23($e->errorCode===$code,"expected {$code}, got {$e->errorCode}");return;}
    g23(false,"operation did not reject {$code}");
}
function g23rm(string $root):void{
    if(!file_exists($root))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir())@rmdir($item->getPathname());else @unlink($item->getPathname());}
    @rmdir($root);
}
/** @param array<string,string> $files */
function g23package(string $destination,array $files,string $version='4.0.0',int $contract=2,array $extra=[]):void{
    $z=new ZipArchive();g23($z->open($destination,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'cannot create ZIP');
    ksort($files,SORT_STRING);
    $entries=[];$migrations=[];
    foreach($files as $path=>$content){
        $digest=hash('sha256',$content);
        $entries[]=['path'=>$path,'size'=>strlen($content),'sha256'=>$digest];
        if(preg_match('#^database/migrations/(\d{4}_[a-z0-9_]+)\.sql$#D',$path,$m))$migrations[]=['version'=>$m[1],'sha256'=>$digest];
        $z->addFromString('payload/'.$path,$content);
    }
    $manifest=[
        'format'=>'sokna-component-package-v1','schema_version'=>1,'component'=>'local',
        'version'=>$version,'source_commit'=>str_repeat('a',40),'updater_contract'=>$contract,
        'upgrade'=>['release_line'=>'sokna-g23-universal-test','mode'=>'full'],
        'contracts'=>['runtime_contract'=>'1.0.0','print_server_protocol'=>4],
        'database'=>['policy'=>'forward-compatible-v1','migrations'=>$migrations],
        'files'=>$entries,
    ];
    $manifest=array_replace_recursive($manifest,$extra);
    $z->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $z->close();
}
function g23service(string $root,string $version,PDO $pdo,string $installedMigrationDir):LocalUpdateService{
    foreach(['pkg/database/migrations','Web/public','data'] as $path)@mkdir($root.'/'.$path,0700,true);
    file_put_contents($root.'/pkg/VERSION.txt',$version."\n");
    file_put_contents($root.'/Web/bootstrap.php','<?php // existing source, must not change on stage');
    foreach(glob($installedMigrationDir.'/*.sql')?:[] as $migration)copy($migration,$root.'/pkg/database/migrations/'.basename($migration));
    file_put_contents($root.'/compat.json',json_encode([
        'release_line'=>'sokna-g23-universal-test',
        'components'=>['local'=>['requires'=>['runtime_contract'=>'>=1.0.0 <2.0.0','print_server_protocol'=>4]]],
    ],JSON_THROW_ON_ERROR));
    file_put_contents($root.'/trust.json',json_encode(['signature_policy'=>'optional_until_release_qualification','ed25519_public_keys'=>[]],JSON_THROW_ON_ERROR));
    return new LocalUpdateService(
        new Observability($root.'/data'),new Migrations($pdo,$installedMigrationDir),
        $root.'/pkg',$root.'/Web',$root.'/compat.json',$root.'/trust.json'
    );
}
g23(class_exists(ZipArchive::class),'PHP zip is required');
g23(in_array('mysql',PDO::getAvailableDrivers(),true),'PDO MySQL is required');

$env=static fn(string $key,string $default):string=>(string)(getenv($key)?:$default);
$pdo=new PDO(
    'mysql:host='.$env('SOKNA_TEST_DB_HOST','127.0.0.1').';port='.$env('SOKNA_TEST_DB_PORT','3306').';dbname='.$env('SOKNA_TEST_DB_NAME','sokna_m2').';charset=utf8mb4',
    $env('SOKNA_TEST_DB_USER','sokna'),$env('SOKNA_TEST_DB_PASS','sokna'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
);
$source=dirname(__DIR__).'/apps/local-web/database/migrations';
$existing=new Migrations($pdo,$source);
$applied=$existing->appliedVersions();
g23(count($applied)>20,'G2 must receive the fully migrated disposable CI database');
$files=[
    'bootstrap.php'=>'<?php // target bootstrap',
    'public/_app.php'=>'<?php // target app',
    'public/index.php'=>'<?php // target front controller',
];
foreach(glob($source.'/*.sql')?:[] as $migration)$files['database/migrations/'.basename($migration)]=(string)file_get_contents($migration);
g23(count($files)>20,'migration catalog missing');
$last=array_key_last($files);
$tmp=sys_get_temp_dir().'/sokna-g23-universal-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
try{
    $zip=$tmp.'/universal-full.zip';
    g23package($zip,$files);
    $digest=hash_file('sha256',$zip);
    $older=g23service($tmp.'/older','3.0.0',$pdo,$source);
    $newer=g23service($tmp.'/newer','3.7.0',$pdo,$source);
    $a=$older->stageUploadedZip($zip,1);$b=$newer->stageUploadedZip($zip,1);
    g23($a['version']==='4.0.0'&&$b['version']==='4.0.0','same target was not staged for both versions');
    g23($a['package_sha256']===$digest&&$b['package_sha256']===$digest,'ZIP identity drifted across source versions');
    g23($older->currentVersion()==='3.0.0'&&$newer->currentVersion()==='3.7.0','stage unexpectedly activated new version');
    g23((string)file_get_contents($tmp.'/older/Web/bootstrap.php')==='<?php // existing source, must not change on stage','stage wrote business Web files');
    g23(($a['database_preflight']['policy']??'')==='forward-compatible-v1','missing guarded database preflight');
    g23deny(fn()=>$older->rollback(1),'no_recovery_point');

    $tampered=$tmp.'/tampered.zip';copy($zip,$tampered);
    $z=new ZipArchive();g23($z->open($tampered)===true,'tampered ZIP open failed');
    $z->addFromString('payload/bootstrap.php','<?php // tampered content');$z->close();
    g23deny(fn()=>$older->stageUploadedZip($tampered,1),'hash_mismatch');

    $unsigned=$tmp.'/unsigned.zip';
    g23package($unsigned,$files);
    file_put_contents($tmp.'/older/trust.json',json_encode(['signature_policy'=>'required','ed25519_public_keys'=>[]],JSON_THROW_ON_ERROR));
    g23deny(fn()=>$older->stageUploadedZip($unsigned,1),'signature_required');
    file_put_contents($tmp.'/older/trust.json',json_encode(['signature_policy'=>'optional_until_release_qualification','ed25519_public_keys'=>[]],JSON_THROW_ON_ERROR));

    $legacy=$tmp.'/legacy-no-upgrade-contract.zip';
    g23package($legacy,$files,'4.0.0',1);
    g23deny(fn()=>$older->stageUploadedZip($legacy,1),'upgrade_contract_missing');

    $withoutLast=$files;unset($withoutLast[$last]);
    g23(str_starts_with((string)$last,'database/migrations/'),'last catalog entry must be a migration');
    $downgrade=$tmp.'/schema-downgrade.zip';g23package($downgrade,$withoutLast);
    g23deny(fn()=>$newer->stageUploadedZip($downgrade,1),'schema_downgrade_unsupported');

    $drift=$files;$historical=array_key_first(array_filter(array_keys($files),static fn(string $p):bool=>str_starts_with($p,'database/migrations/')));
    g23(is_string($historical),'historical SQL file missing');
    $drift[$historical].="\n-- tampered migration history";
    $driftZip=$tmp.'/changed-history.zip';g23package($driftZip,$drift);
    g23deny(fn()=>$older->stageUploadedZip($driftZip,1),'migration_history_changed');

    $delta=$tmp.'/baseline-bound-delta.zip';
    g23package($delta,['bootstrap.php'=>'<?php // delta'], '4.0.0',3,[
        'upgrade'=>['mode'=>'delta-v2'],
        'base'=>['version'=>'3.0.0','managed_tree_sha256'=>str_repeat('a',64)],
        'target'=>['managed_tree_sha256'=>str_repeat('b',64)],
        'database'=>['migrations'=>array_map(
            static fn(string $path):array=>['version'=>substr(basename($path),0,-4),'sha256'=>hash('sha256',$files[$path])],
            array_values(array_filter(array_keys($files),static fn(string $p):bool=>str_starts_with($p,'database/migrations/')))
        )],
    ]);
    g23deny(fn()=>$newer->stageUploadedZip($delta,1),'base_version_mismatch');
    g23deny(fn()=>$older->stageUploadedZip($delta,1),'base_tree_mismatch');
    g23($older->snapshot()['active_version']==='3.0.0'&&$newer->snapshot()['active_version']==='3.7.0','negative tests changed active version');
}finally{g23rm($tmp);}
echo "G2.3 universal Local ZIP cross-baseline stage + safety regression: OK\n";
