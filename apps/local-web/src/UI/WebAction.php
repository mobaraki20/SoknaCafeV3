<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Bootstrap;
use Throwable;

final class WebAction
{
    private const CSRF_KEY='sokna_csrf_token';

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
