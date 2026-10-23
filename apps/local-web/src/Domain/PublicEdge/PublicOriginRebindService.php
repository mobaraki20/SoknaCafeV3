<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Sokna\Local\Core\Config;
use Sokna\Local\Domain\Notifications\NotificationService;
use Throwable;

/** Coordinates a two-sided, rollback-aware Public origin rebind. */
final class PublicOriginRebindService
{
    private const CONTRACT='sokna-local-public-origin-rebind-v2';

    public function __construct(private readonly Config $config,private readonly PublicEdgeSyncClient $client,private readonly PublicAuthorityStateService $authorityState,private readonly string $configPath,private readonly ?NotificationService $notifications=null){}

    public function preflight(string $targetBaseUrl): array
    {
        if(!$this->client->configured())throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز pair نشده است.',409);
        if($this->authorityState->phase()!=='active')throw new PublicEdgeSyncException('public_authority_not_active','تغییر آدرس هنگام وضعیت recovery/reenrollment مجاز نیست.',409);
        $current=$this->client->publicBaseUrl();$target=$this->client->canonicalBaseUrl($targetBaseUrl);
        if($target==='')throw new PublicEdgeSyncException('public_url_invalid','نشانی مقصد Public معتبر نیست.',422);
        if(hash_equals($current,$target))throw new PublicEdgeSyncException('public_origin_unchanged','نشانی مقصد با نشانی فعلی یکسان است.',409);
        $currentProbe=$this->client->deploymentBindingProbe($current);$targetProbe=$this->client->deploymentBindingProbe($target);
        $a=(string)($currentProbe['public_instance_id']??'');$b=(string)($targetProbe['public_instance_id']??'');
        if($a===''||$b===''||!hash_equals($a,$b))throw new PublicEdgeSyncException('public_origin_instance_mismatch','نشانی مقصد به همان Public instance متصل نیست.',409);
        $candidate=(array)($targetProbe['candidate']??[]);
        if(($candidate['staff_worker_present']??false)!==true||($candidate['staff_runtime_present']??false)!==true)throw new PublicEdgeSyncException('public_origin_target_pwa_incomplete','مسیر مقصد اجزای لازم Staff PWA را کامل ارائه نمی‌کند.',409);
        // Warm target before any state mutation. This mirrors guarded blue/green cutover: target must be healthy before prepare/commit.
        $health=$this->client->deploymentTargetHealth($target,6);$healthDeployment=(array)($health['deployment']??[]);
        if(($healthDeployment['coherent']??false)!==true)throw new PublicEdgeSyncException('public_origin_target_health_mismatch','Health نشانی مقصد binding صحیح را تأیید نکرد.',502);
        $transition=$this->transitionImpact($current,$target);
        return ['ok'=>true,'contract'=>self::CONTRACT,'current_base_url'=>$current,'target_base_url'=>$target,'public_instance_id'=>$a,'current_deployment'=>(array)($currentProbe['deployment']??[]),'target_observed_deployment'=>(array)($targetProbe['deployment']??[]),'target_candidate'=>$candidate,'target_health_verified'=>true,...$transition,'safe_to_prepare'=>true];
    }

    public function transitionStatus(): array
    {
        if(!$this->client->configured())return ['ok'=>true,'active'=>false,'contract'=>self::CONTRACT];
        $bases=array_values(array_unique(array_filter([$this->client->publicBaseUrl()])));
        foreach($bases as $base){try{$r=$this->client->deploymentBindingRequest($base,['action'=>'transition_status'],5);$body=(array)($r['body']??[]);$body['contract_local']=self::CONTRACT;return $body;}catch(Throwable){}}
        return ['ok'=>true,'active'=>false,'contract'=>self::CONTRACT,'status_unavailable'=>true];
    }

    public function closeTransition(string $rebindId,int $actorId=0): array
    {
        if(!$this->client->configured())throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز pair نشده است.',409);
        $id=trim($rebindId);if(preg_match('/^rebind-[a-f0-9]{24}$/D',$id)!==1)throw new PublicEdgeSyncException('public_origin_rebind_id_invalid','شناسه انتقال آدرس معتبر نیست.',422);
        $base=$this->client->publicBaseUrl();$status=$this->client->deploymentBindingRequest($base,['action'=>'status','rebind_id'=>$id],8);$phase=(string)($status['body']['phase']??'');
        // A lost finalize response must not strand the deployment forever. Closing the grace is the
        // explicit operator recovery point; retry finalize idempotently before retirement.
        if($phase==='committed'){$status=$this->client->deploymentBindingRequest($base,['action'=>'finalize','rebind_id'=>$id],8);$phase=(string)($status['body']['phase']??'');}
        if($phase!=='finalized')throw new PublicEdgeSyncException('public_origin_transition_not_finalized','انتقال Public هنوز به وضعیت نهایی نرسیده و قابل بستن نیست.',409);
        $r=$this->client->deploymentBindingRequest($base,['action'=>'transition_close','rebind_id'=>$id],8);
        return ['ok'=>true,'contract'=>self::CONTRACT,'actor_user_id'=>$actorId,'transition'=>(array)($r['body']??[])];
    }

