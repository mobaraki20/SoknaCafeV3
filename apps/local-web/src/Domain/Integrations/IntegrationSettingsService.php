<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use Sokna\Local\Core\Config;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Throwable;

final class IntegrationSettingsService
{
    private const LAN_RUNTIME_PROBE_BUDGET_MS=700;

    public function __construct(
        private readonly Config $config,
        private readonly string $configPath,
        private readonly ?PublicEdgeSyncClient $publicClient=null,
        private readonly mixed $httpTransport=null,
        private readonly mixed $resolver=null,
    ) {}

    public function accommodationSnapshot(): array
    {
        $base=$this->config->string('integrations.accommodation.base_url','');$key=$this->apiKeyFromConfig($this->config->all());
        return ['enabled'=>(bool)$this->config->get('integrations.accommodation.enabled',false),'base_url'=>trim($base),'configured'=>trim($base)!==''&&trim($key)!=='','credential_saved'=>trim($key)!=='','key_fingerprint'=>trim($key)!==''?substr(hash('sha256',$key),0,12):''];
    }

    public function saveAccommodation(array $data): array
    {
        $lock=$this->configLock();try{$raw=$this->loadConfig();$base=trim((string)($data['base_url']??''));$enabled=(bool)($data['enabled']??false);$apiKey=trim((string)($data['api_key']??''));if($base!=='')$base=$this->canonicalBase($base);$existing=$this->apiKeyFromConfig($raw);$effectiveKey=$apiKey!==''?$apiKey:$existing;if($enabled&&($base===''||$effectiveKey===''))throw new IntegrationException('accommodation_not_configured','برای فعال‌کردن اقامتگاه، آدرس و کلید اتصال لازم است.',422);$raw['integrations']=is_array($raw['integrations']??null)?$raw['integrations']:[];$raw['integrations']['accommodation']=is_array($raw['integrations']['accommodation']??null)?$raw['integrations']['accommodation']:[];$raw['integrations']['accommodation']['enabled']=$enabled;$raw['integrations']['accommodation']['base_url']=$base;$raw['integrations']['accommodation']['api_key']=$effectiveKey;unset($raw['integrations']['accommodation']['secret']);$this->writeConfig($raw);return ['saved'=>true,'reload_required'=>true,'enabled'=>$enabled,'base_url'=>trim($base),'credential_saved'=>$effectiveKey!==''];}finally{$this->configUnlock($lock);}
    }

    public function lanStaffSnapshot(): array
    {
        $enabled=(bool)$this->config->get('lan_staff.enabled',false);$rawBase=trim($this->config->string('lan_staff.base_url',''));$base='';$valid=true;$error='';
        if($rawBase!==''){try{$base=$this->canonicalLanBase($rawBase);}catch(IntegrationException $e){$valid=false;$error=$e->errorCode;$base=$rawBase;}}
        $publicBase=trim($this->config->string('public.base_url',''));$publicOrigin=$this->safeOrigin($publicBase);$lanOrigin=$valid?$this->safeOrigin($base):'';$separate=$lanOrigin!==''&&($publicOrigin===''||!hash_equals($publicOrigin,$lanOrigin));$allowed=trim($this->config->string('lan_staff.allowed_origin',''));$effectiveAllowed=$allowed!==''?$this->safeOrigin($allowed):$publicOrigin;
        return ['enabled'=>$enabled,'configured'=>$enabled&&$valid&&$base!==''&&$separate,'base_url'=>$base,'valid'=>$valid,'error_code'=>$error,'separate_from_public'=>$separate,'allowed_origin'=>$effectiveAllowed,'allowed_origin_source'=>$allowed!==''?'explicit':'public.base_url','source'=>'config','staff_policy'=>'lan_preferred_public_fallback','guest_policy'=>'public_first','guest_lan_enabled'=>(bool)$this->config->get('lan_guest.enabled',false),'preflight_required'=>$enabled];
    }

