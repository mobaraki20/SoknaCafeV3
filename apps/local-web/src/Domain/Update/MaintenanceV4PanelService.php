<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;

use Closure;
use Throwable;
use ZipArchive;

/**
 * Browser admin -> PHP Web adapter -> installed Windows Maintenance V4.
 * The HMAC key authenticates the local transport and is provisioned by Windows
 * Services; it is NOT a release signing key and is never sent to the browser.
 * No Windows/Cafe-specific version, hard-coded IP or public-network dependency.
 */
final class MaintenanceV4PanelService
{
    private const MAX_BYTES=134217728;
    private const CHUNK_BYTES=131072; // <= Supervisor's 196608 byte limit
    private const FORMAT='sokna-maintenance-release-set-v2.1';

    /** @param ?Closure(string,string,array,string,int):array $sender For transport-only regression tests. */
    public function __construct(
        private readonly string $localDataRoot,
        private readonly ?string $supervisorDataRoot=null,
        private readonly ?Closure $sender=null
    ) {}

    public static function isV4Archive(string $path): bool
    {
        if(!is_file($path)||!class_exists(ZipArchive::class))return false;
        $zip=new ZipArchive();if($zip->open($path)!==true)return false;
        try{return $zip->locateName('release-set.json')!==false;}finally{$zip->close();}
    }

    public function hasStaged(): bool
    {
        $s=$this->load();
        return isset($s['stage_operation_id'])&&
            in_array((string)($s['state']??''),['staged','preparing','activating','outcome_unknown','recovery_required'],true);
    }

    public function ownsCurrentUpdate(): bool
    {
        return isset($this->load()['stage_operation_id']);
    }

    public function snapshot(): array
    {
        $s=$this->load();
        $out=['engine'=>'maintenance-v4','available'=>false,'panel_update_ready'=>false,
            'blocking_code'=>'MAINTENANCE_UNAVAILABLE','staged'=>null,
            'activation_state'=>(string)($s['state']??'idle')];
        if(isset($s['stage_operation_id']))$out['staged']=[
            'version'=>(string)$s['version'],'stage_operation_id'=>(string)$s['stage_operation_id'],
            'state'=>(string)$s['state']
        ];
        try{
            $response=$this->send('GET','/v1/updates/capabilities');
            $cap=(array)($response['data']??[]);
            $out['available']=true;
            $out['panel_update_ready']=($cap['panel_update_ready']??false)===true;
            $out['blocking_code']=(string)($cap['blocking_code']??'');
            $out['inbox_transport_available']=($cap['inbox_transport_available']??false)===true;
            $out['actions']=(array)($cap['local_routine']??[]);
            if(isset($s['stage_operation_id'])&&preg_match('/^[a-f0-9]{32}$/D',(string)$s['stage_operation_id'])){
                $operation=$this->send('GET','/v1/operations/'.$s['stage_operation_id']);
                $op=(array)($operation['data']??[]);
                if(($op['state']??'')==='recovery_required'){
                    $out['panel_update_ready']=false;
                    $out['blocking_code']='LOCAL_RECOVERY_REQUIRED';
                }
                if(in_array((string)($s['state']??''),['activating','outcome_unknown','recovery_required'],true)){
                    try {
                        $activation=$this->send('GET','/v1/operations/by-idempotency/'.$s['activation_key']);
                        $outcome=(array)($activation['data']??[]);
                        $state=(string)($outcome['state']??'');
                        if($state==='committed'&&($s['state']??'')!=='completed'){
                            $s['state']='completed';$this->save($s);
                            $out['activation_state']='completed';
                            $out['staged']['state']='completed';
                            $out['panel_update_ready']=false;
                        }elseif($state==='recovery_required'){
                            $s['state']='recovery_required';$this->save($s);
                            $out['activation_state']='recovery_required';
                            $out['staged']['state']='recovery_required';
                            $out['panel_update_ready']=false;
                            $out['blocking_code']='LOCAL_RECOVERY_REQUIRED';
                        }
                    }catch(LocalUpdateException){/* An absent/ambiguous operation cannot authorize retry. */}
                }
            }
        }catch(LocalUpdateException $e){
            $out['blocking_code']=$e->errorCode;
            $out['panel_update_ready']=false;
        }
        return $out;
    }

