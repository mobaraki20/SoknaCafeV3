<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use InvalidArgumentException;

final class LocalEndpoint
{
    public const MIN_PORT = 1024;
    public const MAX_PORT = 65535;
    public const CANONICAL_HOST = '127.0.0.1';

    public static function normalize(string $value): string
    {
        $value=trim($value);
        if($value==='')throw new InvalidArgumentException('local_endpoint_empty');
        $parts=parse_url($value);
        if(!is_array($parts))throw new InvalidArgumentException('local_endpoint_parse');
        $scheme=strtolower((string)($parts['scheme']??''));
        $host=strtolower(trim((string)($parts['host']??''),'[]'));
        if(!in_array($scheme,['http','https'],true))throw new InvalidArgumentException('local_endpoint_scheme');
        if(!in_array($host,[self::CANONICAL_HOST,'localhost','::1'],true))throw new InvalidArgumentException('local_endpoint_not_loopback');
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new InvalidArgumentException('local_endpoint_origin_only');
        $path=(string)($parts['path']??'');
        if($path!==''&&$path!=='/')throw new InvalidArgumentException('local_endpoint_origin_only');
        $port=(int)($parts['port']??($scheme==='https'?443:80));
        if($port<self::MIN_PORT||$port>self::MAX_PORT)throw new InvalidArgumentException('local_endpoint_port');
        return $scheme.'://'.self::CANONICAL_HOST.':'.$port.'/';
    }

    public static function fromServer(array $server): string
    {
        $scheme=(!empty($server['HTTPS'])&&strtolower((string)$server['HTTPS'])!=='off')?'https':'http';
        $port=(int)($server['SERVER_PORT']??0);
        if($port<self::MIN_PORT||$port>self::MAX_PORT)throw new InvalidArgumentException('local_endpoint_port');
        return self::normalize($scheme.'://'.self::CANONICAL_HOST.':'.$port.'/');
    }

    public static function origin(string $baseUrl): string
    {
        return rtrim(self::normalize($baseUrl),'/');
    }

    public static function canonicalRedirectTarget(array $server): ?string
    {
        $expected=self::fromServer($server);
        $expectedParts=parse_url($expected);
        $scheme=(string)$expectedParts['scheme'];
        $port=(int)$expectedParts['port'];

        $hostHeader=trim((string)($server['HTTP_HOST']??''));
        if($hostHeader==='')return null;
        $current=parse_url($scheme.'://'.$hostHeader.'/');
        if(!is_array($current))return $expected;
        $currentHost=strtolower(trim((string)($current['host']??''),'[]'));
        $currentPort=(int)($current['port']??($scheme==='https'?443:80));

        if($currentHost===self::CANONICAL_HOST&&$currentPort===$port)return null;

        $uri=(string)($server['REQUEST_URI']??'/');
        if($uri===''||$uri[0]!=='/'||str_contains($uri,"\r")||str_contains($uri,"\n"))$uri='/';
        return rtrim($expected,'/').'/'.ltrim($uri,'/');
    }
}
