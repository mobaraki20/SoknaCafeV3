<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

final class AssetUrl
{
    /** @var array<string,string> */
    private static array $hashes=[];
    private static ?string $buildVersion=null;

    public static function asset(string $path): string
    {
        $normalized='/'.ltrim(trim($path),'/');
        $physical=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'public'.str_replace('/',DIRECTORY_SEPARATOR,$normalized);
        return self::withVersion(LocalUrl::path($normalized),self::fileToken($physical));
    }

    public static function scds(string $file): string
    {
        $safe=basename(trim($file));
        $physical=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'scds'.DIRECTORY_SEPARATOR.$safe;
        $base=LocalUrl::path('/scds.php').'?file='.rawurlencode($safe);
        return self::withVersion($base,self::fileToken($physical));
    }

    public static function sprite(string $assetPath,string $symbol): string
    {
        return self::asset($assetPath).'#'.ltrim($symbol,'#');
    }

    public static function buildVersion(): string
    {
        if(self::$buildVersion!==null)return self::$buildVersion;
        $raw=@file_get_contents(dirname(__DIR__,2).DIRECTORY_SEPARATOR.'VERSION.txt');
        $version=is_string($raw)?trim($raw):'';
        return self::$buildVersion=$version!==''?$version:'dev';
    }

    private static function fileToken(string $path): string
    {
        if(isset(self::$hashes[$path]))return self::$hashes[$path];
        $hash=is_file($path)?@hash_file('sha256',$path):false;
        return self::$hashes[$path]=is_string($hash)&&$hash!==''?substr($hash,0,16):substr(hash('sha256',self::buildVersion().'|'.$path),0,16);
    }

    private static function withVersion(string $url,string $token): string
    {
        return $url.(str_contains($url,'?')?'&':'?').'v='.rawurlencode($token);
    }
}