    public function stageUploadedZip(string $path,int $actorId): array
    {
        if($actorId<1||!is_file($path))
            throw new LocalUpdateException('maintenance_actor_invalid','درخواست به‌روزرسانی معتبر نیست.',403);
        $bytes=(int)filesize($path);
        if($bytes<128||$bytes>self::MAX_BYTES)
            throw new LocalUpdateException('package_size','حجم بسته معتبر نیست.',413);
        $manifest=$this->manifest($path);
        $version=(string)($manifest['targets']['local']['version']??'');
        $release=(string)($manifest['release_set_id']??'');
        if(!preg_match('/^[0-9A-Za-z._+-]{1,200}$/D',$version)||
           !preg_match('/^[0-9A-Za-z._-]{1,96}$/D',$release))
            throw new LocalUpdateException('manifest_contract','هویت نسخه Local معتبر نیست.',422);
        if($this->hasStaged())
            throw new LocalUpdateException('maintenance_stage_exists','نسخه آماده قبلی Maintenance باید ابتدا تعیین تکلیف شود.',409);
        $operator='local-admin:'.$actorId;
        $hash=(string)hash_file('sha256',$path);
        $uploadId=bin2hex(random_bytes(16));
        $stream=@fopen($path,'rb');
        if(!is_resource($stream))
            throw new LocalUpdateException('upload_storage_unavailable','خواندن فایل انتخاب‌شده ممکن نیست.',503);
        $out=[];$offset=0;
        try{
            while($offset<$bytes){
                $part=fread($stream,min(self::CHUNK_BYTES,$bytes-$offset));
                if(!is_string($part)||$part==='')break;
                $out=$this->send('POST','/v1/local-updates/inbox-chunk',[
                    'upload_id'=>$uploadId,'operator_id'=>$operator,
                    'expected_sha256'=>$hash,'total_bytes'=>$bytes,
                    'offset'=>$offset,'chunk_base64'=>base64_encode($part)
                ]);
                $offset+=strlen($part);
            }
        }finally{fclose($stream);}
        $transfer=(array)($out['data']??[]);
        if($offset!==$bytes||($transfer['complete']??false)!==true||
            ($transfer['content_sha256_verified']??false)!==true||
            (string)($transfer['package_ref']??'')!==$hash.'.zip')
            throw new LocalUpdateException('maintenance_upload_incomplete','انتقال به Maintenance کامل نشد.',503);

        $pre=$this->send('POST','/v1/local-updates/preflight',[
            'package_ref'=>$hash.'.zip','operator_id'=>$operator
        ],'pre-'.bin2hex(random_bytes(16)));
        $preId=(string)($pre['data']['operation']['operation_id']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$preId))
            throw new LocalUpdateException('maintenance_preflight_unverified','بررسی بسته تأیید نشد.',409);
        $stage=$this->send('POST','/v1/local-updates/stage',[
            'preflight_operation_id'=>$preId,'operator_id'=>$operator
        ],'stage-'.bin2hex(random_bytes(16)));
        $stageId=(string)($stage['data']['operation']['operation_id']??'');
        $receipt=(array)($stage['data']['receipt']??[]);
        if(!preg_match('/^[a-f0-9]{32}$/D',$stageId)||
            ($receipt['operator_authorized']??false)!==true||
            ($receipt['artifact_integrity_verified']??false)!==true||
            ($receipt['signature_verified']??true)!==false)
            throw new LocalUpdateException('maintenance_stage_unverified','Stage این بسته در Maintenance تأیید نشد.',409);
        $this->save([
            'format'=>'sokna-local-maintenance-panel-v1',
            'version'=>$version,'release_set_id'=>$release,'package_sha256'=>$hash,
            'stage_operation_id'=>$stageId,'operator_id'=>$operator,'actor_id'=>$actorId,
            'state'=>'staged','created_at'=>gmdate('c'),
            'candidate_key'=>'candidate-'.bin2hex(random_bytes(16)),
            'slot_key'=>'slot-'.bin2hex(random_bytes(16)),
            'activation_key'=>'activate-'.bin2hex(random_bytes(16))
        ]);
        return ['engine'=>'maintenance-v4','version'=>$version,
            'stage_operation_id'=>$stageId,'state'=>'staged',
            'signature_status'=>'not_required_operator_upload'];
    }

    public function activateStaged(int $actorId): array
    {
        $s=$this->load();
        if($actorId<1||$actorId!==(int)($s['actor_id']??0)||!isset($s['stage_operation_id']))
            throw new LocalUpdateException('maintenance_stage_missing','نسخه آماده‌شده برای این مدیر یافت نشد.',409);
        if(($s['state']??'')!=='staged')
            throw new LocalUpdateException('maintenance_activation_in_progress',
                'نصب قبلاً درخواست شده است؛ وضعیت آن را بررسی کن.',409);
        $cap=$this->snapshot();
        if(!$cap['available']||!$cap['panel_update_ready']||
           ($cap['actions']['activate']??false)!==true)
            throw new LocalUpdateException('maintenance_activation_blocked',
                'میزبان هنوز برای نصب آماده نیست: '.(string)$cap['blocking_code'],409);
        $id=(string)$s['stage_operation_id'];$operator=(string)$s['operator_id'];
        // Record intent BEFORE physical mutations. Never retry ambiguously
        // or fall back to PHP's copy-tree activation on an interrupted request.
        $s['state']='preparing';$this->save($s);
        try{
            $candidate=$this->send('POST','/v1/local-updates/prepare-inactive',[
                'stage_operation_id'=>$id,'operator_id'=>$operator
            ],(string)$s['candidate_key']);
            if(($candidate['data']['receipt']['active_slot_unchanged']??false)!==true)
                throw new LocalUpdateException('maintenance_candidate_unverified',
                    'آماده‌سازی نسخه غیرفعال تأیید نشد.',409);
            $slot=$this->send('POST','/v1/local-updates/prepare-inactive-slot',[
                'stage_operation_id'=>$id,'operator_id'=>$operator,
                'private_candidate_idempotency_key'=>(string)$s['candidate_key']
            ],(string)$s['slot_key']);
            if(($slot['data']['receipt']['actual_host_health_qualified']??false)!==true)
                throw new LocalUpdateException('maintenance_slot_unverified',
                    'سلامت میزبان برای فعال‌سازی تأیید نشد.',409);
            $s['state']='activating';$this->save($s);
            $result=$this->send('POST','/v1/local-updates/activate-code-only',[
                'stage_operation_id'=>$id,'operator_id'=>$operator
            ],(string)$s['activation_key'],250);
            $operation=(array)($result['data']['operation']??[]);
            $status=(string)($operation['state']??$result['state']??'unknown');
            $s['state']=$status==='committed'?'completed':'activating';
            $s['activation_operation_id']=(string)($operation['operation_id']??$result['operation_id']??'');
            $this->save($s);
            return ['engine'=>'maintenance-v4','state'=>$status];
        }catch(Throwable $e){
            // Connection loss may happen after an irreversible mutation. Do not
            // invent a rollback success or rerun PHP's old updater.
            $s['state']='outcome_unknown';$this->save($s);
            throw $e;
        }
    }

    private function manifest(string $path): array
    {
        if(!class_exists(ZipArchive::class))
            throw new LocalUpdateException('zip_unavailable','افزونه ZIP فعال نیست.',503);
        $zip=new ZipArchive();
        if($zip->open($path)!==true)
            throw new LocalUpdateException('invalid_zip','فایل ZIP قابل خواندن نیست.',422);
        try{
            $raw=$zip->getFromName('release-set.json');
            if(!is_string($raw)||strlen($raw)>2097152)
                throw new LocalUpdateException('manifest_missing','ساختار Release Set معتبر نیست.',422);
            $manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($manifest)||($manifest['format']??'')!==self::FORMAT||
                (string)($manifest['schema_version']??'')!=='2.1'||
                ($manifest['release_type']??'')!=='local_only'||
                count((array)($manifest['artifacts']??[]))!==1)
                throw new LocalUpdateException('manifest_contract',
                    'فایل برای Local Maintenance V4 معتبر نیست.',422);
            return $manifest;
        }finally{$zip->close();}
    }

    /** Signed local HMAC transport. This is NOT signing the release ZIP. */
    private function send(string $method,string $path,array $payload=[],string $idem='',int $timeout=30): array
    {
        if(!preg_match('#^/v1/[a-z0-9/-]+$#D',$path))
            throw new LocalUpdateException('maintenance_path_invalid','مسیر داخلی نامعتبر است.',500);
        [$base,$key]=$this->endpoint();
        $url=rtrim($base,'/').$path;
        $body=$method==='GET'?'':json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($body)>1048576)
            throw new LocalUpdateException('maintenance_request_large','درخواست داخلی بیش از حد مجاز است.',413);
        $stamp=gmdate('Y-m-d\TH:i:s').'.0000000+00:00';
        $nonce=bin2hex(random_bytes(16));$digest=hash('sha256',$body);
        $canonical=implode("\n",[$method,$path,'1.0',$stamp,$nonce,$digest]);
        $headers=[
            'X-Sokna-Maintenance-Protocol: 1.0',
            'X-Sokna-Timestamp: '.$stamp,'X-Sokna-Nonce: '.$nonce,
            'X-Sokna-Key-Id: web-adapter','X-Sokna-Body-SHA256: '.$digest,
            'X-Sokna-Signature: '.hash_hmac('sha256',$canonical,$key),
            'Accept: application/json'
        ];
        if($idem!=='')$headers[]='X-Sokna-Idempotency-Key: '.$idem;
        if($method==='POST')$headers[]='Content-Type: application/json; charset=utf-8';
        if($this->sender!==null)$response=($this->sender)($method,$url,$headers,$body,$timeout);
        else{
            if(!function_exists('curl_init'))
                throw new LocalUpdateException('maintenance_transport_unavailable',
                    'ارتباط داخلی PHP با Maintenance در دسترس نیست.',503);
            $curl=curl_init($url);
            if($curl===false)
                throw new LocalUpdateException('maintenance_transport_unavailable','اتصال داخلی ممکن نیست.',503);
            try{
                curl_setopt_array($curl,[
                    CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,
                    CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$timeout,
                    CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
                    CURLOPT_CUSTOMREQUEST=>$method
                ]);
                if($method==='POST')curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
                $text=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
                if(!is_string($text))
                    throw new LocalUpdateException('maintenance_unreachable',
                        'ارتباط داخلی با Maintenance برقرار نشد.',503);
                $response=['status'=>$status,'body'=>$text];
            }finally{curl_close($curl);}
        }
        try{$result=json_decode((string)$response['body'],true,64,JSON_THROW_ON_ERROR);}
        catch(Throwable){
            throw new LocalUpdateException('maintenance_bad_response','پاسخ Maintenance معتبر نیست.',503);
        }
        if(!is_array($result)||!array_key_exists('ok',$result))
            throw new LocalUpdateException('maintenance_bad_response','پاسخ Maintenance معتبر نیست.',503);
        $pendingActivation=$path==='/v1/local-updates/activate-code-only'&&
            (int)$response['status']===202&&
            in_array((string)($result['state']??''),['accepted','running','activating'],true);
        if(!$pendingActivation&&((int)$response['status']<200||
           (int)$response['status']>=300||$result['ok']!==true))
            throw new LocalUpdateException('maintenance_rejected',
                'Maintenance درخواست را رد کرد: '.(string)($result['code']??'unknown'),409,
                ['maintenance_code'=>(string)($result['code']??'unknown')]);
        return $result;
    }

    /** Root and trust material are discovered ONLY on this host, never from the request. */
    private function endpoint(): array
    {
        $root=$this->supervisorDataRoot;
        if($root===null){
            $root=getenv('ProgramData')?:getenv('PROGRAMDATA');
            if(!is_string($root)||$root==='')
                throw new LocalUpdateException('maintenance_not_installed','Windows Services یافت نشد.',503);
            $root=rtrim($root,"\\/").DIRECTORY_SEPARATOR.'SOKNA'.
                DIRECTORY_SEPARATOR.'Maintenance';
        }
        $descriptor=$root.DIRECTORY_SEPARATOR.'control-plane.json';
        $keyFile=$root.DIRECTORY_SEPARATOR.'secrets'.DIRECTORY_SEPARATOR.'web-adapter.key';
        if(!is_readable($descriptor)||!is_readable($keyFile))
            throw new LocalUpdateException('maintenance_adapter_unavailable',
                'دسترسی خودکار پنل به Maintenance آماده نیست.',503);
        try{$data=json_decode((string)file_get_contents($descriptor),true,16,JSON_THROW_ON_ERROR);}
        catch(Throwable){
            throw new LocalUpdateException('maintenance_adapter_unavailable',
                'تنظیمات Maintenance قابل خواندن نیست.',503);
        }
        $base=(string)($data['base_url']??'');
        if(($data['format']??'')!=='sokna-maintenance-control-plane-v1'||
            ($data['protocol']??'')!=='1.0'||
            !preg_match('#^http://127\.0\.0\.1:([1-9][0-9]{3,4})/$#D',$base,$port)||
            (int)($port[1]??0)>65535)
            throw new LocalUpdateException('maintenance_adapter_unavailable',
                'نشانی سرویس Maintenance معتبر نیست.',503);
        $key=base64_decode(trim((string)file_get_contents($keyFile)),true);
        if(!is_string($key)||strlen($key)!==32)
            throw new LocalUpdateException('maintenance_adapter_unavailable',
                'احراز هویت داخلی Maintenance آماده نیست.',503);
        return [$base,$key];
    }

    private function load(): array
    {
        $file=$this->stateFile();
        if(!is_file($file))return [];
        try{$a=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);}
        catch(Throwable){return [];}
        return is_array($a)&&($a['format']??'')==='sokna-local-maintenance-panel-v1'?$a:[];
    }
    private function save(array $state): void
    {
        $path=$this->stateFile();$dir=dirname($path);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
            throw new LocalUpdateException('maintenance_state_unavailable',
                'ثبت وضعیت نصب ممکن نیست.',503);
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $data=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$data,LOCK_EX)===false||!@rename($tmp,$path))
            throw new LocalUpdateException('maintenance_state_unavailable',
                'ثبت وضعیت نصب ممکن نیست.',503);
        @chmod($path,0600);
    }
    private function stateFile(): string
    {
        return rtrim($this->localDataRoot,"\\/").DIRECTORY_SEPARATOR.'maintenance-v4-panel-state.json';
    }
}
