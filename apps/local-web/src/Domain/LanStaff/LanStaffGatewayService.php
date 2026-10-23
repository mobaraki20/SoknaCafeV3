<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\LanStaff;
use PDO;use RuntimeException;use Sokna\Local\Core\Config;use Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder;use Sokna\Local\Relay\RealtimeDispatchService;use Sokna\Local\Relay\DeferredDispatchService;
final class LanStaffGatewayService
{
    private const MODEL_CAP=['operations'=>'operations.read','operations_workspace'=>'operations.workspace.read','preparation'=>'preparation.read','inventory'=>'inventory.read','inventory_cost'=>'inventory.cost.read','subscribers'=>'subscriber.read','reports'=>'reports.read','notifications'=>'notifications.read','deferred_context'=>'deferred.context','table_draft_context'=>'orders.table_draft'];
    private const RT_CAP=['operations.mutate'=>'operations.write','operations.query'=>'operations.workspace.read','order.staff_status'=>'orders.mutate','hall.waiter_status'=>'orders.mutate','hall.table_move'=>'orders.mutate','hall.order_reprint'=>'orders.mutate','hall.order_item_adjust'=>'orders.mutate','settlement.review'=>'finance.settle','settlement.discount_preview'=>'finance.settle','settlement.commit'=>'finance.settle','settlement.discount'=>'finance.settle','settlement.receipt_reprint'=>'finance.settle','settlement.followup_detach'=>'finance.settle','settlement.followup_destination'=>'finance.settle','preparation.mutate'=>'preparation.mutate','table_draft.get'=>'orders.table_draft','table_draft.create'=>'orders.table_draft','table_draft.edit'=>'orders.table_draft','table_draft.finalize'=>'orders.table_draft','table_draft.finalize_atomic'=>'orders.table_draft','table_draft.cancel'=>'orders.table_draft','notification.push.subscribe'=>'notifications.push','notification.push.unsubscribe'=>'notifications.push','notification.preferences.save'=>'notifications.push'];
    private const DEF_CAP=['supply.need.create'=>'supply.need.defer','supply.status.prepare'=>'supply.manage.defer','supply.status.return'=>'supply.manage.defer','supply.receipt'=>'supply.manage.defer','inventory.waste'=>'inventory.waste.defer','inventory.count_draft'=>'inventory.count_draft.defer','subscriber.payment'=>'subscriber.payment.defer','expense.create'=>'expense.create.defer'];
    public function __construct(private readonly Config $config,private readonly PDO $pdo,private readonly PublicProjectionBuilder $projection,private readonly RealtimeDispatchService $realtime,private readonly DeferredDispatchService $deferred,private readonly LanStaffRealtimeReceiptService $receipts){}
    public function allowedOrigin(): string
    { $v=trim($this->config->string('lan_staff.allowed_origin',''));if($v!=='')return $this->normalizedOrigin($v);return $this->normalizedOrigin($this->config->string('public.base_url','')); }
    public function authenticate(string $token,string $remoteAddr): array{return $this->authenticateToken($token,$remoteAddr,true);}
    /** Preflight-only authentication lets Setup prove a candidate LAN URL before enabling it. */
    public function authenticatePreflight(string $token,string $remoteAddr): array{return $this->authenticateToken($token,$remoteAddr,false);}
    private function authenticateToken(string $token,string $remoteAddr,bool $requireConfigured): array
    {
        if($requireConfigured&&!$this->lanConfigurationValid())throw new RuntimeException('lan_disabled');
        if(!$this->privateAddress($remoteAddr))throw new RuntimeException('lan_source_forbidden');
        $installation=$this->config->requiredString('installation.id');$secret=$this->config->requiredString('public.shared_secret');
        $payload=(new LanStaffTokenVerifier($secret,$installation))->verify($token);$projectionId=(string)$payload['projection_id'];
        foreach($this->projection->authProjections() as $p)if(hash_equals((string)$p['projection_id'],$projectionId)){
            $uid=(int)substr($projectionId,5);return ['installation_id'=>$installation,'projection_id'=>$projectionId,'user_id'=>$uid,'capabilities'=>array_values(array_map('strval',(array)$p['capabilities'])),'preparation_areas'=>array_values(array_map('strval',(array)$p['preparation_areas'])),'display_name'=>(string)($p['display_name']??''),'role'=>(string)($p['role']??'')];
        }
        throw new RuntimeException('lan_session_revoked');
    }
    public function health(array $session): array{return ['ok'=>true,'mode'=>'local_lan','installation_id'=>$session['installation_id'],'projection_id'=>$session['projection_id'],'display_name'=>(string)($session['display_name']??''),'role'=>(string)($session['role']??''),'role_label'=>$this->roleLabel((string)($session['role']??'')),'capabilities'=>array_values(array_map('strval',(array)($session['capabilities']??[]))),'server_time'=>gmdate('c'),'transport'=>['contract'=>'lan-staff-v3-offline-bootstrap','read'=>true,'realtime'=>true,'deferred'=>true,'offline_bootstrap'=>true,'realtime_kinds'=>array_keys(self::RT_CAP),'deferred_kinds'=>array_keys(self::DEF_CAP)]];}
    public function read(array $session,string $key): array
    {
        $cap=self::MODEL_CAP[$key]??'';if($cap===''||!$this->has($session,$cap))throw new RuntimeException($cap===''?'unknown_model':'forbidden');
        $model=null;foreach($this->projection->remoteModelsFor([$key]) as $m)if(($m['model_key']??'')===$key){$model=$m;break;}if(!is_array($model))throw new RuntimeException('model_unavailable');
        $payload=is_array($model['payload']??null)?$model['payload']:[];$payload=$this->filter($key,$payload,$session);
        return ['ok'=>true,'model_key'=>$key,'source_version'=>(string)$model['source_version'],'generated_at'=>(string)$model['generated_at'],'last_sync_at'=>gmdate(DATE_ATOM),'stale'=>false,'connectivity'=>['local_fresh'=>true,'mode'=>'local_lan'],'payload'=>$payload];
    }
    public function realtime(array $session,array $envelope): array
    {
        $envelope['actor_projection_id']=$session['projection_id'];unset($envelope['session_id']);$invalid=$this->validateEnvelope($envelope,true);if($invalid!==[])return ['ok'=>false,'error'=>'invalid_envelope','fields'=>$invalid,'status'=>400];$kind=trim((string)($envelope['kind']??''));$cap=self::RT_CAP[$kind]??'';
        if($cap===''||!$this->has($session,$cap))return ['ok'=>false,'error'=>$cap===''?'unsupported_kind':'forbidden','status'=>$cap===''?422:403];
        $out=$this->realtime->isMutation($envelope)?$this->receipts->dispatchAtomic((string)$session['installation_id'],$envelope,fn()=>$this->realtime->dispatch($envelope)):$this->receipts->dispatch((string)$session['installation_id'],$envelope,fn()=>$this->realtime->dispatch($envelope));return ['ok'=>true]+$out;
    }
    public function realtimeResult(array $session,string $requestId): array
    { $out=$this->receipts->result((string)$session['installation_id'],$requestId);if(!is_array($out))return ['ok'=>false,'error'=>'not_found','status'=>404];$q=$this->pdo->prepare('SELECT actor_projection_id FROM lan_staff_realtime_receipts WHERE installation_id=? AND request_id=? LIMIT 1');$q->execute([$session['installation_id'],$requestId]);$actor=(string)($q->fetchColumn()?:'');if(!$this->has($session,'*')&&!hash_equals((string)$session['projection_id'],$actor))return ['ok'=>false,'error'=>'forbidden','status'=>403];return ['ok'=>true,'request_id'=>$requestId]+$out; }
    public function deferred(array $session,array $envelope): array
    { $envelope['actor_projection_id']=$session['projection_id'];unset($envelope['session_id']);$invalid=$this->validateEnvelope($envelope,false);if($invalid!==[])return ['ok'=>false,'error'=>'invalid_envelope','fields'=>$invalid,'status'=>400];$kind=trim((string)($envelope['kind']??''));$cap=self::DEF_CAP[$kind]??'';if($cap===''||!$this->has($session,$cap))return ['ok'=>false,'error'=>$cap===''?'unsupported_kind':'forbidden','status'=>$cap===''?422:403];$out=$this->deferred->dispatch((string)$session['installation_id'],$envelope);return ['ok'=>true]+$out; }
    public function deferredResult(array $session,string $requestId): array
    { $q=$this->pdo->prepare('SELECT request_id,kind,actor_projection_id,state,result_json,error_code,occurred_at,created_at,updated_at FROM deferred_work_receipts WHERE installation_id=? AND request_id=? LIMIT 1');$q->execute([$session['installation_id'],$requestId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($r))return ['ok'=>false,'error'=>'not_found','status'=>404];if(!$this->has($session,'*')&&!hash_equals((string)$session['projection_id'],(string)$r['actor_projection_id']))return ['ok'=>false,'error'=>'forbidden','status'=>403];$result=json_decode((string)($r['result_json']??''),true);return ['ok'=>true,'request_id'=>$requestId,'state'=>(string)$r['state'],'result'=>is_array($result)?$result:[],'error_code'=>(string)($r['error_code']??'')]; }
    public function deferredList(array $session,int $limit=12): array
    { $limit=max(1,min(100,$limit));$sql='SELECT request_id,kind,actor_projection_id,state,occurred_at,error_code,created_at,updated_at FROM deferred_work_receipts WHERE installation_id=?';$params=[$session['installation_id']];if(!$this->has($session,'*')){$sql.=' AND actor_projection_id=?';$params[]=$session['projection_id'];}$sql.=' ORDER BY created_at DESC LIMIT '.$limit;$q=$this->pdo->prepare($sql);$q->execute($params);return ['ok'=>true,'items'=>$q->fetchAll(PDO::FETCH_ASSOC)?:[]]; }
    private function roleLabel(string $role): string{return match($role){'admin'=>'مدیر','operator'=>'اپراتور','waiter'=>'همکار سالن',default=>'عضو تیم'};}
    private function has(array $s,string $cap): bool{$c=(array)($s['capabilities']??[]);return in_array('*',$c,true)||$cap==='*'&&in_array('*',$c,true)||in_array($cap,$c,true);}
    private function filter(string $key,array $payload,array $session): array
    {
        if($key==='operations'&&!$this->has($session,'finance.settle'))unset($payload['settlement_accounts']);
        if($key==='operations_workspace'){if(!$this->has($session,'*'))$payload['expenses']=null;$payload=\Sokna\Shared\OperationsSnapshotPolicy::apply($payload,$this->has($session,'*')||$this->has($session,'inventory.cost.read'));}
        if($key==='preparation'){
            $all=$this->has($session,'*');$monitor=!$all&&$this->has($session,'preparation.monitor');$canMutate=!$all&&$this->has($session,'preparation.mutate');
            $assigned=is_array($session['preparation_areas']??null)?array_values(array_intersect(['kitchen','bar'],array_map('strval',$session['preparation_areas']))):[];
            $visible=($all||$monitor)?['kitchen','bar']:$assigned;$actionable=$canMutate?$assigned:[];
            if(array_key_exists('orders',$payload)){
                $orders=[];foreach(is_array($payload['orders']??null)?$payload['orders']:[] as $order){if(!is_array($order))continue;$areas=[];foreach(is_array($order['areas']??null)?$order['areas']:[] as $area){if(!is_array($area))continue;$areaKey=(string)($area['area']??'');if(!in_array($areaKey,$visible,true))continue;$area['actionable']=in_array($areaKey,$actionable,true);$areas[]=$area;}if($areas===[])continue;$order['areas']=$areas;$orders[]=$order;}
                $payload['orders']=$orders;
            }else{
                $allow=static fn(array $row):bool=>in_array((string)($row['area']??$row['area_key']??''),$visible,true);
                $payload['tasks']=array_values(array_filter(is_array($payload['tasks']??null)?$payload['tasks']:[],static fn(mixed $row):bool=>is_array($row)&&$allow($row)));
                $payload['adjustments']=array_values(array_filter(is_array($payload['adjustments']??null)?$payload['adjustments']:[],static fn(mixed $row):bool=>is_array($row)&&$allow($row)));
            }
            $payload['permissions']=['preparation_visible'=>$visible!==[],'preparation_actionable'=>$actionable!==[],'monitor_only'=>$visible!==[]&&$actionable===[],'visible_preparation_areas'=>$visible,'actionable_preparation_areas'=>$actionable];
        }
        if($key==='deferred_context'&&!$this->has($session,'*')){if(!$this->has($session,'supply.need.defer')&&!$this->has($session,'supply.manage.defer'))$payload['supply_groups']=[];if(!$this->has($session,'supply.need.defer')&&!$this->has($session,'supply.manage.defer')&&!$this->has($session,'inventory.waste.defer')&&!$this->has($session,'inventory.count_draft.defer'))$payload['inventory_items']=[];if(!$this->has($session,'inventory.count_draft.defer'))$payload['count_drafts']=[];if(!$this->has($session,'subscriber.payment.defer'))$payload['subscribers']=[];if(!$this->has($session,'expense.create.defer'))$payload['expense_categories']=[];}
        if($key==='notifications'){$id=(string)$session['projection_id'];$payload['items']=array_values(array_filter((array)($payload['items']??[]),static fn($r)=>is_array($r)&&hash_equals($id,(string)($r['projection_id']??''))));foreach($payload['items'] as &$r)unset($r['projection_id']);unset($r);$push=is_array($payload['push']??null)?$payload['push']:[];$subscriptions=is_array($push['subscriptions']??null)?$push['subscriptions']:[];$push['subscriptions']=array_values(array_filter($subscriptions,static fn($r)=>is_array($r)&&hash_equals($id,(string)($r['projection_id']??''))));foreach($push['subscriptions'] as &$r)unset($r['projection_id']);unset($r);$payload['push']=$push;}
        return $payload;
    }
    private function validateEnvelope(array $envelope,bool $realtime): array
    {
        $errors=[];$requestId=trim((string)($envelope['request_id']??''));if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestId)!==1)$errors[]='request_id';
        if(!isset($envelope['payload'])||!is_array($envelope['payload']))$errors[]='payload';$created=\Sokna\Shared\IranTime::wireEpoch((string)($envelope['created_at']??''))?:0;if($created<=0)$errors[]='created_at';
        if($realtime){$expires=\Sokna\Shared\IranTime::wireEpoch((string)($envelope['expires_at']??''))?:0;if($expires<=0||($created>0&&$expires<=$created)||$expires<time()-5)$errors[]='expires_at';if(array_key_exists('occurred_at',$envelope))$errors[]='occurred_at';}
        else{$occurred=\Sokna\Shared\IranTime::wireEpoch((string)($envelope['occurred_at']??''))?:0;if($occurred<=0||$occurred>time()+300)$errors[]='occurred_at';if(array_key_exists('expires_at',$envelope))$errors[]='expires_at';}
        if(isset($envelope['expected_version'])&&!is_int($envelope['expected_version'])&&!is_string($envelope['expected_version']))$errors[]='expected_version';if(isset($envelope['expected_state'])&&!is_string($envelope['expected_state']))$errors[]='expected_state';return array_values(array_unique($errors));
    }
    private function lanConfigurationValid(): bool
    {
        if(!(bool)$this->config->get('lan_staff.enabled',false))return false;
        $base=trim($this->config->string('lan_staff.base_url',''));if($base==='')return false;$p=parse_url($base);if(!is_array($p))return false;
        if(strtolower((string)($p['scheme']??''))!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment']))return false;
        $po=$this->normalizedOrigin($this->config->string('public.base_url',''));$lo=$this->normalizedOrigin($base);
        if($po!==''&&$lo!==''&&hash_equals($po,$lo))return false;
        return true;
    }
    private function normalizedOrigin(string $value): string
    {
        $p=parse_url(trim($value));if(!is_array($p))return '';$scheme=strtolower((string)($p['scheme']??''));$host=strtolower(trim((string)($p['host']??''),'[]'));if(!in_array($scheme,['http','https'],true)||$host===''||isset($p['user'])||isset($p['pass']))return '';
        $portNumber=isset($p['port'])?(int)$p['port']:null;$port=$portNumber!==null&&!(($scheme==='https'&&$portNumber===443)||($scheme==='http'&&$portNumber===80))?':'.$portNumber:'';$authority=str_contains($host,':')?'['.$host.']':$host;return $scheme.'://'.$authority.$port;
    }

    private function privateAddress(string $ip): bool
    {
        if($ip==='127.0.0.1'||$ip==='::1')return true;
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false){$n=ip2long($ip);if($n===false)return false;$n&=0xffffffff;return (($n&0xff000000)===0x0a000000)||(($n&0xfff00000)===0xac100000)||(($n&0xffff0000)===0xc0a80000);}
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false){$bin=inet_pton($ip);if(!is_string($bin)||strlen($bin)!==16)return false;$b0=ord($bin[0]);$b1=ord($bin[1]);return (($b0&0xfe)===0xfc)||($b0===0xfe&&($b1&0xc0)===0x80);}
        return false;
    }
}
