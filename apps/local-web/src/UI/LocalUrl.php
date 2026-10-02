<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Session;

final class LocalUrl
{
    public static function basePath(): string
    {
        return self::basePathForRequest();
    }

    public static function basePathForRequest(
        ?string $scriptName=null,
        ?string $scriptFilename=null,
        ?string $documentRoot=null,
        ?string $publicRoot=null,
    ): string {
        $publicRoot ??= dirname(__DIR__,2).DIRECTORY_SEPARATOR.'public';
        $cookiePath=Session::cookiePathForRequest($scriptName,$scriptFilename,$documentRoot,$publicRoot);
        if($cookiePath==='/'||$cookiePath==='')return '';
        return '/'.trim($cookiePath,'/');
    }

    public static function baseHref(): string
    {
        $base=self::basePath();
        return $base===''?'/':$base.'/';
    }

    public static function path(string $path): string
    {
        $path=trim($path);
        if($path===''||$path[0]==='#'||preg_match('#^(?:https?:)?//#i',$path)===1||preg_match('#^(?:mailto|tel|data):#i',$path)===1)return $path;
        $base=self::basePath();
        if($path[0]!=='/')$path='/'.$path;
        if($base!==''&&($path===$base||str_starts_with($path,$base.'/')))return $path;
        return $base.$path;
    }
}