    /** Safe, non-mutating LAN validation. No token/secret is returned. */
    public function preflightLanStaff(array $data,int $actorId=0): array
    {
        $enabled=(bool)($data['enabled']??false);$base=trim((string)($data['base_url']??''));
        if(!$enabled)return ['ok'=>true,'enabled'=>false,'safe_to_commit'=>true,'staff_policy'=>'lan_preferred_public_fallback','guest_policy'=>'public_first'];
        if($base==='')throw new IntegrationException('lan_endpoint_required','برای فعال‌کردن مسیر LAN، آدرس داخلی Local لازم است.',422);
        $base=$this->canonicalLanBase($base);$raw=$this->loadConfig();$publicBase=trim((string)($raw['public']['base_url']??''));$publicOrigin=$this->safeOrigin($publicBase);$lanOrigin=$this->safeOrigin($base);
        if($publicOrigin===''||$this->publicClient===null||!$this->publicClient->configured())throw new IntegrationException('lan_public_pairing_required','برای پیش‌بررسی LAN، Public Edge باید متصل باشد.',409);
        if($actorId<1)throw new IntegrationException('lan_actor_required','هویت مدیر برای پیش‌بررسی LAN لازم است.',403);
        if(hash_equals($publicOrigin,$lanOrigin))throw new IntegrationException('lan_endpoint_must_be_separate','آدرس LAN باید از origin وب عمومی جدا باشد.',422);
        $parts=parse_url($base);$host=strtolower(trim((string)($parts['host']??''),'[]'));$resolved=$this->resolveLanHost($host);
        $tokenResult=$this->publicClient->mintLanStaffToken('user:'.$actorId,900);$token=trim((string)($tokenResult['token']??''));if($token==='')throw new IntegrationException('lan_token_unavailable','توکن کوتاه‌عمر پیش‌بررسی LAN ساخته نشد.',503);
        $url=rtrim($base,'/').'/lan-staff/api.php?action=preflight_health';
        $pre=$this->http($url,'OPTIONS',[
            'Origin'=>$publicOrigin,
            'Access-Control-Request-Method'=>'GET',
            'Access-Control-Request-Headers'=>'content-type, x-sokna-lan-token',
            'Access-Control-Request-Private-Network'=>'true',
        ],null,1500);
        if((int)$pre['status']!==204)throw new IntegrationException('lan_cors_preflight_failed','پیش‌بررسی CORS مسیر LAN تأیید نشد.',422,['http_status'=>(int)$pre['status']]);
        $acao=$this->header($pre,'access-control-allow-origin');$acah=strtolower($this->header($pre,'access-control-allow-headers'));$acam=strtoupper($this->header($pre,'access-control-allow-methods'));$acapn=strtolower($this->header($pre,'access-control-allow-private-network'));
        if($acao===''||!hash_equals($publicOrigin,rtrim($acao,'/')))throw new IntegrationException('lan_cors_origin_mismatch','CORS مسیر LAN، origin فعلی Public را تأیید نکرد.',422);
        if(!str_contains($acah,'x-sokna-lan-token')||!str_contains($acam,'GET'))throw new IntegrationException('lan_cors_headers_invalid','CORS مسیر LAN header/method لازم را اجازه نمی‌دهد.',422);
        if($acapn!=='true')throw new IntegrationException('lan_private_network_access_missing','مسیر LAN مجوز Private Network Access مرورگر را ارائه نمی‌کند.',422);
        $successes=[];$failures=[];
        for($i=0;$i<3;$i++){
            $started=microtime(true);
            try{$r=$this->http($url,'GET',['Origin'=>$publicOrigin,'Accept'=>'application/json','X-Sokna-Lan-Token'=>$token],null,1200);$elapsed=(int)round((microtime(true)-$started)*1000);$j=is_array($r['json']??null)?$r['json']:[];if((int)$r['status']!==200||($j['ok']??false)!==true)throw new IntegrationException('lan_health_failed','Health مسیر LAN تأیید نشد.',502,['http_status'=>(int)$r['status']]);if(!hash_equals($this->publicClient->installationId(),trim((string)($j['installation_id']??''))))throw new IntegrationException('lan_installation_mismatch','LAN به installation دیگری متصل است.',409);if(!hash_equals('user:'.$actorId,trim((string)($j['projection_id']??''))))throw new IntegrationException('lan_projection_mismatch','توکن LAN برای کاربر مورد انتظار تأیید نشد.',409);$transport=(array)($j['transport']??[]);$contract=trim((string)($transport['contract']??''));if(($j['mode']??'')!=='local_lan'||!str_starts_with($contract,'lan-staff-v'))throw new IntegrationException('lan_transport_contract_invalid','قرارداد transport مسیر LAN معتبر نیست.',409);$successes[]=$elapsed;}catch(Throwable $e){$failures[]=$e instanceof IntegrationException?$e->errorCode:'lan_probe_failed';}
        }
        if(count($successes)<2)throw new IntegrationException('lan_health_quorum_failed','مسیر LAN در پیش‌بررسی پایدار نبود؛ حداقل دو probe از سه probe باید موفق باشد.',502,['successes'=>count($successes),'failures'=>$failures]);
        sort($successes);$median=$successes[(int)floor(count($successes)/2)];
        if($median>self::LAN_RUNTIME_PROBE_BUDGET_MS)throw new IntegrationException('lan_endpoint_too_slow_for_runtime','مسیر LAN سالم است اما برای انتخاب خودکار بیش از حد کند است.',422,['median_latency_ms'=>$median,'runtime_budget_ms'=>self::LAN_RUNTIME_PROBE_BUDGET_MS]);
        return ['ok'=>true,'enabled'=>true,'safe_to_commit'=>true,'base_url'=>$base,'lan_origin'=>$lanOrigin,'public_origin'=>$publicOrigin,'resolved_private_addresses'=>$resolved,'cors'=>['origin'=>true,'token_header'=>true,'get_method'=>true,'private_network_access'=>true],'health_quorum'=>count($successes).'/3','median_latency_ms'=>$median,'runtime_probe_budget_ms'=>self::LAN_RUNTIME_PROBE_BUDGET_MS,'staff_policy'=>'lan_preferred_public_fallback','guest_policy'=>'public_first','checked_at'=>gmdate('c')];
    }

