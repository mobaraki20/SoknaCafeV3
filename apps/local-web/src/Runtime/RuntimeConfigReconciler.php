<?php
declare(strict_types=1);

namespace Sokna\Local\Runtime;

use Throwable;

/**
 * Keeps the installed Windows Runtime trigger catalog compatible with the
 * currently installed Local Web build without replacing machine identity,
 * tokens or operator-owned settings.
 */
final class RuntimeConfigReconciler
{
    /** @return list<array{key:string,intervalSeconds:int}> */
    public static function requiredTriggers(): array
    {
        return [
            ['key'=>'inventory.order_events','intervalSeconds'=>15],
            ['key'=>'public.relay_sync','intervalSeconds'=>5],
            ['key'=>'public.live_projection_sync','intervalSeconds'=>1],
            ['key'=>'public.projection_sync','intervalSeconds'=>30],
            ['key'=>'notifications.outbox','intervalSeconds'=>5],
            ['key'=>'maintenance.health','intervalSeconds'=>60],
        ];
    }

    /** @return array{changed:bool,ready:bool,path:string,missing_before:list<string>,triggers:list<string>} */
    public static function reconcileFromConfig(array $config): array
    {
        $dataDir=rtrim((string)($config['app']['data_dir']??''),"\\/");
        if($dataDir==='')return ['changed'=>false,'ready'=>false,'path'=>'','missing_before'=>array_column(self::requiredTriggers(),'key'),'triggers'=>[]];
        return self::reconcile($dataDir.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'runtime-config.json');
    }

    /** @return array{changed:bool,ready:bool,path:string,missing_before:list<string>,triggers:list<string>} */
    public static function reconcile(string $path): array
    {
        $required=self::requiredTriggers();
        if(!is_file($path))return ['changed'=>false,'ready'=>false,'path'=>$path,'missing_before'=>array_column($required,'key'),'triggers'=>[]];
        try{
            $raw=@file_get_contents($path);$cfg=is_string($raw)?json_decode($raw,true,64,JSON_THROW_ON_ERROR):null;
            if(!is_array($cfg))return ['changed'=>false,'ready'=>false,'path'=>$path,'missing_before'=>array_column($required,'key'),'triggers'=>[]];
            $existing=is_array($cfg['triggers']??null)?$cfg['triggers']:[];$byKey=[];$order=[];
            foreach($existing as $row){if(!is_array($row))continue;$key=trim((string)($row['key']??''));if($key===''||isset($byKey[$key]))continue;$byKey[$key]=$row;$order[]=$key;}
            $missing=[];$changed=false;
            foreach($required as $row){$key=$row['key'];if(!isset($byKey[$key])){$missing[]=$key;$byKey[$key]=$row;$order[]=$key;$changed=true;continue;}
                // Required trigger cadence is product-owned. Preserve unknown
                // fields but reconcile the cadence when an older install differs.
                if((int)($byKey[$key]['intervalSeconds']??0)!==$row['intervalSeconds']){$byKey[$key]['intervalSeconds']=$row['intervalSeconds'];$changed=true;}
            }
            $next=[];foreach($order as $key)$next[]=$byKey[$key];
            if($changed){$cfg['triggers']=$next;$tmp=$path.'.tmp-'.bin2hex(random_bytes(4));$json=json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";if(@file_put_contents($tmp,$json,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);return ['changed'=>false,'ready'=>false,'path'=>$path,'missing_before'=>$missing,'triggers'=>array_values($order)];}@chmod($path,0600);}
            return ['changed'=>$changed,'ready'=>true,'path'=>$path,'missing_before'=>$missing,'triggers'=>array_values($order)];
        }catch(Throwable){return ['changed'=>false,'ready'=>false,'path'=>$path,'missing_before'=>array_column($required,'key'),'triggers'=>[]];}
    }
}
