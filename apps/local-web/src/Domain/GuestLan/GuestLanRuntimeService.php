<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\GuestLan;

use Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder;

/**
 * Local-only Guest view adapter. It exposes the same shape consumed by the
 * Public GuestPageRenderer while reading only canonical Local state.
 */
final class GuestLanRuntimeService
{
    public function __construct(
        private readonly PublicProjectionBuilder $builder,
        private readonly string $installationId,
    ) {}

    public function bundle(string $installationId): array
    {
        $installationId=trim($installationId);
        if($installationId===''||!hash_equals(trim($this->installationId),$installationId))return [];
        $publish=$this->builder->guestPublish();
        $snapshot=is_array($publish['snapshot']??null)?$publish['snapshot']:[];
        if($snapshot===[])return [];
        $availability=$this->builder->availability();
        $generated=trim((string)($availability['generated_at']??''));if($generated==='')$generated=gmdate('c');
        $acceptance=is_array($availability['order_acceptance']??null)?$availability['order_acceptance']:[];
        return [
            'installation_id'=>$installationId,
            'display_name'=>(string)($snapshot['cafe_name']??'SOKNA'),
            'active'=>true,
            'remote_enabled'=>true,
            'order_intake_enabled'=>(bool)($acceptance['cafe']??true),
            'revision_id'=>(string)($publish['revision_id']??''),
            'generated_at'=>(string)($publish['generated_at']??$generated),
            'snapshot'=>$snapshot,
            'media_manifest'=>is_array($publish['media_manifest']??null)?$publish['media_manifest']:[],
            'availability'=>$availability,
            'last_sync_at'=>$generated,
            'heartbeat_at'=>$generated,
        ];
    }

    public function actionState(array $bundle,int $liveFreshSeconds=45,int $projectionFreshSeconds=90): array
    {
        $enabled=$bundle!==[]&&(bool)($bundle['remote_enabled']??true)&&(bool)($bundle['order_intake_enabled']??false);
        return [
            'enabled'=>$enabled,
            'remote_enabled'=>true,
            'order_intake_enabled'=>(bool)($bundle['order_intake_enabled']??false),
            'command_ready'=>true,
            'heartbeat_fresh'=>true,
            'model_fresh'=>true,
            'local_fresh'=>true,
            'availability_fresh'=>true,
            'heartbeat_at'=>(string)($bundle['heartbeat_at']??gmdate('c')),
            'last_sync_at'=>(string)($bundle['last_sync_at']??gmdate('c')),
        ];
    }
}
