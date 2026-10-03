<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use Sokna\Local\Domain\Preparation\PreparationException;
use Sokna\Local\Domain\Preparation\PreparationService;
use Throwable;

final class PreparationRealtimeAdapter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PreparationService $preparation,
    ) {}

    public function dispatch(array $envelope): array
    {
        if(trim((string)($envelope['kind']??''))!=='preparation.mutate')
            throw new PreparationException('unsupported_kind','عملیات آماده‌سازی معتبر نیست.',422);

        $projection=trim((string)($envelope['actor_projection_id']??''));
        if(!preg_match('/^user:(\d+)$/',$projection,$match))
            throw new PreparationException('actor_invalid','هویت کاربر راه‌دور معتبر نیست.',403);

        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        $action=trim((string)($payload['action']??''));
        if($action!=='claim_order_area')
            throw new PreparationException('unsupported_action','عملیات آماده‌سازی پشتیبانی نمی‌شود.',422);

        $this->pdo->beginTransaction();
        try{
            $actorStmt=$this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE');
            $actorStmt->execute([(int)$match[1]]);
            $actor=$actorStmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($actor)||(int)$actor['active']!==1)
                throw new PreparationException('actor_invalid','حساب کاربری راه‌دور فعال نیست.',403);

            $result=$this->preparation->claimTx($payload,$actor);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
