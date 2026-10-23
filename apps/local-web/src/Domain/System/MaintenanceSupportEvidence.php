<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use Throwable;

/**
 * Small diagnostic index inside the Local support bundle. Windows Services
 * owns the complete signed journal and machine diagnostics: never copy raw
 * HTTP envelopes, keys, machine paths, payloads, or idempotency tokens here.
 * A failed read is UNKNOWN, never evidence of an idle/healthy engine.
 */
final class MaintenanceSupportEvidence
{
    public static function capture(MaintenanceSupervisorClient $client): array
    {
        $started=microtime(true);
        try{$connection=$client->connectionDiagnostics();}catch(Throwable){$connection=['error_code'=>'MAINTENANCE_CONNECTION_PROBE_FAILED','authenticated'=>false];}
        try{$cap=$client->updateCapabilities();}catch(Throwable){$cap=['ready'=>false,'state'=>'unavailable','code'=>'MAINTENANCE_CAPABILITY_READ_FAILED'];}
        try{$ops=$client->updateOperations();}catch(Throwable){$ops=['ok'=>false,'state'=>'unavailable','code'=>'MAINTENANCE_OPERATION_READ_FAILED'];}
        $result=self::summarize($cap,$ops);
        $result['connection']=[
            'discovery'=>self::shortCode($connection['discovery']??'unverified'),
            'web_adapter_key'=>self::shortCode($connection['web_adapter_key']??'unverified'),
            'signed_health'=>self::shortCode($connection['signed_health']??'unverified'),
            'http_status'=>is_int($connection['http_status']??null)?$connection['http_status']:null,
            'error_code'=>self::shortCode($connection['error_code']??'MAINTENANCE_CONNECTION_UNKNOWN'),
            'authenticated'=>($connection['authenticated']??false)===true,
        ];
        $result['elapsed_ms']=(int)round((microtime(true)-$started)*1000);
        return $result;
    }

    /** @param array<string,mixed> $cap @param array<string,mixed> $ops */
    public static function summarize(array $cap,array $ops): array
    {
        $ok=($ops['ok']??false)===true;
        $capCode=self::shortCode($cap['code']??'MAINTENANCE_CAPABILITY_UNAVAILABLE');
        $result=[
            'format'=>'sokna-maintenance-local-support-index-v1',
            'captured_at_utc'=>gmdate('c'),
            'authority'=>'windows_maintenance_supervisor',
            'full_engine_diagnostics'=>'windows_services_support_bundle',
            'transport'=>'signed_read_only',
            'capabilities'=>[
                'state'=>self::shortCode($cap['state']??'unavailable'),
                'blocking_code'=>$capCode,
                'install_ready'=>($cap['ready']??false)===true,
                'verification_ready'=>($cap['verification_ready']??false)===true,
                'inbox_transport_available'=>($cap['inbox_transport_available']??false)===true,
            ],
            'operations'=>[
                'evidence_available'=>$ok,
                'state'=>self::shortCode($ops['state']??'unavailable'),
                'error_code'=>$ok?'':self::shortCode($ops['code']??'OPERATION_EVIDENCE_UNAVAILABLE'),
                // Unknown must not be represented as 0, or clear a recovery gate.
                'active_count'=>$ok?(int)($ops['active_count']??0):null,
                'blocking_count'=>$ok?(int)($ops['blocking_count']??0):null,
                'requires_recovery'=>$ok?(($ops['requires_recovery']??false)===true):null,
                'history_available'=>$ok?(($ops['history_available']??false)===true):false,
                'history_error'=>$ok?self::shortCode($ops['history_error']??''):'',
                'active'=>$ok?self::operations($ops['active_operations']??[]):[],
                'blocking'=>$ok?self::operations($ops['blocking_operations']??[]):[],
                'recent'=>$ok&&($ops['history_available']??false)===true?self::operations($ops['recent_operations']??[]):[],
            ],
        ];
        return $result;
    }

    private static function shortCode(mixed $code): string
    {
        if(!is_string($code)||$code==='')return '';
        // Never accept arbitrary strings (e.g., HTTP body, path, key) as codes.
        return preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/D',$code)===1?$code:'invalid_or_redacted';
    }

    private static function operations(mixed $records): array
    {
        if(!is_array($records))return [];
        $out=[];
        foreach(array_slice($records,0,10) as $op){
            if(!is_array($op))continue;
            $id=(string)($op['operation_id']??'');
            $id=preg_match('/^[a-fA-F0-9-]{16,64}$/D',$id)===1?$id:'';
            $at=(string)($op['updated_at_utc']??$op['finished_at_utc']??$op['created_at_utc']??'');
            if(strlen($at)>48||($at!==''&&strtotime($at)===false))$at='';
            $out[]=[
                'operation_id'=>$id,
                'operation_type'=>self::shortCode($op['operation_type']??''),
                'state'=>self::shortCode($op['state']??''),
                'result_code'=>self::shortCode($op['result_code']??''),
                'updated_at_utc'=>$at,
            ];
        }
        return $out;
    }
}
