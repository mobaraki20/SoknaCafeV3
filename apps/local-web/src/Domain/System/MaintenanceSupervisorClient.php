<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use Throwable;

/**
 * Server-side, signed adapter for SOKNA Maintenance Supervisor protocol 1.0.
 *
 * The browser never receives the machine HMAC key. Discovery and authentication
 * material stay under Windows-owned ProgramData. Descriptor discovery accepts
 * historical P1 read-only and signed newer Supervisor capabilities; actual
 * update permissions still require authenticated capability and host gates.
 */
final class MaintenanceSupervisorClient
{
    private const PROTOCOL = '1.0';
    private const AUTH_SCHEME = 'sokna-hmac-sha256-v1';
    private const KEY_ID = 'web-adapter';
    private const DESCRIPTOR_FORMAT = 'sokna-maintenance-control-plane-v1';
    private const MAX_RESPONSE_BYTES = 262144;

    /** @var null|callable */
    private $transport;

    public function __construct(?string $maintenanceRoot = null, ?callable $transport = null)
    {
        $this->maintenanceRoot = $maintenanceRoot !== null
            ? rtrim($maintenanceRoot, "\\/")
            : $this->defaultMaintenanceRoot();
        $this->transport = $transport;
    }

    private readonly string $maintenanceRoot;

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        try {
            $discovery = $this->discovery();
            if (($discovery['ok'] ?? false) !== true) {
                return $this->baseResult((string)($discovery['status'] ?? 'unavailable'), (string)($discovery['error_code'] ?? 'maintenance_discovery_failed'));
            }

            $descriptor = $discovery['descriptor'];
            $keyResult = $this->loadKey();
            if (($keyResult['ok'] ?? false) !== true) {
                return $this->baseResult((string)$keyResult['status'], (string)$keyResult['error_code'], $this->publicDescriptor($descriptor));
            }
            $key = $keyResult['key'];

            $health = $this->signedGet($descriptor, '/v1/health', $key);
            if (($health['ok'] ?? false) !== true) {
                return $this->baseResult($this->statusFromResponse($health), (string)($health['error_code'] ?? 'maintenance_health_failed'), $this->publicDescriptor($descriptor), ['health' => $health]);
            }

            $routes = [
                'status' => '/v1/status',
                'components' => '/v1/components',
                'active_operation' => '/v1/operations/active',
                'backups' => '/v1/backups',
                'recovery' => '/v1/recovery',
                'journal' => '/v1/journal/head',
            ];
            $parts = ['health' => $health];
            $degraded = false;
            foreach ($routes as $name => $path) {
                $parts[$name] = $this->signedGet($descriptor, $path, $key);
                if (($parts[$name]['ok'] ?? false) !== true) $degraded = true;
            }

            $healthData = is_array($health['data'] ?? null) ? $health['data'] : [];
            $descriptorPublic = $this->publicDescriptor($descriptor);
            $identityMismatch = isset($healthData['instance_id']) && (string)$healthData['instance_id'] !== (string)($descriptor['instance_id'] ?? '');
            $generationMismatch = isset($healthData['install_generation']) && (int)$healthData['install_generation'] !== (int)($descriptor['install_generation'] ?? 0);
            if ($identityMismatch || $generationMismatch) {
                return $this->baseResult('identity_mismatch', 'maintenance_descriptor_identity_mismatch', $descriptorPublic, $parts);
            }

            return [
                'status' => $degraded ? 'degraded' : 'ready',
                'error_code' => '',
                'integration_ready' => !$degraded,
                'protocol' => self::PROTOCOL,
                'read_only_p1' => (bool)($descriptor['read_only_p1'] ?? true),
                'descriptor' => $descriptorPublic,
                'health' => $this->dataOnly($parts['health']),
                'supervisor_status' => $this->dataOnly($parts['status']),
                'components' => $this->dataOnly($parts['components']),
                'active_operation' => $this->dataOnly($parts['active_operation']),
                'backups' => $this->dataOnly($parts['backups']),
                'recovery' => $this->dataOnly($parts['recovery']),
                'journal' => $this->dataOnly($parts['journal']),
                'route_results' => $this->routeSummaries($parts),
            ];
        } catch (Throwable) {
            return $this->baseResult('error', 'maintenance_adapter_failed');
        }
    }

    /**
     * Privacy-safe read-only connection evidence for the Local admin panel.
     * No filesystem paths, URLs, tokens, HMAC values, raw HTTP bodies, or
     * machine-bound identifiers leave this adapter.
     * A running Windows service/port is NOT proof of HTTP/API readiness.
     * @return array<string,mixed>
     */
    public function connectionDiagnostics(): array
    {
        $result = [
            'format' => 'sokna-maintenance-connection-diagnostics-v1',
            'checked_at_utc' => gmdate('c'),
            'discovery' => 'unverified',
            'web_adapter_key' => 'unverified',
            'signed_health' => 'unverified',
            'http_status' => null,
            'error_code' => 'MAINTENANCE_NOT_CHECKED',
            'authenticated' => false,
            'update_ready' => false,
        ];
        try {
            $discovery = $this->discovery();
            if (($discovery['ok'] ?? false) !== true) {
                $result['discovery'] = 'failed';
                $result['error_code'] = (string)($discovery['error_code'] ?? 'maintenance_discovery_failed');
                return $result;
            }
            $result['discovery'] = 'valid';
            $key = $this->loadKey();
            if (($key['ok'] ?? false) !== true) {
                $result['web_adapter_key'] = 'unavailable';
                $result['error_code'] = (string)($key['error_code'] ?? 'maintenance_web_adapter_key_unavailable');
                return $result;
            }
            $result['web_adapter_key'] = 'available';
            $health = $this->signedGet($discovery['descriptor'], '/v1/health', $key['key']);
            $http = (int)($health['http_status'] ?? 0);
            $result['http_status'] = $http > 0 ? $http : null;
            if (($health['ok'] ?? false) !== true) {
                $result['signed_health'] = 'failed';
                $result['error_code'] = (string)($health['error_code'] ?? 'maintenance_health_failed');
                return $result;
            }
            $healthData = is_array($health['data'] ?? null) ? $health['data'] : [];
            if ((isset($healthData['instance_id']) &&
                    (string)$healthData['instance_id'] !== (string)($discovery['descriptor']['instance_id'] ?? '')) ||
                (isset($healthData['install_generation']) &&
                    (int)$healthData['install_generation'] !== (int)($discovery['descriptor']['install_generation'] ?? 0))) {
                $result['signed_health'] = 'identity_mismatch';
                $result['error_code'] = 'maintenance_descriptor_identity_mismatch';
                return $result;
            }
            $result['signed_health'] = 'valid';
            $result['authenticated'] = true;
            $cap = $this->updateCapabilities();
            $result['update_ready'] = ($cap['ready'] ?? false) === true;
            $result['verification_ready'] = ($cap['verification_ready'] ?? false) === true;
            $result['capability_state'] = (string)($cap['state'] ?? 'unknown');
            $result['error_code'] = $result['update_ready'] ? 'OK'
                : (string)($cap['code'] ?? 'MAINTENANCE_CAPABILITY_NOT_READY');
            return $result;
        } catch (Throwable) {
            $result['error_code'] = 'MAINTENANCE_CONNECTION_PROBE_FAILED';
            return $result;
        }
    }

    /**
     * Read-only, signed capability negotiation. Older Supervisors must NOT be
     * interpreted as update-ready; their 404 means foundation upgrade required.
     * @return array<string,mixed>
     */
    public function updateCapabilities(): array
    {
        $discovery = $this->discovery();
        if (($discovery['ok'] ?? false) !== true) {
            return ['ready'=>false, 'state'=>'unavailable', 'code'=>(string)($discovery['error_code'] ?? 'maintenance_discovery_failed')];
        }
        $key = $this->loadKey();
        if (($key['ok'] ?? false) !== true) {
            return ['ready'=>false, 'state'=>'unavailable', 'code'=>(string)($key['error_code'] ?? 'maintenance_key_unavailable')];
        }
        $response = $this->signedGet($discovery['descriptor'], '/v1/updates/capabilities', $key['key']);
        if (($response['ok'] ?? false) !== true) {
            $http = (int)($response['http_status'] ?? 0);
            return ['ready'=>false, 'state'=>$http === 404 ? 'foundation_required' : 'unavailable',
                'code'=>$http === 404 ? 'FOUNDATION_UPDATE_REQUIRED' : (string)($response['error_code'] ?? 'maintenance_capabilities_unavailable')];
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $actions = is_array($data['local_routine'] ?? null) ? $data['local_routine'] : [];
        if (($data['format'] ?? '') !== 'sokna-unified-updater-capabilities-v1'
            || ($data['schema_version'] ?? null) !== 1
            || ($data['auth_contract'] ?? '') !== 'signed_supervisor_hmac_v1'
            || !is_bool($data['panel_update_ready'] ?? null)
            || !is_string($data['blocking_code'] ?? null)) {
            return ['ready'=>false,'state'=>'contract_mismatch','code'=>'CAPABILITIES_CONTRACT_MISMATCH'];
        }
        // Routine Web actions are Preflight, Stage and Activate only. Recovery
        // must remain Recovery-Console-only; requiring recover=true here
        // deadlocks the UI contract or incorrectly grants Web recovery rights.
        foreach (['preflight','stage','activate','recover'] as $action) {
            if (!is_bool($actions[$action] ?? null))
                return ['ready'=>false,'state'=>'contract_mismatch','code'=>'CAPABILITIES_CONTRACT_MISMATCH'];
        }
        if ($actions['recover'] !== false)
            return ['ready'=>false,'state'=>'contract_mismatch','code'=>'RECOVERY_CONSOLE_AUTHORITY_BOUNDARY'];
        // Upload/signature verification/Stage are non-activating operations.
        // Their signed capability is independent of production ACTIVATE approval.
        // Never infer either capability from the mere presence of an endpoint.
        $inbox = ($data['inbox_transport_available']??false)===true;
        $verificationReady = $inbox && $actions['preflight']===true && $actions['stage']===true;
        $ready = $verificationReady && $actions['activate']===true
            && $data['panel_update_ready']===true && $data['blocking_code']==='';
        return ['ready'=>$ready,'verification_ready'=>$verificationReady,
            'state'=>$ready?'ready':($verificationReady?'verification_only':'foundation_required'),
            'code'=>$ready?'OK':((string)$data['blocking_code'] ?: 'FOUNDATION_UPDATE_REQUIRED'),
            'inbox_transport_available'=>$inbox,
            'local_routine'=>[ 'preflight'=>$actions['preflight'] ?? false,
                'stage'=>$actions['stage'] ?? false,'activate'=>$actions['activate'] ?? false,
                'recover'=>$actions['recover'] ?? false ]];
    }

    /**
     * On-demand, read-only durable operation state for the Update Center.
     * Current/blocking operations are NEVER inferred from recent history.
     * No raw key, path, HTTP authority or signer material is returned.
     * @return array<string,mixed>
     */
    public function updateOperations(): array
    {
        $discovery=$this->discovery();
        if (($discovery['ok']??false)!==true)
            return ['ok'=>false,'state'=>'unavailable','code'=>(string)($discovery['error_code']??'MAINTENANCE_DISCOVERY_FAILED')];
        $key=$this->loadKey();
        if (($key['ok']??false)!==true)
            return ['ok'=>false,'state'=>'unavailable','code'=>(string)($key['error_code']??'MAINTENANCE_KEY_UNAVAILABLE')];
        $active=$this->signedGet($discovery['descriptor'],'/v1/operations/active',$key['key']);
        // A corrupt active journal must not be replaced with a false idle state.
        if (($active['ok']??false)!==true)
            return ['ok'=>false,'state'=>'evidence_unavailable','code'=>(string)($active['error_code']??'OPERATION_EVIDENCE_UNAVAILABLE')];
        $recent=$this->signedGet($discovery['descriptor'],'/v1/operations/recent',$key['key']);
        $activeData=(array)($active['data']??[]);
        $historyData=(array)($recent['data']??[]);
        return [
            'ok'=>true,'state'=>(string)($active['state']??'unknown'),
            'active_count'=>(int)($activeData['active_count']??0),
            'blocking_count'=>(int)($activeData['blocking_count']??0),
            'requires_recovery'=>($activeData['requires_recovery']??false)===true,
            'active_operations'=>array_slice((array)($activeData['active_operations']??[]),0,10),
            'blocking_operations'=>array_slice((array)($activeData['blocking_operations']??[]),0,10),
            'history_available'=>($recent['ok']??false)===true,
            'history_error'=>($recent['ok']??false)===true?'':(string)($recent['error_code']??'OPERATION_HISTORY_UNAVAILABLE'),
            'recent_operations'=>($recent['ok']??false)===true?array_slice((array)($historyData['operations']??[]),0,10):[],
        ];
    }

    /**
     * Recover a POST's durable identity after a timeout without repeating POST.
     * Only this fixed allowlisted route is accepted; never include query/path
     * strings supplied unchecked by the browser.
     * @return array<string,mixed>
     */
    public function operationByIdempotency(string $idempotencyKey): array
    {
        if (preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D',$idempotencyKey)!==1)
            return ['ok'=>false,'error_code'=>'IDEMPOTENCY_KEY_INVALID'];
        $discovery=$this->discovery();$key=$this->loadKey();
        if (($discovery['ok']??false)!==true||($key['ok']??false)!==true)
            return ['ok'=>false,'error_code'=>'MAINTENANCE_SIGNING_UNAVAILABLE'];
        return $this->signedGet($discovery['descriptor'],
            '/v1/operations/by-idempotency/'.$idempotencyKey,$key['key']);
    }

    /**
     * Read a single durable Supervisor operation by exact ID, rather than
     * relying on an arbitrary recent-N history window. Never grant authority
     * without Supervisor's own signed receipt and machine-lease proof.
     * @return array<string,mixed>
     */
    public function operationById(string $operationId): array
    {
        if (preg_match('/^[a-fA-F0-9]{32}$/D',$operationId)!==1)
            return ['ok'=>false,'error_code'=>'MAINTENANCE_OPERATION_ID_INVALID'];
        $discovery=$this->discovery();$key=$this->loadKey();
        if (($discovery['ok']??false)!==true||($key['ok']??false)!==true)
            return ['ok'=>false,'error_code'=>'MAINTENANCE_SIGNING_UNAVAILABLE'];
        return $this->signedGet($discovery['descriptor'],
            '/v1/operations/'.strtolower($operationId),$key['key']);
    }

    /**
     * Read-only operator-scoped durable progress. Never disclose the machine
     * paths, idempotency index or the raw Windows operation receipt to Web.
     * This does not grant permission to start or retry an activation.
     * @return array<string,mixed>
     */
    public function ownedOperationStatus(string $idempotencyKey, string $operatorId): array
    {
        if (preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $idempotencyKey) !== 1
            || preg_match('/^user:[0-9]{1,16}$/D', $operatorId) !== 1)
            return ['ok'=>false, 'code'=>'MAINTENANCE_STATUS_REQUEST_INVALID'];
        $reply = $this->operationByIdempotency($idempotencyKey);
        if (($reply['ok']??false) !== true) {
            return ['ok'=>false, 'code'=>(string)($reply['error_code']??'MAINTENANCE_STATUS_UNAVAILABLE')];
        }
        $record = is_array($reply['data']??null)?$reply['data']:[];
        if (($record['operator_id']??'') !== $operatorId)
            return ['ok'=>false,'code'=>'MAINTENANCE_OPERATION_NOT_FOUND'];
        $operationId=(string)($record['operation_id']??'');
        $operationType=(string)($record['operation_type']??'');
        $state=(string)($record['state']??'');
        $allowedTypes=['update.local-package-preflight','update.local-stage','update.local-code-only-activate'];
        $allowedStates=['accepted','running','verified','staged','activating','committed','failed',
            'rejected_pre_mutation','recovery_required','recovering'];
        if (preg_match('/^[a-fA-F0-9]{32}$/D',$operationId) !== 1
            || !in_array($operationType,$allowedTypes,true)
            || !in_array($state,$allowedStates,true))
            return ['ok'=>false,'code'=>'MAINTENANCE_OPERATION_EVIDENCE_INVALID'];
        $resultCode=(string)($record['result_code']??'');
        if ($resultCode!=='' && preg_match('/^[A-Z][A-Z0-9_]{2,80}$/D',$resultCode)!==1)
            $resultCode='MAINTENANCE_RESULT_UNKNOWN';
        $at=(string)($record['updated_at_utc']??'');
        if ($at!=='' && (strlen($at)>48 || strtotime($at)===false)) $at='';
        return ['ok'=>true,'operation'=>[
            'operation_id'=>$operationId,
            'operation_type'=>$operationType,
            'state'=>$state,
            'result_code'=>$resultCode,
            'updated_at_utc'=>$at,
            'requires_recovery_console'=>$state==='recovery_required',
            'terminal'=>in_array($state,['committed','failed','rejected_pre_mutation'],true),
            // Committed means only Supervisor's durable journal reports commit;
            // it is not a live host/DB or final release attestation by the UI.
            'host_install_verified'=>false,
        ]];
    }

    /**
     * Strict future panel operation transport. This method is deliberately
     * The server-side Local API owns RBAC/CSRF. This helper owns only the
     * signed allowlisted Windows protocol; it cannot bypass capabilities.
     * Recovery is NEVER a web-adapter operation (recovery-console key only).
     * Signed Preflight/Stage can run in verification-only mode; the final
     * Activate remains forbidden until all install readiness guards pass.
     * @param array<string,string> $payload
     * @return array<string,mixed>
     */
    public function requestLocalUpdateOperation(string $action, array $payload, string $idempotencyKey): array
    {
        $routeMap = [
            'preflight' => ['/v1/local-updates/preflight', ['operator_id','package_ref']],
            'stage' => ['/v1/local-updates/stage', ['operator_id','preflight_operation_id']],
            'activate' => ['/v1/local-updates/activate-code-only', ['operator_id','stage_operation_id']],
            'prepare_inactive' => ['/v1/local-updates/prepare-inactive', ['operator_id','stage_operation_id']],
            'prepare_slot' => ['/v1/local-updates/prepare-inactive-slot', ['operator_id','stage_operation_id','private_candidate_idempotency_key']],
        ];
        if (!isset($routeMap[$action])) return ['ok'=>false,'error_code'=>'MAINTENANCE_ACTION_NOT_ALLOWED'];
        if (!preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $idempotencyKey)) {
            return ['ok'=>false,'error_code'=>'IDEMPOTENCY_KEY_INVALID'];
        }
        [$path,$keys] = $routeMap[$action];
        if (array_keys($payload) !== $keys) return ['ok'=>false,'error_code'=>'MAINTENANCE_PAYLOAD_SCHEMA_INVALID'];
        foreach ($payload as $key => $value) {
            if (!is_string($value) || $value==='' || strlen($value)>160 ||
                !preg_match('/^[a-zA-Z0-9._:-]+$/D', $value)) {
                return ['ok'=>false,'error_code'=>'MAINTENANCE_PAYLOAD_VALUE_INVALID'];
            }
            if ($key === 'package_ref' && (!str_ends_with(strtolower($value), '.zip') || strlen($value)>128)) {
                return ['ok'=>false,'error_code'=>'MAINTENANCE_PACKAGE_REF_INVALID'];
            }
            if (in_array($key,['preflight_operation_id','stage_operation_id'],true)
                && preg_match('/^[a-fA-F0-9]{32}$/D',$value)!==1) {
                return ['ok'=>false,'error_code'=>'MAINTENANCE_OPERATION_ID_INVALID'];
            }
        }
        $capabilities = $this->updateCapabilities();
        $allowed = in_array($action,['activate','prepare_slot'],true)
            ? ($capabilities['ready'] ?? false) === true
            : ($capabilities['verification_ready'] ?? false) === true;
        if (!$allowed) {
            return ['ok'=>false,'error_code'=>(string)($capabilities['code'] ?? 'FOUNDATION_UPDATE_REQUIRED'),
                'state'=>'blocked','requires_foundation'=>true];
        }
        $discovery = $this->discovery();
        $key = $this->loadKey();
        if (($discovery['ok'] ?? false) !== true || ($key['ok'] ?? false) !== true) {
            return ['ok'=>false,'error_code'=>'MAINTENANCE_SIGNING_UNAVAILABLE'];
        }
        $result = $this->signedJsonPost($discovery['descriptor'],$path,$key['key'],$payload,$idempotencyKey);
        return $this->reconcilePossiblyInterruptedPost($result,$idempotencyKey,$action,$payload);
    }

    /**
     * Transport an untrusted ZIP chunk into the Windows-owned Inbox.
     * This MUST NOT attempt Preflight/Stage/Activate and is not install authority.
     * The Supervisor enforces its own signed HMAC, ACL and machine lease.
     * @return array<string,mixed>
     */
    public function uploadInboxChunk(string $uploadId,string $operatorId,string $sha256,
        int $totalBytes,int $offset,string $rawChunk): array
    {
        if (preg_match('/^[a-fA-F0-9]{32}$/D',$uploadId)!==1 ||
            preg_match('/^user:[0-9]{1,16}$/D',$operatorId)!==1 ||
            preg_match('/^[a-fA-F0-9]{64}$/D',$sha256)!==1 ||
            $totalBytes<128 || $totalBytes>268435456 || $offset<0 ||
            $offset>=$totalBytes || strlen($rawChunk)<1 || strlen($rawChunk)>196608 ||
            $offset+strlen($rawChunk)>$totalBytes)
            return ['ok'=>false,'error_code'=>'INBOX_UPLOAD_REQUEST_INVALID'];
        $descriptor=$this->discovery();$key=$this->loadKey();
        if (($descriptor['ok']??false)!==true||($key['ok']??false)!==true)
            return ['ok'=>false,'error_code'=>'MAINTENANCE_SIGNING_UNAVAILABLE'];
        return $this->signedJsonPost($descriptor['descriptor'],
            '/v1/local-updates/inbox-chunk',$key['key'],[
              'upload_id'=>strtolower($uploadId),'operator_id'=>$operatorId,
              'expected_sha256'=>strtolower($sha256),'total_bytes'=>$totalBytes,
              'offset'=>$offset,'chunk_base64'=>base64_encode($rawChunk)
            ],null);
    }

    /** @param array<string,mixed> $descriptor @param array<string,mixed> $payload */
    private function signedJsonPost(array $descriptor,string $path,string $key,
        array $payload,?string $idempotencyKey): array
    {
        if(!in_array($path,['/v1/local-updates/preflight','/v1/local-updates/stage',
            '/v1/local-updates/activate-code-only','/v1/local-updates/inbox-chunk',
            '/v1/local-updates/prepare-inactive','/v1/local-updates/prepare-inactive-slot'],true))
            return ['ok'=>false,'error_code'=>'MAINTENANCE_PATH_NOT_ALLOWED'];
        $body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($body)>1048576)return ['ok'=>false,'error_code'=>'SIGNED_BODY_TOO_LARGE'];
        $timestamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=bin2hex(random_bytes(16));
        $digest=hash('sha256',$body);
        $canonical="POST\n{$path}\n".self::PROTOCOL."\n{$timestamp}\n{$nonce}\n{$digest}";
        $headers=[
            'Accept'=>'application/json','Content-Type'=>'application/json; charset=utf-8',
            'X-Sokna-Maintenance-Protocol'=>self::PROTOCOL,
            'X-Sokna-Timestamp'=>$timestamp,'X-Sokna-Nonce'=>$nonce,
            'X-Sokna-Key-Id'=>self::KEY_ID,'X-Sokna-Body-SHA256'=>$digest,
            'X-Sokna-Signature'=>hash_hmac('sha256',$canonical,$key),
        ];
        if($idempotencyKey!==null)$headers['X-Sokna-Idempotency-Key']=$idempotencyKey;
        $url=rtrim((string)$descriptor['base_url'],'/').$path;
        if(is_callable($this->transport)){
            $response=($this->transport)($url,'POST',$headers,$body);
            return $this->normalizeResponse(is_array($response)?$response:[]);
        }
        $lines=[];foreach($headers as $name=>$value)$lines[]=$name.': '.$value;
        $context=stream_context_create(['http'=>[
            'method'=>'POST','header'=>implode("\r\n",$lines),'content'=>$body,
            'timeout'=>in_array($path,['/v1/local-updates/prepare-inactive','/v1/local-updates/prepare-inactive-slot'],true)?120:10,
            'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,
        ]]);
        // When URL wrappers are disabled, use the same signed POST through a
        // bounded loopback socket; NEVER retry a POST after uncertain delivery.
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) ||
            !in_array('http', stream_get_wrappers(), true)) {
            $socketResponse = $this->loopbackSocketHttp($descriptor, $path, 'POST', $headers,
                $body, in_array($path, ['/v1/local-updates/prepare-inactive',
                    '/v1/local-updates/prepare-inactive-slot'], true) ? 120 : 10);
            return $this->normalizeResponse($socketResponse);
        }
        $raw=@file_get_contents($url,false,$context,0,self::MAX_RESPONSE_BYTES+1);
        $status=$this->httpStatus($http_response_header??[]);
        if($raw===false&&$status===0)return ['ok'=>false,'error_code'=>'MAINTENANCE_UNREACHABLE'];
        if(is_string($raw)&&strlen($raw)>self::MAX_RESPONSE_BYTES)
            return ['ok'=>false,'error_code'=>'MAINTENANCE_RESPONSE_TOO_LARGE'];
        return $this->normalizeResponse(['status'=>$status,'body'=>(string)$raw]);
    }

    /**
     * A lost HTTP response is NOT permission to issue the same POST again.
     * Read only the original durable operation by the SAME idempotency key.
     * Even COMMITTED remains unproven to this layer until the caller obtains
     * installed-release + health evidence; this function never reports success.
     */
    private function reconcilePossiblyInterruptedPost(
        array $result, string $key, string $action, array $payload): array
    {
        if (($result['ok']??false)===true) return $result;
        if (!in_array((string)($result['error_code']??''),
            ['MAINTENANCE_UNREACHABLE','maintenance_http_0'],true)) return $result;
        $read=$this->operationByIdempotency($key);
        $operation=($read['ok']??false)===true?(array)($read['data']??[]):[];
        $expectedType=match($action){
            'preflight'=>'update.local-package-preflight',
            'stage'=>'update.local-stage',
            'activate'=>'update.local-code-only-activate',
            default=>'invalid'
        };
        $reference=match($action){
            'preflight'=>(string)($payload['package_ref']??''),
            'stage'=>(string)($payload['preflight_operation_id']??''),
            'activate'=>(string)($payload['stage_operation_id']??''),
            default=>''
        };
        if ($operation!==[] && ($operation['operation_type']??'')===$expectedType
            && ($operation['package_ref']??'')===$reference
            && ($operation['operator_id']??'')===($payload['operator_id']??'')
            && preg_match('/^[a-fA-F0-9]{32}$/D',(string)($operation['operation_id']??''))===1) {
            return ['ok'=>false,'error_code'=>'MAINTENANCE_OUTCOME_RECONCILIATION_REQUIRED',
                'operation_id'=>(string)$operation['operation_id'],
                'state'=>(string)($operation['state']??'unknown'),
                'requires_recovery_console'=>(string)($operation['state']??'')==='recovery_required',
                'retry_post'=>false];
        }
        return ['ok'=>false,'error_code'=>'MAINTENANCE_POST_OUTCOME_UNKNOWN',
            'state'=>'unproven','retry_post'=>false];
    }

    /** @return array<string,mixed> */
    public function discovery(): array
    {
        $path = $this->maintenanceRoot . DIRECTORY_SEPARATOR . 'control-plane.json';
        if (!is_file($path)) return ['ok' => false, 'status' => 'not_installed_or_stopped', 'error_code' => 'maintenance_descriptor_missing'];
        if (!is_readable($path)) return ['ok' => false, 'status' => 'acl_pending', 'error_code' => 'maintenance_descriptor_unreadable'];
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) return ['ok' => false, 'status' => 'descriptor_invalid', 'error_code' => 'maintenance_descriptor_invalid'];
        $descriptor = json_decode($raw, true);
        if (!is_array($descriptor) || !$this->validDescriptor($descriptor)) return ['ok' => false, 'status' => 'descriptor_invalid', 'error_code' => 'maintenance_descriptor_invalid'];
        return ['ok' => true, 'status' => 'ready', 'error_code' => '', 'descriptor' => $descriptor];
    }

    /** @return array<string,mixed> */
    private function loadKey(): array
    {
        $path = $this->maintenanceRoot . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'web-adapter.key';
        if (!is_file($path)) return ['ok' => false, 'status' => 'acl_pending', 'error_code' => 'maintenance_web_adapter_key_missing'];
        if (!is_readable($path)) return ['ok' => false, 'status' => 'acl_pending', 'error_code' => 'maintenance_web_adapter_key_unreadable'];
        $raw = trim((string)@file_get_contents($path));
        $key = base64_decode($raw, true);
        if (!is_string($key) || strlen($key) !== 32) return ['ok' => false, 'status' => 'configuration_error', 'error_code' => 'maintenance_web_adapter_key_invalid'];
        return ['ok' => true, 'status' => 'ready', 'error_code' => '', 'key' => $key];
    }

    /** @param array<string,mixed> $descriptor @return array<string,mixed> */
    private function signedGet(array $descriptor, string $path, string $key): array
    {
        if (!in_array($path, ['/v1/health','/v1/updates/capabilities','/v1/status','/v1/components','/v1/operations/active','/v1/operations/recent','/v1/backups','/v1/recovery','/v1/journal/head'], true)
            && preg_match('~^/v1/operations/by-idempotency/[a-zA-Z0-9._:-]{16,128}$~D',$path)!==1
            && preg_match('~^/v1/operations/[a-f0-9]{32}$~D',$path)!==1) {
            return ['ok' => false, 'http_status' => 0, 'error_code' => 'maintenance_path_not_allowed'];
        }
        $base = rtrim((string)$descriptor['base_url'], '/') . '/';
        $url = $base . ltrim($path, '/');
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', '');
        $canonical = "GET\n{$path}\n" . self::PROTOCOL . "\n{$timestamp}\n{$nonce}\n{$bodyHash}";
        $signature = hash_hmac('sha256', $canonical, $key);
        $headers = [
            'Accept' => 'application/json',
            'X-Sokna-Maintenance-Protocol' => self::PROTOCOL,
            'X-Sokna-Timestamp' => $timestamp,
            'X-Sokna-Nonce' => $nonce,
            'X-Sokna-Key-Id' => self::KEY_ID,
            'X-Sokna-Body-SHA256' => $bodyHash,
            'X-Sokna-Signature' => $signature,
        ];

        if (is_callable($this->transport)) {
            $response = ($this->transport)($url, 'GET', $headers, '');
            return $this->normalizeResponse(is_array($response) ? $response : []);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headerLines),
            'timeout' => 1.25,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ]]);
        // Preserve the transport failure class without exposing a potentially
        // sensitive PHP warning (local paths, proxy, runtime details) to UI.
        error_clear_last();
        $raw = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
        $transportWarning = $raw === false ? (string)(error_get_last()['message'] ?? '') : '';
        $status = $this->httpStatus($http_response_header ?? []);
        if ($raw === false && $status === 0) {
            // GET is read-only: fallback can safely retry with a fresh nonce and
            // the original signed headers. No such retry is allowed for POST.
            $socketResponse = $this->loopbackSocketHttp($descriptor, $path, 'GET',
                $headers, '', 3.5);
            if (($socketResponse['status'] ?? 0) > 0)
                return $this->normalizeResponse($socketResponse);
            return ['ok' => false, 'http_status' => 0,
                'error_code' => $this->transportFailureCode($transportWarning)];
        }
        if (is_string($raw) && strlen($raw) > self::MAX_RESPONSE_BYTES) return ['ok' => false, 'http_status' => $status, 'error_code' => 'maintenance_response_too_large'];
        return $this->normalizeResponse(['status' => $status, 'body' => (string)$raw]);
    }

    /**
     * Bounded alternate transport. The signed request, authenticated scheme and
     * endpoint allowlist are unchanged. DNS, proxy and external hosts are not
     * involved, and this helper never retries a POST.
     * @param array<string,mixed> $descriptor
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function loopbackSocketHttp(array $descriptor, string $path, string $method,
        array $headers, string $body, float $timeoutSeconds): array
    {
        $base = (string)($descriptor['base_url'] ?? '');
        if (!$this->validDescriptor($descriptor) ||
            !in_array($method, ['GET','POST'], true) ||
            !str_starts_with($path, '/v1/') ||
            preg_match('~^/v1/[A-Za-z0-9._:/-]+$~D', $path) !== 1)
            return ['status' => 0, 'body' => ''];
        $parts = parse_url($base);
        $port = is_array($parts) ? (int)($parts['port'] ?? 0) : 0;
        if ($port < 1024 || $port > 65535)
            return ['status' => 0, 'body' => ''];

        $error = 0;
        $message = '';
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message,
            min(5.0, $timeoutSeconds), STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) return ['status' => 0, 'body' => ''];
        try {
            stream_set_timeout($socket, (int)ceil($timeoutSeconds));
            $lines = [$method . ' ' . $path . ' HTTP/1.1', 'Host: 127.0.0.1:' . $port,
                'Connection: close'];
            foreach ($headers as $name => $value) {
                if (preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1 ||
                    preg_match('/[\r\n]/', $value) === 1)
                    return ['status' => 0, 'body' => ''];
                $lines[] = $name . ': ' . $value;
            }
            $lines[] = 'Content-Length: ' . strlen($body);
            $request = implode("\r\n", $lines) . "\r\n\r\n" . $body;
            $written = 0;
            while ($written < strlen($request)) {
                $n = @fwrite($socket, substr($request, $written));
                if (!is_int($n) || $n <= 0) return ['status' => 0, 'body' => ''];
                $written += $n;
            }
            $buffer = '';
            $maxWire = self::MAX_RESPONSE_BYTES + 16384;
            while (!feof($socket) && strlen($buffer) <= $maxWire) {
                $chunk = @fread($socket, min(8192, $maxWire + 1 - strlen($buffer)));
                if (!is_string($chunk) || $chunk === '') {
                    if (stream_get_meta_data($socket)['timed_out'] ?? false) break;
                    if (feof($socket)) break;
                    continue;
                }
                $buffer .= $chunk;
            }
            if (strlen($buffer) > $maxWire) return ['status' => 0, 'body' => ''];
            $divider = strpos($buffer, "\r\n\r\n");
            if ($divider === false || $divider > 16384) return ['status' => 0, 'body' => ''];
            $header = substr($buffer, 0, $divider);
            $wireBody = substr($buffer, $divider + 4);
            if (preg_match('/^HTTP\/1\.[01] ([0-9]{3})(?: |\r|\n|$)/', $header, $m) !== 1)
                return ['status' => 0, 'body' => ''];
            $status = (int)$m[1];
            if (preg_match('/^transfer-encoding:\s*chunked\s*$/im', $header)) {
                $decoded = '';
                $left = $wireBody;
                while (true) {
                    $end = strpos($left, "\r\n");
                    if ($end === false || $end > 128) return ['status'=>0,'body'=>''];
                    $sizeText = explode(';', substr($left, 0, $end), 2)[0];
                    if (preg_match('/^[0-9a-fA-F]{1,8}$/D', $sizeText) !== 1)
                        return ['status'=>0,'body'=>''];
                    $size = hexdec($sizeText);
                    $left = substr($left, $end + 2);
                    if ($size === 0) break;
                    if ($size > self::MAX_RESPONSE_BYTES - strlen($decoded) ||
                        strlen($left) < $size + 2 || substr($left, $size, 2) !== "\r\n")
                        return ['status'=>0,'body'=>''];
                    $decoded .= substr($left, 0, $size);
                    $left = substr($left, $size + 2);
                }
                $wireBody = $decoded;
            } elseif (preg_match('/^content-length:\s*(\d+)\s*$/im', $header, $cl)) {
                $declared = (int)$cl[1];
                if ($declared > self::MAX_RESPONSE_BYTES || strlen($wireBody) < $declared)
                    return ['status'=>0,'body'=>''];
                $wireBody = substr($wireBody, 0, $declared);
            }
            if (strlen($wireBody) > self::MAX_RESPONSE_BYTES)
                return ['status' => 0, 'body' => ''];
            return ['status' => $status, 'body' => $wireBody];
        } finally {
            fclose($socket);
        }
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function normalizeResponse(array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $body = $response['body'] ?? '';
        $data = is_array($body) ? $body : json_decode((string)$body, true);
        if ($status === 401) return ['ok' => false, 'http_status' => 401, 'error_code' => 'maintenance_unauthorized'];
        if ($status === 409 && is_array($data) && ($data['code'] ?? '') === 'AUTH_REPLAY') return ['ok' => false, 'http_status' => 409, 'error_code' => 'maintenance_auth_replay'];
        // Propagate stable Supervisor error codes and recovery state without
        // leaking internal paths or authentication evidence into the browser.
        if ($status < 200 || $status >= 300) {
            $valid=is_array($data) && $this->validEnvelope($data);
            $code=$valid?(string)$data['code']:'';
            $op=$valid?(string)($data['operation_id']??''):'';
            return ['ok'=>false,'http_status'=>$status,
                'error_code'=>preg_match('/^[A-Z][A-Z0-9_]{2,80}$/D',$code)===1?$code:'maintenance_http_'.($status?:0),
                'state'=>$valid?(string)$data['state']:'unknown',
                'requires_recovery_console'=>$valid&&$data['requires_recovery_console']===true,
                'operation_id'=>preg_match('/^[a-fA-F0-9]{32}$/D',$op)===1?$op:null];
        }
        if (!is_array($data) || !$this->validEnvelope($data)) return ['ok' => false, 'http_status' => $status, 'error_code' => 'maintenance_response_invalid'];
        if (($data['ok'] ?? false) !== true) return ['ok' => false, 'http_status' => $status, 'error_code' => 'maintenance_' . strtolower((string)($data['code'] ?? 'rejected')), 'envelope' => $data];
        return ['ok' => true, 'http_status' => $status, 'error_code' => '', 'data' => is_array($data['data'] ?? null) ? $data['data'] : [], 'state' => (string)($data['state'] ?? '')];
    }

    /** @param array<string,mixed> $descriptor */
    private function validDescriptor(array $descriptor): bool
    {
        if (($descriptor['format'] ?? null) !== self::DESCRIPTOR_FORMAT || (int)($descriptor['schema_version'] ?? 0) !== 1) return false;
        if (($descriptor['protocol'] ?? null) !== self::PROTOCOL || ($descriptor['auth_scheme'] ?? null) !== self::AUTH_SCHEME) return false;
        if (!is_bool($descriptor['read_only_p1'] ?? null) || !in_array(self::KEY_ID, (array)($descriptor['supported_key_ids'] ?? []), true)) return false;
        $base = trim((string)($descriptor['base_url'] ?? ''));
        $parts = parse_url($base);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'http') return false;
        if ((string)($parts['host'] ?? '') !== '127.0.0.1' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
        $port = (int)($parts['port'] ?? 0);
        if ($port < 1024 || $port > 65535 || !in_array((string)($parts['path'] ?? '/'), ['', '/'], true)) return false;
        if (!preg_match('/^[a-zA-Z0-9._:-]{8,160}$/', (string)($descriptor['instance_id'] ?? ''))) return false;
        return (int)($descriptor['install_generation'] ?? 0) >= 1;
    }

    /** @param array<string,mixed> $data */
    private function validEnvelope(array $data): bool
    {
        return is_bool($data['ok'] ?? null)
            && ($data['protocol'] ?? null) === self::PROTOCOL
            && is_string($data['state'] ?? null)
            && is_string($data['code'] ?? null)
            && is_bool($data['retryable'] ?? null)
            && is_bool($data['requires_recovery_console'] ?? null)
            && is_array($data['data'] ?? null);
    }

    /** @param array<string,mixed> $descriptor @return array<string,mixed> */
    private function publicDescriptor(array $descriptor): array
    {
        return [
            'protocol' => (string)($descriptor['protocol'] ?? ''),
            'base_url' => (string)($descriptor['base_url'] ?? ''),
            'supervisor_version' => (string)($descriptor['supervisor_version'] ?? ''),
            'instance_id' => (string)($descriptor['instance_id'] ?? ''),
            'install_generation' => (int)($descriptor['install_generation'] ?? 0),
            'readiness' => (string)($descriptor['readiness'] ?? ''),
            'auth_scheme' => (string)($descriptor['auth_scheme'] ?? ''),
            'read_only_p1' => (bool)($descriptor['read_only_p1'] ?? false),
            'written_at_utc' => (string)($descriptor['written_at_utc'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $part @return array<string,mixed>|null */
    private function dataOnly(array $part): ?array
    {
        return ($part['ok'] ?? false) === true && is_array($part['data'] ?? null) ? $part['data'] : null;
    }

    /** @param array<string,array<string,mixed>> $parts @return array<string,array<string,mixed>> */
    private function routeSummaries(array $parts): array
    {
        $result = [];
        foreach ($parts as $name => $part) {
            $result[$name] = [
                'ok' => ($part['ok'] ?? false) === true,
                'http_status' => (int)($part['http_status'] ?? 0),
                'error_code' => (string)($part['error_code'] ?? ''),
            ];
        }
        return $result;
    }

    /** @param array<string,mixed>|null $descriptor @param array<string,mixed> $parts @return array<string,mixed> */
    private function baseResult(string $status, string $errorCode, ?array $descriptor = null, array $parts = []): array
    {
        return [
            'status' => $status,
            'error_code' => $errorCode,
            'integration_ready' => false,
            'protocol' => self::PROTOCOL,
            'read_only_p1' => (bool)($descriptor['read_only_p1'] ?? true),
            'descriptor' => $descriptor,
            'health' => isset($parts['health']) && is_array($parts['health']) ? $this->dataOnly($parts['health']) : null,
            'supervisor_status' => null,
            'components' => null,
            'active_operation' => null,
            'backups' => null,
            'recovery' => null,
            'journal' => null,
            'route_results' => $parts ? $this->routeSummaries($parts) : [],
        ];
    }

    /**
     * Stable allowlisted diagnostic codes only. Never return raw PHP warnings,
     * the descriptor URL, keys or filesystem paths to the browser.
     */
    private function transportFailureCode(string $warning): string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) ||
            !in_array('http', stream_get_wrappers(), true))
            return 'maintenance_php_http_transport_disabled';
        $w = strtolower($warning);
        if (str_contains($w, 'timed out') || str_contains($w, 'timeout'))
            return 'maintenance_http_timeout';
        if (str_contains($w, 'refused') || str_contains($w, 'actively refused'))
            return 'maintenance_http_connection_refused';
        if (str_contains($w, 'permission denied') || str_contains($w, 'access is denied'))
            return 'maintenance_http_access_denied';
        return 'maintenance_unreachable';
    }

    /** @param array<string,mixed> $response */
    private function statusFromResponse(array $response): string
    {
        return match ((string)($response['error_code'] ?? '')) {
            'maintenance_unreachable' => 'unreachable',
            'maintenance_unauthorized' => 'acl_or_auth_error',
            'maintenance_response_invalid' => 'protocol_error',
            default => 'degraded',
        };
    }

    /** @param array<int,string> $headers */
    private function httpStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
        return $status;
    }

    private function defaultMaintenanceRoot(): string
    {
        $programData = trim((string)(getenv('ProgramData') ?: getenv('PROGRAMDATA') ?: ''));
        if ($programData === '') $programData = 'C:\\ProgramData';
        return rtrim($programData, "\\/") . DIRECTORY_SEPARATOR . 'SOKNA' . DIRECTORY_SEPARATOR . 'Maintenance';
    }
}