    public function saveLanStaff(array $data,int $actorId=0): array
    {
        $enabled=(bool)($data['enabled']??false);$pre=$this->preflightLanStaff($data,$actorId);$prePublicOrigin=(string)($pre['public_origin']??'');
        $lock=$this->configLock();try{$raw=$this->loadConfig();$base=trim((string)($data['base_url']??''));if($base!=='')$base=$this->canonicalLanBase($base);$publicBase=trim((string)($raw['public']['base_url']??''));$publicOrigin=$this->safeOrigin($publicBase);if($enabled&&($prePublicOrigin===''||!hash_equals($prePublicOrigin,$publicOrigin)))throw new IntegrationException('lan_preflight_public_origin_drift','آدرس Public بعد از پیش‌بررسی تغییر کرده است؛ LAN دوباره بررسی شود.',409);$lanOrigin=$base!==''?$this->safeOrigin($base):'';if($enabled&&$publicOrigin!==''&&$lanOrigin!==''&&hash_equals($publicOrigin,$lanOrigin))throw new IntegrationException('lan_endpoint_must_be_separate','آدرس LAN باید از origin وب عمومی جدا باشد.',422);
            $raw['lan_staff']=is_array($raw['lan_staff']??null)?$raw['lan_staff']:[];$raw['lan_staff']['enabled']=$enabled;$raw['lan_staff']['base_url']=$base;if(!array_key_exists('allowed_origin',$raw['lan_staff']))$raw['lan_staff']['allowed_origin']='';$raw['lan_staff']['mode']='lan_preferred_public_fallback';$raw['lan_staff']['config_version']=2;$raw['lan_staff']['last_preflight_at']=$enabled?(string)($pre['checked_at']??gmdate('c')):'';
            $raw['lan_guest']=is_array($raw['lan_guest']??null)?$raw['lan_guest']:[];$raw['lan_guest']['enabled']=false;$raw['lan_guest']['mode']='public_first';$this->writeConfig($raw);
            return ['saved'=>true,'reload_required'=>true,'enabled'=>$enabled,'base_url'=>$base,'separate_from_public'=>$base===''||$publicOrigin===''||!hash_equals($publicOrigin,$lanOrigin),'staff_policy'=>'lan_preferred_public_fallback','guest_policy'=>'public_first','preflight'=>$pre];
        }finally{$this->configUnlock($lock);}
    }

