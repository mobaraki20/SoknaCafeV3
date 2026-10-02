<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Push;

use RuntimeException;

final class WebPushService
{
    private const MAX_SUBSCRIPTIONS=100;
    private const MAX_PAYLOAD_BYTES=3000;
    private const ALLOWED_HOST_SUFFIXES=['googleapis.com','push.services.mozilla.com','push.apple.com','notify.windows.com'];
    /** @var null|callable */ private $transport;

    public function __construct(private readonly PushKeyStore $keys,private readonly string $subject,?callable $transport=null){$this->transport=$transport;}

    public function publicConfig(): array{return ['vapid_public_key'=>$this->keys->publicKey(),'bridge_configured'=>true];}

    public function deliver(array $notification,array $subscriptions,string $idempotencyKey=''): array
    {
        if($subscriptions===[]||count($subscriptions)>self::MAX_SUBSCRIPTIONS)throw new RuntimeException('Push subscription count is invalid.');
        $title=$this->bounded($notification['title']??'',180);$body=$this->bounded($notification['body']??'',600);$url=trim((string)($notification['url']??'/'));
        if($url===''||!str_starts_with($url,'/'))$url='/';
        $payload=['title'=>$title,'body'=>$body,'url'=>$url,'tag'=>$idempotencyKey!==''?substr(hash('sha256',$idempotencyKey),0,32):''];
        $delivered=0;$gone=[];$failures=[];
        foreach($subscriptions as $subscription){
            if(!is_array($subscription))continue;
            $request=$this->buildRequest($subscription,$payload,$idempotencyKey);
            $response=$this->send($request['endpoint'],$request['headers'],$request['body']);$status=(int)($response['status']??0);
            if($status>=200&&$status<300){$delivered++;continue;}
            $hash=hash('sha256',(string)$subscription['endpoint']);
            if(in_array($status,[404,410],true)){$gone[]=$hash;continue;}
            $failures[]=['endpoint_hash'=>$hash,'status'=>$status];
        }
        if($failures!==[])throw new RuntimeException('Web Push delivery has transient or configuration failures.');
        return ['ok'=>true,'delivered'=>$delivered,'gone_endpoint_hashes'=>array_values(array_unique($gone)),'attempted'=>count($subscriptions)];
    }

