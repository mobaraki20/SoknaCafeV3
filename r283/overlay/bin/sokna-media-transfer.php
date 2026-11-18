<?php
declare(strict_types=1);
/**
 * SOKNA R283 private media vault tool. CLI only; never activates a target.
 * Usage:
 *   SOKNA_MEDIA_TRANSFER_PASSWORD=<secret> php bin/sokna-media-transfer.php export --business-backup=/private/backup.skbf --output=/usb/media.smv
 *   SOKNA_MEDIA_TRANSFER_PASSWORD=<secret> php bin/sokna-media-transfer.php stage --business-backup=/private/backup.skbf --archive=/usb/media.smv --source-installation-id=local-...
 * Password comes only from environment or protected prompt wrapper, never argv.
 */
if(PHP_SAPI!=='cli'||isset($_SERVER['REMOTE_ADDR'])){http_response_code(404);exit(1);}
$root=dirname(__DIR__);
require_once $root.'/src/Domain/Recovery/RecoveryException.php';
require_once $root.'/src/Domain/Recovery/PrivateMediaTransferService.php';

use Sokna\Local\Domain\Recovery\PrivateMediaTransferService;
use Sokna\Local\Domain\Recovery\RecoveryException;

try{
    $configPath=dirname($root).DIRECTORY_SEPARATOR.'config.php';
    if(!is_file($configPath)||is_link($configPath))
        throw new RuntimeException('Installed Local config.php not found in private parent');
    $config=require $configPath;
    if(!is_array($config))throw new RuntimeException('Local configuration invalid');
    $dataRoot=trim((string)($config['app']['data_dir']??''));
    $localId=trim((string)($config['installation']['id']??''));
    if($dataRoot===''||$localId==='')
        throw new RuntimeException('Data directory and installation ID are required');
    $password=(string)(getenv('SOKNA_MEDIA_TRANSFER_PASSWORD')?:'');
    if(strlen($password)<16)
        throw new RuntimeException('SOKNA_MEDIA_TRANSFER_PASSWORD must be set and >=16 characters');
    $mode=trim((string)($argv[1]??''));
    $params=[];
    foreach(array_slice($argv,2) as $param){
        if(!preg_match('/^--([a-z][a-z0-9-]+)=(.+)$/D',$param,$match))
            throw new RuntimeException('Invalid parameter');
        if(isset($params[$match[1]]))throw new RuntimeException('Duplicate parameter');
        $params[$match[1]]=$match[2];
    }
    $required=static function(string $key)use($params):string{
        $value=trim((string)($params[$key]??''));
        if($value==='')throw new RuntimeException('Missing --'.$key);
        return $value;
    };
    $backup=$required('business-backup');
    if(!is_file($backup)||is_link($backup))
        throw new RuntimeException('Exact encrypted business backup missing');
    $backupSha=hash_file('sha256',$backup);
    if(!is_string($backupSha))throw new RuntimeException('Business backup checksum failed');
    $service=new PrivateMediaTransferService();
    if($mode==='export'){
        if(array_diff(array_keys($params),['business-backup','output']))
            throw new RuntimeException('Unsupported export parameter');
        $output=$required('output');
        if(str_starts_with(realpath(dirname($output))?:'',realpath($dataRoot.DIRECTORY_SEPARATOR.'guest-media')?:'NO_GUEST_MEDIA_ROOT'))
            throw new RuntimeException('Media transfer archive must be stored outside active vault');
        $result=$service->export($dataRoot.DIRECTORY_SEPARATOR.'guest-media',
            $output,$password,$localId,$backupSha);
    }elseif($mode==='stage'){
        if(array_diff(array_keys($params),['business-backup','archive','source-installation-id']))
            throw new RuntimeException('Unsupported stage parameter');
        $sourceId=$required('source-installation-id');
        if(hash_equals($sourceId,$localId))
            throw new RuntimeException('Do not stage same installation as source');
        $result=$service->importStage($required('archive'),$dataRoot,
            $password,$sourceId,$backupSha);
    }else throw new RuntimeException('Mode must be export or stage');
    unset($password);
    echo json_encode(['ok'=>true,'mode'=>$mode,'result'=>$result],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}catch(Throwable $e){
    $code=$e instanceof RecoveryException?$e->errorCode:'media_transfer_preflight_failed';
    fwrite(STDERR,json_encode(['ok'=>false,'error_code'=>$code,'message'=>$e->getMessage()],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}
