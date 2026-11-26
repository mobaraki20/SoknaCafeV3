<?php
declare(strict_types=1);
// One-time, exact-origin owner repair. Remove this file after use. No migrations.
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
ini_set('display_errors','0');
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function digest(string $p):string{check(is_file($p)&&!is_link($p),'FILE_MISSING');$s=hash_file('sha256',$p);check(is_string($s),'HASH_FAILED');return $s;}
function readj(string $p):array{$s=file_get_contents($p);check(is_string($s),'STATE_READ');$r=json_decode($s,true,64,JSON_THROW_ON_ERROR);check(is_array($r),'STATE_INVALID');return $r;}
function scratch(string $p):string{return dirname($p).'/.cp107-owner-'.basename($p).'.part';}
function scratchCheck(string $p):void{if(file_exists($p)||is_link($p))check(is_file($p)&&!is_link($p)&&stat($p)['nlink']===1,'SCRATCH_INVALID');}
function atomic(string $p,string $b,int $mode):void{
 $tmp=scratch($p);scratchCheck($tmp);if(is_file($tmp))check(unlink($tmp),'SCRATCH_REMOVE_FAILED');
 $f=fopen($tmp,'x+b');check(is_resource($f),'TEMP_FAILED');
 try{check(fwrite($f,$b)===strlen($b)&&fflush($f)&&fsync($f),'WRITE_FAILED');check(chmod($tmp,$mode),'MODE_FAILED');}finally{fclose($f);}
 check(rename($tmp,$p),'RENAME_FAILED');clearstatcache(true,$p);check(digest($p)===hash('sha256',$b),'READBACK_FAILED');
}
function fresh(string $p):void{if(function_exists('opcache_get_status')){$s=opcache_get_status(false);if(is_array($s)&&($s['opcache_enabled']??false)){check(function_exists('opcache_invalidate')&&opcache_invalidate($p,true),'OPCACHE_REFRESH_UNAVAILABLE');return;}}$enabled=filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN);check(!$enabled||PHP_SAPI==='cli'&&!filter_var(ini_get('opcache.enable_cli'),FILTER_VALIDATE_BOOLEAN),'OPCACHE_STATUS_UNAVAILABLE');}
function safeState(array $s,array $q):void{check(($s['format']??'')==='sokna-public-routine-update-state-v2'&&($s['schema_version']??0)===2,'STATE_FORMAT');check(($s['active_version']??'')===$q['version']&&in_array($s['active_managed_tree_sha256']??'',[ $q['before'],$q['after']],true),'STATE_ORIGIN');foreach(['staged','pending_verification','activation_journal','db_forward_fence'] as $k)check(array_key_exists($k,$s)&&$s[$k]===null,'OPEN_OPERATION_'.$k);check(is_array($s['history']??null),'HISTORY_INVALID');}
// A single private, immutable preimage is sufficient for this one-time repair.
// No product lifecycle state or database writes are made before it exists.
function repairDatabasePreflight(\Sokna\PublicEdge\Core\Config $cfg,array $q):PDO{
 $db=\Sokna\PublicEdge\Core\Database::connect($cfg);
 check((int)$db->query("SELECT GET_LOCK('sokna_v3_public_migrations',0)")->fetchColumn()===1,'MIGRATION_BUSY');
 try{$ledger=$db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);check($ledger===$q['ledger'],'LEDGER_MISMATCH');return $db;}
 catch(Throwable $e){try{$db->query("SELECT RELEASE_LOCK('sokna_v3_public_migrations')");}catch(Throwable){}throw $e;}
}
function repairState(string $before,array $q,string $at):string{
 $s=json_decode($before,true,64,JSON_THROW_ON_ERROR);safeState($s,$q);check($s['active_managed_tree_sha256']===$q['before'],'BACKUP_ORIGIN');
 $s['active_managed_tree_sha256']=$q['after'];$oldLkg=$s['lkg_recovery_id']??'';$s['lkg_recovery_id']='';
 $s['history'][]=['at'=>$at,'action'=>'manual_owner_repair','version'=>$q['version'],'actor_hint'=>'authenticated-emergency-operator','result'=>'success','prior_tree'=>$q['before'],'managed_tree_sha256'=>$q['after'],'prior_lkg_recovery_id'=>$oldLkg,'backup_id'=>'cp107-owner-original'];
 return json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
function repairBackup(string $path,array $q):array{
 check(is_file($path)&&!is_link($path),'BACKUP_MISSING');$raw=file_get_contents($path);$prefix="<?php http_response_code(404); exit; ?>\n";
 check(is_string($raw)&&str_starts_with($raw,$prefix),'BACKUP_FORMAT');$x=json_decode(base64_decode(trim(substr($raw,strlen($prefix))),true),true,64,JSON_THROW_ON_ERROR);
 check(($x['format']??'')==='sokna-owner-repair-cp107'&&($x['plan_sha']??'')===hash('sha256',json_encode($q,JSON_THROW_ON_ERROR)),'BACKUP_PLAN');
 check(is_array($x['files']??null)&&array_keys($x['files'])===array_keys($q['patch']),'BACKUP_FILES');
 foreach($q['patch'] as $n=>$v){$b=base64_decode($x['files'][$n],true);check(is_string($b)&&hash('sha256',$b)===$v['old'],'BACKUP_PIN');}
 $before=base64_decode($x['state_b64'],true);check(is_string($before)&&is_string($x['at']??null),'BACKUP_STATE');
 $next=repairState($before,$q,$x['at']);check(hash('sha256',$next)===($x['next_sha']??''),'BACKUP_NEXT');return ['before'=>$before,'next'=>$next];
}
function repairInspect(string $root,string $storage,object $r,array $q):array{
 $state=$storage.'/routine-update-v2/state.json';$before=file_get_contents($state);check(is_string($before),'STATE_READ');$s=json_decode($before,true,64,JSON_THROW_ON_ERROR);safeState($s,$q);
 check(!file_exists($r->maintenanceLockPath()),'MAINTENANCE_ACTIVE');
 $dir=$storage.'/recovery/cp107-owner-original';$path=$dir.'/backup.php';check(!is_link($dir)&&!is_link($path),'BACKUP_PATH');
 $backup=is_file($path)?repairBackup($path,$q):null;
 $map=[];foreach($r->managedInventory()['files'] as $row)$map[$row['path']]=$row['sha256'];
 $allOld=true;$allNew=true;$targets=[];$scratch=[];
 foreach($q['patch'] as $n=>$v){$f=$root.'/'.$n;$pin=digest($f);check(in_array($pin,[$v['old'],$v['new']],true),'OWNER_PIN_MISMATCH');$allOld=$allOld&&$pin===$v['old'];$allNew=$allNew&&$pin===$v['new'];
  $targets[$n]=base64_decode($v['b64'],true);check(is_string($targets[$n])&&hash('sha256',$targets[$n])===$v['new'],'PATCH_PIN');
  if(isset($map[$n]))$map[$n]=$v['old'];$tmp=scratch($f);scratchCheck($tmp);if(is_file($tmp)){check($backup!==null,'UNOWNED_SCRATCH');$scratch[]=$tmp;unset($map[substr($tmp,strlen($root)+1)]);}
 }
 foreach($q['stable_before'] as $n=>$pin){if(isset($q['patch'][$n]))continue;check(digest($root.'/'.$n)===$pin,'STABLE_PIN_MISMATCH');}
 ksort($map,SORT_STRING);$ctx=hash_init('sha256');foreach($map as $n=>$pin)hash_update($ctx,$n."\0".$pin."\n");check(hash_final($ctx)===$q['before'],'ORIGINAL_TREE_DRIFT');
 $stmp=scratch($state);scratchCheck($stmp);if(is_file($stmp)){check($backup!==null,'UNOWNED_STATE_SCRATCH');$scratch[]=$stmp;}
 if($backup===null){check($allOld&&$s['active_managed_tree_sha256']===$q['before'],'EXACT_ORIGIN_MISMATCH');return compact('state','before','dir','path','targets','scratch')+['phase'=>'new'];}
 if(hash_equals($backup['next'],$before)){check($allNew&&$r->managedTreeDigest()===$q['after']&&$scratch===[],'COMMITTED_DRIFT');return ['phase'=>'done'];}
 check(hash_equals($backup['before'],$before),'STATE_CHANGED');return compact('state','before','dir','path','targets','scratch')+['phase'=>'resume'];
}
function repairPrepare(string $root,array $q,array $c):void{
 check($c['phase']==='new','NOT_NEW');$at=gmdate('c');$original=[];foreach($q['patch'] as $n=>$v){$b=file_get_contents($root.'/'.$n);check(is_string($b)&&hash('sha256',$b)===$v['old'],'ORIGINAL_PIN');$original[$n]=base64_encode($b);}
 $before=$c['before'];$next=repairState($before,$q,$at);$x=['format'=>'sokna-owner-repair-cp107','plan_sha'=>hash('sha256',json_encode($q,JSON_THROW_ON_ERROR)),'state_b64'=>base64_encode($before),'at'=>$at,'next_sha'=>hash('sha256',$next),'files'=>$original];
 if(!is_dir($c['dir']))check(mkdir($c['dir'],0700,true),'BACKUP_DIR');check(!is_link($c['dir'])&&!file_exists($c['path']),'BACKUP_ALREADY_EXISTS');
 atomic($c['path'],"<?php http_response_code(404); exit; ?>\n".base64_encode(json_encode($x,JSON_THROW_ON_ERROR))."\n",0600);repairBackup($c['path'],$q);
}
function repairApply(string $root,string $storage,object $r,array $q):void{
 $c=repairInspect($root,$storage,$r,$q);if($c['phase']==='done')return;
 foreach($q['patch'] as $n=>$v)fresh($root.'/'.$n);
 if($c['phase']==='new'){repairPrepare($root,$q,$c);$c=repairInspect($root,$storage,$r,$q);}
 check($c['phase']==='resume','BACKUP_REQUIRED');$b=repairBackup($c['path'],$q);
 foreach($c['scratch'] as $tmp){scratchCheck($tmp);check(unlink($tmp),'SCRATCH_REMOVE_FAILED');}
 foreach($q['patch'] as $n=>$v){$f=$root.'/'.$n;$pin=digest($f);check(in_array($pin,[$v['old'],$v['new']],true),'OWNER_PIN_MISMATCH');if($pin===$v['old'])atomic($f,$c['targets'][$n],0644);fresh($f);}
 check($r->managedTreeDigest()===$q['after'],'REPAIRED_TREE');foreach($q['stable_after'] as $n=>$pin)check(digest($root.'/'.$n)===$pin,'REPAIRED_STABLE_PIN');
 check(file_get_contents($c['state'])===$b['before']&&!file_exists($r->maintenanceLockPath()),'STATE_CHANGED');
 atomic($c['state'],$b['next'],0600);check(repairInspect($root,$storage,$r,$q)['phase']==='done','FINAL_READBACK');
}
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
  $db=repairDatabasePreflight($cfg,$q);
  $c=repairInspect($root,$storage,$r,$q);
  if($c['phase']==='done'){$done=true;$message='اصلاح قبلاً ثبت شده است. تغییر تازه‌ای انجام نشد.';}
  elseif(!$post){$ready=true;$message=$c['phase']==='resume'?'عملیات قبلی نیمه‌تمام است. با همان تأیید، ادامه داده می‌شود.':'پیش‌بررسی موفق: نسخه، فایل‌ها، وضعیت و ۱۱ مهاجرت مطابق مرجع هستند.';}
  else{repairApply($root,$storage,$r,$q);$done=true;$message='اصلاح یک‌باره انجام شد. هیچ مهاجرت یا تغییر داده انجام نشد. ابزار را حذف کنید و به Emergency برگردید.';}

 }finally{if(isset($db))try{$db->query("SELECT RELEASE_LOCK('sokna_v3_public_migrations')");}catch(Throwable){}flock($lock,LOCK_UN);fclose($lock);}
}catch(Throwable $ex){http_response_code(409);$message='توقف ایمن: '.(get_class($ex)===RuntimeException::class?$ex->getMessage():'PREFLIGHT_OR_REPAIR_FAILED');$ready=false;}
?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>اصلاح یک‌باره Recovery</title><style>body{font:16px Tahoma,Arial,sans-serif;max-width:680px;margin:40px auto;padding:20px;line-height:1.8}button{padding:12px}p{overflow-wrap:anywhere}</style><h1>اصلاح یک‌باره Recovery کافه</h1><p><?=h($message)?></p><?php if($ready):?><p>قبل از تأیید، از فایل‌ها و دیتابیس بکاپ معتبر بگیرید. لوکال خاموش باشد و هیچ عملیات Recovery یا Emergency دیگری در حال اجرا نباشد. این پیش‌بررسی نمی‌تواند پردازش قدیمی ازقبل‌درحال‌اجرا را به‌طور مستقل تشخیص دهد.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="confirm" value="REPAIR"><label><input type="checkbox" name="quiet" value="yes" required>پشتیبان دارم و هیچ عملیات دیگری در حال اجرا نیست.</label><br><button>اصلاح یک‌باره</button></form><?php endif;?><p><a href="emergency.php">بازگشت به Emergency</a></p></html>
