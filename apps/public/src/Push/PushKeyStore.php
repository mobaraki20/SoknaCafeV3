<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Push;

use RuntimeException;

final class PushKeyStore
{
    private const FORMAT='sokna-public-vapid-v1';

    public function __construct(private readonly string $storageRoot){}

    /** @return array{private_key_pem:string,public_key:string,created_at:string} */
    public function loadOrCreate(): array
    {
        $path=$this->path();
        if(is_file($path)){
            $decoded=json_decode((string)file_get_contents($path),true);
            if(is_array($decoded)&&($decoded['format']??'')===self::FORMAT){
                $pem=base64_decode((string)($decoded['private_key_pem_base64']??''),true);
                $pub=trim((string)($decoded['public_key']??''));
                if(is_string($pem)&&str_contains($pem,'PRIVATE KEY')&&$this->validPublicKey($pub))
                    return ['private_key_pem'=>$pem,'public_key'=>$pub,'created_at'=>(string)($decoded['created_at']??'')];
            }
            throw new RuntimeException('Stored VAPID key material is invalid.');
        }
        if(!extension_loaded('openssl'))throw new RuntimeException('OpenSSL is required for Web Push.');
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if($key===false)throw new RuntimeException('VAPID key generation failed.');
        $pem='';if(!openssl_pkey_export($key,$pem)||$pem==='')throw new RuntimeException('VAPID private key export failed.');
        $details=openssl_pkey_get_details($key);$ec=is_array($details)?($details['ec']??null):null;
        if(!is_array($ec)||!is_string($ec['x']??null)||!is_string($ec['y']??null)||strlen($ec['x'])!==32||strlen($ec['y'])!==32)
            throw new RuntimeException('VAPID public key extraction failed.');
        $public=self::b64url("\x04".$ec['x'].$ec['y']);$created=gmdate('c');
        $this->ensureDir(dirname($path));
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(4));
        $json=json_encode(['format'=>self::FORMAT,'private_key_pem_base64'=>base64_encode($pem),'public_key'=>$public,'created_at'=>$created],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json."\n",LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('VAPID key storage failed.');}
        @chmod($path,0600);
        return ['private_key_pem'=>$pem,'public_key'=>$public,'created_at'=>$created];
    }

    public function publicKey(): string{return $this->loadOrCreate()['public_key'];}

    private function path(): string{return rtrim($this->storageRoot,"\\/").DIRECTORY_SEPARATOR.'secrets'.DIRECTORY_SEPARATOR.'web-push-vapid.json';}
    private function ensureDir(string $dir): void{if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('VAPID secret directory is unavailable.');@chmod($dir,0700);}
    private function validPublicKey(string $value): bool{$raw=self::b64urlDecode($value);return is_string($raw)&&strlen($raw)===65&&$raw[0]==="\x04";}
    public static function b64url(string $raw): string{return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
    public static function b64urlDecode(string $value): string|false{$value=strtr(trim($value),'-_','+/');$value.=str_repeat('=',(4-strlen($value)%4)%4);return base64_decode($value,true);}
}
