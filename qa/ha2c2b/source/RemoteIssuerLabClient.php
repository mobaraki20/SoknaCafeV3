<?php
declare(strict_types=1);
require_once __DIR__.'/IssuerProtocol.php';
final class RemoteIssuerLabClient {
    public function __construct(private string $base, private string $token,private string $pinnedPem,private string $pin) {
        if(PHP_SAPI!=='cli'||getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION')throw new RuntimeException('LAB_ONLY');
        if(!preg_match('#^http://issuer:808[01]$#D',$base)||strlen($token)<32||!preg_match('/^[a-f0-9]{64}$/D',$pin)||!hash_equals(hash('sha256',$pinnedPem),$pin))throw new RuntimeException('UNTRUSTED_ISSUER_BINDING');
        $key=openssl_pkey_get_public($pinnedPem);$details=$key?openssl_pkey_get_details($key):false;if(!is_array($details)||($details['type']??-1)!==OPENSSL_KEYTYPE_EC||($details['ec']['curve_name']??'')!=='prime256v1')throw new RuntimeException('BAD_KEY_CURVE');
    }
    public function read(string $installation,int $expectedEpoch,string $release):array {return $this->doRequest('/floor',['installation_id'=>$installation,'nonce'=>bin2hex(random_bytes(16))],$installation,$expectedEpoch,$release);}
    public function advance(string $installation,int $expected,int $next,string $release):array {return $this->doRequest('/advance',['installation_id'=>$installation,'expected'=>$expected,'next'=>$next,'release_set_sha256'=>$release,'nonce'=>bin2hex(random_bytes(16))],$installation,$next,$release);}
    private function doRequest(string $route,array $body,string $install,int $expectedEpoch,string $release):array {
        try {
            if(!preg_match('/^[a-f0-9]{32}$/D',$install)||!preg_match('/^[a-f0-9]{64}$/D',$release)||$expectedEpoch<1)throw new RuntimeException('BAD_CONTEXT');
            $options=['http'=>['method'=>'POST','header'=>'Content-Type: application/json\r\nAuthorization: Bearer '.$this->token.'\r\nConnection: close\r\n','content'=>json_encode($body,JSON_THROW_ON_ERROR),'timeout'=>3,'ignore_errors'=>true]];
            // use actual CRLF in headers (a literal backslash here is not valid HTTP).
            $options['http']['header']=str_replace('\\r\\n',"\r\n",$options['http']['header']);
            $context=stream_context_create($options);$raw=@file_get_contents($this->base.$route,false,$context);
            if(!is_string($raw)||strlen($raw)>8192||!str_starts_with((string)($http_response_header[0]??''),'HTTP/1.1 200'))throw new RuntimeException('REMOTE_UNAVAILABLE_OR_REJECTED');
            $obj=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($obj)||array_keys($obj)!==['status','payload','signature','key_id','execution_authorized','for_install']||$obj['status']!=='SIGNED_LAB_FLOOR_NOT_COMMIT'||$obj['execution_authorized']!==false||$obj['for_install']!==false||!hash_equals($this->pin,$obj['key_id']))throw new RuntimeException('UNTRUSTED_REPLY');
            $payload=$obj['payload'];$signed=IssuerProtocol::bytes($payload);
            if($payload['installation_id']!==$install||$payload['epoch']!==$expectedEpoch||$payload['release_set_sha256']!==$release||!hash_equals($payload['nonce'],$body['nonce']))throw new RuntimeException('RESPONSE_IDENTITY_OR_FLOOR');
            $now=time();if($payload['issued_at']>$now+1||$payload['expires_at']<$now||$payload['expires_at']-$payload['issued_at']>60)throw new RuntimeException('EXPIRED');
            $binary=base64_decode($obj['signature'],true);if($binary===false||strlen($binary)<64||strlen($binary)>256||openssl_verify($signed,$binary,$this->pinnedPem,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('BAD_SIGNATURE');
            return ['status'=>'MATCHED_ISSUER_LAB_NOT_COMMIT','epoch'=>$payload['epoch'],'revision'=>$payload['revision'],'key_id'=>$obj['key_id'],'execution_authorized'=>false,'for_install'=>false,'commit_proven'=>false];
        }catch(Throwable $e){return IssuerProtocol::blocked($e->getMessage());}
    }
}
