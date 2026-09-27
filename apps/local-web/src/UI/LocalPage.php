<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Bootstrap;

final class LocalPage
{
    public static function user(Bootstrap $core): array
    {
        $user=$core->auth()->currentUser();
        if($user===null){header('Location: /login.php');exit;}
        return $user;
    }

    public static function requireAdmin(Bootstrap $core): array
    {
        $user=self::user($core);
        if((string)($user['role']??'')!=='admin')self::forbidden();
        return $user;
    }

    /** @param list<string> $capabilities */
    public static function requireAny(Bootstrap $core,array $capabilities): array
    {
        $user=self::user($core);
        if((string)($user['role']??'')==='admin')return $user;
        foreach($capabilities as $capability)if($core->auth()->hasCapability($capability,$user))return $user;
        self::forbidden();
    }

    public static function forbidden(): never
    {
        http_response_code(403);
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>دسترسی محدود | سکنا</title><link rel="stylesheet" href="/scds.php?file=tokens.css"><link rel="stylesheet" href="/scds.php?file=components.css"></head><body>';
        echo SCDS::systemState('دسترسی محدود است.','این حساب اجازه ورود به این بخش را ندارد.','<a class="sc-button sc-button--secondary" href="/">بازگشت</a>');
        echo '</body></html>';
        exit;
    }
}
