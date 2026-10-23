<?php
declare(strict_types=1);
/*
 * SOKNA Local Update Rescue — browser-only control-plane fallback.
 * Self-contained by design: no app bootstrap and no database mutation.
 * It can only rebind the CURRENT Web tree to an already-valid installation
 * identity from config.php + install.lock, then return to Update Center.
 */
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Frame-Options: DENY');
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$loopback=in_array($remote,['127.0.0.1','::1','::ffff:127.0.0.1'],true);
if(!$loopback){http_response_code(403);header('Content-Type: text/plain; charset=utf-8');echo 'Local recovery is available only on this computer.';exit;}
if(session_status()!==PHP_SESSION_ACTIVE){session_name('SOKNA_UPDATE_RESCUE');session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);}
if(!isset($_SESSION['csrf'])||!is_string($_SESSION['csrf'])||strlen($_SESSION['csrf'])<32)$_SESSION['csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['csrf'];
$localRoot=dirname(__DIR__);$packageRoot=dirname($localRoot);
$configPath=$packageRoot.DIRECTORY_SEPARATOR.'config.php';$lockPath=$packageRoot.DIRECTORY_SEPARATOR.'install.lock';$bindingPath=$localRoot.DIRECTORY_SEPARATOR.'.sokna-installation.json';$version=trim((string)@file_get_contents($localRoot.DIRECTORY_SEPARATOR.'VERSION.txt'));
function rescue_json(string $path): array{$raw=@file_get_contents($path);if(!is_string($raw)||$raw==='')return [];try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}}
function rescue_write_json(string $path,array $data): void{$tmp=$path.'.tmp-'.bin2hex(random_bytes(4));$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('write_failed');if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('replace_failed');}}
function rescue_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function rescue_base(): string{$script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/update-rescue.php'));$dir=rtrim(str_replace('\\','/',dirname($script)),'/');return $dir==='.'?'':$dir;}
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&($_GET['finalize']??'')==='1'){
    $reset=null;if(function_exists('opcache_reset')){try{$reset=(bool)@opcache_reset();}catch(Throwable){$reset=false;}}clearstatcache();
    $target=rescue_base().'/system/?tab=updates&finalized=1';header('Location: '.$target);exit;
}

$config=[];if(is_file($configPath)){try{$v=require $configPath;if(is_array($v))$config=$v;}catch(Throwable){}}
$lock=rescue_json($lockPath);$binding=rescue_json($bindingPath);
$installationId=trim((string)($config['installation']['id']??''));$lockId=trim((string)($lock['installation_id']??''));
$identityValid=$installationId!==''&&$lockId!==''&&hash_equals($installationId,$lockId)&&($lock['format']??'')==='sokna-install-lock-v3';
$bindingCurrent=$identityValid&&($binding['format']??'')==='sokna-local-installation-binding-v1'&&hash_equals($installationId,trim((string)($binding['installation_id']??'')))&&$version!==''&&hash_equals($version,trim((string)($binding['web_version']??'')));
$dataDir=rtrim(trim((string)($config['app']['data_dir']??'')),"\\/");$recoveryMarker=$dataDir!==''?$dataDir.DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'recovery-required.json':'';$recovery=$recoveryMarker!==''?rescue_json($recoveryMarker):[];
function rescue_redact(mixed $value,string $key=''): mixed{
    if(preg_match('/secret|password|token|authorization|cookie|private.?key|shared.?secret/i',$key))return '[REDACTED]';
    if(is_array($value)){$out=[];foreach($value as $k=>$v)$out[$k]=rescue_redact($v,(string)$k);return $out;}
    if(is_string($value)&&strlen($value)>4096)return substr($value,0,4096).'…';return $value;
}
function rescue_tail_logs(string $dataDir,int $limit=160): array{
    $dir=$dataDir!==''?$dataDir.DIRECTORY_SEPARATOR.'logs':'';if($dir===''||!is_dir($dir))return [];$files=glob($dir.DIRECTORY_SEPARATOR.'sokna-*.jsonl')?:[];rsort($files,SORT_STRING);$rows=[];
    foreach(array_slice($files,0,3) as $file){$lines=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!is_array($lines))continue;foreach(array_reverse(array_slice($lines,-$limit)) as $line){if(count($rows)>=$limit)break 2;if(strlen($line)>16384)continue;try{$row=json_decode($line,true,32,JSON_THROW_ON_ERROR);if(is_array($row)){$row['log_file']=basename($file);$rows[]=rescue_redact($row);}}catch(Throwable){}}}return $rows;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&($_GET['download']??'')==='diagnostics'){
    $state=$dataDir!==''?rescue_json($dataDir.DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'state.json'):[];
    $managed=$dataDir!==''?rescue_json($dataDir.DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'managed-files.json'):[];
    $payload=['format'=>'sokna-rescue-diagnostics-v1','generated_at'=>gmdate('c'),'source'=>'self-contained-update-rescue','local_version'=>$version,'php_version'=>PHP_VERSION,'server_sapi'=>PHP_SAPI,'remote_loopback'=>$loopback,'installation_hint'=>$installationId!==''?substr(hash('sha256',$installationId),0,12):'','binding'=>rescue_redact($binding),'local_update_state'=>rescue_redact($state),'managed_files'=>['format'=>$managed['format']??'','updated_at'=>$managed['updated_at']??'','count'=>count((array)($managed['files']??[]))],'recovery_required'=>rescue_redact($recovery),'recent_logs'=>rescue_tail_logs($dataDir)];
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);$gz=gzencode($json,6,ZLIB_ENCODING_GZIP);if($gz===false){http_response_code(500);echo 'diagnostics_compression_failed';exit;}
    $name='sokna-rescue-'.gmdate('Ymd-His').'.json.gz';header('Content-Type: application/gzip');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen($gz));echo $gz;exit;
}