    private function resolveLanHost(string $host): array
    {
        if($host===''||in_array($host,['localhost','localhost.localdomain'],true))throw new IntegrationException('lan_host_not_private','LAN hostname باید به یک آدرس خصوصی شبکه کافه resolve شود.',422);
        $ips=[];
        if(filter_var($host,FILTER_VALIDATE_IP)!==false)$ips[]=$host;
        elseif(is_callable($this->resolver)){$v=($this->resolver)($host);if(is_array($v))$ips=$v;}
        else{
            $v=@gethostbynamel($host);if(is_array($v))$ips=array_merge($ips,$v);
            if(function_exists('dns_get_record')){foreach((array)@dns_get_record($host,DNS_AAAA) as $row){$ip=trim((string)($row['ipv6']??''));if($ip!=='')$ips[]=$ip;}}
        }
        $ips=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$ips))));if(!$ips)throw new IntegrationException('lan_dns_unresolved','LAN hostname به هیچ آدرسی resolve نشد.',422);
        foreach($ips as $ip)if(!$this->isPrivateLanIp($ip))throw new IntegrationException('lan_dns_public_address','LAN hostname به آدرس عمومی/نامعتبر resolve می‌شود و برای جلوگیری از DNS rebinding رد شد.',422,['address'=>$this->safeIpLabel($ip)]);
        return $ips;
    }

    private function isPrivateLanIp(string $ip): bool
    {
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false){$n=ip2long($ip);if($n===false)return false;$u=(int)sprintf('%u',$n);return ($u>=167772160&&$u<=184549375)||($u>=2886729728&&$u<=2887778303)||($u>=3232235520&&$u<=3232301055);}
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false){$bin=@inet_pton($ip);if(!is_string($bin)||strlen($bin)!==16)return false;$first=ord($bin[0]);return ($first&0xFE)===0xFC;}
        return false;
    }
    private function safeIpLabel(string $ip): string{return filter_var($ip,FILTER_VALIDATE_IP)!==false?$ip:'invalid';}

    private function http(string $url,string $method,array $headers,?string $body,int $timeoutMs): array
    {
        if(is_callable($this->httpTransport)){$r=($this->httpTransport)($url,$method,$headers,$body,$timeoutMs);if(!is_array($r))throw new IntegrationException('lan_probe_transport_invalid','Transport پیش‌بررسی LAN پاسخ معتبر نداد.',502);$r['headers']=$this->normalizeHeaders((array)($r['headers']??[]));if(!isset($r['json'])&&isset($r['body'])&&is_string($r['body'])){$j=json_decode($r['body'],true);if(is_array($j))$r['json']=$j;}return ['status'=>(int)($r['status']??0),'headers'=>$r['headers'],'body'=>(string)($r['body']??''),'json'=>(array)($r['json']??[])];}
        $lines=[];foreach($headers as $k=>$v)$lines[]=$k.': '.$v;$ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$lines)."\r\nConnection: close\r\n",'content'=>$body??'','timeout'=>max(.25,$timeoutMs/1000),'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);$raw=@file_get_contents($url,false,$ctx);$responseHeaders=$http_response_header??[];$status=0;if(isset($responseHeaders[0])&&preg_match('/\s(\d{3})(?:\s|$)/',(string)$responseHeaders[0],$m))$status=(int)$m[1];$json=[];if(is_string($raw)&&$raw!==''){$v=json_decode($raw,true);if(is_array($v))$json=$v;}return ['status'=>$status,'headers'=>$this->normalizeHeaders($responseHeaders),'body'=>is_string($raw)?$raw:'','json'=>$json];
    }
    private function normalizeHeaders(array $headers): array{$out=[];foreach($headers as $k=>$v){if(is_string($k)){$out[strtolower(trim($k))]=trim(is_array($v)?implode(', ',$v):(string)$v);continue;}if(!is_string($v)||!str_contains($v,':'))continue;[$name,$value]=explode(':',$v,2);$out[strtolower(trim($name))]=trim($value);}return $out;}
    private function header(array $response,string $name): string{return trim((string)(($response['headers'][strtolower($name)]??'')));}

    private function apiKeyFromConfig(array $config): string{$a=is_array($config['integrations']['accommodation']??null)?$config['integrations']['accommodation']:[];return trim((string)($a['api_key']??$a['secret']??''));}
    private function canonicalBase(string $value): string{$parts=parse_url($value);if(!is_array($parts))throw new IntegrationException('accommodation_url_invalid','آدرس اقامتگاه معتبر نیست.',422);$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));if($host===''||!in_array($scheme,['http','https'],true))throw new IntegrationException('accommodation_url_invalid','آدرس اقامتگاه باید یک نشانی کامل HTTP/HTTPS باشد.',422);if($scheme!=='https'&&!in_array($host,['localhost','127.0.0.1','::1'],true))throw new IntegrationException('insecure_endpoint','ارتباط اقامتگاه باید HTTPS باشد.',422);$port=isset($parts['port'])?':'.(int)$parts['port']:'';$path=rtrim((string)($parts['path']??''),'/');return $scheme.'://'.(string)($parts['host']??'').$port.$path;}
    private function canonicalLanBase(string $value): string{$parts=parse_url(trim($value));if(!is_array($parts))throw new IntegrationException('lan_endpoint_invalid','آدرس LAN معتبر نیست.',422);$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));if($host===''||$scheme==='')throw new IntegrationException('lan_endpoint_invalid','آدرس LAN باید یک URL کامل باشد.',422);if($scheme!=='https')throw new IntegrationException('lan_endpoint_https_required','برای مسیر LAN، HTTPS معتبر الزامی است.',422);if(isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new IntegrationException('lan_endpoint_invalid','آدرس LAN نباید شامل نام کاربری، query یا fragment باشد.',422);$path=(string)($parts['path']??'');if($path!==''&&$path!=='/'){$decoded=rawurldecode($path);foreach(explode('/',$decoded) as $segment)if($segment==='.'||$segment==='..')throw new IntegrationException('lan_endpoint_invalid','مسیر آدرس LAN معتبر نیست.',422);if(str_contains($decoded,"\0"))throw new IntegrationException('lan_endpoint_invalid','مسیر آدرس LAN معتبر نیست.',422);}$portNumber=isset($parts['port'])?(int)$parts['port']:null;$port=$portNumber!==null&&$portNumber!==443?':'.$portNumber:'';$path=rtrim($path,'/');$hostOut=str_contains($host,':')?'['.$host.']':$host;return $scheme.'://'.$hostOut.$port.$path;}
    private function safeOrigin(string $value): string{$value=trim($value);if($value==='')return '';$parts=parse_url($value);if(!is_array($parts))return '';$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower(trim((string)($parts['host']??''),'[]'));if($scheme===''||$host==='')return '';$portNumber=isset($parts['port'])?(int)$parts['port']:null;$port=$portNumber!==null&&!(($scheme==='https'&&$portNumber===443)||($scheme==='http'&&$portNumber===80))?':'.$portNumber:'';$hostOut=str_contains($host,':')?'['.$host.']':$host;return $scheme.'://'.$hostOut.$port;}
    /** @return resource */ private function configLock(){$path=$this->configPath.'.write.lock';$h=@fopen($path,'c+');if(!is_resource($h)||!@flock($h,LOCK_EX)){if(is_resource($h))@fclose($h);throw new IntegrationException('config_lock_failed','قفل تنظیمات سامانه در دسترس نیست.',503);}@chmod($path,0600);return $h;}
    private function configUnlock(mixed $handle): void{if(is_resource($handle)){@flock($handle,LOCK_UN);@fclose($handle);}}
    private function loadConfig(): array{if(!is_file($this->configPath))throw new IntegrationException('config_unavailable','فایل تنظیمات سامانه پیدا نشد.',500);try{$raw=require $this->configPath;}catch(Throwable){throw new IntegrationException('config_unavailable','تنظیمات سامانه قابل خواندن نیست.',500);}if(!is_array($raw))throw new IntegrationException('config_unavailable','تنظیمات سامانه معتبر نیست.',500);return $raw;}
    private function writeConfig(array $raw): void{$directory=dirname($this->configPath);if(!is_dir($directory)||!is_writable($directory))throw new IntegrationException('config_write_failed','مسیر تنظیمات سامانه قابل نوشتن نیست.',500);$tmp=$this->configPath.'.integration-'.bin2hex(random_bytes(5)).'.tmp';$php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($raw,true).";\n";if(file_put_contents($tmp,$php,LOCK_EX)===false){@unlink($tmp);throw new IntegrationException('config_write_failed','ثبت تنظیمات اتصال کامل نشد.',500);}@chmod($tmp,0600);if(@rename($tmp,$this->configPath)){@chmod($this->configPath,0600);return;}$backup=$this->configPath.'.integration-backup-'.bin2hex(random_bytes(4));$had=is_file($this->configPath);if($had&&!@rename($this->configPath,$backup)){@unlink($tmp);throw new IntegrationException('config_write_failed','قفل جایگزینی تنظیمات اتصال باز نشد.',500);}if(!@rename($tmp,$this->configPath)){if($had)@rename($backup,$this->configPath);@unlink($tmp);throw new IntegrationException('config_write_failed','ثبت تنظیمات اتصال کامل نشد.',500);}@chmod($this->configPath,0600);if($had)@unlink($backup);}
}
