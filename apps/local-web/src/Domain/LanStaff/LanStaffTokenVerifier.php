<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\LanStaff;
use RuntimeException;
final class LanStaffTokenVerifier
{
    public function __construct(private readonly string $sharedSecret,private readonly string $installationId){}
    public function verify(string $token): array
    {
        $parts=explode('.',trim($token));
        if(count($parts)!==3||$parts[0]!=='v1')throw new RuntimeException('lan_token_invalid');
        $payloadRaw=self::decode($parts[1]);$signature=self::decode($parts[2]);
        $expected=hash_hmac('sha256','v1.'.$parts[1],$this->sharedSecret,true);
        if(!hash_equals($expected,$signature))throw new RuntimeException('lan_token_invalid');
        $payload=json_decode($payloadRaw,true);
        if(!is_array($payload)||($payload['format']??'')!=='sokna-lan-staff-token-v1'||($payload['aud']??'')!=='sokna-local-lan-staff')throw new RuntimeException('lan_token_invalid');
        $now=time();$iat=(int)($payload['iat']??0);$exp=(int)($payload['exp']??0);
        if($iat<1||$iat>$now+300||$exp<=$now||$exp>$iat+43200)throw new RuntimeException('lan_token_expired');
        if(!hash_equals($this->installationId,(string)($payload['installation_id']??'')))throw new RuntimeException('lan_token_wrong_installation');
        $projection=trim((string)($payload['projection_id']??''));
        if(!preg_match('/^user:[1-9][0-9]*$/D',$projection))throw new RuntimeException('lan_token_invalid');
        return $payload;
    }
    private static function decode(string $value): string
    {
        $pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        if(!is_string($raw))throw new RuntimeException('lan_token_invalid');return $raw;
    }
}
