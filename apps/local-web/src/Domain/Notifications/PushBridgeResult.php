<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Notifications;
final class PushBridgeResult
{
    /** @return array{accepted:array,gone:array,transient:array,fatal:array,complete:bool,raw:array} */
    public static function parse(string $body,array $subscriptions): array
    {
        try{$json=json_decode($body,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new NotificationException('push_bridge_invalid_response','Push bridge response is not valid JSON.',503);}
        $rows=is_array($json['results']??null)?$json['results']:[];
        if($rows===[])throw new NotificationException('push_bridge_invalid_response','Push bridge did not return per-subscription results.',503);
        $expected=[];foreach($subscriptions as $s){$endpoint=trim((string)($s['endpoint_url']??''));if($endpoint!=='')$expected[hash('sha256',$endpoint)]=$endpoint;}
        $seen=[];$accepted=[];$gone=[];$transient=[];$fatal=[];
        foreach($rows as $r){if(!is_array($r))continue;$hash=strtolower(trim((string)($r['endpoint_hash']??'')));if($hash===''&&trim((string)($r['endpoint']??''))!=='')$hash=hash('sha256',trim((string)$r['endpoint']));if(!isset($expected[$hash])||isset($seen[$hash]))continue;$seen[$hash]=true;$code=(int)($r['status_code']??$r['status']??0);$state=strtolower(trim((string)($r['state']??'')));
            if(($code>=200&&$code<300)||in_array($state,['accepted','ok','sent','server_accepted'],true))$accepted[]=$hash;
            elseif(in_array($code,[404,410],true)||in_array($state,['gone','expired','unsubscribed'],true))$gone[]=$hash;
            elseif(in_array($code,[408,425,429],true)||$code>=500||in_array($state,['retry','transient','pending'],true))$transient[]=$hash;
            else $fatal[]=$hash;
        }
        foreach(array_keys($expected) as $hash)if(!isset($seen[$hash]))$transient[]=$hash;
        return ['accepted'=>$accepted,'gone'=>$gone,'transient'=>$transient,'fatal'=>$fatal,'complete'=>count($seen)===count($expected),'raw'=>$json];
    }
}