    /** @return array{endpoint:string,headers:array<string,string>,body:string,payload:array} */
    public function buildRequest(array $subscription,array $payload,string $idempotencyKey=''): array
    {
        $endpoint=$this->validEndpoint((string)($subscription['endpoint']??''));$keys=is_array($subscription['keys']??null)?$subscription['keys']:[];
        $clientPublic=PushKeyStore::b64urlDecode((string)($keys['p256dh']??''));$auth=PushKeyStore::b64urlDecode((string)($keys['auth']??''));
        if(!is_string($clientPublic)||strlen($clientPublic)!==65||$clientPublic[0]!=="\x04"||!is_string($auth)||strlen($auth)<16)throw new RuntimeException('Push subscription key material is invalid.');
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>self::MAX_PAYLOAD_BYTES)throw new RuntimeException('Push payload is too large.');
        $encrypted=$this->encrypt($clientPublic,$auth,$json);
        $keyMaterial=$this->keys->loadOrCreate();$jwt=$this->vapidJwt($endpoint,$keyMaterial['private_key_pem']);
        $headers=[
            'Content-Type'=>'application/octet-stream','Content-Encoding'=>'aes128gcm','TTL'=>'300',
            'Authorization'=>'vapid t='.$jwt.', k='.$keyMaterial['public_key'],'Content-Length'=>(string)strlen($encrypted),
        ];
        if($idempotencyKey!=='')$headers['Topic']=substr(PushKeyStore::b64url(hash('sha256',$idempotencyKey,true)),0,32);
        return ['endpoint'=>$endpoint,'headers'=>$headers,'body'=>$encrypted,'payload'=>$payload];
    }

    private function encrypt(string $clientPublic,string $auth,string $json): string
    {
        $clientPem=$this->rawPublicToPem($clientPublic);$clientKey=openssl_pkey_get_public($clientPem);if($clientKey===false)throw new RuntimeException('Push client public key is invalid.');
        $ephemeral=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if($ephemeral===false)throw new RuntimeException('Push ephemeral key generation failed.');
        $details=openssl_pkey_get_details($ephemeral);$ec=is_array($details)?($details['ec']??null):null;if(!is_array($ec)||!is_string($ec['x']??null)||!is_string($ec['y']??null))throw new RuntimeException('Push ephemeral key details unavailable.');
        $serverPublic="\x04".$ec['x'].$ec['y'];$shared=openssl_pkey_derive($clientKey,$ephemeral,32);if(!is_string($shared)||strlen($shared)!==32)throw new RuntimeException('Push ECDH derivation failed.');
        $prkKey=$this->hkdfExtract($auth,$shared);$info="WebPush: info\0".$clientPublic.$serverPublic;$ikm=$this->hkdfExpand($prkKey,$info,32);
        $salt=random_bytes(16);$prk=$this->hkdfExtract($salt,$ikm);$cek=$this->hkdfExpand($prk,"Content-Encoding: aes128gcm\0",16);$nonce=$this->hkdfExpand($prk,"Content-Encoding: nonce\0",12);
        $tag='';$cipher=openssl_encrypt($json."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Push payload encryption failed.');
        return $salt.pack('N',4096).chr(strlen($serverPublic)).$serverPublic.$cipher.$tag;
    }

    private function vapidJwt(string $endpoint,string $privatePem): string
    {
        $parts=parse_url($endpoint);if(!is_array($parts))throw new RuntimeException('Push endpoint is invalid.');$aud=strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']).(isset($parts['port'])?':'.(int)$parts['port']:'');
        $subject=trim($this->subject);if($subject===''||(!str_starts_with($subject,'https://')&&!str_starts_with($subject,'mailto:')))throw new RuntimeException('VAPID subject is invalid.');
        $h=PushKeyStore::b64url(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_THROW_ON_ERROR));$p=PushKeyStore::b64url(json_encode(['aud'=>$aud,'exp'=>time()+43200,'sub'=>$subject],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$input=$h.'.'.$p;
        $der='';if(!openssl_sign($input,$der,$privatePem,OPENSSL_ALGO_SHA256))throw new RuntimeException('VAPID signing failed.');$raw=$this->derToJose($der);return $input.'.'.PushKeyStore::b64url($raw);
    }

    private function validEndpoint(string $endpoint): string
    {
        $endpoint=trim($endpoint);$p=parse_url($endpoint);if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))throw new RuntimeException('Push endpoint is invalid.');
        $host=strtolower(trim((string)($p['host']??''),'.'));if($host===''||filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Push endpoint host is not allowed.');
        $ok=false;foreach(self::ALLOWED_HOST_SUFFIXES as $suffix)if($host===$suffix||str_ends_with($host,'.'.$suffix)){$ok=true;break;}if(!$ok)throw new RuntimeException('Push endpoint provider is not allowed.');return $endpoint;
    }
    private function send(string $endpoint,array $headers,string $body): array
    {
        if(is_callable($this->transport))return ($this->transport)($endpoint,$headers,$body);
        $lines=[];foreach($headers as $k=>$v)$lines[]=$k.': '.$v;$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$lines),'content'=>$body,'timeout'=>10,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);$raw=@file_get_contents($endpoint,false,$ctx);$status=0;foreach((array)($http_response_header??[]) as $line)if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m)){$status=(int)$m[1];break;}return ['status'=>$status,'body'=>$raw===false?'':$raw];
    }
    private function rawPublicToPem(string $raw): string{$der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";}
    private function hkdfExtract(string $salt,string $ikm): string{return hash_hmac('sha256',$ikm,$salt,true);}
    private function hkdfExpand(string $prk,string $info,int $len): string{$out='';$t='';for($i=1;strlen($out)<$len;$i++){$t=hash_hmac('sha256',$t.$info.chr($i),$prk,true);$out.=$t;}return substr($out,0,$len);}
    private function derToJose(string $der): string
    {
        $i=0;if(ord($der[$i++]??"\0")!==0x30)throw new RuntimeException('Invalid ECDSA signature.');$this->asnLen($der,$i);if(ord($der[$i++]??"\0")!==0x02)throw new RuntimeException('Invalid ECDSA R.');$rl=$this->asnLen($der,$i);$r=substr($der,$i,$rl);$i+=$rl;if(ord($der[$i++]??"\0")!==0x02)throw new RuntimeException('Invalid ECDSA S.');$sl=$this->asnLen($der,$i);$s=substr($der,$i,$sl);$r=ltrim($r,"\0");$s=ltrim($s,"\0");if(strlen($r)>32||strlen($s)>32)throw new RuntimeException('ECDSA signature size invalid.');return str_pad($r,32,"\0",STR_PAD_LEFT).str_pad($s,32,"\0",STR_PAD_LEFT);
    }
    private function asnLen(string $der,int &$i): int{$b=ord($der[$i++]??"\0");if(($b&0x80)===0)return $b;$n=$b&0x7f;if($n<1||$n>2)throw new RuntimeException('Unsupported ASN.1 length.');$v=0;for($j=0;$j<$n;$j++)$v=($v<<8)|ord($der[$i++]??"\0");return $v;}
    private function bounded(mixed $v,int $max): string{$s=trim((string)$v);if($s===''||strlen($s)>$max*4)throw new RuntimeException('Push notification text is invalid.');return $s;}
}
