<?php
declare(strict_types=1);
// One-time, exact-origin owner repair. Remove this file after use. No migrations.
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ini_set('display_errors','0');
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function digest(string $p):string{check(is_file($p)&&!is_link($p),'FILE_MISSING');$s=hash_file('sha256',$p);check(is_string($s),'HASH_FAILED');return $s;}
function readj(string $p):array{$s=file_get_contents($p);check(is_string($s),'STATE_READ');$r=json_decode($s,true,64,JSON_THROW_ON_ERROR);check(is_array($r),'STATE_INVALID');return $r;}
function atomic(string $p,string $b,int $mode):void{$tmp=tempnam(dirname($p),'.sokna-owner-');check(is_string($tmp),'TEMP_FAILED');try{check(file_put_contents($tmp,$b,LOCK_EX)===strlen($b),'WRITE_FAILED');check(chmod($tmp,$mode),'MODE_FAILED');check(rename($tmp,$p),'RENAME_FAILED');clearstatcache(true,$p);check(digest($p)===hash('sha256',$b),'READBACK_FAILED');}finally{if(is_file($tmp))@unlink($tmp);}}
function fresh(string $p):void{if(function_exists('opcache_get_status')){$s=opcache_get_status(false);if(is_array($s)&&($s['opcache_enabled']??false)){check(function_exists('opcache_invalidate')&&opcache_invalidate($p,true),'OPCACHE_REFRESH_UNAVAILABLE');return;}}$enabled=filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN);check(!$enabled||PHP_SAPI==='cli'&&!filter_var(ini_get('opcache.enable_cli'),FILTER_VALIDATE_BOOLEAN),'OPCACHE_STATUS_UNAVAILABLE');}
function safeState(array $s,array $q):void{check(($s['format']??'')==='sokna-public-routine-update-state-v2'&&($s['schema_version']??0)===2,'STATE_FORMAT');check(($s['active_version']??'')===$q['version']&&in_array($s['active_managed_tree_sha256']??'',[ $q['before'],$q['after']],true),'STATE_ORIGIN');foreach(['staged','pending_verification','activation_journal','db_forward_fence'] as $k)check(array_key_exists($k,$s)&&$s[$k]===null,'OPEN_OPERATION_'.$k);check(is_array($s['history']??null),'HISTORY_INVALID');}
$root=__DIR__;$name=basename(__FILE__);$message='';$ready=false;$done=false;
try{
 $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||(int)($_SERVER['SERVER_PORT']??0)===443;
 $host=preg_replace('/:\d+$/','',strtolower((string)($_SERVER['HTTP_HOST']??'')));$local=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)&&in_array($host,['127.0.0.1','localhost','[::1]'],true);
 check($https||$local,'HTTPS_REQUIRED');check(basename((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH))===$name,'DIRECT_PATH_REQUIRED');
 session_name('sokna_public_emergency');ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');$path=rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/'.$name)),'/').'/';session_set_cookie_params(['lifetime'=>0,'path'=>$path,'secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);session_start();$now=time();
 if(($_SESSION['emergency_authenticated']??false)!==true||!is_int($_SESSION['emergency_login_at']??null)||!is_int($_SESSION['emergency_last_seen']??null)||$now-$_SESSION['emergency_login_at']>14400||$now-$_SESSION['emergency_last_seen']>900){http_response_code(403);echo '<meta charset="utf-8"><p>ابتدا از Emergency وارد شوید.</p><a href="emergency.php">Emergency</a>';exit;}
 $csrf=(string)($_SESSION['csrf']??'');check(strlen($csrf)>=32,'SESSION_CSRF');
 $post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';if($post){check(hash_equals($csrf,(string)($_POST['csrf']??'')),'CSRF_INVALID');check(($_POST['confirm']??'')==='REPAIR'&&($_POST['quiet']??'')==='yes','CONFIRM_REQUIRED');}
 $q=json_decode(base64_decode('__PLAN_BASE64__',true),true,64,JSON_THROW_ON_ERROR);
 check(trim((string)file_get_contents($root.'/VERSION.txt'))===$q['version'],'VERSION_MISMATCH');check(is_file($root.'/install.lock')&&is_file($root.'/config.php'),'NOT_INSTALLED');
 require_once $root.'/src/Core/Config.php';require_once $root.'/src/Core/Database.php';require_once $root.'/src/Emergency/PublicUpdateException.php';require_once $root.'/src/Update/RoutinePublicUpdateService.php';
 $raw=require $root.'/config.php';check(is_array($raw),'CONFIG_INVALID');$cfg=\Sokna\PublicEdge\Core\Config::fromArray($raw);$storage=rtrim(trim($cfg->string('app.storage_dir'))?:$root.'/storage',"/\\");check(is_dir($storage),'STORAGE_MISSING');
 $state=$storage.'/routine-update-v2/state.json';$maintenance=$storage.'/routine-update-v2/activation.lock';
 $r=new \Sokna\PublicEdge\Update\RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json');
 $lock=fopen($storage.'/public-update-lifecycle.lock','c+');check(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'UPDATE_BUSY');
 try{
  $s=readj($state);safeState($s,$q);check(!file_exists($maintenance),'MAINTENANCE_ACTIVE');$e=readj($storage.'/emergency/update/state.json');check(($e['format']??'')==='sokna-public-update-state-v1'&&empty($e['staged'])&&empty($e['recovery_required']),'LEGACY_OPERATION_OPEN');
  $db=\Sokna\PublicEdge\Core\Database::connect($cfg);check((int)$db->query("SELECT GET_LOCK('sokna_v3_public_migrations',0)")->fetchColumn()===1,'MIGRATION_BUSY');
  $ledger=$db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);check($ledger===$q['ledger'],'LEDGER_MISMATCH');
  $oldTree=$r->managedTreeDigest();$expected=$s['active_managed_tree_sha256'];$pins=[];foreach($q['patch'] as $n=>$v)$pins[$n]=digest($root.'/'.$n);
  $isOld=$oldTree===$q['before']&&$expected===$q['before'];$isNew=$oldTree===$q['after']&&$expected===$q['after'];
  foreach($q['patch'] as $n=>$v){$isOld=$isOld&&$pins[$n]===$v['old'];$isNew=$isNew&&$pins[$n]===$v['new'];}
  $stable=$isNew?$q['stable_after']:$q['stable_before'];foreach($stable as $n=>$hash)check(digest($root.'/'.$n)===$hash,'STABLE_PIN_MISMATCH');
  check($isOld||$isNew,'EXACT_ORIGIN_MISMATCH');
  if($isNew){$done=true;$message='اصلاح قبلاً ثبت شده است. تغییر تازه‌ای انجام نشد.';}
  elseif(!$post){$ready=true;$message='پیش‌بررسی موفق: نسخه، فایل‌ها، وضعیت و ۱۱ مهاجرت مطابق مرجع هستند.';}
  else{
   $before=file_get_contents($state);check(is_string($before),'STATE_READ');$original=[];$target=[];
   foreach($q['patch'] as $n=>$v){$original[$n]=file_get_contents($root.'/'.$n);$target[$n]=base64_decode($v['b64'],true);check(is_string($original[$n])&&is_string($target[$n])&&hash('sha256',$original[$n])===$v['old']&&hash('sha256',$target[$n])===$v['new'],'PATCH_PIN');fresh($root.'/'.$n);}
   // Save the exact originals and state; never overwrite an existing backup.
   $dir=$storage.'/recovery/cp105-owner-original-'.bin2hex(random_bytes(8));check(mkdir($dir,0700,true),'BACKUP_DIR');
   $backup=['state_b64'=>base64_encode($before),'files'=>array_map('base64_encode',$original),'tree'=>$q['before']];
   $backupBytes="<?php http_response_code(404); exit; ?>\n".base64_encode(json_encode($backup,JSON_THROW_ON_ERROR))."\n";
   atomic($dir.'/backup.php',$backupBytes,0600);
   try{
    foreach($target as $n=>$v){atomic($root.'/'.$n,$v,0644);fresh($root.'/'.$n);}
    check($r->managedTreeDigest()===$q['after'],'REPAIRED_TREE');foreach($q['stable_after'] as $n=>$hash)check(digest($root.'/'.$n)===$hash,'REPAIRED_STABLE_PIN');
    check(file_get_contents($state)===$before&&!file_exists($maintenance),'STATE_CHANGED');
    $s['active_managed_tree_sha256']=$q['after'];$oldLkg=$s['lkg_recovery_id']??'';$s['lkg_recovery_id']='';$s['history'][]=['at'=>gmdate('c'),'action'=>'manual_owner_repair','version'=>$q['version'],'actor_hint'=>'authenticated-emergency-operator','result'=>'success','prior_tree'=>$q['before'],'managed_tree_sha256'=>$q['after'],'prior_lkg_recovery_id'=>$oldLkg,'backup_id'=>basename($dir)];
    atomic($state,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",0600);$done=true;$message='اصلاح یک‌باره انجام شد. هیچ مهاجرت یا تغییر داده انجام نشد. ابزار را حذف کنید و به Emergency برگردید.';
   }catch(Throwable $failure){
    // Restore only if the original state has not been independently changed. No DB rollback.
    if(file_get_contents($state)===$before&&!file_exists($maintenance)){foreach($original as $n=>$v){atomic($root.'/'.$n,$v,0644);fresh($root.'/'.$n);}check($r->managedTreeDigest()===$q['before'],'RESTORE_FAILED');}
    throw $failure;
   }
  }
 }finally{if(isset($db))try{$db->query("SELECT RELEASE_LOCK('sokna_v3_public_migrations')");}catch(Throwable){}flock($lock,LOCK_UN);fclose($lock);}
}catch(Throwable $ex){http_response_code(409);$message='توقف ایمن: '.(get_class($ex)===RuntimeException::class?$ex->getMessage():'PREFLIGHT_OR_REPAIR_FAILED');$ready=false;}
?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اصلاح یک‌باره Recovery</title><style>body{font:16px Tahoma,Arial,sans-serif;max-width:680px;margin:40px auto;padding:20px;line-height:1.8}button{padding:12px}p{overflow-wrap:anywhere}</style><h1>اصلاح یک‌باره Recovery کافه</h1><p><?=h($message)?></p><?php if($ready):?><p>قبل از تأیید، از فایل‌ها و دیتابیس بکاپ معتبر بگیرید. لوکال خاموش باشد و هیچ عملیات Recovery یا Emergency دیگری در حال اجرا نباشد. این پیش‌بررسی نمی‌تواند پردازش قدیمی ازقبل‌درحال‌اجرا را به‌طور مستقل تشخیص دهد.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="confirm" value="REPAIR"><label><input type="checkbox" name="quiet" value="yes" required>پشتیبان دارم و هیچ عملیات دیگری در حال اجرا نیست.</label><br><button>اصلاح یک‌باره</button></form><?php endif;?><p><a href="emergency.php">بازگشت به Emergency</a></p></html>
