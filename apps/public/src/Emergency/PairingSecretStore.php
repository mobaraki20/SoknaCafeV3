<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Emergency;
use PDO;
final class PairingSecretStore
{
    private string $key;
    public function __construct(private readonly PDO $pdo,string $keyBase64)
    {
        $k=base64_decode(trim($keyBase64),true);if(!is_string($k)||strlen($k)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new PublicUpdateException('pairing_key_invalid','Public pairing encryption key is invalid.',500);$this->key=$k;
    }
    public function secret(string $installationId): string
    {
        $q=$this->pdo->prepare('SELECT secret_ciphertext FROM installation_pairing_secrets WHERE installation_id=? LIMIT 1');$q->execute([$installationId]);$v=$q->fetchColumn();if(!is_string($v)||$v==='')return '';$raw=base64_decode($v,true);if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return '';$nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$cipher=substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$plain=sodium_crypto_secretbox_open($cipher,$nonce,$this->key);return is_string($plain)?$plain:'';
    }
    public function put(string $installationId,string $secret): void
    {
        if(strlen($secret)<32)throw new PublicUpdateException('shared_secret_weak','Pairing secret is too short.');$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$blob=base64_encode($nonce.sodium_crypto_secretbox($secret,$nonce,$this->key));$q=$this->pdo->prepare('INSERT INTO installation_pairing_secrets(installation_id,secret_ciphertext,rotated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE secret_ciphertext=VALUES(secret_ciphertext),rotated_at=UTC_TIMESTAMP()');$q->execute([$installationId,$blob]);
    }
}
