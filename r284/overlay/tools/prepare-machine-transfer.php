<?php
declare(strict_types=1);
/**
 * Offline old-Local fence/export for the Windows service owner.
 *
 * Requires Administrator stop + independent readback of all Local web, Print
 * Agent and Runtime workers. Does not switch Public or activate a new PC.
 * No API/browser path exists for this command. Secrets are environment-only.
 */
if(PHP_SAPI!=='cli'||isset($_SERVER['REMOTE_ADDR'])){
    http_response_code(404);exit(1);
}
$web=dirname(__DIR__);
require_once $web.'/bootstrap.php';
use Sokna\Local\Core\Config;
use Sokna\Local\Core\Database;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Recovery\LocalMachineTransferFence;
use Sokna\Local\Domain\Recovery\RecoveryException;

try{
    if(PHP_OS_FAMILY!=='Windows')
        throw new RecoveryException('cutover_native_windows_required',
            'صدور قفل انتقال فقط توسط سرویس‌های ویندوز Local مجاز است.',409);
    $configPath=dirname($web).DIRECTORY_SEPARATOR.'config.php';
    if(!is_file($configPath)||is_link($configPath))
        throw new RecoveryException('cutover_config_invalid','فایل تنظیمات نصب معتبر نیست.',409);
    $conf=require $configPath;
    if(!is_array($conf))throw new RecoveryException('cutover_config_invalid','تنظیمات نصب معتبر نیست.',409);
    $config=Config::fromArray($conf);
    $dataRoot=$config->requiredString('app.data_dir');
    $oldId=$config->requiredString('installation.id');
    $args=[];
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--([a-z][a-z0-9-]+)=(.+)$/D',$arg,$m))
            throw new RecoveryException('cutover_arg_invalid','پارامتر انتقال معتبر نیست.',422);
        if(isset($args[$m[1]]))
            throw new RecoveryException('cutover_arg_duplicate','پارامتر تکراری است.',422);
        $args[$m[1]]=$m[2];
    }
    if(array_diff(array_keys($args),['backup','media','receipt'])!==[]||
        count($args)!==3)
        throw new RecoveryException('cutover_required_files_missing','سه فایل خروجی لازم است.',422);
    $businessPass=(string)(getenv('SOKNA_BUSINESS_TRANSFER_PASSWORD')?:'');
    $mediaPass=(string)(getenv('SOKNA_MEDIA_TRANSFER_PASSWORD')?:'');
    $receiptKey=(string)(getenv('SOKNA_LOCAL_HANDOFF_SECRET')?:'');
    if(strlen($businessPass)<12||strlen($mediaPass)<16||strlen($receiptKey)<32)
        throw new RecoveryException('cutover_secrets_unavailable',
            'رمزهای قوی باید از مسیر امن سرویس ویندوز دریافت شوند.',422);

    $workersStopped=static function():bool {
        // Infrastructure service labels are product-level, never cafe/host-specific.
        // Unknown/failed queries are not proof of a stopped process.
        $services=['SoknaApache','SoknaPrintAgent','SoknaRuntime'];
        $known=0;
        foreach($services as $name){
            $lines=[];$exit=0;
            exec('sc.exe query '.escapeshellarg($name).' 2>&1',$lines,$exit);
            $out=implode("\n",$lines);
            if($exit!==0){
                if(str_contains($out,'1060'))continue;
                return false;
            }
            ++$known;
            if(!preg_match('/STATE\s*:\s*1\s+STOPPED/i',$out))
                return false;
        }
        // A PC without the known Local web runtime is not an accepted source.
        return $known>0;
    };
    if(!$workersStopped())
        throw new RecoveryException('cutover_workers_running',
            'Apache/Runtime/PrintAgent هنوز متوقف و تأیید نشده‌اند.',409);
    $pdo=Database::connect($config);
    $service=new LocalMachineTransferFence(
        $pdo,new Observability($dataRoot),$dataRoot,$oldId,$workersStopped);
    $result=$service->seal(
        $args['backup'],$args['media'],
        $businessPass,$mediaPass,$receiptKey,$args['receipt']);
    echo json_encode(['ok'=>true,'source_fenced'=>$result['source_fenced'],
        'receipt_file'=>$args['receipt'],'business_backup_file'=>$args['backup'],
        'media_archive_file'=>$args['media'],
        'source_public_owner_transfer_required'=>true,
        'new_local_activation_authorized'=>false],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}catch(Throwable $e){
    $code=$e instanceof RecoveryException?$e->errorCode:'cutover_preflight_failed';
    fwrite(STDERR,json_encode(['ok'=>false,'code'=>$code,'message'=>$e->getMessage()],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}
