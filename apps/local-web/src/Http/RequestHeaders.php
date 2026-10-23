<?php
declare(strict_types=1);

namespace Sokna\Local\Http;

final class RequestHeaders
{
    /** @return array<string,string> */
    public static function all(array $server): array
    {
        $headers=[];
        if(function_exists('getallheaders')){
            $raw=getallheaders();
            if(is_array($raw))foreach($raw as $k=>$v)if(is_string($k)&&(is_string($v)||is_numeric($v)))$headers[$k]=(string)$v;
        }
        foreach($server as $key=>$value){
            if(!is_string($key)||(!is_string($value)&&!is_numeric($value)))continue;
            if(str_starts_with($key,'HTTP_')){
                $name=str_replace(' ','-',ucwords(strtolower(str_replace('_',' ',substr($key,5)))));
                if(!isset($headers[$name]))$headers[$name]=(string)$value;
            }
        }
        $authorization=self::authorization($server,$headers);
        if($authorization!=='')$headers['Authorization']=$authorization;
        return $headers;
    }

    /** @param array<string,string> $headers */
    public static function value(array $headers,string $name): string
    {
        foreach($headers as $key=>$value)if(strcasecmp((string)$key,$name)===0)return trim((string)$value);
        return '';
    }

    /** @param array<string,string> $headers */
    public static function authorization(array $server,array $headers=[]): string
    {
        foreach(['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $key){
            $value=trim((string)($server[$key]??''));if($value!=='')return $value;
        }
        foreach($headers as $key=>$value)if(strcasecmp((string)$key,'Authorization')===0){$value=trim((string)$value);if($value!=='')return $value;}
        return '';
    }
}
