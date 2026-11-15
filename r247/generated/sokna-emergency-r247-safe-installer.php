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
const R247_OLD_VERSION='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat';
const R247_OLD_TREE='be2ebe23091472480beadd6a06cad621b43da365d3677f54fadaab6ba56242b6';
const R247_OLD_GUI_SHA='98076588e1eaf7d385ae64e1fe786fc579477e8e6bda499d96ebeea698bdff56';
const R247_OLD_ROUTINE_SHA='3d83ba9e34090e860ffa254c535cbbc95109c9f7f2828884d219aac5ba835492';
const R247_OLD_BRIDGE_SHA='123b6108fc95900f89f8bf9b1a6505bbdc820775d1ae8d80798033d16be10efe';
const R247_OLD_OWNER_SHA='2e041f0fb99c59c47954136ae9478d50f9045d95ec5f322df80e543daec173d6';
const R247_OLD_META_SHA='9e910cc41cc5316744098fb7442bd0d70f6c424c5fda260357e97163f707bb93';
const R247_OLD_OWNER_VERSION='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa';
const R247_OLD_ENTRY_BLOB='85b028d832788a06d5d6ba9f8255b8094f50faee';
const R247_OLD_ACCESS_BLOB='54e2ed8fcd8100863ca3686a22789d5a1b46766e';
const R247_NEW_ACCESS_SHA='65f5c6fc92f17a5baba3965a69c7f8f2cdabfc5f0d2467240889cc48c8d5a9bb';
const R247_NEW_ENTRY_SHA='b41a7deae19becca160586401a0c4bc33150c43f811d838c5973249bbeb2c6ce';
const R247_ENTRY_Z='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';
const R247_ACCESS_Z='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';
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
