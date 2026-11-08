<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use RuntimeException;
use Sokna\Local\Core\Observability;
use Throwable;

final class SupportBundleWriter
{
    private const FORMAT='sokna-support-bundle-v1';
    private const MAX_BUNDLES=5;
    private const MAX_LOG_LINES=200;

    public function __construct(private readonly Observability $observability,private readonly ?\Closure $financeAudit=null,private readonly ?\Closure $catalogPushAudit=null) {}

    public function create(array $snapshot): array
    {
        $started=microtime(true);$dir=$this->bundleDir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Support bundle directory is not writable.');
        $id='support-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $path=$dir.DIRECTORY_SEPARATOR.$id.'.json.gz';
        $tmp=$path.'.tmp-'.bin2hex(random_bytes(3));
        $bundleSnapshot=$snapshot;unset($bundleSnapshot['recent_logs']);
        $logStarted=microtime(true);$recentLogs=$this->recentLogs(self::MAX_LOG_LINES);$logElapsed=(int)round((microtime(true)-$logStarted)*1000);
        $known=$this->knownState();
        $financeAudit=['status'=>'not_checked','reason'=>'audit_not_configured'];
        if($this->financeAudit!==null){
            try{$financeAudit=($this->financeAudit)();}catch(Throwable){$financeAudit=['status'=>'not_checked','reason'=>'audit_unavailable'];}
        }
        $payload=[
            'format'=>self::FORMAT,
            'generated_at'=>gmdate('c'),
            'notice'=>'Operational diagnostics only. Secrets and credentials are intentionally excluded/redacted.',
            'snapshot'=>$this->observability->redact($bundleSnapshot),
            'known_state'=>$known,
            'triage_index'=>$this->triageIndex($bundleSnapshot,$known,$recentLogs),
            'finance_account_audit'=>$this->observability->redact($financeAudit),
            'catalog_push_readonly_audit'=>$this->observability->redact($this->catalogPushEvidence()),
            // Independent, bounded facts from this installed Local: no private paths or credentials.
            'installed_release_identity'=>$this->installedReleaseIdentity($known),
            'connection_failure_timeline'=>$this->connectionFailureTimeline($recentLogs),
            'recent_logs'=>$recentLogs,
            'bundle_profile'=>['log_tail_elapsed_ms'=>$logElapsed,'log_rows'=>count($recentLogs)],
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $gz=gzencode($json,6,ZLIB_ENCODING_GZIP);if($gz===false)throw new RuntimeException('Support bundle compression failed.');
        if(@file_put_contents($tmp,$gz,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Support bundle could not be written.');}
        @chmod($path,0600);clearstatcache(true,$path);$size=(int)(@filesize($path)?:0);if($size<=0){@unlink($path);throw new RuntimeException('Support bundle file is empty.');}$sha=hash_file('sha256',$path);if(!is_string($sha)||$sha==='')throw new RuntimeException('Support bundle hash failed.');$this->prune($path);
        $elapsed=(int)round((microtime(true)-$started)*1000);
        $this->observability->logEvent('info','system.support_bundle_created',['bundle_id'=>$id,'size_bytes'=>$size,'generation_ms'=>$elapsed,'log_tail_ms'=>$logElapsed]);
        return ['id'=>$id,'file_name'=>basename($path),'size_bytes'=>$size,'sha256'=>$sha,'generation_ms'=>$elapsed,'download'=>'/system/download.php?id='.rawurlencode($id)];
    }

    /** Optional read-only evidence; a failed audit cannot block support collection. */
    private function catalogPushEvidence(): array
    {
        if($this->catalogPushAudit===null)return ['status'=>'not_checked','reason'=>'audit_not_configured'];
        try{return ($this->catalogPushAudit)();}
        catch(Throwable){return ['status'=>'not_checked','reason'=>'audit_unavailable'];}
    }

    public function resolve(string $id): string
    {
        if(preg_match('/^support-\d{8}-\d{6}-[a-f0-9]{8}$/D',$id)!==1)throw new RuntimeException('Invalid support bundle identifier.');
        $path=$this->bundleDir().DIRECTORY_SEPARATOR.$id.'.json.gz';
        if(!is_file($path)||!is_readable($path))throw new RuntimeException('Support bundle not found.');
        return $path;
    }

    /** @return list<array<string,mixed>> */
    public function recentLogs(int $limit=80): array
    {
        $limit=max(1,min(self::MAX_LOG_LINES,$limit));$files=glob($this->observability->logDir().DIRECTORY_SEPARATOR.'sokna-*.jsonl')?:[];
        rsort($files,SORT_STRING);$out=[];
        foreach(array_slice($files,0,3) as $file){
            $need=$limit-count($out);if($need<=0)break;
            foreach($this->tailJsonRows($file,$need) as $row){$row['log_file']=basename($file);$out[]=$row;if(count($out)>=$limit)break 2;}
        }
        return $out;
    }

    /** Read newest valid JSONL records without scanning the complete log file. */
    private function tailJsonRows(string $file,int $limit): array
    {
        $limit=max(1,min(self::MAX_LOG_LINES,$limit));$fh=@fopen($file,'rb');if($fh===false)return [];
        $rows=[];$buffer='';
        try{
            if(fseek($fh,0,SEEK_END)!==0)return [];$pos=ftell($fh);if(!is_int($pos)||$pos<0)return [];
            while($pos>0&&count($rows)<$limit){
                $read=min(65536,$pos);$pos-=$read;if(fseek($fh,$pos,SEEK_SET)!==0)break;$chunk=fread($fh,$read);if(!is_string($chunk)||$chunk==='')break;
                $buffer=$chunk.$buffer;$parts=explode("\n",$buffer);$buffer=(string)array_shift($parts);
                for($i=count($parts)-1;$i>=0&&count($rows)<$limit;$i--){
                    $line=trim((string)$parts[$i]);if($line===''||strlen($line)>16384)continue;
                    try{$row=json_decode($line,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}
                    if(is_array($row))$rows[]=$this->observability->redact($row);
                }
            }
            if($pos===0&&count($rows)<$limit){$line=trim($buffer);if($line!==''&&strlen($line)<=16384){try{$row=json_decode($line,true,32,JSON_THROW_ON_ERROR);if(is_array($row))$rows[]=$this->observability->redact($row);}catch(Throwable){}}}
        }finally{fclose($fh);}
        return $rows;
    }


    private function knownState(): array
    {
        $out=[];$errors=[];
        $targets=[
            'local_update_state'=>$this->observability->dataRoot().DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'state.json',
            'local_managed_files'=>$this->observability->dataRoot().DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'managed-files.json',
        ];
        foreach($targets as $key=>$path){
            try{
                $raw=@file_get_contents($path);if(!is_string($raw)||trim($raw)==='')continue;
                $decoded=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
                if(is_array($decoded))$out[$key]=$this->observability->redact($decoded);
            }catch(Throwable $e){$errors[]=['key'=>$key,'class'=>get_class($e),'fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20)];}
        }
        if($errors!==[])$out['_errors']=$errors;
        return $out;
    }


    /**
     * Operator-safe navigation inside ONE downloaded support file. Values come only
     * from read-only supervisor evidence that was already included in this bundle.
     * Never infer success from missing evidence or include raw Windows/DB exceptions.
     */
    private function triageIndex(array $snapshot,array $known,array $recentLogs): array
    {
        $maintenance=$snapshot['maintenance_update_diagnostics']??[];
        $operations=is_array($maintenance)?($maintenance['operations']??[]):[];
        $cap=is_array($maintenance)?($maintenance['capabilities']??[]):[];
        $local=$known['local_update_state']??[];
        $safeCode=static function(mixed $v): string {
            if(!is_string($v)||preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$v)!==1)return 'unavailable';
            return $v;
        };
        $recent=[];
        if(is_array($operations))foreach(array_slice(is_array($operations['recent']??null)?$operations['recent']:[],0,15) as $o){
            if(!is_array($o))continue;
            $recent[]=['state'=>$safeCode($o['state']??null),
                'result_code'=>$safeCode($o['result_code']??null),
                'operation_type'=>$safeCode($o['operation_type']??null),
                'at'=>substr((string)($o['updated_at_utc']??''),0,48)];
        }
        $out=[
            'format'=>'sokna-support-triage-index-v1',
            'local_version'=>substr((string)($local['active_version']??''),0,180),
            'maintenance'=>[
                'evidence_available'=>($operations['evidence_available']??false)===true,
                'ready'=>($cap['install_ready']??false)===true,
                'state'=>$safeCode($operations['state']??null),
                'requires_recovery'=>$operations['requires_recovery']??null,
                'blocking_count'=>$operations['blocking_count']??null,
                'recent_operations'=>$recent,
            ],
            'last_local_failure'=>$this->observability->redact($local['last_failure']??null),
            'finance_projection'=>$this->financeProjectionTriage($recentLogs),            'evidence_limit'=>'Detailed Windows engine causes beyond Supervisor read-only API are unavailable in this file; never request operator scripts.',
        ];
        return $this->observability->redact($out);
    }


    /**
     * Reports a bounded, already-redacted reason for a blocked financial projection.
     * A missing entry is explicitly inconclusive, never evidence that finance is healthy.
     * No database, receipt, price, or customer record is opened by support collection.
     *
     * @param list<array<string,mixed>> $recentLogs
     */
    private function financeProjectionTriage(array $recentLogs): array
    {
        $fallback=null;
        foreach($recentLogs as $row){
            if(!is_array($row))continue;
            $event=(string)($row['event']??'');
            if(!in_array($event,['public.finance_projection_blocked','public.realtime_projection_prepare_failed','public.projection_model_preparation_failed'],true))continue;
            $context=is_array($row['context']??null)?$row['context']:[];
            if((string)($context['model_key']??'')!=='operations')continue;
            $code=trim((string)($context['reason_code']??''));
            if(preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$code)!==1)$code='not_reported';
            $id=(int)($context['session_id']??0);
            $entry=[
                'evidence'=>'recent_log_only',
                'event'=>$event,
                'reason_code'=>$code,
                'session_id'=>$id>0?$id:null,
                'observed_at'=>substr((string)($row['ts']??''),0,40),
                'meaning'=>'A blocked or failed operations projection is not evidence of an empty account; inspect the canonical finance ledger before any repair.',
            ];
            // Recent generic failures must not hide an older, precise code.
            // We retain the newest generic failure only as a fallback.
            if($code!=='not_reported')return $entry;
            $fallback??=$entry;
        }
        return $fallback??['evidence'=>'not_observed_in_recent_logs','meaning'=>'No matching recent log was found; this does not prove that finance is healthy.'];
    }

    /**
     * Installed version evidence, not a claim that all managed files were verified.
     * Hash only a few predictable application files; never traverse customer data.
     */
    private function installedReleaseIdentity(array $known): array
    {
        $state=is_array($known['local_update_state']??null)?$known['local_update_state']:[];
        $inventory=is_array($known['local_managed_files']??null)?$known['local_managed_files']:[];
        $root=dirname(__DIR__,3);
        $files=[];
        foreach(['VERSION.txt','bootstrap.php','src/Domain/System/SupportBundleWriter.php'] as $relative){
            $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            if(!is_file($path)||!is_readable($path)||is_link($path)){$files[$relative]=['status'=>'unavailable'];continue;}
            $size=@filesize($path);
            if(!is_int($size)||$size<0||$size>2000000){$files[$relative]=['status'=>'not_checked'];continue;}
            $hash=@hash_file('sha256',$path);
            $files[$relative]=is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash)===1
                ? ['status'=>'verified','size_bytes'=>$size,'sha256'=>$hash]
                : ['status'=>'unavailable'];
        }
        $versionPath=$root.DIRECTORY_SEPARATOR.'VERSION.txt';
        $version=is_file($versionPath)&&!is_link($versionPath)&&is_readable($versionPath)&&@filesize($versionPath)<=4096
            ?trim((string)@file_get_contents($versionPath)):'';
        return [
            'format'=>'sokna-installed-release-evidence-v1',
            'active_version'=>substr((string)($state['active_version']??''),0,180),
            'version_file'=>substr($version,0,180),
            'version_match'=>isset($state['active_version'])&&$version!=='' ? hash_equals((string)$state['active_version'],$version) : null,
            'managed_inventory_entry_count'=>is_array($inventory['files']??null)?count($inventory['files']):null,
            'managed_inventory_updated_at'=>substr((string)($inventory['updated_at']??''),0,48),
            'file_hash_scope'=>'selected_application_files_only; not a full managed-tree verification',
            'selected_files'=>$files,
        ];
    }

    /** Bounded, field-allowlisted summary of already-redacted logs; no message text or customer data. */
    private function connectionFailureTimeline(array $recentLogs): array
    {
        $records=[];
        $safe=static function(mixed $value,int $limit=100): string {
            if(!is_string($value))return '';
            return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$value)===1?substr($value,0,$limit):'';
        };
        foreach($recentLogs as $row){
            if(!is_array($row))continue;
            $ctx=is_array($row['context']??null)?$row['context']:[];
            $event=$safe($row['event']??null);
            $code=$safe($ctx['error_code']??$ctx['detail_code']??$ctx['failure_class']??null);
            $trigger=$safe($ctx['trigger_key']??null);
            $workerFailed=$event==='runtime.trigger_http_timing'&&($ctx['worker_success']??null)===false
                &&in_array($trigger,['public.relay_sync','public.projection_sync'],true);
            $http=$ctx['http_status']??$ctx['status_code']??null;
            // Runtime trigger logs use `status` for HTTP (often 200 even when worker failed).
            if($workerFailed&&$http===null)$http=$ctx['status']??null;
            $http=is_numeric($http)?(int)$http:null;
            if($http===null||$http<100||$http>599)$http=null;
            // A successful trigger HTTP response must not hide failed WAN/TLS work.
            $haystack=strtolower($event.' '.$code);
            if(!$workerFailed&&preg_match('/(tls|ssl|handshake|unreachable|timeout|connection|sync_failed|http_error|public.*fail|edge.*fail|network)/',$haystack)!==1 && $http!==503)continue;
            if(!$workerFailed&&preg_match('/(fail|error|timeout|unreach|handshake|tls|ssl|503)/',$haystack)!==1&&$http!==503)continue;
            $records[]=[
                'at'=>$safe($row['ts']??null,48),
                'event'=>$event,
                'error_code'=>$code!==''?$code:($workerFailed?'worker_reported_failure':null),
                'http_status'=>$http,
                'worker_success'=>$workerFailed?false:null,
                'trigger_key'=>$workerFailed?$trigger:null,
                'correlation_id'=>$safe($row['correlation_id']??null,95),
            ];
            if(count($records)>=30)break;
        }
        return [
            'format'=>'sokna-support-connection-timeline-v1',
            'source'=>'recent_local_logs_only',
            'sampled_log_rows'=>count($recentLogs),
            'status'=>$recentLogs===[]?'no_log_evidence':($records===[]?'no_matching_failure_in_sample':'failure_evidence_found'),
            'events'=>$records,
            'scope_note'=>'No matching log does not prove connection health; Public and Windows evidence remain separate.',
        ];
    }

    private function bundleDir(): string{return $this->observability->dataRoot().DIRECTORY_SEPARATOR.'support-bundles';}

    private function prune(string $keepPath): void
    {
        $files=glob($this->bundleDir().DIRECTORY_SEPARATOR.'support-*.json.gz')?:[];
        usort($files,static function(string $a,string $b) use($keepPath): int {if($a===$keepPath)return -1;if($b===$keepPath)return 1;$am=filemtime($a)?:0;$bm=filemtime($b)?:0;return $bm<=>$am ?: strcmp($b,$a);});
        foreach(array_slice($files,self::MAX_BUNDLES) as $old)if($old!==$keepPath)@unlink($old);
    }
}