    public function execute(string $targetBaseUrl,int $actorId=0): array
    {
        $pre=$this->preflight($targetBaseUrl);$current=(string)$pre['current_base_url'];$target=(string)$pre['target_base_url'];$instance=(string)$pre['public_instance_id'];
        $this->notifications?->sealCurrentPushBinding();
        $staged=$this->stageLocalConfig($target);$stagedPath=(string)$staged['path'];$rebindId='';$publicCommitted=false;$pushSuspended=false;
        try{
            $prepared=$this->client->deploymentBindingRequest($current,['action'=>'prepare','target_base_url'=>$target,'target_public_instance_id'=>$instance]);$rebindId=(string)($prepared['body']['rebind_id']??'');
            if($rebindId==='')throw new PublicEdgeSyncException('public_origin_prepare_invalid','Public rebind prepare پاسخ معتبر نداد.',502);
            try{$this->client->deploymentBindingRequest($current,['action'=>'commit','rebind_id'=>$rebindId]);$publicCommitted=true;}
            catch(Throwable $commitError){
                $phase=$this->resolveRebindPhase($current,$target,$rebindId);
                if($phase==='committed'||$phase==='finalized')$publicCommitted=true;
                elseif($phase==='prepared')throw $commitError;
                else throw new PublicEdgeSyncException('public_origin_commit_outcome_unknown','نتیجه commit تغییر آدرس Public نامشخص است؛ ادامه خودکار متوقف شد تا وضعیت بازیابی بررسی شود.',503);
            }
            $targetProbe=$this->client->deploymentBindingProbe($target);$deployment=(array)($targetProbe['deployment']??[]);
            if(($deployment['coherent']??false)!==true||!hash_equals($target,(string)($deployment['base_url']??'')))throw new PublicEdgeSyncException('public_origin_target_binding_mismatch','Public روی نشانی جدید با binding مورد انتظار بالا نیامد.',502);
            $health=$this->client->deploymentTargetHealth($target);$healthDeployment=(array)($health['deployment']??[]);
            if(($healthDeployment['coherent']??false)!==true||!hash_equals($target,(string)($healthDeployment['base_url']??'')))throw new PublicEdgeSyncException('public_origin_target_health_mismatch','Health نشانی جدید binding صحیح را تأیید نکرد.',502);
            $candidate=(array)($targetProbe['candidate']??[]);$this->client->deploymentTargetStaffSurface($target,(string)($candidate['manifest_id']??''),6);
            if($this->notifications!==null){$this->notifications->suspendPushForOriginRebind($target);$pushSuspended=true;}
            $this->commitLocalConfig($stagedPath,(string)$staged['base_hash']);
            $finalizePending=false;$transition=(array)($prepared['body']??[]);
            try{$fin=$this->client->deploymentBindingRequest($target,['action'=>'finalize','rebind_id'=>$rebindId]);$transition=(array)($fin['body']??$transition);}catch(Throwable){$finalizePending=true;}
            return ['ok'=>true,'contract'=>self::CONTRACT,'rebind_id'=>$rebindId,'previous_base_url'=>$current,'base_url'=>$target,'public_instance_id'=>$instance,'reload_required'=>true,'finalize_pending'=>$finalizePending,'push_resubscribe_required'=>$pushSuspended,'actor_user_id'=>$actorId,'transition_kind'=>(string)($pre['transition_kind']??''),'pwa_reinstall_required'=>(bool)($pre['pwa_reinstall_required']??false),'reauthentication_required'=>(bool)($pre['reauthentication_required']??false),'service_worker_reconcile_required'=>(bool)($pre['service_worker_reconcile_required']??true),'old_origin_grace_state'=>(string)($transition['old_origin_grace_state']??'active')];
        }catch(Throwable $error){
            @unlink($stagedPath);$rollbackError=null;
            if($publicCommitted&&$rebindId!==''){try{$this->client->deploymentBindingRequest($current,['action'=>'rollback','rebind_id'=>$rebindId]);}catch(Throwable $rb){$rollbackError=$rb;}}
            if($pushSuspended&&$this->notifications!==null&&$rollbackError===null){try{$this->notifications->cancelPushOriginRebind($target);}catch(Throwable $pushRollback){$rollbackError=$pushRollback;}}
            if($rollbackError!==null)throw new PublicEdgeSyncException('public_origin_rebind_recovery_required','تغییر آدرس کامل نشد و rollback خودکار Public نیز تأیید نشد؛ وضعیت recovery باید بررسی شود.',503);
            throw $error;
        }
    }

    private function transitionImpact(string $current,string $target): array
    {
        $co=$this->origin($current);$to=$this->origin($target);$kind=$co!==''&&$to!==''&&hash_equals($co,$to)?'same_origin':'cross_origin';
        $cp=$this->path($current);$tp=$this->path($target);$cookie=$cp===''?'/':rtrim($cp,'/').'/';$request=$tp===''?'/':rtrim($tp,'/').'/';
        $reauth=$kind==='cross_origin'||!str_starts_with($request,$cookie);
        return ['transition_kind'=>$kind,'pwa_reinstall_required'=>$kind==='cross_origin','reauthentication_required'=>$reauth,'push_resubscribe_required'=>true,'service_worker_reconcile_required'=>true,'old_origin_grace_state'=>'pending'];
    }

