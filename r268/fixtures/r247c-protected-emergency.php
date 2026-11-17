<?php
declare(strict_types=1);

if(function_exists('header_remove'))header_remove('X-Powered-By');

$root=__DIR__;
$reqPath=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
if(is_string($reqPath)&&$reqPath!==''&&basename($reqPath)!=='emergency.php'){
    http_response_code(404);exit;
}
$configPath=trim((string)(getenv('SOKNA_PUBLIC_CONFIG')?:($root.'/config.php')));
require_once $root.'/src/Core/Config.php';
require_once $root.'/src/Core/Database.php';
require_once $root.'/src/Emergency/PublicUpdateException.php';
require_once $root.'/src/Emergency/EmergencyAccessService.php';
require_once $root.'/src/Emergency/PairingSecretStore.php';
require_once $root.'/src/Emergency/PublicTakeoverService.php';
// Load the managed side-by-side owner only after package activation.
// The historical owner remains intact and is never overwritten by a Public ZIP.


use Sokna\PublicEdge\Core\Config;
use Sokna\PublicEdge\Core\Database;
use Sokna\PublicEdge\Emergency\EmergencyAccessService;
use Sokna\PublicEdge\Emergency\PairingSecretStore;
use Sokna\PublicEdge\Emergency\PublicTakeoverService;
use Sokna\PublicEdge\Emergency\PublicUpdateService;
use Sokna\PublicEdge\Emergency\PublicUpdateException;

const SOKNA_EMERGENCY_IDLE_TIMEOUT = 900;
const SOKNA_EMERGENCY_ABSOLUTE_TIMEOUT = 14400;

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
$cspNonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$cspNonce}'; img-src 'self' data:; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

