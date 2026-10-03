<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use Sokna\Local\Domain\Orders\TableDraftException;
use Sokna\Local\Domain\Orders\TableDraftService;
use Throwable;

final class TableDraftRealtimeAdapter
{
    private const KINDS=[
        'table_draft.get',
        'table_draft.create',
        'table_draft.edit',
        'table_draft.finalize',
        'table_draft.cancel',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TableDraftService $drafts,
    ) {}

    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));
        if(!in_array($kind,self::KINDS,true))
            throw new TableDraftException('unsupported_kind','عملیات پیش‌نویس معتبر نیست.',422);

        $projection=trim((string)($envelope['actor_projection_id']??''));
        if(!preg_match('/^user:(\d+)$/',$projection,$match))
            throw new TableDraftException('actor_invalid','هویت کاربر راه‌دور معتبر نیست.',403);
        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];

        $this->pdo->beginTransaction();
        try{
            $actorStmt=$this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE');
            $actorStmt->execute([(int)$match[1]]);
            $actor=$actorStmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($actor)||(int)$actor['active']!==1)
                throw new TableDraftException('actor_invalid','حساب کاربری راه‌دور فعال نیست.',403);

            $result=match($kind){
                'table_draft.get'=>$this->drafts->get((int)($payload['table_id']??0),$actor),
                'table_draft.create'=>$this->drafts->saveTx(['expected_version'=>0]+$payload,$actor),
                'table_draft.edit'=>$this->drafts->saveTx($payload,$actor),
                'table_draft.finalize'=>$this->drafts->finalizeTx($payload,$actor),
                'table_draft.cancel'=>$this->drafts->cancelTx($payload,$actor),
            };
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }
}
