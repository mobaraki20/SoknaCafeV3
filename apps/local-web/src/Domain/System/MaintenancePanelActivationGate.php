<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\System;

/**
 * Read-only precondition before forwarding a privileged Local activation.
 * Supervisor must revalidate the signed Stage/slot under its machine lease.
 * Never interpret LocalUpdateService's legacy staged ZIP as a Stage receipt.
 */
final class MaintenancePanelActivationGate
{
    /**
     * Check an exact signed Supervisor GET /v1/operations/{id} response.
     * Never accept a browser-provided stage receipt or recent-history window.
     */
    public static function exactStageVerified(array $operations, array $receipt, string $stageId, string $actor): bool
    {
        if (preg_match('/^[a-fA-F0-9]{32}$/D',$stageId)!==1
            || ($operations['ok']??false)!==true
            || ($operations['requires_recovery']??true)!==false
            || (int)($operations['active_count']??-1)!==0
            || (int)($operations['blocking_count']??-1)!==0
            || ($receipt['ok']??false)!==true
            || !is_array($receipt['data']??null)) return false;
        $stage=$receipt['data'];
        return hash_equals(strtolower($stageId),strtolower((string)($stage['operation_id']??'')))
            && ($stage['operation_type']??'')==='update.local-stage'
            && ($stage['state']??'')==='staged'
            && ($stage['operator_id']??'')===$actor
            && preg_match('/^[a-zA-Z0-9._:-]{1,160}$/D',(string)($stage['package_ref']??''))===1;
    }

    /**
     * Classify the *signed Supervisor response* from Activate without
     * confusing HTTP 202 acceptance with an installed/committed release.
     * The browser may poll the same durable operation but must never retry
     * POST on an ambiguous response.
     *
     * @return 'pending'|'committed'|'invalid'
     */
    public static function classifyActivationResponse(array $response, string $stageId, string $actor): string
    {
        if (($response['ok']??false)!==true ||
            preg_match('/^[a-fA-F0-9]{32}$/D',$stageId)!==1 ||
            preg_match('/^user:[0-9]{1,16}$/D',$actor)!==1) return 'invalid';
        $data=is_array($response['data']??null)?$response['data']:[];
        $op=is_array($data['operation']??null)?$data['operation']:[];
        if (preg_match('/^[a-fA-F0-9]{32}$/D',(string)($op['operation_id']??''))!==1 ||
            ($op['operation_type']??'')!=='update.local-code-only-activate' ||
            ($op['operator_id']??'')!==$actor ||
            !hash_equals(strtolower($stageId),strtolower((string)($op['package_ref']??''))) ||
            !is_bool($data['database_mutation_performed']??null) ||
            ($data['public_mutation_performed']??null)!==false)
            return 'invalid';
        $state=(string)($op['state']??'');
        $http=(int)($response['http_status']??0);
        if ($http===202 && in_array($state,['accepted','running','activating'],true) &&
            ($data['completion_requires_operation_poll']??null)===true &&
            ($data['activation_committed']??null)===false)
            return 'pending';
        if ($http===200 && $state==='committed' &&
            ($data['activation_committed']??true)===true)
            return 'committed';
        return 'invalid';
    }

    public static function stageVerified(array $operations, string $stageId, string $actor): bool
    {
        if (preg_match('/^[a-fA-F0-9]{32}$/D', $stageId) !== 1
            || ($operations['ok'] ?? false) !== true
            || ($operations['history_available'] ?? false) !== true
            || ($operations['requires_recovery'] ?? true) !== false
            || !isset($operations['active_count'], $operations['blocking_count'])
            || (int)$operations['active_count'] !== 0
            || (int)$operations['blocking_count'] !== 0)
            return false;
        foreach ((array)($operations['recent_operations'] ?? []) as $operation) {
            if (!is_array($operation)) continue;
            if (hash_equals(strtolower($stageId), strtolower((string)($operation['operation_id'] ?? '')))
                && ($operation['operation_type'] ?? '') === 'update.local-stage'
                && ($operation['state'] ?? '') === 'staged'
                && ($operation['operator_id'] ?? '') === $actor)
                return true;
        }
        return false;
    }
}