function g39_e(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
/** Reuse the exact Sokna brand asset shipped in this Public release, with a local fallback. */
function g39_brand_mark(): string {
    static $mark=null;
    if($mark!==null)return $mark;
    $path=__DIR__.'/assets/brand/Sokna-AppIcon-256.png';
    $bytes=is_file($path)?@file_get_contents($path):false;
    if(is_string($bytes)&&str_starts_with($bytes,"\x89PNG\r\n\x1a\n")&&strlen($bytes)<100000){
        $mark='<img src="data:image/png;base64,'.base64_encode($bytes).'" width="48" height="48" alt="لوگوی سکنا">';
    }else{$mark='<span class="brand-fallback" aria-label="سکنا">سکنا</span>';}
    return $mark;
}

function g39_short_version(string $version): string{if(preg_match('/workspace\.(\d+[a-z]+)/i',$version,$m))return 'Public '.strtoupper($m[1]);return $version!==''?$version:'نامشخص';}
function g39_iran_time(string $utc): string{try{if(trim($utc)==='')return '—';return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y/m/d H:i');}catch(\Throwable){return '—';}}
function g39_json(array $v,int $status=200): never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function g39_https(): bool{$value=strtolower(trim((string)($_SERVER['HTTPS']??'')));return ($value!==''&&$value!=='off'&&$value!=='0')||(int)($_SERVER['SERVER_PORT']??0)===443;}
function g39_local_host(): bool{$remote=trim((string)($_SERVER['REMOTE_ADDR']??''));$host=strtolower(trim((string)($_SERVER['HTTP_HOST']??'')));$host=preg_replace('/:\d+$/','',$host)??$host;return in_array($remote,['127.0.0.1','::1'],true)&&in_array($host,['localhost','127.0.0.1','[::1]','::1'],true);}
function g39_client_key(): string{return trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));}
function g39_session_path(): string{$p=str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/emergency.php')));$p=rtrim($p,'/');return $p===''?'/':$p.'/';}
function g39_start_session(): void
{
    if(session_status()===PHP_SESSION_ACTIVE)return;
    session_name('sokna_public_emergency');
    session_set_cookie_params(['lifetime'=>0,'path'=>g39_session_path(),'secure'=>g39_https(),'httponly'=>true,'samesite'=>'Strict']);
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    session_start();
    if(!isset($_SESSION['csrf'])||!is_string($_SESSION['csrf'])||strlen($_SESSION['csrf'])<32)$_SESSION['csrf']=bin2hex(random_bytes(24));
}
function g39_csrf(): string{return (string)($_SESSION['csrf']??'');}
function g39_require_csrf(): void
{
    $got=(string)($_POST['csrf']??'');
    if($got===''||!hash_equals(g39_csrf(),$got))throw new PublicUpdateException('csrf_failed','درخواست امنیتی معتبر نیست. صفحه را دوباره باز کنید.',403);
}
function g39_authenticated(): bool{return ($_SESSION['emergency_authenticated']??false)===true;}
function g39_clear_auth(): void
{
    unset($_SESSION['emergency_authenticated'],$_SESSION['emergency_login_at'],$_SESSION['emergency_last_seen']);
    $_SESSION['csrf']=bin2hex(random_bytes(24));
}
function g39_action_label(string $action): string
{
    return [
        'access_rotated'=>'تغییر رمز اضطراری','login_success'=>'ورود موفق','login_failed'=>'ورود ناموفق','login_locked'=>'قفل موقت ورود',
        'logout'=>'خروج','session_timeout'=>'پایان جلسه','update_stage'=>'اعتبارسنجی بسته','update_activate'=>'فعال‌سازی نسخه','update_commit'=>'تأیید نهایی نسخه',
        'update_repair'=>'ترمیم نسخه','update_rollback'=>'بازگشت به نسخه سالم','kill_switch'=>'توقف دسترسی راه‌دور',
        'takeover_offer'=>'ساخت کد انتقال','takeover_completed'=>'تکمیل انتقال','action_failed'=>'عملیات ناموفق',
    ][$action]??$action;
}
function g39_error_message(string $code,string $fallback=''): string
{
    return [
        'nothing_staged'=>'هیچ بسته‌ای برای فعال‌سازی آماده نشده است.',
        'same_version_requires_repair'=>'این نسخه با نسخه فعال یکسان است؛ از «ترمیم همین نسخه» استفاده کنید.',
        'repair_requires_same_version'=>'برای ترمیم، بسته آماده‌شده باید دقیقاً با نسخه فعال یکسان باشد.',
        'no_recovery_point'=>'هنوز نقطه بازیابی سالمی برای بازگشت وجود ندارد.',
        'upload_missing'=>'ابتدا فایل ZIP به‌روزرسانی را انتخاب کنید.',
        'invalid_zip'=>'فایل انتخاب‌شده ZIP معتبر نیست.',
        'manifest_missing'=>'بسته به‌روزرسانی manifest معتبر ندارد.',
        'manifest_contract'=>'ساختار بسته به‌روزرسانی معتبر نیست.',
        'bad_signature'=>'امضای بسته معتبر نیست.',
        'database_unavailable'=>'اتصال دیتابیس در دسترس نیست.',
        'installation_not_found'=>'نصب موردنظر فعال یا موجود نیست.',
        'takeover_same_identity'=>'شناسه نصب جدید باید با نصب قبلی متفاوت باشد.',
        'invalid_installation'=>'شناسه نصب معتبر نیست.',
        'csrf_failed'=>'درخواست امنیتی معتبر نیست. صفحه را دوباره باز کنید.',
        'confirmation_required'=>'برای این عملیات، تأیید خواسته‌شده را کامل کنید.',
        'https_required'=>'ورود اضطراری فقط روی HTTPS مجاز است.',
    ][$code]??($fallback!==''?$fallback:'عملیات انجام نشد.');
}
function g39_confirm(string $expected): void
{
    if((string)($_POST['confirmation']??'')!==$expected)throw new PublicUpdateException('confirmation_required','Confirmation required.',422);
}
/** This is the only owner selection logic; no additional service or public route. */
function g39_owner_reject(string $storage,string $sha,string $reason): void
{
    $dir=rtrim($storage,'/\\').'/emergency/update';
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return;
    $file=$dir.'/owner-candidate-blocked.json';
    $data=['sha256'=>$sha,'reason'=>$reason,'blocked_at'=>gmdate('c')];
    try{$bytes=json_encode($data,JSON_THROW_ON_ERROR);$tmp=$file.'.tmp-'.bin2hex(random_bytes(4));
        if(@file_put_contents($tmp,$bytes,LOCK_EX)!==false){@chmod($tmp,0600);if(!@rename($tmp,$file))@unlink($tmp);}}
    catch(Throwable){}
}
/**
 * R247 read-only source reconciliation for one proven historical R171 baseline.
 * No metadata or Routine state is rewritten; all writes remain with Routine v2.
 */
function g39_r247_legacy_owner_exception(string $root, string $current, string $rel, string $sha): bool {
    if(!hash_equals('3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat', $current) ||
       !hash_equals('3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa', $rel) ||
       !hash_equals('2e041f0fb99c59c47954136ae9478d50f9045d95ec5f322df80e543daec173d6', $sha))return false;
    $pins=[
        'emergency-next.php'=>'98076588e1eaf7d385ae64e1fe786fc579477e8e6bda499d96ebeea698bdff56',
        'src/Update/RoutinePublicUpdateService.php'=>'3d83ba9e34090e860ffa254c535cbbc95109c9f7f2828884d219aac5ba835492',
        'src/Update/IndependentEmergencyRoutineBridge.php'=>'123b6108fc95900f89f8bf9b1a6505bbdc820775d1ae8d80798033d16be10efe',
        'src/Update/EmergencyOwnerCandidate.php'=>'2e041f0fb99c59c47954136ae9478d50f9045d95ec5f322df80e543daec173d6',
        'src/Update/emergency-owner-v1.json'=>'9e910cc41cc5316744098fb7442bd0d70f6c424c5fda260357e97163f707bb93',
    ];
    foreach($pins as $p=>$h){
        $file=$root.'/'.$p;
        if(!is_file($file)||!is_readable($file)||!hash_equals($h,(string)hash_file('sha256',$file)))return false;
    }
    return true;
}

function g39_load_owner(string $root,string $storage): array
{
    $metaFile=$root.'/src/Update/emergency-owner-v1.json';
    $candidate=$root.'/src/Update/EmergencyOwnerCandidate.php';
    $legacy=$root.'/src/Emergency/PublicUpdateService.php';
    $fallback=static function()use($legacy):array{
        require_once $legacy;
        return ['mode'=>'legacy_read_only'];
    };
    if(!is_file($metaFile)||!is_file($candidate))return $fallback();
    $meta=json_decode((string)@file_get_contents($metaFile),true);
    $sha=strtolower((string)($meta['sha256']??''));
    $rel=(string)($meta['release_version']??'');
    $current=trim((string)@file_get_contents($root.'/VERSION.txt'));
    // Fixed, non-user-controlled candidate location. The manifest hash binds code to the installed release.
    if(!is_array($meta)||($meta['format']??'')!=='sokna-emergency-owner-v1'
        ||!preg_match('/^[a-f0-9]{64}$/D',$sha)||$rel===''||(!hash_equals($current,$rel)&&!g39_r247_legacy_owner_exception($root,$current,$rel,$sha))
        ||!hash_equals($sha,(string)hash_file('sha256',$candidate)))return $fallback();
    $blocked=json_decode((string)@file_get_contents(rtrim($storage,'/\\').'/emergency/update/owner-candidate-blocked.json'),true);
    if(is_array($blocked)&&hash_equals((string)($blocked['sha256']??''),$sha))return $fallback();
    $GLOBALS['sokna_emergency_candidate_healthy']=false;
    register_shutdown_function(static function()use($storage,$sha):void{
        if(($GLOBALS['sokna_emergency_candidate_healthy']??false)===true)return;
        $last=error_get_last();
        if(is_array($last)&&in_array((int)($last['type']??0),[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))
            g39_owner_reject($storage,$sha,'candidate_boot_failure');
    });
    try{require_once $candidate;}
    catch(Throwable $e){
        g39_owner_reject($storage,$sha,'candidate_load_failure');
        // A class may have been declared before the exception; never redeclare it.
        if(class_exists(\Sokna\PublicEdge\Emergency\PublicUpdateService::class,false))throw $e;
        return $fallback();
    }
    if(!class_exists(\Sokna\PublicEdge\Emergency\PublicUpdateService::class,false)){
        g39_owner_reject($storage,$sha,'candidate_missing_class');
        return $fallback();
    }
    return ['mode'=>'candidate','sha256'=>$sha];
}

// Redirect only to the public Emergency endpoint under the actual deployment subdirectory.
// Never use a fixed root-level /emergency.php (404 on /hpublic deployments).
function g39_redirect_self(): never{
    $script=(string)($_SERVER['SCRIPT_NAME']??'/emergency.php');
    $dir=str_replace('\\','/',dirname($script));
    $dir=rtrim($dir,'/');
    $path=($dir===''?'':$dir).'/emergency.php';
    header('Location: '.$path,true,303);exit;
}

try{
    if(!is_file($configPath)){http_response_code(503);echo 'Public Edge هنوز پیکربندی نشده است.';exit;}
    $cfgRaw=require $configPath;
    if(!is_array($cfgRaw))throw new RuntimeException('invalid config');
    $cfg=Config::fromArray($cfgRaw);
    $storage=trim($cfg->string('app.storage_dir'));
    if($storage==='')$storage=$root.'/storage';
    $owner=g39_load_owner($root,$storage);
    $access=new EmergencyAccessService($storage);
    $health=static function()use($cfg):bool{
        try{return (int)Database::connect($cfg)->query('SELECT 1')->fetchColumn()===1;}catch(Throwable){return false;}
    };
    // Existing migration runner's advisory lock stays held through this HTTP request.
    // This is a read-only schema + migration ledger proof, NOT a general-purpose DB write tracker.
    $dbProofConnection=null;
    $dbProof=static function()use($cfg,&$dbProofConnection):array{
        if($dbProofConnection===null)$dbProofConnection=Database::connect($cfg);
        $pdo=$dbProofConnection;
        if((int)$pdo->query("SELECT GET_LOCK('sokna_v3_public_migrations',0)")->fetchColumn()!==1)
            throw new PublicUpdateException('migration_lock_busy','Public migration is active; Emergency update is blocked.',409);
        $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if($db==='')throw new PublicUpdateException('db_identity_unavailable','Database identity is unavailable.',503);
        $sv=$pdo->query('SELECT @@hostname, @@port')->fetch(\PDO::FETCH_NUM);
        if(!is_array($sv)||count($sv)<2)throw new PublicUpdateException('db_identity_unavailable','Database server identity is unavailable.',503);
        // Ledger existence is mandatory. A missing ledger must NEVER be created by a preflight.
        $hasLedger=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();
        if($hasLedger!==1)throw new PublicUpdateException('migration_ledger_missing','Public migration ledger is absent.',503);
        $versions=$pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
        if(!is_array($versions))throw new PublicUpdateException('migration_ledger_unreadable','Migration ledger read failed.',503);
        // Normalized schema fingerprints catch external DDL not recorded in the ledger.
        $tables=$pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(\PDO::FETCH_ASSOC);
        $columns=$pdo->query('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(\PDO::FETCH_ASSOC);
        $indexes=$pdo->query('SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,INDEX_TYPE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(\PDO::FETCH_ASSOC);
        if(!is_array($tables)||!is_array($columns)||!is_array($indexes))throw new PublicUpdateException('db_schema_unreadable','Schema fingerprint failed.',503);
        $identity=substr(hash('sha256',$db.'|'.(string)$sv[0].'|'.(string)$sv[1]),0,32);
        return ['authoritative'=>true,'covers_all_db_mutations'=>false,'forward_only'=>false,
            'db_identity'=>$identity,
            'schema_sha256'=>hash('sha256',json_encode([$versions,$tables,$columns,$indexes],JSON_THROW_ON_ERROR)),
            'migration_head'=>$versions!==[]?(string)end($versions):'none',
            'mutation_epoch'=>count($versions)];
    };
    $updates=($owner['mode']??'')==='candidate'
        ? new PublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',$health,$dbProof)
        : new PublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',$health);
    if(($owner['mode']??'')==='candidate'){
        try{$updates->snapshot();$GLOBALS['sokna_emergency_candidate_healthy']=true;}
        catch(Throwable $e){g39_owner_reject($storage,(string)$owner['sha256'],'candidate_health_failure');throw $e;}
    }
    // The v2 Routine engine already owns all writes, recovery state and the
    // shared OS lock. Reuse its exact production factory, not a second engine.
    // Missing/broken bridge stays READ-ONLY; protected Emergency code is never edited.
    $forwardBridgeAvailable=false;
    if(($owner['mode']??'')==='candidate'){
        try{
            $bridgeFile=$root.'/src/Update/IndependentEmergencyRoutineBridge.php';
            if(!is_file($bridgeFile))throw new RuntimeException('Forward bridge unavailable');
            require_once $root.'/src/Core/Migrations.php';
            require_once $root.'/src/Update/RoutinePublicUpdateService.php';
            require_once $root.'/src/Core/SafeErrors.php';
            require_once $root.'/src/Health/PublicHealthService.php';
            require_once $root.'/src/Core/Bootstrap.php';
            require_once $bridgeFile;
            $bootstrap=\Sokna\PublicEdge\Core\Bootstrap::fromArray($cfgRaw);
            $bridge=new \Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge(
                $bootstrap->routinePublicUpdates(),$storage,
                static fn():array=>$bootstrap->migrations()->pendingVersions(),
                static function()use($bootstrap,$root,$storage):array{
                    $health=new \Sokna\PublicEdge\Health\PublicHealthService($bootstrap->database(),$root,$storage);
                    return (array)(($health->status())['body']??[]);
                }
            );
            $bridge->snapshot();
            $updates=$bridge;
            $forwardBridgeAvailable=true;
        }catch(Throwable){
            $forwardBridgeAvailable=false;
            $owner['mode']='candidate_read_only'; // Emergency access/takeover stays available.
        }
    }
    $action=(string)($_GET['action']??'');
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');

    // Machine re-enrollment is a separate one-time-code API and intentionally does not use the human console session.
    if($action==='reenroll'&&$method==='POST'){
        $body=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($body))g39_json(['ok'=>false,'error'=>'invalid_json'],400);
        $pdo=Database::connect($cfg);
        $store=new PairingSecretStore($pdo,$cfg->requiredString('relay.secret_encryption_key_base64'));
        $svc=new PublicTakeoverService($pdo,$store,$access);
        try{
            g39_json($svc->complete((string)($body['new_installation_id']??''),(string)($body['enrollment_code']??''),(string)($body['new_shared_secret']??''),(string)($body['display_name']??'')));
        }catch(PublicUpdateException $e){g39_json(['ok'=>false,'error'=>$e->errorCode],$e->httpStatus);}
    }

    if(!$access->configured()){http_response_code(503);echo 'دسترسی اضطراری هنوز provision نشده است.';exit;}

    g39_start_session();
    $clientKey=g39_client_key();
    $actor=$access->clientActorHint($clientKey);
    $notice='';$error='';$errorCode='';$oneTimeCode='';

    if(g39_authenticated()){
        $now=time();
        $loginAt=(int)($_SESSION['emergency_login_at']??0);
        $lastSeen=(int)($_SESSION['emergency_last_seen']??0);
        if($loginAt<=0||$lastSeen<=0||($now-$lastSeen)>SOKNA_EMERGENCY_IDLE_TIMEOUT||($now-$loginAt)>SOKNA_EMERGENCY_ABSOLUTE_TIMEOUT){
            g39_clear_auth();
            $access->audit('session_timeout',null,$actor,[]);
            $notice='جلسه اضطراری به‌دلیل پایان زمان اعتبار بسته شد. دوباره وارد شوید.';
        }else{
            $_SESSION['emergency_last_seen']=$now;
        }
    }

    if($action==='login'&&$method==='POST'&&!g39_authenticated()){
        try{
            g39_require_csrf();
            if(!g39_https()&&!g39_local_host())throw new PublicUpdateException('https_required','HTTPS required.',426);
            $status=$access->loginStatus($clientKey);
            if(!$status['allowed']){
                $access->audit('login_locked',null,$actor,['retry_after'=>$status['retry_after']]);
                throw new PublicUpdateException('login_locked','ورود موقتاً قفل شده است.',429,['retry_after'=>$status['retry_after']]);
            }
            $password=(string)($_POST['emergency_password']??'');
            if(!$access->verify($password)){
                $next=$access->recordLoginFailure($clientKey);
                $access->audit('login_failed',null,$actor,['remaining'=>$next['remaining'],'retry_after'=>$next['retry_after']]);
                if(!$next['allowed'])throw new PublicUpdateException('login_locked','ورود موقتاً قفل شده است.',429,['retry_after'=>$next['retry_after']]);
                throw new PublicUpdateException('login_denied','رمز دسترسی اضطراری صحیح نیست.',401,['remaining'=>$next['remaining']]);
            }
            $access->recordLoginSuccess($clientKey);
            session_regenerate_id(true);
            $_SESSION['emergency_authenticated']=true;
            $_SESSION['emergency_login_at']=time();
            $_SESSION['emergency_last_seen']=time();
            $_SESSION['csrf']=bin2hex(random_bytes(24));
            $access->audit('login_success',null,$actor,[]);
            g39_redirect_self();
        }catch(PublicUpdateException $e){
            $errorCode=$e->errorCode;
            if($e->errorCode==='login_locked'){
                $retry=(int)($e->context['retry_after']??0);
                $error='تلاش‌های ناموفق زیاد بوده است. حدود '.max(1,(int)ceil($retry/60)).' دقیقه دیگر دوباره تلاش کنید.';
            }elseif($e->errorCode==='login_denied'){
                $remaining=(int)($e->context['remaining']??0);
                $error='رمز دسترسی اضطراری صحیح نیست. '.($remaining>0?'تعداد تلاش باقی‌مانده: '.$remaining:'');
            }else{$error=g39_error_message($e->errorCode,$e->getMessage());}
        }
    }

    if($action==='logout'&&$method==='POST'&&g39_authenticated()){
        try{g39_require_csrf();$access->audit('logout',null,$actor,[]);}catch(Throwable){}
        g39_clear_auth();
        session_regenerate_id(true);
        g39_redirect_self();
    }

    if(!g39_authenticated()){
        $rate=$access->loginStatus($clientKey);
        $locked=!$rate['allowed'];
        $retryMinutes=$locked?max(1,(int)ceil($rate['retry_after']/60)):0;
        ?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>ورود اضطراری | سکنا</title>
<style>
@font-face{font-family:Vazirmatn;src:url('font.php?family=vazirmatn&v=33.003') format('woff2');font-display:swap;font-weight:100 900}
:root{--green:#0b6b56;--text:#23302a;--muted:#64726a;--line:#dce4dd;--surface:#fff;--bg:#f1f3ee;--red:#a43830}*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:#f3f1eb;background:linear-gradient(150deg,#eaf2ed,#f6f3ed 62%);font-family:Vazirmatn,Tahoma,sans-serif;color:var(--text)}.login{width:min(100%,426px);padding:30px;background:var(--surface);border:1px solid var(--line);border-radius:21px;box-shadow:0 18px 45px #25382d13}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}.mark{width:43px;height:43px;border-radius:12px;background:var(--green);display:grid;place-items:center;color:white;font-weight:800}.eyebrow{font-size:11px;color:var(--green);font-weight:750;letter-spacing:.04em}.brand h1{font-size:23px;margin:1px 0}.lead{font-size:13px;line-height:2;color:var(--muted);margin:0 0 19px}.alert{padding:10px 12px;background:#fff1ee;color:var(--red);border:1px solid #efcbc6;border-radius:10px;font-size:13px;line-height:1.8;margin:0 0 15px}.notice{padding:10px 12px;background:#eef7f1;color:#215c43;border-radius:10px;font-size:13px;margin-bottom:14px}label{display:block;font-size:13px;font-weight:750;margin-bottom:7px}.field{display:flex;align-items:center;gap:4px;border:1px solid var(--line);border-radius:11px;background:#fff}.field:focus-within{border-color:var(--green);outline:3px solid #0b6b561a}.field input{border:0;min-width:0;flex:1;font:inherit;font-size:16px;outline:0;padding:12px 13px;background:transparent}.field button{width:auto;margin:0;background:transparent;color:var(--muted);border:0;font-size:12px;padding:9px 12px;cursor:pointer}.primary{width:100%;border:0;border-radius:11px;background:var(--green);color:#fff;padding:12px;margin-top:14px;font:inherit;font-weight:700;cursor:pointer}.primary:disabled{opacity:.55;cursor:not-allowed}.foot{margin:16px 0 0;color:var(--muted);font-size:11px;line-height:1.8;text-align:center}@media(max-width:450px){.login{padding:23px 20px;border-radius:17px}}

/* Sokna control contract — common with the management panel, self-contained in Emergency. */
:root{--control-h:44px;--control-radius:10px;--focus:#0b6b5640}
body{line-height:1.65}.login{width:min(100%,444px);padding:28px;border-radius:16px;box-shadow:0 12px 36px #233a2b14}
.brand{gap:13px;margin-bottom:20px}.brand-badge{width:52px;height:52px;display:grid;place-items:center;flex:none;border-radius:12px;background:#0b6b56;overflow:hidden}
.brand-badge img{display:block;width:100%;height:100%;object-fit:cover}.brand-fallback{color:#fff;font-size:13px;font-weight:850}
.brand h1{font-size:23px;line-height:1.35}.eyebrow{font-size:10px}.lead{line-height:1.85;margin-bottom:20px}
.field{min-height:var(--control-h);border-radius:var(--control-radius)}.field input{min-height:var(--control-h);padding:8px 12px}
.field button{min-height:40px;min-width:92px;border-radius:8px;font-weight:650}.field button:hover{background:#f2f5f2}
.primary{min-height:var(--control-h);border-radius:var(--control-radius);margin-top:12px;padding:10px 16px}
.primary:hover:not(:disabled){background:#095d4b}.primary:focus-visible,.field button:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
@media(max-width:450px){.login{padding:23px 20px}.brand-badge{width:46px;height:46px}}
</style></head><body><main class="login"><div class="brand"><div class="brand-badge"><?=g39_brand_mark()?></div><div><div class="eyebrow">SOKNA · PUBLIC EMERGENCY</div><h1>دسترسی اضطراری</h1></div></div>
<p class="lead">برای بازیابی و به‌روزرسانی اضطراری، رمز تعیین‌شده هنگام راه‌اندازی سکنا را وارد کنید.</p>
<?php if($notice!==''):?><div class="notice" role="status"><?=g39_e($notice)?></div><?php endif;?>
<?php if($error!==''):?><div class="alert" role="alert" id="login-error"><?=g39_e($error)?></div><?php endif;?>
<?php if($locked):?><div class="alert" role="alert">ورود موقتاً قفل شده است؛ حدود <?=g39_e($retryMinutes)?> دقیقه دیگر دوباره تلاش کنید.</div><?php endif;?>
<form method="post" action="?action=login" autocomplete="off" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label for="emergency_password">رمز دسترسی اضطراری</label><div class="field"><input id="emergency_password" name="emergency_password" type="password" autocomplete="current-password" required <?=($locked?'disabled':'')?> <?=($error!==''?'aria-invalid="true" aria-describedby="login-error"':'')?>><button id="toggle-password" type="button" aria-controls="emergency_password" aria-pressed="false">نمایش رمز</button></div><button type="submit" class="primary" <?=($locked?'disabled':'')?>>ورود به کنسول</button></form>
<p class="foot">ورود فقط با اتصال امن HTTPS مجاز است. جلسه پس از ۱۵ دقیقه بی‌فعالیتی پایان می‌یابد.</p></main>
<script nonce="<?=g39_e($cspNonce)?>">(()=>{const t=document.getElementById('toggle-password'),p=document.getElementById('emergency_password');t?.addEventListener('click',()=>{const show=p.type==='password';p.type=show?'text':'password';t.textContent=show?'پنهان‌کردن':'نمایش رمز';t.setAttribute('aria-pressed',String(show));});document.querySelectorAll('form[data-pending]').forEach(f=>f.addEventListener('submit',()=>{if(!f.checkValidity())return;const b=f.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='در حال بررسی...';}}));})();</script>
</body></html><?php
        exit;

    }

    $pdo=null;$db='unavailable';
    try{$pdo=Database::connect($cfg);$db=(int)$pdo->query('SELECT 1')->fetchColumn()===1?'ready':'unavailable';}catch(Throwable){}

    if($method==='POST'&&$action!==''&&!in_array($action,['login','logout','reenroll'],true)){
        try{
            if(!g39_https()&&!g39_local_host())throw new PublicUpdateException('https_required','HTTPS required.',426);
            g39_require_csrf();
            // Never expose the old owner for writes when the new owner is unhealthy.
            if(!$forwardBridgeAvailable && in_array($action,['stage','activate','commit','repair','rollback'],true))
                throw new PublicUpdateException('emergency_owner_recovery_required','The old owner is read-only. Reinstall a healthy Public update from the normal panel.',503);
            if($action==='stage'){
                if(!isset($_FILES['package'])||!is_uploaded_file($_FILES['package']['tmp_name']))throw new PublicUpdateException('upload_missing','Update package is required.');
                $st=$updates->stageUploadedZip($_FILES['package']['tmp_name'],$actor);
                $access->audit('update_stage',null,$actor,['version'=>$st['version']]);
                $notice='بسته با موفقیت اعتبارسنجی و برای فعال‌سازی آماده شد.';
            }elseif($action==='activate'){
                g39_confirm('activate');
                $updates->activateStaged($actor);
                $access->audit('update_activate',null,$actor,[]);
                $notice='فایل‌ها فعال شدند؛ تأیید نهایی هنوز انجام نشده است. در درخواست جداگانه، سلامت و دیتابیس مقصد باید بررسی شود.';
            }elseif($action==='commit'){
                g39_confirm('commit');
                $result=$updates->commitPending($actor);
                $access->audit('update_commit',null,$actor,['version'=>(string)($result['active_version']??'')]);
                $notice='نسخه جدید پس از بررسی مجدد سلامت و دیتابیس تأیید شد؛ قفل تاریخی بازیابی حفظ شده است.';
            }elseif($action==='repair'){
                g39_confirm('repair');
                $updates->repairStaged($actor);
                $access->audit('update_repair',null,$actor,[]);
                $notice='ترمیم نسخه فعال با موفقیت انجام شد.';
            }elseif($action==='rollback'){
                g39_confirm('ROLLBACK');
                $updates->rollback($actor);
                $access->audit('update_rollback',null,$actor,[]);
                $notice='Public Edge به آخرین نقطه بازیابی سالم بازگردانده شد.';
            }elseif($action==='kill_switch'){
                g39_confirm('DISABLE');
                if(!$pdo)throw new PublicUpdateException('database_unavailable','Database unavailable.',503);
                $store=new PairingSecretStore($pdo,$cfg->requiredString('relay.secret_encryption_key_base64'));
                $svc=new PublicTakeoverService($pdo,$store,$access);
                $svc->killSwitch((string)($_POST['installation_id']??''),$actor);
                $notice='دریافت راه‌دور برای نصب انتخاب‌شده متوقف شد.';
            }elseif($action==='takeover_offer'){
                g39_confirm('TAKEOVER');
                if(!$pdo)throw new PublicUpdateException('database_unavailable','Database unavailable.',503);
                $store=new PairingSecretStore($pdo,$cfg->requiredString('relay.secret_encryption_key_base64'));
                $svc=new PublicTakeoverService($pdo,$store,$access);
                $offer=$svc->createOffer((string)($_POST['old_installation_id']??''),(string)($_POST['new_installation_id']??''),1800,$actor);
                $oneTimeCode=(string)$offer['enrollment_code'];
                $notice='کد یک‌بارمصرف انتقال ساخته شد. آن را همین حالا در محل امن کپی کنید.';
            }else{
                throw new PublicUpdateException('unknown_action','عملیات ناشناخته است.',404);
            }
        }catch(PublicUpdateException $e){
            $errorCode=$e->errorCode;
            $error=g39_error_message($e->errorCode,$e->getMessage());
            $access->audit('action_failed',null,$actor,['action'=>$action,'error'=>$e->errorCode]);
        }catch(Throwable){
            $errorCode='emergency_action_failed';$error='عملیات اضطراری با خطای داخلی متوقف شد.';
            $access->audit('action_failed',null,$actor,['action'=>$action,'error'=>'internal']);
        }
    }

    $snap=$updates->snapshot();
    if(($owner['mode']??'')!=='candidate'){$error='نسخه قدیمی Emergency فقط برای مشاهده در دسترس است؛ نصب از این مسیر تا اصلاح نسخه جدید غیرفعال است.';}
    $logs=$access->recentAudit(80);
    $staged=is_array($snap['staged']??null)?$snap['staged']:null;
    $dbReady=$db==='ready';
    $lkgListed=trim((string)($snap['lkg_recovery_id']??''))!=='';
    $diskVersion=trim((string)@file_get_contents($root.'/VERSION.txt'));
    $engineVersion=trim((string)($snap['current_version']??''));
    $recordedEmergencyVersion=trim((string)($snap['historical_emergency_active_version']??$snap['active_version']??''));
    $versionMismatch=($diskVersion!==''&&$engineVersion!==''&&!hash_equals($diskVersion,$engineVersion));
    // Schema/ledger readback cannot prove absence of business data mutations.
    // No unconditional activate/repair/rollback UI actions until a real DB write fence is qualified.
    $pending=is_array($snap['pending_verification']??null)?$snap['pending_verification']:null;
    $unresolvedJournal=is_array($snap['activation_journal']??null)&&$pending===null;
    $routineTreeOkay=($snap['managed_tree_matches_expected']??null)===true;
    $routineOwnerAligned=$pending!==null||hash_equals((string)($snap['active_version']??''),(string)($snap['current_version']??''));
    $dbSafetyQualified=$forwardBridgeAvailable&&$dbReady&&!$versionMismatch;
    $canStage=$dbSafetyQualified&&$pending===null&&!$unresolvedJournal&&$routineTreeOkay&&$routineOwnerAligned;
    $canActivate=$canStage&&is_array($staged)&&!empty($staged['version']);
    $canCommit=$dbSafetyQualified&&$pending!==null&&
        ($snap['current_version']??'')===($pending['version']??'')&&
        hash_equals((string)($pending['target_tree_sha256']??''),(string)($snap['managed_tree_sha256']??''));
    $canMutate=false; // legacy rollback/repair ALWAYS disabled under forward DB boundary.
    ?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>کنسول اضطراری سکنا</title>
<style>
@font-face{font-family:Vazirmatn;src:url('font.php?family=vazirmatn&v=33.003') format('woff2');font-display:swap;font-weight:100 900}
:root{--bg:#f4f2ec;--surface:#fff;--line:#e2e5df;--ink:#23302a;--muted:#65756a;--green:#0b6b56;--red:#a43830;--amber:#986419;--light:#edf5f0}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Vazirmatn,Tahoma,sans-serif;font-size:14px}.shell{max-width:1120px;margin:auto;padding:26px 20px 48px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.title small{color:var(--green);font-size:11px;font-weight:800}.title h1{font-size:25px;margin:2px 0}.title p{margin:0;color:var(--muted);font-size:12px}.grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(310px,.85fr);gap:16px}.card{background:var(--surface);border-radius:16px;border:1px solid var(--line);padding:18px;margin-bottom:16px;box-shadow:0 7px 22px #22382a09}.card h2{margin:0 0 5px;font-size:17px}.desc{margin:0 0 16px;color:var(--muted);font-size:12px;line-height:1.9}.status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}.status{border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:12px;min-width:0}.status .caption{display:block;color:var(--muted);font-size:11px;margin-bottom:7px}.status strong{display:block;overflow-wrap:anywhere;line-height:1.7}.pill{display:inline-block;border-radius:20px;font-size:11px;font-weight:700;padding:3px 9px;background:#f1f3f1}.pill.ok{background:#eaf6ef;color:#176447}.pill.warn{background:#fff5e2;color:#815a21}.pill.bad{background:#fff0ee;color:#a43830}.mono{direction:ltr;unicode-bidi:isolate;font-size:12px}.muted{color:var(--muted)}.hint{font-size:12px;line-height:1.9;color:var(--muted)}.notice,.error,.warning{padding:11px 13px;border-radius:11px;margin:0 0 13px;font-size:12px;line-height:1.9}.notice{background:#eaf6ef;color:#175d43}.error{background:#fff0ee;color:#8a302b}.warning{background:#fff7e9;color:#815a21}.panel{border-top:1px solid var(--line);padding-top:15px;margin-top:15px}.row{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.split{display:grid;grid-template-columns:1fr 1fr;gap:10px}.stack{display:grid;gap:10px}.stack label{display:block;font-size:12px;font-weight:700}.field,input[type=file],input[type=text]{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 11px;font:inherit;margin-top:5px;background:white}.field:focus,input:focus{outline:2px solid #0b6b5625;border-color:var(--green)}button{border:0;background:var(--green);color:white;border-radius:10px;padding:10px 13px;font:inherit;font-weight:700;cursor:pointer}button.secondary{background:#f1f3f1;color:var(--ink);border:1px solid var(--line)}button.warn{background:var(--amber)}button.danger{background:var(--red)}button:disabled{opacity:.5;cursor:not-allowed}.confirm{display:flex!important;align-items:start;gap:8px;line-height:1.8;color:var(--ink);font-weight:400!important}.confirm input{margin-top:5px;flex-shrink:0}details{border:1px solid var(--line);border-radius:11px;margin-top:12px;background:#fbfcfa}summary{cursor:pointer;padding:11px 13px;font-weight:700;font-size:12px}details .detail-body{padding:0 13px 13px}.detail-body p{margin:5px 0 10px}.technical pre{white-space:pre-wrap;overflow:auto;direction:ltr;text-align:left;font-size:11px;background:#17251e;color:#eff7f0;padding:10px;border-radius:9px}.danger-details{border-color:#edd5d2}.danger-details>summary{color:var(--red)}table{border-collapse:collapse;width:100%;font-size:12px}th,td{padding:8px 5px;border-bottom:1px solid var(--line);text-align:right;vertical-align:middle}th{color:var(--muted)}.events{overflow:auto}.events table{min-width:300px}.version-full{font-size:11px;overflow-wrap:anywhere}.no-result{padding:16px;color:var(--muted);text-align:center}@media(max-width:860px){.grid{grid-template-columns:1fr}.status-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.shell{padding:18px 12px}.top{align-items:flex-start}.status-grid,.split{grid-template-columns:1fr}.title h1{font-size:21px}.stack button{width:100%}}

/* Shared Sokna UI dimensions, focus and typography. These styles do not load panel JS. */
:root{--control-h:44px;--radius:10px;--focus:#0b6b5640}
.shell{max-width:1160px;padding:25px 20px 48px}.title-brand{display:flex;align-items:center;gap:12px}
.brand-badge{width:50px;height:50px;display:grid;place-items:center;border-radius:12px;background:var(--green);overflow:hidden;flex:none}
.brand-badge img{width:100%;height:100%;object-fit:cover;display:block}.brand-fallback{color:#fff;font-size:12px;font-weight:800}
.top{margin-bottom:18px}.card{border-radius:14px;padding:20px;box-shadow:0 4px 16px #22382a09}.card h2{font-size:17px;line-height:1.5}.desc{font-size:12px;margin-bottom:14px}
.grid{grid-template-columns:minmax(0,1.6fr) minmax(290px,.9fr);align-items:start}.grid>div,.grid>aside{min-width:0}
.status-grid{gap:12px}.status{border-radius:11px;min-height:114px;padding:13px}.status .caption{font-size:12px}.status strong{line-height:1.55}
.status small.mono{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere;line-height:1.65}
button,input[type=text],input[type=file],.file-trigger{font:inherit}.stack{gap:12px}.stack label{margin:0;font-weight:650;line-height:1.65}
input[type=text]{min-height:var(--control-h);border-radius:var(--radius);padding:9px 12px;font-size:14px}
button{min-height:var(--control-h);padding:10px 16px;border-radius:var(--radius);line-height:1.4}
button:not(:disabled):hover{filter:brightness(.95)}button:focus-visible,input:focus-visible,summary:focus-visible,.file-trigger:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
button.secondary{background:#f8faf8;border:1px solid #d5ded8}.stack button{justify-self:stretch}
.stack button:not(.compact-btn){min-width:0}.split{gap:12px}.split form{min-width:0}
.confirm{min-height:42px;align-items:center!important;gap:10px}.confirm input{width:16px;height:16px;accent-color:var(--green);margin:0}
.card>details{margin-top:12px}details{border-radius:11px;margin-top:12px;overflow:hidden;background:#fcfdfc}
details>summary{min-height:var(--control-h);display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 15px;list-style:none;font-size:13px;line-height:1.6;user-select:none}
details>summary::-webkit-details-marker{display:none}details>summary::before{content:'';width:8px;height:8px;flex:none;border-bottom:2px solid currentColor;border-left:2px solid currentColor;transform:rotate(-45deg);transition:transform .15s}
details[open]>summary{background:#f1f5f2;border-bottom:1px solid var(--line)}details[open]>summary::before{transform:rotate(135deg)}details>summary:hover{background:#f3f7f3}
details .detail-body{padding:14px 15px 16px}.danger-details{border-color:#ecd5d0}.danger-details[open]>summary{background:#fff3f1}
.technical pre{display:block;max-height:300px;overflow:auto;overscroll-behavior:contain;white-space:pre-wrap;overflow-wrap:anywhere;tab-size:2;padding:16px;font-size:12px;line-height:1.75;border-radius:10px;direction:ltr;text-align:left}
.detail-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:9px;justify-content:space-between;margin:8px 0}.detail-toolbar .caption{font-size:12px;color:var(--muted)}
.compact-btn{min-height:36px;padding:6px 11px;font-size:12px}.copy-feedback{font-size:12px;color:var(--green)}
.events-history{max-height:330px;overflow:auto;overscroll-behavior:contain}.events-history table{min-width:420px}.events{max-width:100%}
th,td{padding:10px 7px;line-height:1.65}th{font-weight:700}.events tr:nth-child(even){background:#fafbf9}
.file-picker{display:flex;align-items:center;gap:10px;border:1px solid var(--line);padding:7px;border-radius:var(--radius);background:#fff;min-height:56px;margin-top:7px;min-width:0}
.file-picker input[type=file]{position:absolute;width:1px;height:1px;clip-path:inset(50%);overflow:hidden;white-space:nowrap;opacity:0;pointer-events:none}
.file-picker input[type=file]:focus-visible+.file-trigger{outline:3px solid var(--focus);outline-offset:2px}
.file-trigger{min-height:40px;display:inline-flex!important;align-items:center;justify-content:center;white-space:nowrap;flex:none;padding:8px 15px!important;margin:0!important;background:#eef4f0;border:1px solid #cbdad0;border-radius:8px;cursor:pointer;font-weight:700}
.file-picker input:disabled+.file-trigger{cursor:not-allowed;opacity:.45}.file-picker .file-name{color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;font-size:12px;direction:auto}
.notice,.error,.warning{border:1px solid transparent;line-height:1.75;border-radius:var(--radius);padding:12px 14px}
.notice{border-color:#cfe6d7}.error{border-color:#edc9c4}.warning{border-color:#f3dfb8}.pill{white-space:nowrap}
@media(max-width:860px){.grid{grid-template-columns:1fr}.status-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.shell{padding:14px 12px 28px}.top{align-items:center}.brand-badge{width:44px;height:44px}.title h1{font-size:21px}.card{padding:16px}.status-grid,.split{grid-template-columns:1fr}.status{min-height:0}.file-picker{flex-wrap:wrap}.file-picker .file-name{width:100%;padding:0 4px}.stack button{width:100%}}
@media(prefers-reduced-motion:reduce){details>summary::before{transition:none}}
</style></head><body><main class="shell">
<header class="top"><div class="title title-brand"><div class="brand-badge"><?=g39_brand_mark()?></div><div><small>SOKNA · PUBLIC EMERGENCY</small><h1>کنسول اضطراری</h1><p>به‌روزرسانی و بازیابی، مستقل از پنل Local</p></div></div><form method="post" action="?action=logout" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><button type="submit" class="secondary">خروج امن</button></form></header>
<?php if($notice!==''):?><div class="notice" role="status"><?=g39_e($notice)?></div><?php endif;?>
<?php if($error!==''):?><div class="error" role="alert"><?=g39_e($error)?> <?php if($errorCode!==''):?><span class="mono">(<?=g39_e($errorCode)?>)</span><?php endif;?></div><?php endif;?>
<?php if($oneTimeCode!==''):?><div class="notice"><strong>کد انتقال یک‌بارمصرف</strong><p>فقط یک‌بار نمایش داده می‌شود و ۳۰ دقیقه اعتبار دارد.</p><code class="mono"><?=g39_e($oneTimeCode)?></code></div><?php endif;?>
<section class="status-grid" aria-label="وضعیت سامانه">
  <div class="status"><span class="caption">اتصال دیتابیس</span><strong><span class="pill <?=($dbReady?'ok':'bad')?>"><?=($dbReady?'متصل':'قطع')?></span></strong><small class="hint">اتصال، به معنی سلامت کامل نیست.</small></div>
  <div class="status"><span class="caption">نسخه فایل‌های Public</span><strong><?=g39_e(g39_short_version($diskVersion))?></strong><small class="hint mono"><?=g39_e($diskVersion)?></small><?php if($recordedEmergencyVersion!==''&&$recordedEmergencyVersion!==$diskVersion):?><small class="hint">سابقه مستقل Emergency: <?=g39_e(g39_short_version($recordedEmergencyVersion))?></small><?php endif;?></div>
  <div class="status"><span class="caption">بسته مرحله‌بندی‌شده</span><strong><?=($staged?g39_e(g39_short_version((string)($staged['version']??''))):'—')?></strong><small class="hint"><?=($staged?'فقط بررسی و آماده‌سازی شده':'بسته‌ای آماده نشده')?></small></div>
  <div class="status"><span class="caption">قابلیت بازگشت</span><strong><span class="pill warn">تأییدنشده</span></strong><small class="hint"><?=($lkgListed?'شناسه بازیابی ثبت شده؛ سلامت و دیتابیس تأیید نشده':'نقطه بازیابی معتبر تأیید نشده')?></small></div>
</section>
<?php if($versionMismatch):?><div class="warning" role="status">نسخه گزارش‌شده توسط موتور با نسخه فایل‌های Public متفاوت است. تا تعیین علت، نصب متوقف بماند. <details><summary>نمایش نسخه‌های فنی</summary><div class="detail-body mono">فایل: <?=g39_e($diskVersion)?> / موتور: <?=g39_e($engineVersion)?></div></details></div><?php endif;?>
<div class="grid"><div>
<section class="card"><h2>به‌روزرسانی و بازیابی</h2><p class="desc">یک ZIP را بررسی کنید؛ نصب و بازگشت فقط وقتی فعال می‌شوند که کنترل‌های ایمنی کامل باشند.</p>
<?php if(!$forwardBridgeAvailable):?><div class="warning" role="status">موتور Routine مشترک برای عملیات مستقل Emergency احراز نشده است؛ فقط مشاهده و عملیات مدیریت دسترسی مجازند.</div><?php elseif(!$dbReady):?><div class="warning" role="status">اتصال و سلامت دیتابیس برای عملیات به‌روزرسانی در دسترس نیست.</div><?php else:?><div class="warning" role="status">این مسیر فقط به‌روزرسانی رو‌به‌جلو را با همان موتور Routine انجام می‌دهد؛ ترمیم و بازگشت کد قدیمی فعال نمی‌شوند. پس از فعال‌سازی، یک تأیید نهایی مستقل لازم است.</div><?php endif;?>
<?php if($staged):?><div class="status"><span class="caption">بسته آماده‌شده</span><strong><?=g39_e(g39_short_version((string)($staged['version']??'')))?></strong><details><summary>جزئیات بسته</summary><div class="detail-body mono"><?=g39_e($staged['version']??'—')?> · <?=g39_e($staged['staged_at']??'—')?></div></details></div><?php endif;?>
<form class="stack panel" method="post" action="?action=stage" enctype="multipart/form-data" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label for="public-package">بسته به‌روزرسانی Public</label><div class="file-picker"><input type="file" id="public-package" name="package" accept=".zip,application/zip" required <?=($canStage?'':'disabled')?>><label for="public-package" class="file-trigger">انتخاب فایل ZIP</label><output for="public-package" id="selected-package" class="file-name" aria-live="polite">فایلی انتخاب نشده است</output></div><div><button type="submit" <?=($canStage?'':'disabled')?>>بررسی و آماده‌سازی بسته</button></div></form>
<details><summary>نصب، ترمیم و بازیابی</summary><div class="detail-body"><p class="hint">فعال‌سازی نسخه جدید، ترمیم همان نسخه و بازگشت به کد قبلی عملیات متفاوتی هستند؛ تا تأیید ایمنی دیتابیس و نسخه مقصد اجرا نمی‌شوند.</p><div class="split">
<form class="stack" method="post" action="?action=activate" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label class="confirm"><input type="checkbox" name="confirmation" value="activate" required>نسخه آماده‌شده را فعال می‌کنم.</label><button type="submit" <?=($canActivate?'':'disabled')?>>فعال‌سازی رو‌به‌جلو</button></form>
<form class="stack" method="post" action="?action=repair" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label class="confirm"><input type="checkbox" name="confirmation" value="repair" required>ترمیم نسخه فعلی را تأیید می‌کنم.</label><button type="submit" class="secondary" <?=($canMutate&&$staged?'':'disabled')?>>ترمیم نسخه فعلی</button></form>
</div><?php if($pending!==null):?><form class="stack panel" method="post" action="?action=commit" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label class="confirm"><input type="checkbox" name="confirmation" value="commit" required>نسخه فعال‌شده باید در درخواست تازه، از نظر نسخه، درخت فایل‌ها و دیتابیس تأیید شود.</label><button type="submit" <?=($canCommit?'':'disabled')?>>بررسی تازه و Commit نسخه</button></form><?php endif;?><form class="stack panel" method="post" action="?action=rollback" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label>برای بازگشت، عبارت <span class="mono">ROLLBACK</span> را وارد کنید<input type="text" name="confirmation" autocomplete="off" required></label><button class="warn" type="submit" <?=($canMutate&&$lkgListed?'':'disabled')?>>بازگشت به نسخه سالم</button></form></div></details>
<details class="technical"><summary>جزئیات فنی به‌روزرسانی و تمامیت بسته</summary><div class="detail-body"><p class="hint">ساختار، سازگاری و SHA256 بسته کنترل می‌شود. این اطلاعات صرفاً برای عیب‌یابی است.</p><div class="detail-toolbar"><span class="caption">گزارش فنی — فقط مشاهده</span><button type="button" class="secondary compact-btn" id="copy-technical">کپی جزئیات</button><span class="copy-feedback" role="status" aria-live="polite" id="copy-feedback"></span></div><pre id="technical-json" tabindex="0" aria-label="جزئیات فنی قابل پیمایش"><?=g39_e(json_encode($snap,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></pre></div></details>
</section>
<details class="card danger-details"><summary>عملیات اضطراری پیشرفته</summary><div class="detail-body"><p class="hint">فقط برای قطع ارتباط نصب یا انتقال مالکیت؛ این بخش در شرایط عادی لازم نیست.</p><div class="split">
<form class="stack" method="post" action="?action=kill_switch" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label>شناسه نصب<input type="text" name="installation_id" dir="ltr" required></label><label>عبارت تأیید <span class="mono">DISABLE</span><input type="text" name="confirmation" autocomplete="off" required></label><button class="danger" type="submit" <?=($dbReady?'':'disabled')?>>قطع دسترسی راه‌دور</button></form>
<form class="stack" method="post" action="?action=takeover_offer" data-pending="1"><input type="hidden" name="csrf" value="<?=g39_e(g39_csrf())?>"><label>شناسه نصب فعلی<input type="text" name="old_installation_id" dir="ltr" required></label><label>شناسه نصب جدید<input type="text" name="new_installation_id" dir="ltr" required></label><label>عبارت تأیید <span class="mono">TAKEOVER</span><input type="text" name="confirmation" autocomplete="off" required></label><button class="danger" type="submit" <?=($dbReady?'':'disabled')?>>ساخت کد انتقال</button></form>
</div></div></details>
</div><aside>
<section class="card"><h2>وضعیت دسترسی</h2><p class="desc">این کنسول با رمز اضطراری مستقل محافظت می‌شود.</p><div class="status"><span class="caption">نشست اضطراری</span><strong class="pill ok">فعال</strong><p class="hint">پایان نشست پس از ۱۵ دقیقه بی‌فعالیتی</p></div><details><summary>جزئیات امنیتی</summary><div class="detail-body"><p class="hint">HttpOnly · SameSite=Strict<?=g39_https()?' · Secure':''?> · محدودیت تلاش ورود: ۵ تلاش در ۱۰ دقیقه</p></div></details></section>
<section class="card"><h2>رویدادهای اخیر</h2><p class="desc">۱۰ رویداد آخر بر اساس ساعت ایران؛ باقی در بخش جزئیات.</p>
<?php if(!$logs):?><div class="no-result">رویدادی ثبت نشده است.</div><?php else:?><div class="events"><table><thead><tr><th>زمان ایران</th><th>رویداد</th></tr></thead><tbody><?php foreach(array_slice($logs,0,10) as $row):?><tr><td class="mono"><?=g39_e(g39_iran_time((string)($row['at']??'')))?></td><td><?=g39_e(g39_action_label((string)($row['action']??'')))?></td></tr><?php endforeach;?></tbody></table></div>
<?php if(count($logs)>10):?><details><summary>نمایش <?=g39_e(count($logs)-10)?> رویداد دیگر</summary><div class="detail-body events events-history"><table><thead><tr><th>زمان ایران</th><th>رویداد</th><th>شناسه نصب</th></tr></thead><tbody><?php foreach(array_slice($logs,10) as $row):?><tr><td class="mono"><?=g39_e(g39_iran_time((string)($row['at']??'')))?></td><td><?=g39_e(g39_action_label((string)($row['action']??'')))?></td><td class="mono"><?=g39_e($row['installation_id']??'—')?></td></tr><?php endforeach;?></tbody></table></div></details><?php endif;?><?php endif;?></section>
</aside></div>
</main><script nonce="<?=g39_e($cspNonce)?>">(()=>{const picker=document.getElementById('public-package'),chosen=document.getElementById('selected-package');
picker?.addEventListener('change',()=>{if(chosen)chosen.textContent=picker.files?.[0]?.name||'فایلی انتخاب نشده است';});
const copy=document.getElementById('copy-technical'),contents=document.getElementById('technical-json'),feedback=document.getElementById('copy-feedback');
copy?.addEventListener('click',async()=>{try{if(!navigator.clipboard?.writeText)throw new Error('unsupported');await navigator.clipboard.writeText(contents?.textContent||'');if(feedback)feedback.textContent='کپی شد';}catch(e){if(feedback)feedback.textContent='کپی خودکار ممکن نیست؛ متن را انتخاب کنید';}});
document.querySelectorAll('form[data-pending]').forEach(f=>f.addEventListener('submit',()=>{if(!f.checkValidity())return;const b=f.querySelector('button[type=submit]');if(b){b.disabled=true;b.textContent='در حال انجام...';}}));})();</script></body></html><?php
}catch(Throwable){http_response_code(500);echo 'Emergency console unavailable.';}