$message='';$ok=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        $token=(string)($_POST['csrf']??'');if($token===''||!hash_equals($csrf,$token))throw new RuntimeException('csrf');
        if(!$identityValid)throw new RuntimeException('identity_invalid');
        if($version==='')throw new RuntimeException('version_missing');
        rescue_write_json($bindingPath,['format'=>'sokna-local-installation-binding-v1','installation_id'=>$installationId,'bound_at'=>gmdate('c'),'source'=>'browser-update-rescue','web_version'=>$version]);
        $ok=true;$message='اتصال نسخه فعلی به نصب موجود بازسازی شد. در حال بازگشت به Update Center…';
        $base=rescue_base();header('Refresh: 1; url='.$base.'/system/?tab=updates&recovery=1');
    }catch(Throwable){$message='بازیابی اتصال انجام نشد. هیچ تغییری در دیتابیس انجام نشده است.';}
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بازیابی به‌روزرسانی سکنا</title><style>body{font-family:system-ui,sans-serif;background:#f5f3ee;color:#26221d;margin:0;padding:32px}.box{max-width:700px;margin:auto;background:#fff;border:1px solid #ddd8cf;border-radius:18px;padding:28px;box-shadow:0 12px 32px #00000010}.note{padding:14px;border-radius:12px;background:#f7f4ef;margin:14px 0}.ok{background:#e9f8ee}.bad{background:#fff0ee}.warn{background:#fff6df}.meta{font-family:ui-monospace,monospace;direction:ltr;text-align:left;word-break:break-all}button,a.btn{display:inline-block;border:0;border-radius:10px;padding:12px 16px;text-decoration:none;cursor:pointer;background:#0f6b66;color:#fff;font:inherit}button[disabled]{opacity:.5;cursor:not-allowed}small{display:block;color:#666;margin-top:10px}</style></head><body><main class="box"><h1>بازیابی مسیر به‌روزرسانی</h1><p>این صفحه مستقل از سامانه اصلی اجرا می‌شود و فقط اتصال فایل‌های فعلی Web به نصب معتبر همین دستگاه را بازسازی می‌کند. دیتابیس، اطلاعات کافه و migrationها را تغییر نمی‌دهد.</p><?php if($message!==''):?><div class="note <?=$ok?'ok':'bad'?>"><?=rescue_e($message)?></div><?php endif;?><div class="note <?=$identityValid?'':'bad'?>">هویت نصب: <strong><?=$identityValid?'معتبر':'نامعتبر'?></strong><br>نسخه Web: <span class="meta"><?=rescue_e($version?:'—')?></span><br>Binding: <strong><?=$bindingCurrent?'هماهنگ':'نیازمند بازسازی'?></strong><?php if(($recovery['format']??'')!==''):?><br>Recovery Mode: <strong>فعال</strong><?php endif;?></div><?php if($bindingCurrent):?><a class="btn" href="<?=rescue_e(rescue_base().'/system/?tab=updates&recovery=1')?>">باز کردن Update Center</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=rescue_e($csrf)?>"><button type="submit" <?=$identityValid?'':'disabled'?>>بازسازی اتصال و باز کردن Update Center</button></form><?php endif;?> <a class="btn" href="?download=diagnostics">دانلود عیب‌یابی مستقل</a> <a class="btn" href="?finalize=1">تازه‌سازی موتور PHP و بازگشت</a><small>بسته عیب‌یابی مستقل بدون Bootstrap سامانه، دیتابیس یا Public ساخته می‌شود. این ابزار فقط از 127.0.0.1 روی همین رایانه قابل استفاده است.</small></main></body></html>
