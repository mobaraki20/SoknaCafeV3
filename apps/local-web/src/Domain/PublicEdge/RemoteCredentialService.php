<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use PDO;
use Throwable;

final class RemoteCredentialService
{
    public function __construct(private readonly PDO $pdo){}

    public function syncFromPassword(int $userId,string $password): void
    {
        if($userId<1||$password==='')return;
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $q=$this->pdo->prepare(
            'INSERT INTO remote_user_credentials(user_id,password_hash,credential_version,source_mode) VALUES(?,?,1,\'local_password\') '
            .'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),credential_version=credential_version+1,source_mode=\'local_password\',updated_at=CURRENT_TIMESTAMP'
        );
        $q->execute([$userId,$hash]);
    }

    public function ensureMatchesVerifiedPassword(int $userId,string $password): bool
    {
        if($userId<1||$password==='')return false;
        try{
            $q=$this->pdo->prepare("SELECT password_hash FROM remote_user_credentials WHERE source_mode='local_password' AND user_id=? LIMIT 1");
            $q->execute([$userId]);$current=trim((string)($q->fetchColumn()?:''));
            if($current!==''&&password_verify($password,$current))return false;
            $this->syncFromPassword($userId,$password);
            return true;
        }catch(Throwable){
            // Local authentication remains authoritative. A transient projection-write
            // failure must not block a valid local login; the next login/password change retries.
            return false;
        }
    }
}
