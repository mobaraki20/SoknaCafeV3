<?php
declare(strict_types=1);
/** Lab-only wire contract; deliberately cannot authorize installation. */
final class IssuerProtocol {
    private const PREFIX="SOKNA-HA2C2B-SEPARATE-ISSUER-LAB-V1\n";
    private const KEYS=['installation_id','epoch','revision','release_set_sha256','issued_at','expires_at','nonce'];
    public static function bytes(array $p): string {
        if(array_keys($p)!==self::KEYS || !is_string($p['installation_id']) || !preg_match('/^[a-f0-9]{32}$/D',$p['installation_id']) || !is_int($p['epoch']) || $p['epoch']<1 || !is_int($p['revision']) || $p['revision']<1 || !is_string($p['release_set_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$p['release_set_sha256']) || !is_int($p['issued_at']) || !is_int($p['expires_at']) || $p['expires_at']<=$p['issued_at'] || $p['expires_at']-$p['issued_at']>60 || !is_string($p['nonce']) || !preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])) throw new RuntimeException('BAD_SIGNED_PAYLOAD');
        return self::PREFIX.json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    public static function blocked(string $why): array {return ['status'=>'BLOCKED','reason'=>$why,'floor_matched'=>false,'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];}
}
