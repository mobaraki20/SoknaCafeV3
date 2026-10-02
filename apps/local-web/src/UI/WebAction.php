<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Bootstrap;
use Throwable;

final class WebAction
{
    private const CSRF_KEY='sokna_csrf_token';

    public static function requireUser(Bootstrap $core): array
    {
        $user=$core->auth()->currentUser();
        if($user===null)self::json(['success'=>false,'code'=>'unauthenticated','message'=>'نشست شما پایان یافته؛ دوباره وارد شوید.'],401);
        return $user;
    }

    /** @param list<string> $capabilities */
    public static function requireAny(Bootstrap $core,array $capabilities): array
    {
        $user=$core->auth()->currentUser();
        if($user===null)self::json(['success'=>false,'code'=>'unauthenticated','message'=>'نشست شما پایان یافته؛ دوباره وارد شوید.'],401);
        if((string)($user['role']??'')==='admin')return $user;
        foreach($capabilities as $capability)if($core->auth()->hasCapability($capability,$user))return $user;
        self::json(['success'=>false,'code'=>'forbidden','message'=>'دسترسی این بخش برای حساب شما فعال نیست.'],403);
    }

    public static function csrfToken(): string
    {
        if(session_status()!==PHP_SESSION_ACTIVE)throw new \LogicException('CSRF requires active session.');
        $token=(string)($_SESSION[self::CSRF_KEY]??'');
        if(strlen($token)!==64||!ctype_xdigit($token)){
            $token=bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY]=$token;
        }
        return $token;
    }

    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        $raw=(string)file_get_contents('php://input');
        if(strlen($raw)>1048576)self::json(['success'=>false,'code'=>'request_too_large','message'=>'حجم درخواست بیش از حد مجاز است.'],413);
        if(trim($raw)==='')return [];
        $data=json_decode($raw,true);
        if(!is_array($data))self::json(['success'=>false,'code'=>'invalid_json','message'=>'ساختار درخواست معتبر نیست.'],400);
        return $data;
    }

    /** @return array<string,mixed> */
    public static function requireMutation(): array
    {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')self::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش درخواست معتبر نیست.'],405);
        $data=self::jsonBody();
        $candidate=trim((string)($data['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??'')));
        $expected=(string)($_SESSION[self::CSRF_KEY]??'');
        if($expected===''||$candidate===''||!hash_equals($expected,$candidate)){
            self::json(['success'=>false,'code'=>'csrf_expired','message'=>'نشست صفحه منقضی شده؛ صفحه را تازه کنید.'],419);
        }
        return $data;
    }

    public static function requireCsrfHeader(): void
    {
        $candidate=trim((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
        $expected=(string)($_SESSION[self::CSRF_KEY]??'');
        if($expected===''||$candidate===''||!hash_equals($expected,$candidate)){
            self::json(['success'=>false,'code'=>'csrf_expired','message'=>'نشست صفحه منقضی شده؛ صفحه را تازه کنید.'],419);
        }
    }

    /** @return array{code:string,message:string,status:int} */
    public static function uploadProblem(int $error,string $subject='فایل'): array
    {
        $subject=trim($subject)!==''?trim($subject):'فایل';
        return match($error){
            UPLOAD_ERR_OK=>['code'=>'','message'=>'','status'=>200],
            UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>['code'=>'upload_server_limit','message'=>'حجم '.$subject.' از سقف بارگذاری این نصب بیشتر است. سقف فعلی سرور: '.self::uploadLimitLabel().'.','status'=>413],
            UPLOAD_ERR_PARTIAL=>['code'=>'upload_interrupted','message'=>'بارگذاری '.$subject.' نیمه‌کاره ماند. دوباره تلاش کن.','status'=>422],
            UPLOAD_ERR_NO_FILE=>['code'=>'upload_missing','message'=>$subject.' انتخاب نشده است.','status'=>422],
            UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE=>['code'=>'upload_storage_unavailable','message'=>'فضای موقت بارگذاری روی سرور آماده نیست. سلامت سامانه را بررسی کن.','status'=>500],
            UPLOAD_ERR_EXTENSION=>['code'=>'upload_blocked','message'=>'بارگذاری '.$subject.' توسط تنظیمات سرور متوقف شد.','status'=>422],
            default=>['code'=>'upload_failed','message'=>'بارگذاری '.$subject.' کامل نشد. دوباره تلاش کن.','status'=>422],
        };
    }

    private static function uploadLimitLabel(): string
    {
        $upload=trim((string)ini_get('upload_max_filesize'));$post=trim((string)ini_get('post_max_size'));
        $u=self::iniBytes($upload);$p=self::iniBytes($post);$effective=$u>0&&$p>0?min($u,$p):max($u,$p);
        if($effective<=0)return $upload!==''?$upload:($post!==''?$post:'نامشخص');
        if($effective%1073741824===0)return (string)($effective/1073741824).'G';
        if($effective%1048576===0)return (string)($effective/1048576).'M';
        if($effective%1024===0)return (string)($effective/1024).'K';
        return (string)$effective.'B';
    }

    private static function iniBytes(string $value): int
    {
        $value=trim($value);if($value==='')return 0;$last=strtolower(substr($value,-1));$n=(float)$value;
        return (int)round($n*match($last){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1});
    }

    public static function json(array $payload,int $status=200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        exit;
    }

    public static function knownFailure(Throwable $e): never
    {
        $status=property_exists($e,'httpStatus')?(int)$e->httpStatus:409;
        $code=property_exists($e,'errorCode')?(string)$e->errorCode:'operation_rejected';
        $details=property_exists($e,'details')&&is_array($e->details)?$e->details:[];
        self::json(['success'=>false,'code'=>$code,'message'=>$e->getMessage(),'details'=>$details],max(400,min(599,$status)));
    }
}
