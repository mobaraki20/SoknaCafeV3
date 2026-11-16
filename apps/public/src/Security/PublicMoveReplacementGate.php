<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use PDO;
use RuntimeException;
use Sokna\PublicEdge\Core\Bootstrap;

/**
 * R260 Public replacement: NO old Public data import. Require a genuinely
 * unbound, empty destination; after normal Local sync, explicitly open intake.
 * The checks are small and apply only to this replacement path.
 */
final class PublicMoveReplacementGate
{
    public function __construct(private readonly PDO $pdo) {}

    public function requireEmpty(): void
    {
        // Unpaired, newly installed Public cannot own guest orders (all
        // guest/deferred/realtime tables belong to installation rows).
        foreach (['installations','deferred_work','realtime_requests','guest_publish_revisions'] as $table) {
            $count=$this->pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
            if($count===false || (int)$count!==0) {
                throw new RuntimeException('destination_not_empty');
            }
        }
    }

    public function activateAfterVerifiedSync(
        Bootstrap $core,string $installationId,string $method,string $path,
        string $timestamp,string $nonce,string $signature,string $rawBody
    ): array {
        $auth=$core->signedLocalRequests()->verify(
            $installationId,$method,$path,$timestamp,$nonce,$rawBody,$signature
        );
        if(($auth['ok']??false)!==true) {
            return ['status'=>(int)($auth['status']??403),'body'=>[
                'ok'=>false,'error'=>(string)($auth['error']??'not_authorized')]];
        }
        $actual=(string)($auth['installation_id']??'');
        if($actual===''||!hash_equals($installationId,$actual)) {
            return ['status'=>403,'body'=>['ok'=>false,'error'=>'installation_mismatch']];
        }
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare(
                'SELECT installation_id,order_intake_enabled,public_move_intake_paused FROM installations WHERE installation_id=? AND active=1 FOR UPDATE'
            );$q->execute([$actual]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new RuntimeException('installation_not_paired');
            // These are records written by the existing Local publisher.
            $has=function(string $table)use($actual):bool{
                $q=$this->pdo->prepare('SELECT 1 FROM '.$table.' WHERE installation_id=? LIMIT 1');
                $q->execute([$actual]);return $q->fetchColumn()!==false;
            };
            if(!$has('guest_active_revisions')||!$has('guest_availability_state')) {
                throw new RuntimeException('first_sync_not_complete');
            }
            if((int)$row['public_move_intake_paused']===0 && (int)$row['order_intake_enabled']===1) {
                $this->pdo->commit();
                return ['status'=>200,'body'=>['ok'=>true,'status'=>'ready','guest_intake_enabled'=>true]];
            }
            if((int)$row['public_move_intake_paused']!==1)
                throw new RuntimeException('no_replacement_intake_gate');
            $q=$this->pdo->prepare(
                'UPDATE installations SET order_intake_enabled=1,public_move_intake_paused=0 WHERE installation_id=? AND public_move_intake_paused=1'
            );$q->execute([$actual]);
            if($q->rowCount()!==1)throw new RuntimeException('guest_activation_race');
            $this->pdo->commit();
            return ['status'=>200,'body'=>['ok'=>true,'status'=>'ready','guest_intake_enabled'=>true]];
        }catch(\Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            return ['status'=>409,'body'=>['ok'=>false,'error'=>'replacement_first_sync_required']];
        }
    }
}
