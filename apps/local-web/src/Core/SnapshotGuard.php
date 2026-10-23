<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use Throwable;

final class SnapshotGuard
{
    public function __construct(private readonly Observability $observability) {}

    /**
     * @template T
     * @param T $fallback
     * @param callable():T $loader
     * @return array{value:T,error:?array{code:string,message:string,support_id:string}}
     */
    public function capture(string $workspace,string $section,mixed $fallback,string $message,callable $loader): array
    {
        try {
            return ['value'=>$loader(),'error'=>null];
        } catch (Throwable $e) {
            $correlationId=$this->observability->correlationId();
            $this->observability->logEvent('error','workspace.snapshot_section_failed',[
                'workspace'=>$workspace,
                'section'=>$section,
                'exception'=>get_class($e),
                'message'=>$e->getMessage(),
            ],$correlationId);
            return [
                'value'=>$fallback,
                'error'=>[
                    'code'=>'section_unavailable',
                    'message'=>$message,
                    'support_id'=>substr($correlationId,0,12),
                ],
            ];
        }
    }
}