    private function resolveRebindPhase(string $current,string $target,string $id): string
    {
        foreach([$current,$target] as $base){try{$r=$this->client->deploymentBindingRequest($base,['action'=>'status','rebind_id'=>$id],4);$phase=trim((string)($r['body']['phase']??''));if($phase!=='')return $phase;}catch(Throwable){}}
        return 'unknown';
    }

    private function stageLocalConfig(string $target): array
    {
        $baseHash=is_file($this->configPath)?(string)(hash_file('sha256',$this->configPath)?:''):'';
        $raw=is_file($this->configPath)?require $this->configPath:[];if(!is_array($raw)||$baseHash==='')throw new PublicEdgeSyncException('config_unavailable','تنظیمات سامانه قابل به‌روزرسانی نیست.',500);
        $current=$this->client->publicBaseUrl();$raw['public']=is_array($raw['public']??null)?$raw['public']:[];$raw['public']['base_url']=$target;
        $oldOrigin=$this->origin($current);$newOrigin=$this->origin($target);$raw['lan_staff']=is_array($raw['lan_staff']??null)?$raw['lan_staff']:[];$allowed=trim((string)($raw['lan_staff']['allowed_origin']??''));
        if($allowed!==''&&$oldOrigin!==''&&hash_equals(rtrim($allowed,'/'),$oldOrigin))$raw['lan_staff']['allowed_origin']=$newOrigin;
        $dir=dirname($this->configPath);if(!is_dir($dir)||!is_writable($dir))throw new PublicEdgeSyncException('config_write_failed','مسیر تنظیمات سامانه قابل نوشتن نیست.',500);
        $staged=$this->configPath.'.origin-rebind-'.bin2hex(random_bytes(6)).'.tmp';$php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($raw,true).";\n";
        if(file_put_contents($staged,$php,LOCK_EX)===false){@unlink($staged);throw new PublicEdgeSyncException('config_write_failed','آماده‌سازی تنظیمات آدرس کامل نشد.',500);}@chmod($staged,0600);return ['path'=>$staged,'base_hash'=>$baseHash];
    }

    private function commitLocalConfig(string $staged,string $expectedBaseHash): void
    {
        if(!is_file($staged))throw new PublicEdgeSyncException('config_write_failed','فایل موقت تنظیمات آدرس پیدا نشد.',500);
        $lock=$this->configLock();
        try{
            $currentHash=is_file($this->configPath)?(string)(hash_file('sha256',$this->configPath)?:''):'';
            if($currentHash===''||$expectedBaseHash===''||!hash_equals($expectedBaseHash,$currentHash))throw new PublicEdgeSyncException('local_config_drift','تنظیمات Local هم‌زمان تغییر کرده است؛ تغییر آدرس برای جلوگیری از overwrite متوقف شد.',409);
            if(@rename($staged,$this->configPath)){@chmod($this->configPath,0600);return;}
            $backup=$this->configPath.'.origin-backup-'.bin2hex(random_bytes(4));$had=is_file($this->configPath);
            if($had&&!@rename($this->configPath,$backup)){@unlink($staged);throw new PublicEdgeSyncException('config_write_failed','قفل جایگزینی تنظیمات آدرس باز نشد.',500);}
            try{if(!@rename($staged,$this->configPath)){if($had)@rename($backup,$this->configPath);throw new PublicEdgeSyncException('config_write_failed','ثبت تنظیمات آدرس کامل نشد.',500);}@chmod($this->configPath,0600);if($had)@unlink($backup);}catch(Throwable $e){@unlink($staged);throw $e;}
        }finally{$this->configUnlock($lock);}
    }

    /** @return resource */
    private function configLock(){ $path=$this->configPath.'.write.lock';$h=@fopen($path,'c+');if(!is_resource($h)||!@flock($h,LOCK_EX)){if(is_resource($h))@fclose($h);throw new PublicEdgeSyncException('config_lock_failed','قفل تنظیمات Local در دسترس نیست.',503);}@chmod($path,0600);return $h; }
    private function configUnlock(mixed $handle): void{if(is_resource($handle)){@flock($handle,LOCK_UN);@fclose($handle);}}
    private function origin(string $base): string{$p=parse_url($base);if(!is_array($p))return '';$scheme=strtolower((string)($p['scheme']??''));$host=strtolower(trim((string)($p['host']??''),'[]'));if($scheme===''||$host==='')return '';$authority=str_contains($host,':')?'['.$host.']':$host;if(isset($p['port'])){$port=(int)$p['port'];if(!(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80)))$authority.=':'.$port;}return $scheme.'://'.$authority;}
    private function path(string $base): string{$p=parse_url($base);if(!is_array($p))return '';$v='/'.trim((string)($p['path']??''),'/');return $v==='/'?'':$v;}
}
