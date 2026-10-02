<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Emergency;

use PDO;
use Throwable;

final class PublicInitialPairingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PairingSecretStore $secrets,
        private readonly EmergencyAccessService $access,
        private readonly string $pairingCode,
    ) {}

    public function complete(string $installationId,string $code,string $newSecret,string $displayName=''): array
    {
        $this->validId($installationId);
        $expected=trim($this->pairingCode);$code=trim($code);$displayName=trim($displayName);
        if($expected===''||str_starts_with($expected,'CHANGE_ME_')||strlen($expected)<16)
            throw new PublicUpdateException('initial_pairing_not_configured','Initial Public pairing code is not configured.',409);
        if($code===''||!hash_equals($expected,$code))
            throw new PublicUpdateException('initial_pairing_denied','Initial pairing code is invalid.',401);
        if(strlen($newSecret)<32)
            throw new PublicUpdateException('shared_secret_weak','Pairing secret is too short.',422);

        $this->pdo->beginTransaction();
        try{
            $active=$this->pdo->query("SELECT installation_id FROM installations WHERE active=1 AND revoked_at IS NULL LIMIT 1 FOR UPDATE")->fetchColumn();
            if(is_string($active)&&$active!=='')
                throw new PublicUpdateException('initial_pairing_closed','Initial pairing is already complete.',409);

            $q=$this->pdo->prepare(
                'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled,revoked_at) VALUES(?,?,1,1,1,NULL)'
            );
            $q->execute([$installationId,$displayName!==''?$displayName:null]);
            $this->secrets->put($installationId,$newSecret);
            $this->pdo->commit();
            $this->access->audit('initial_pairing_completed',$installationId,'initial_pairing',[]);
            return ['ok'=>true,'installation_id'=>$installationId,'paired'=>true];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function validId(string $id): void
    {
        if(preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$id)!==1)
            throw new PublicUpdateException('invalid_installation','Installation identity is invalid.',422);
    }
}
