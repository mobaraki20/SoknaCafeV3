<?php
/**
 * Sokna Public R247 one-time stable Emergency owner installer.
 * Exact R171 baseline only. No database, config, Routine state or trust-file writes.
 * Auth: the existing Emergency password; no hard-coded default credentials.
 * Offline candidate contents are pinned to audited R171/35ES archive bytes.
 */
declare(strict_types=1);
if(function_exists('header_remove'))header_remove('X-Powered-By');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
const R247_OLD_VERSION='__R171_VERSION__';
const R247_OLD_TREE='__R171_TREE_SHA__';
const R247_OLD_GUI_SHA='__R171_GUI_SHA__';
const R247_OLD_ROUTINE_SHA='__R171_ROUTINE_SHA__';
const R247_OLD_BRIDGE_SHA='__R171_BRIDGE_SHA__';
const R247_OLD_OWNER_SHA='__R171_OWNER_SHA__';
const R247_OLD_META_SHA='__R171_META_SHA__';
const R247_OLD_OWNER_VERSION='__R171_OWNER_RELEASE__';
const R247_OLD_ENTRY_BLOB='__LEGACY_EMERGENCY_GIT_SHA1__';
const R247_OLD_ACCESS_BLOB='__LEGACY_ACCESS_GIT_SHA1__';
const R247_NEW_ACCESS_SHA='__MODERN_ACCESS_SHA__';
const R247_NEW_ENTRY_SHA='__R247_GUI_SHA__';
const R247_ENTRY_Z='__EMERGENCY_BYTES_GZ_BASE64__';
const R247_ACCESS_Z='__ACCESS_BYTES_GZ_BASE64__';
const R247_KEY='r247_sokna_emergency';
function r247_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function r247_https():bool{
 $v=strtolower((string)($_SERVER['HTTPS']??''));
 return ($v!==''&&$v!=='off'&&$v!=='0')||(int)($_SERVER['SERVER_PORT']??0)===443;
}
function r247_blob(string $v):string{return sha1('blob '.strlen($v)."\0".$v);}
function r247_sha(string $file):string{if(!is_file($file)||!is_readable($file))return '';return (string)hash_file('sha256',$file);}
function r247_json(string $path):array{if(!is_file($path))return [];try{$v=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}}
function r247_frozen(string $encoded,string $sha):string{
 $z=base64_decode($encoded,true);$bytes=is_string($z)?gzdecode($z):false;
 if(!is_string($bytes)||!hash_equals($sha,hash('sha256',$bytes)))throw new RuntimeException('candidate_integrity_error');
 return $bytes;
}
function r247_state_dir(string $storage):string{return rtrim($storage,'/\\').'/emergency/r247-repair';}
function r247_mkdir(string $dir):void{if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('storage_not_writable');}
function r247_write(string $path,string $contents):void{
 $written=@file_put_contents($path,$contents,LOCK_EX);
 if($written!==strlen($contents)){@unlink($path);throw new RuntimeException('write_failed');}
 @chmod($path,0600);
}
function r247_backup_file(string $path,string $source):void{
 // Direct access via PHP returns 404, even if webserver ignores .htaccess.
 r247_write($path,"<?php http_response_code(404);exit; ?>\n".base64_encode($source)."\n");
}
function r247_backup_read(string $path):string{
 $raw=@file_get_contents($path);
 if(!is_string($raw)||!str_starts_with($raw,"<?php http_response_code(404);exit; ?>\n"))throw new RuntimeException('backup_invalid');
 $p=base64_decode(trim(substr($raw,strlen("<?php http_response_code(404);exit; ?>\n"))),true);
 if(!is_string($p)||!str_starts_with($p,'<?php'))throw new RuntimeException('backup_invalid');
 return $p;
}
function r247_atomic(string $target,string $bytes):void{
 $tmp=dirname($target).'/.sokna-r247-'.bin2hex(random_bytes(12)).'.php';
 r247_write($tmp,$bytes);@chmod($tmp,0644);
 if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('atomic_replace_failed');}
 if(function_exists('opcache_invalidate'))@opcache_invalidate($target,true);
}
function r247_gate(string $root,string $storage):array{
 $checks=[];
 $expect=function(string $key,bool $ok)use(&$checks):void{$checks[$key]=$ok;};
 $expect('installed_R171',trim((string)@file_get_contents($root.'/VERSION.txt'))===R247_OLD_VERSION);
 $expect('protected_GUI_byte_exact',r247_sha($root.'/emergency-next.php')===R247_OLD_GUI_SHA);
 $expect('routine_engine_byte_exact',r247_sha($root.'/src/Update/RoutinePublicUpdateService.php')===R247_OLD_ROUTINE_SHA);
 $expect('emergency_bridge_byte_exact',r247_sha($root.'/src/Update/IndependentEmergencyRoutineBridge.php')===R247_OLD_BRIDGE_SHA);
 $expect('owner_candidate_byte_exact',r247_sha($root.'/src/Update/EmergencyOwnerCandidate.php')===R247_OLD_OWNER_SHA);
 $expect('old_owner_metadata_byte_exact',r247_sha($root.'/src/Update/emergency-owner-v1.json')===R247_OLD_META_SHA);
 $meta=r247_json($root.'/src/Update/emergency-owner-v1.json');
 $expect('historical_owner_release_exact',($meta['release_version']??null)===R247_OLD_OWNER_VERSION && ($meta['sha256']??null)===R247_OLD_OWNER_SHA);
 $entry=@file_get_contents($root.'/emergency.php');
 $newEntry=is_string($entry)&&hash_equals(R247_NEW_ENTRY_SHA,hash('sha256',$entry));
 $oldEntry=is_string($entry)&&hash_equals(R247_OLD_ENTRY_BLOB,r247_blob($entry));
 $expect('known_protected_entrypoint',$newEntry||$oldEntry);
 $access=@file_get_contents($root.'/src/Emergency/EmergencyAccessService.php');
 $newAccess=is_string($access)&&hash_equals(R247_NEW_ACCESS_SHA,hash('sha256',$access));
 $oldAccess=is_string($access)&&hash_equals(R247_OLD_ACCESS_BLOB,r247_blob($access));
 $expect('known_protected_access_engine',$newAccess||$oldAccess);
 $expect('file_permissions',is_writable($root)&&is_writable($root.'/emergency.php')&&is_writable($root.'/src/Emergency/EmergencyAccessService.php')&&is_writable($storage));
 $routineOk=false;
 try{
   require_once $root.'/src/Update/RoutinePublicUpdateService.php';
   $routine=new \Sokna\PublicEdge\Update\RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json');
   $state=$routine->snapshot();
   $routineOk=($state['current_version']??null)===R247_OLD_VERSION && ($state['active_version']??null)===R247_OLD_VERSION &&
    ($state['managed_tree_matches_expected']??null)===true &&
    ($state['managed_tree_sha256']??null)===R247_OLD_TREE &&
    ($state['expected_managed_tree_sha256']??null)===R247_OLD_TREE &&
    !is_array($state['staged']??null) && !is_array($state['pending_verification']??null) &&
    !is_array($state['activation_journal']??null) && (($state['emergency_state_reconciliation_pending']??false)===false);
 }catch(Throwable){}
 $expect('routine_state_and_tree_fully_reconciled',$routineOk);
 $emergencyState=r247_json($storage.'/emergency/update/state.json');
 $staged=$emergencyState['staged']??$emergencyState['staged_version']??null;
 $expect('no_legacy_emergency_staging',!is_array($staged) && trim((string)$staged)==='');
 $expect('no_symlinks',!is_link($root.'/emergency.php')&&!is_link($root.'/src/Emergency/EmergencyAccessService.php'));
 $expect('no_existing_install_marker_conflict',!(is_file(r247_state_dir($storage).'/latest.json')&&$oldEntry));
 return ['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'installed'=>$newEntry&&$newAccess,'installable'=>$oldEntry&&($oldAccess||$newAccess),'legacy_entry'=>$oldEntry,'needs_access_upgrade'=>$oldAccess];
}
function r247_lock(string $storage){
 $path=rtrim($storage,'/\\').'/public-update-lifecycle.lock';
 $fh=@fopen($path,'c+');if(!is_resource($fh))throw new RuntimeException('lock_unavailable');
 if(!@flock($fh,LOCK_EX|LOCK_NB)){fclose($fh);throw new RuntimeException('update_busy');}
 return $fh;
}
function r247_install(string $root,string $storage):string{
 $lock=r247_lock($storage);
 try{
  $gate=r247_gate($root,$storage);
  if(!$gate['ok']||!$gate['installable']||$gate['installed'])throw new RuntimeException('preflight_failed_no_changes');
  $entry=r247_frozen(R247_ENTRY_Z,R247_NEW_ENTRY_SHA);
  $access=r247_frozen(R247_ACCESS_Z,R247_NEW_ACCESS_SHA);
  $dir=r247_state_dir($storage);r247_mkdir($dir);
  $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));$dest=$dir.'/'.$id;
  r247_mkdir($dest);
  r247_write($dest.'/.htaccess',"Require all denied\nDeny from all\n");
  r247_backup_file($dest.'/emergency-original.php',(string)file_get_contents($root.'/emergency.php'));
  r247_backup_file($dest.'/access-original.php',(string)file_get_contents($root.'/src/Emergency/EmergencyAccessService.php'));
  $oldE=r247_backup_read($dest.'/emergency-original.php');$oldA=r247_backup_read($dest.'/access-original.php');
  if(r247_blob($oldE)!==R247_OLD_ENTRY_BLOB || !(r247_blob($oldA)===R247_OLD_ACCESS_BLOB||hash('sha256',$oldA)===R247_NEW_ACCESS_SHA))throw new RuntimeException('backup_identity_failed');
  $record=['format'=>'sokna-r247-stable-emergency-repair-v1','id'=>$id,'at'=>gmdate('c'),
   'old_emergency_gitsha1'=>r247_blob($oldE),'old_access_gitsha1'=>r247_blob($oldA),
   'new_emergency_sha256'=>R247_NEW_ENTRY_SHA,'new_access_sha256'=>R247_NEW_ACCESS_SHA,
   'r171_version'=>R247_OLD_VERSION,'r171_tree'=>R247_OLD_TREE,'status'=>'installed',
   'scope'=>'protected_emergency_php_and_access_only'];
  $markerTmp=$dir.'/latest.json.tmp-'.bin2hex(random_bytes(6));
  r247_write($markerTmp,json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $changedA=false;$changedE=false;
  try{
    if($gate['needs_access_upgrade']){r247_atomic($root.'/src/Emergency/EmergencyAccessService.php',$access);$changedA=true;}
    if(r247_sha($root.'/src/Emergency/EmergencyAccessService.php')!==R247_NEW_ACCESS_SHA)throw new RuntimeException('access_swap_failed');
    r247_atomic($root.'/emergency.php',$entry);$changedE=true;
    if(r247_sha($root.'/emergency.php')!==R247_NEW_ENTRY_SHA)throw new RuntimeException('emergency_swap_failed');
    // The immutable app/Routine tree stays untouched: only the two protected PHP files changed.
    $after=r247_gate($root,$storage);
    if(!$after['ok']||!$after['installed'])throw new RuntimeException('post_install_readback_failed');
    if(!@rename($markerTmp,$dir.'/latest.json'))throw new RuntimeException('marker_write_failed');
  }catch(Throwable $e){
    @unlink($markerTmp);
    if($changedE)r247_atomic($root.'/emergency.php',$oldE);
    if($changedA)r247_atomic($root.'/src/Emergency/EmergencyAccessService.php',$oldA);
    throw $e;
  }
  return $id;
 }finally{@flock($lock,LOCK_UN);fclose($lock);}
}
function r247_restore(string $root,string $storage):void{
 $lock=r247_lock($storage);
 try{
  $gate=r247_gate($root,$storage);
  if(!$gate['ok']||!$gate['installed'])throw new RuntimeException('restore_gate_blocked');
  $dir=r247_state_dir($storage);$meta=r247_json($dir.'/latest.json');
  $id=(string)($meta['id']??'');if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/D',$id))throw new RuntimeException('missing_backup_marker');
  $backup=$dir.'/'.$id;
  $oldE=r247_backup_read($backup.'/emergency-original.php');$oldA=r247_backup_read($backup.'/access-original.php');
  if(r247_blob($oldE)!==R247_OLD_ENTRY_BLOB||!(r247_blob($oldA)===R247_OLD_ACCESS_BLOB||hash('sha256',$oldA)===R247_NEW_ACCESS_SHA))throw new RuntimeException('restore_integrity_failed');
  r247_atomic($root.'/emergency.php',$oldE);
  r247_atomic($root.'/src/Emergency/EmergencyAccessService.php',$oldA);
  $meta['status']='restored';$meta['restored_at']=gmdate('c');
  r247_write($dir.'/latest.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 }finally{@flock($lock,LOCK_UN);fclose($lock);}
}
function r247_throttle(string $storage,string $ip,bool $failure=false):bool{
 $dir=r247_state_dir($storage);r247_mkdir($dir);
 $path=$dir.'/login-limit.json';$fh=@fopen($path,'c+');if(!is_resource($fh))return false;
 try{
  if(!@flock($fh,LOCK_EX))return false;
  $raw=stream_get_contents($fh);$db=is_string($raw)?json_decode($raw,true):[];if(!is_array($db))$db=[];
  $id=hash('sha256',$ip);$now=time();$entry=is_array($db[$id]??null)?$db[$id]:[];
  $trials=array_values(array_filter((array)($entry['failures']??[]),static fn($x):bool=>is_int($x)&&$x>$now-900));
  $until=(int)($entry['lock_until']??0);
  if($failure){$trials[]=$now;if(count($trials)>=5)$until=$now+900;}
  $db=[$id=>['failures'=>$trials,'lock_until'=>$until]]; // Avoid unbounded state.
  ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode($db,JSON_THROW_ON_ERROR));fflush($fh);@chmod($path,0600);
  return $until<=$now;
 }finally{@flock($fh,LOCK_UN);fclose($fh);}
}
$root=dirname(__DIR__);$error='';$info='';$gate=null;$authenticated=false;
try{
 if(!r247_https())throw new RuntimeException('HTTPS required. No installation performed.');
 if(!is_file($root.'/config.php')&&!getenv('SOKNA_PUBLIC_CONFIG'))throw new RuntimeException('Public config missing.');
 require_once $root.'/src/Core/Config.php';
 require_once $root.'/src/Emergency/PublicUpdateException.php';
 require_once $root.'/src/Emergency/EmergencyAccessService.php';
 $cfgPath=(string)(getenv('SOKNA_PUBLIC_CONFIG')?:$root.'/config.php');$cfgRaw=require $cfgPath;
 if(!is_array($cfgRaw))throw new RuntimeException('Invalid Public config.');
 $cfg=\Sokna\PublicEdge\Core\Config::fromArray($cfgRaw);$storage=trim((string)$cfg->string('app.storage_dir'));
 if($storage==='')$storage=$root.'/storage';
 $access=new \Sokna\PublicEdge\Emergency\EmergencyAccessService($storage);
 if(!$access->configured())throw new RuntimeException('Emergency password not provisioned.');
 session_name(R247_KEY);session_set_cookie_params(['lifetime'=>0,'path'=>rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/')),'/').'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
 ini_set('session.use_only_cookies','1');ini_set('session.use_strict_mode','1');session_start();
 if(!is_string($_SESSION['csrf']??null))$_SESSION['csrf']=bin2hex(random_bytes(24));
 $now=time();$authenticated=($_SESSION['authorized']??false)===true && ($now-(int)($_SESSION['last_auth']??0))<600;
 $action=(string)($_POST['action']??'');
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  if(!hash_equals((string)$_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('CSRF failed.');
  if($action==='login'){
   $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
   if(!r247_throttle($storage,$ip))throw new RuntimeException('Login temporarily locked.');
   $code=(string)($_POST['emergency_password']??'');
   if($code!==''&&strlen($code)<=512&&$access->verify($code)){
      session_regenerate_id(true);$_SESSION['authorized']=true;$_SESSION['last_auth']=$now;
      $_SESSION['csrf']=bin2hex(random_bytes(24));$authenticated=true;$info='Emergency authentication successful.';
   }else{r247_throttle($storage,$ip,true);throw new RuntimeException('Invalid Emergency password.');}
  }elseif($authenticated){
   if($action==='install'&&(string)($_POST['confirmation']??'')==='INSTALL-R247'){
     $id=r247_install($root,$storage);$info='Installed protected Emergency owner. Backup ID: '.$id;
   }elseif($action==='restore'&&(string)($_POST['confirmation']??'')==='RESTORE-R247'){
     r247_restore($root,$storage);$info='Original protected owner successfully restored.';
   }elseif($action==='cleanup'&&(string)($_POST['confirmation']??'')==='REMOVE-INSTALLER'){
     if(!@unlink(__FILE__))throw new RuntimeException('Could not delete installer; remove this file via DirectAdmin.');
     $info='Installer removed from public path.';
   }else throw new RuntimeException('Confirmation missing.');
  }
 }
 if($authenticated){$_SESSION['last_auth']=$now;$gate=r247_gate($root,$storage);}
}catch(Throwable $e){$error=$e->getMessage();}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سکنا | ترمیم مالک Emergency R247</title><style>
*{box-sizing:border-box}body{font-family:Tahoma,system-ui,sans-serif;color:#e9edef;background:#0e1720;max-width:900px;margin:auto;padding:28px;line-height:1.9}h1{font-size:24px}section{background:#17222e;border:1px solid #425365;border-radius:14px;padding:18px;margin:18px 0}table{border-collapse:collapse;width:100%}td,th{padding:7px;border-bottom:1px solid #3c4b5b;text-align:right;font-size:13px}input,button{padding:10px;font:inherit;border-radius:8px}input{background:#1c2934;color:white;border:1px solid #8195a3}button{border:0;background:#1c74b4;color:white;cursor:pointer;margin:8px 0}.bad{color:#ffb8a9}.good{color:#9de4a8}.info{color:#a9d3ef}.warn{color:#ffdc98}small{color:#a9b5c6}code{direction:ltr;display:inline-block;text-align:left}form{margin-top:12px}
</style></head><body><h1>ترمیم کنترل‌شده Emergency پابلیک سکنا — R247</h1><p>مخصوص مبدأ دقیق R171، بدون تغییر پایگاه‌داده یا سرویس لوکال. این ابزار فقط دو فایل محافظت‌شده Emergency را با پشتیبان‌گیری تغییر می‌دهد و خودِ ارتقای R246 نیست.</p>
<?php if($error!==''):?><section class="bad"><strong>اجرا متوقف شد:</strong> <?=r247_e($error)?></section><?php endif;?>
<?php if($info!==''):?><section class="good"><?=r247_e($info)?></section><?php endif;?>
<?php if(!$authenticated):?><section><h2>ورود با رمز فعلی Emergency</h2><form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?=r247_e($_SESSION['csrf']??'')?>"><input type="password" name="emergency_password" autocomplete="current-password" required maxlength="512"><button>ورود و بررسی بدون تغییر</button></form></section>
<?php else:?>
<section><h2>آزمون قبل از نصب</h2><?php if(is_array($gate)):?><p class="<?=($gate['ok']?'good':'bad')?>"><strong><?=($gate['ok']?'تمام شروط پایه تأیید شدند':'یکی از شروط ایمنی برقرار نیست؛ عملیات غیرفعال است')?></strong></p><table><?php foreach($gate['checks'] as $k=>$v):?><tr><td><code><?=r247_e($k)?></code></td><td class="<?=($v?'good':'bad')?>"><?=($v?'PASS':'BLOCKED')?></td></tr><?php endforeach;?></table><?php endif;?></section>
<?php if(is_array($gate)&&$gate['ok']&&$gate['installable']&&!$gate['installed']):?><section><h2>نصب ایمن رابط گرافیکی</h2><p class="warn">این کار فقط فایل ورودی Emergency و سرویس دسترسی آن را به‌صورت اتمیک جایگزین می‌کند. پس از آن مسیر Stage و Activate نسخهٔ پابلیک را جداگانه بررسی کنید.</p><form method="post"><input type="hidden" name="action" value="install"><input type="hidden" name="csrf" value="<?=r247_e($_SESSION['csrf']??'')?>"><input type="hidden" name="confirmation" value="INSTALL-R247"><button>ایجاد پشتیبان و نصب Emergency جدید</button></form></section>
<?php elseif(is_array($gate)&&$gate['installed']):?><section><h2 class="good">رابط گرافیکی Emergency نصب شده است</h2><p>اکنون صفحهٔ <a class="info" href="emergency.php">Emergency</a> را در یک تب دیگر باز و وضعیت Routine را بررسی کنید؛ بدون فعال‌سازی شتاب‌زده.</p><form method="post"><input type="hidden" name="action" value="restore"><input type="hidden" name="csrf" value="<?=r247_e($_SESSION['csrf']??'')?>"><input type="hidden" name="confirmation" value="RESTORE-R247"><button>بازگرداندن دو فایل قدیمی (فقط قبل از ارتقای R246)</button></form></section><?php endif;?>
<section><h2>پاک‌سازی نصب‌کننده</h2><p>بعد از تأیید رابط و انتقال به روال عادی، فایل نصب‌کننده را حذف کنید؛ باقی‌گذاشتن ابزار تعمیر در مسیر عمومی توصیه نمی‌شود.</p><form method="post"><input type="hidden" name="action" value="cleanup"><input type="hidden" name="csrf" value="<?=r247_e($_SESSION['csrf']??'')?>"><input type="hidden" name="confirmation" value="REMOVE-INSTALLER"><button>حذف نصب‌کننده از هاست</button></form></section>
<?php endif;?><p><small>R247 / fail-closed / provenance pinned / no database mutation / no trust overwrite</small></p></body></html>
