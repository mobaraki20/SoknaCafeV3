<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Notifications;
use Sokna\Local\Core\Config;
use PDO;use Throwable;
use Sokna\Local\UI\MoneyDisplay;
final class NotificationService
{
    private const PUSH_BINDING_KEY='notifications.push_public_binding_sha256';
    private const PUSH_REBIND_PENDING_KEY='notifications.push_public_rebind_pending_sha256';
    public function __construct(private readonly PDO $pdo,private readonly ?Config $config=null){}

    /** Canonical Local notification route with compatibility for historical persisted paths. */
    public static function normalizeTargetUrl(string $url): string
    {
        $url=trim($url);if($url==='')return '';
        if(!str_starts_with($url,'/'))return $url;
        if($url==='/preparation'||$url==='/staff/preparation')return '/waiter/';
        foreach(['/preparation/','/staff/preparation/'] as $legacy){
            if(str_starts_with($url,$legacy))return '/waiter/'.substr($url,strlen($legacy));
        }
        return $url;
    }

    /** Bind legacy/current Push rows to the currently configured Public deployment before a rebind starts. */
    public function sealCurrentPushBinding(): array
    {
        $current=$this->currentPublicBindingFingerprint();if($current==='')throw new NotificationException('push_binding_unavailable','Public binding برای اعلان Push معتبر نیست.',409);
        $stored=$this->setting(self::PUSH_BINDING_KEY);if($stored===''){$this->setSetting(self::PUSH_BINDING_KEY,$current);$stored=$current;}
        return ['binding_fingerprint'=>$current,'stored_fingerprint'=>$stored,'binding_ready'=>hash_equals($stored,$current)&&$this->setting(self::PUSH_REBIND_PENDING_KEY)===''];
    }

    /** Suspend delivery before Local commits a new Public origin. Old-origin endpoints must never receive after the switch. */
    public function suspendPushForOriginRebind(string $targetBaseUrl): array
    {
        $target=$this->bindingFingerprint($targetBaseUrl);if($target==='')throw new NotificationException('push_binding_target_invalid','Public target binding برای Push معتبر نیست.',422);
        $this->setSetting(self::PUSH_REBIND_PENDING_KEY,$target);return ['suspended'=>true,'target_binding_fingerprint'=>$target];
    }

    public function cancelPushOriginRebind(string $targetBaseUrl): void
    {
        $target=$this->bindingFingerprint($targetBaseUrl);$pending=$this->setting(self::PUSH_REBIND_PENDING_KEY);if($target!==''&&$pending!==''&&hash_equals($pending,$target))$this->setSetting(self::PUSH_REBIND_PENDING_KEY,'');
    }
    public function snapshot(array $user): array
    {
        $uid=$this->uid($user);$this->ensurePreference($uid);$q=$this->pdo->prepare('SELECT in_app_enabled,push_enabled,push_operations_enabled,push_management_enabled,updated_at FROM notification_preferences WHERE user_id=?');$q->execute([$uid]);$pref=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $q=$this->pdo->prepare('SELECT id,title,body,target_url,read_at,created_at FROM notification_inbox WHERE user_id=? ORDER BY id DESC LIMIT 100');$q->execute([$uid]);$items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($items as &$item)$item['target_url']=self::normalizeTargetUrl((string)($item['target_url']??''));unset($item);
        $binding=$this->pushBindingState();$subscriptions=0;if(($binding['ready']??false)===true){$q=$this->pdo->prepare('SELECT COUNT(*) FROM notification_push_endpoint_owners WHERE user_id=?');$q->execute([$uid]);$subscriptions=(int)$q->fetchColumn();}
        return ['preferences'=>$pref,'items'=>$items,'unread'=>count(array_filter($items,fn($r)=>empty($r['read_at']))),'push'=>['subscriptions'=>\Sokna\Shared\IranTime::transport($subscriptions,'UTC'),'vapid_public_key'=>$this->setting('notifications.vapid_public_key'),'bridge_configured'=>$this->setting('notifications.push_bridge_url')!=='','binding_ready'=>(bool)($binding['ready']??false),'origin_rebind_pending'=>(bool)($binding['rebind_pending']??false)]];
    }
    public function savePreferences(array $input,array $user): array{$uid=$this->uid($user);$q=$this->pdo->prepare('INSERT INTO notification_preferences(user_id,in_app_enabled,push_enabled,push_operations_enabled,push_management_enabled) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE in_app_enabled=VALUES(in_app_enabled),push_enabled=VALUES(push_enabled),push_operations_enabled=VALUES(push_operations_enabled),push_management_enabled=VALUES(push_management_enabled)');$q->execute([$uid,!empty($input['in_app_enabled'])?1:0,!empty($input['push_enabled'])?1:0,!empty($input['push_operations_enabled'])?1:0,!empty($input['push_management_enabled'])?1:0]);return $this->snapshot($user)['preferences'];}
    public function registerPush(array $subscription,array $user): array
    {
        $uid=$this->uid($user);$endpoint=trim((string)($subscription['endpoint']??''));$keys=is_array($subscription['keys']??null)?$subscription['keys']:[];$p=trim((string)($keys['p256dh']??''));$a=trim((string)($keys['auth']??''));if($endpoint===''||!str_starts_with(strtolower($endpoint),'https://')||$p===''||$a==='')throw new NotificationException('invalid_subscription','اشتراک Push معتبر نیست.',422);if(strlen($endpoint)>3000||strlen($p)>255||strlen($a)>255)throw new NotificationException('invalid_subscription','اشتراک Push معتبر نیست.',422);$hash=hash('sha256',$endpoint);$binding=$this->pushBindingState();$current=(string)($binding['current']??'');if($current==='')throw new NotificationException('push_binding_unavailable','Public binding برای اعلان Push معتبر نیست.',409);
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            if(($binding['ready']??false)!==true){$this->pdo->exec('UPDATE notification_push_subscriptions SET active=0 WHERE active=1');$this->pdo->exec('DELETE FROM notification_push_endpoint_owners');$this->setSetting(self::PUSH_BINDING_KEY,$current);$this->setSetting(self::PUSH_REBIND_PENDING_KEY,'');}
            $this->pdo->prepare('INSERT INTO notification_push_endpoint_owners(endpoint_hash,user_id,endpoint_url,last_seen_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint_url=VALUES(endpoint_url),last_seen_at=UTC_TIMESTAMP()')->execute([$hash,$uid,$endpoint]);
            $this->pdo->prepare('UPDATE notification_push_subscriptions SET active=0 WHERE endpoint_hash=? AND user_id<>? AND active=1')->execute([$hash,$uid]);
            $q=$this->pdo->prepare('INSERT INTO notification_push_subscriptions(user_id,endpoint_hash,endpoint_url,p256dh_key,auth_secret,active,last_seen_at) VALUES(?,?,?,?,?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE endpoint_url=VALUES(endpoint_url),p256dh_key=VALUES(p256dh_key),auth_secret=VALUES(auth_secret),active=1,last_seen_at=UTC_TIMESTAMP()');$q->execute([$uid,$hash,$endpoint,$p,$a]);
            $this->pdo->prepare('INSERT INTO notification_preferences(user_id,in_app_enabled,push_enabled) VALUES(?,1,1) ON DUPLICATE KEY UPDATE push_enabled=1')->execute([$uid]);
            if($ownsTransaction)$this->pdo->commit();
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['registered'=>true,'endpoint_hash'=>$hash,'owner_user_id'=>$uid];
    }
    public function unregisterPush(string $endpoint,array $user): array{$uid=$this->uid($user);$hash=hash('sha256',trim($endpoint));$ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();try{$q=$this->pdo->prepare('UPDATE notification_push_subscriptions SET active=0 WHERE user_id=? AND endpoint_hash=?');$q->execute([$uid,$hash]);$removed=$q->rowCount();$owner=$this->pdo->prepare('DELETE FROM notification_push_endpoint_owners WHERE endpoint_hash=? AND user_id=?');$owner->execute([$hash,$uid]);if($ownsTransaction)$this->pdo->commit();return ['removed'=>$removed,'owner_removed'=>$owner->rowCount()];}catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
    public function markRead(int $id,array $user): array{$uid=$this->uid($user);$q=$this->pdo->prepare('UPDATE notification_inbox SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=? AND user_id=?');$q->execute([$id,$uid]);return ['updated'=>$q->rowCount()];}
    public function compose(array $input,array $actor): array
    {
        if((string)($actor['role']??'')!=='admin')throw new NotificationException('forbidden','ارسال اعلان فقط برای مدیر فعال است.',403);$title=$this->text($input['title']??'',180);$body=$this->text($input['body']??'',600);$url=self::normalizeTargetUrl(trim((string)($input['target_url']??'')));if($url!==''&&!str_starts_with($url,'/'))throw new NotificationException('invalid_url','لینک اعلان باید داخلی باشد.',422);$role=trim((string)($input['role']??''));$sql="SELECT id FROM users WHERE active=1";$p=[];if($role!==''&&in_array($role,['admin','operator','waiter'],true)){$sql.=' AND role=?';$p[]=$role;}$ids=$this->preparedColumn($sql,$p);$event='manual.'.gmdate('YmdHis').'.'.bin2hex(random_bytes(4));$payload=['source'=>'manual','actor_user_id'=>(int)$actor['id'],'notification_category'=>'management'];if($url!=='')$payload['notification_actions']=[['action'=>'open','title'=>'مشاهده','url'=>$url]];$queued=0;foreach($ids as $uid)$queued+=$this->queueForUser((int)$uid,$event,$title,$body,$url,$payload);return ['recipients'=>count($ids),'queued'=>$queued,'event_key'=>$event];
    }
    public function syncRoleCondition(string $conditionKey,bool $active,string $title,string $body,string $url='',string $role='admin',array $payload=[]): array
    {
        $conditionKey=preg_replace('/[^a-z0-9_.-]+/i','.',trim($conditionKey))?:'condition';
        $event=substr('condition.'.$conditionKey,0,80);
        $title=$this->text($title,180);$body=$this->text($body,600);$url=self::normalizeTargetUrl($url);
        if($url!==''&&!str_starts_with($url,'/'))throw new NotificationException('invalid_url','لینک اعلان باید داخلی باشد.',422);
        $sql='SELECT id FROM users WHERE active=1';$params=[];
        if($role!==''&&in_array($role,['admin','operator','waiter'],true)){$sql.=' AND role=?';$params[]=$role;}
        $ids=$this->preparedColumn($sql,$params);$created=0;$updated=0;$resolved=0;
        if(!isset($payload['notification_category']))$payload['notification_category']='management';
        $fingerprint=hash('sha256',$title."\n".$body."\n".$url."\n".json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        foreach($ids as $uid){
            $uid=(int)$uid;$this->ensurePreference($uid);
            $prefQ=$this->pdo->prepare('SELECT in_app_enabled,push_enabled,push_operations_enabled,push_management_enabled FROM notification_preferences WHERE user_id=?');$prefQ->execute([$uid]);$pref=$prefQ->fetch(PDO::FETCH_ASSOC)?:[];
            $category=(string)($payload['notification_category']??'management');$categoryPush=$category==='operations'?(int)($pref['push_operations_enabled']??1)===1:(int)($pref['push_management_enabled']??1)===1;
            foreach(['in_app'=>(int)($pref['in_app_enabled']??0)===1,'web_push'=>(int)($pref['push_enabled']??0)===1&&$categoryPush] as $channel=>$enabled){
                $idk=hash('sha256',$event.'|'.$uid.'|'.$channel);
                $q=$this->pdo->prepare('SELECT id,state,payload_json FROM notification_outbox WHERE idempotency_key=? LIMIT 1');$q->execute([$idk]);$row=$q->fetch(PDO::FETCH_ASSOC)?:null;
                if(!$active){
                    if(is_array($row)){
                        $old=json_decode((string)($row['payload_json']??''),true);$old=is_array($old)?$old:[];
                        if(($old['condition_active']??null)!==false){$old['condition_active']=false;$old['condition_fingerprint']=$fingerprint;$this->pdo->prepare('UPDATE notification_outbox SET payload_json=? WHERE id=?')->execute([json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$row['id']]);$this->pdo->prepare('UPDATE notification_inbox SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE user_id=? AND outbox_id=?')->execute([$uid,(int)$row['id']]);$resolved++;}
                    }
                    continue;
                }
                if(!$enabled&&!is_array($row))continue;
                $conditionPayload=$payload;$conditionPayload['condition_active']=true;$conditionPayload['condition_fingerprint']=$fingerprint;
                $json=json_encode($conditionPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                if(!is_array($row)){
                    $stmt=$this->pdo->prepare("INSERT IGNORE INTO notification_outbox(user_id,event_key,channel,title,body,target_url,payload_json,idempotency_key,state,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,'pending',UTC_TIMESTAMP())");
                    $stmt->execute([$uid,$event,$channel,$title,$body,$url!==''?$url:null,$json,$idk]);$created+=$stmt->rowCount();continue;
                }
                $old=json_decode((string)($row['payload_json']??''),true);$old=is_array($old)?$old:[];$wasActive=($old['condition_active']??false)===true;$oldFingerprint=(string)($old['condition_fingerprint']??'');
                if($wasActive&&hash_equals($oldFingerprint,$fingerprint))continue;
                $state=(string)($row['state']??'pending');$newState=$channel==='web_push'&&$enabled?'pending':$state;
                $next=$channel==='web_push'&&$enabled?gmdate('Y-m-d H:i:s'):null;
                $this->pdo->prepare('UPDATE notification_outbox SET title=?,body=?,target_url=?,payload_json=?,state=?,next_attempt_at=?,last_error=NULL WHERE id=?')->execute([$title,$body,$url!==''?$url:null,$json,$newState,$next,(int)$row['id']]);
                if($channel==='in_app')$this->pdo->prepare('UPDATE notification_inbox SET title=?,body=?,target_url=?,read_at=NULL WHERE user_id=? AND outbox_id=?')->execute([$title,$body,$url!==''?$url:null,$uid,(int)$row['id']]);
                $updated++;
            }
        }
        return ['active'=>$active,'condition_key'=>$conditionKey,'created'=>$created,'updated'=>$updated,'resolved'=>$resolved];
    }

    public function queueForUser(int $uid,string $event,string $title,string $body,string $url='',array $payload=[],int $priority=50,int $ttlSeconds=300,?string $occurredAt=null): int
    {
        $url=self::normalizeTargetUrl($url);
        $this->ensurePreference($uid);$q=$this->pdo->prepare('SELECT in_app_enabled,push_enabled,push_operations_enabled,push_management_enabled FROM notification_preferences WHERE user_id=?');$q->execute([$uid]);$pref=$q->fetch(PDO::FETCH_ASSOC)?:[];$category=(string)($payload['notification_category']??'management');$categoryPush=$category==='operations'?(int)($pref['push_operations_enabled']??1)===1:(int)($pref['push_management_enabled']??1)===1;$channels=[];if(!empty($pref['in_app_enabled']))$channels[]='in_app';if(!empty($pref['push_enabled'])&&$categoryPush)$channels[]='web_push';$priority=max(0,min(100,$priority));$ttlSeconds=max(30,min(86400,$ttlSeconds));$count=0;$occurredAt=$occurredAt!==null&&trim($occurredAt)!==''?trim($occurredAt):null;foreach($channels as $ch){$idk=hash('sha256',$event.'|'.$uid.'|'.$ch);if($occurredAt===null){$stmt=$this->pdo->prepare("INSERT IGNORE INTO notification_outbox(user_id,event_key,channel,priority,title,body,target_url,payload_json,idempotency_key,state,next_attempt_at,ttl_expires_at) VALUES(?,?,?,?,?,?,?,?,?,'pending',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))");$params=[$uid,$event,$ch,$priority,$title,$body,$url!==''?$url:null,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$idk,$ttlSeconds];}else{$stmt=$this->pdo->prepare("INSERT IGNORE INTO notification_outbox(user_id,event_key,channel,priority,title,body,target_url,payload_json,idempotency_key,state,next_attempt_at,ttl_expires_at) VALUES(?,?,?,?,?,?,?,?,?,'pending',UTC_TIMESTAMP(),DATE_ADD(?,INTERVAL ? SECOND))");$params=[$uid,$event,$ch,$priority,$title,$body,$url!==''?$url:null,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$idk,$occurredAt,$ttlSeconds];}$stmt->execute($params);$count+=$stmt->rowCount();}return $count;
    }
    public function processPending(int $limit=50,?int $deadlineEpochMs=null): array
    {
        $limit=max(1,min(200,$limit));$deadlineEpochMs=$deadlineEpochMs??((int)floor(microtime(true)*1000)+8000);$this->recoverStaleClaims();$events=$this->queueOperationalEvents();
        $this->pdo->exec("UPDATE notification_outbox SET state='skipped',last_error='ttl_expired',processing_token=NULL,processing_started_at=NULL,lease_expires_at=NULL WHERE state='pending' AND ttl_expires_at IS NOT NULL AND ttl_expires_at<=UTC_TIMESTAMP()");
        $rows=$this->pdo->query("SELECT * FROM notification_outbox WHERE state='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) AND (ttl_expires_at IS NULL OR ttl_expires_at>UTC_TIMESTAMP()) ORDER BY priority DESC,id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];$done=0;$failed=0;$skipped=0;$claimedElsewhere=0;$budgetExhausted=false;
        foreach($rows as $r){if($this->remainingMs($deadlineEpochMs)<450){$budgetExhausted=true;break;}$id=(int)$r['id'];$token=bin2hex(random_bytes(16));$claim=$this->pdo->prepare("UPDATE notification_outbox SET state='processing',processing_token=?,processing_started_at=UTC_TIMESTAMP(),lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 SECOND),attempts=attempts+1 WHERE id=? AND state='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())");$claim->execute([$token,$id]);if($claim->rowCount()!==1){$claimedElsewhere++;continue;}
            try{$channel=(string)$r['channel'];if($channel==='in_app'){$this->pdo->prepare('INSERT IGNORE INTO notification_inbox(user_id,outbox_id,title,body,target_url) VALUES(?,?,?,?,?)')->execute([(int)$r['user_id'],$id,(string)$r['title'],(string)$r['body'],$r['target_url']]);$this->delivered($id,$token,['channel'=>'in_app','device_display'=>'not_applicable']);$done++;continue;}$result=$this->deliverPush($r,$deadlineEpochMs);$pushState=(string)($result['state']??'');if($pushState==='skipped'){$this->finishClaim($id,$token,'skipped',(string)($result['reason']??'push_skipped'),$result);$skipped++;}elseif($pushState==='failed'){$this->finishClaim($id,$token,'failed',(string)($result['reason']??'push_failed'),$result);$failed++;}else{$this->delivered($id,$token,$result);$done++;}}
            catch(Throwable $e){$attempts=(int)$r['attempts']+1;if($attempts>=5){$this->finishClaim($id,$token,'failed',substr($e->getMessage(),0,300),['error_code'=>$e instanceof NotificationException?$e->errorCode:'exception']);$failed++;}else{$delay=min(3600,30*(2**max(0,$attempts-1)));$next=gmdate('Y-m-d H:i:s',time()+$delay);$q=$this->pdo->prepare("UPDATE notification_outbox SET state='pending',next_attempt_at=?,last_error=?,processing_token=NULL,processing_started_at=NULL,lease_expires_at=NULL WHERE id=? AND processing_token=?");$q->execute([$next,substr($e->getMessage(),0,300),$id,$token]);}}
        }
        return ['processed'=>$done+$failed+$skipped,'selected'=>count($rows),'delivered'=>$done,'skipped'=>$skipped,'failed'=>$failed,'claimed_elsewhere'=>$claimedElsewhere,'deadline_exhausted'=>$budgetExhausted,'events'=>$events];
    }
    public function remoteRows(): array{$rows=$this->pdo->query("SELECT CONCAT('user:',i.user_id) projection_id,i.id,i.title,i.body,i.target_url,i.read_at,i.created_at FROM notification_inbox i WHERE i.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY i.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($rows as &$row)$row['target_url']=self::normalizeTargetUrl((string)($row['target_url']??''));unset($row);return $rows;}
    public function remotePushConfig(): array{$binding=$this->pushBindingState();$rows=[];if(($binding['ready']??false)===true)$rows=$this->pdo->query("SELECT CONCAT('user:',user_id) projection_id,endpoint_hash,last_seen_at FROM notification_push_endpoint_owners ORDER BY endpoint_hash")->fetchAll(PDO::FETCH_ASSOC)?:[];$prefs=$this->pdo->query("SELECT CONCAT('user:',u.id) projection_id,COALESCE(p.in_app_enabled,1) in_app_enabled,COALESCE(p.push_enabled,1) push_enabled,COALESCE(p.push_operations_enabled,1) push_operations_enabled,COALESCE(p.push_management_enabled,1) push_management_enabled,p.updated_at FROM users u LEFT JOIN notification_preferences p ON p.user_id=u.id WHERE u.active=1 ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC)?:[];return ['vapid_public_key'=>$this->setting('notifications.vapid_public_key'),'bridge_configured'=>$this->setting('notifications.push_bridge_url')!=='','binding_ready'=>(bool)($binding['ready']??false),'origin_rebind_pending'=>(bool)($binding['rebind_pending']??false),'binding_fingerprint'=>(string)($binding['current']??''),'subscriptions'=>\Sokna\Shared\IranTime::transport($rows,'UTC'),'preferences'=>$prefs];}
    private function deliverPush(array $r,int $deadlineEpochMs): array
    {
        $bridge=$this->setting('notifications.push_bridge_url');if($bridge===''||!str_starts_with(strtolower($bridge),'https://'))return ['state'=>'skipped','reason'=>'push_not_configured'];$outboxId=(int)$r['id'];$uid=(int)$r['user_id'];$this->ensurePushDeliverySet($outboxId,$uid);$this->markInactivePushDeliveriesGone($outboxId,$uid);$subs=$this->pendingPushSubscriptions($outboxId,$uid);if($subs===[])return $this->pushDeliveryTerminalResult($outboxId);
        $remaining=$this->remainingMs($deadlineEpochMs);if($remaining<350)throw new NotificationException('push_budget_exhausted','Notification worker budget exhausted before bridge call.',503);$token=$this->setting('notifications.push_bridge_token');$notification=['title'=>(string)$r['title'],'body'=>(string)$r['body'],'url'=>(string)($r['target_url']??'/'),'actions'=>$this->safePushActions($r)];$payload=['notification'=>$notification,'subscriptions'=>$subs,'idempotency_key'=>(string)$r['idempotency_key'],'urgency'=>(int)($r['priority']??50)>=80?'high':'normal','ttl_seconds'=>$this->ttlRemainingSeconds($r)];$headers="Content-Type: application/json\r\n".($token!==''?'Authorization: Bearer '.$token."\r\n":'');$timeout=max(0.25,min(3.0,($remaining-150)/1000));$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'timeout'=>$timeout,'ignore_errors'=>true]]);$body=@file_get_contents($bridge,false,$ctx);$status=0;foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$h,$m))$status=(int)$m[1];
        if($body===false||$status<200||$status>=300){$this->markPushAttemptTransient($outboxId,$subs,$status,$body===false?'bridge_transport_failure':'bridge_http_'.$status);throw new NotificationException('push_bridge_failed','Push bridge delivery failed.',503);}
        try{$parsed=PushBridgeResult::parse($body,$subs);}catch(Throwable $e){$this->markPushAttemptTransient($outboxId,$subs,$status,'bridge_response_unusable');throw $e;}$this->persistPushDeliveryResult($outboxId,$parsed,$status);foreach($parsed['gone'] as $hash)$this->deactivateGoneEndpoint((string)$hash);$summary=$this->pushDeliveryTerminalResult($outboxId,$status);if(($summary['retryable_endpoint_hashes']??[])!==[])throw new NotificationException('push_bridge_partial_failure','Push bridge has retryable endpoint deliveries pending.',503);return $summary;
    }
    private function ensurePushDeliverySet(int $outboxId,int $uid): void
    {
        if(($this->pushBindingState()['ready']??false)!==true)return;
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM notification_push_deliveries WHERE outbox_id=?');$q->execute([$outboxId]);if((int)$q->fetchColumn()>0)return;$q=$this->pdo->prepare("INSERT IGNORE INTO notification_push_deliveries(outbox_id,endpoint_hash,state) SELECT ?,s.endpoint_hash,'pending' FROM notification_push_subscriptions s JOIN notification_push_endpoint_owners o ON o.endpoint_hash=s.endpoint_hash AND o.user_id=s.user_id WHERE s.user_id=? AND s.active=1");$q->execute([$outboxId,$uid]);
    }
    private function markInactivePushDeliveriesGone(int $outboxId,int $uid): void
    {
        if(($this->pushBindingState()['ready']??false)!==true){$q=$this->pdo->prepare("UPDATE notification_push_deliveries SET state='gone',last_error='public_origin_rebind',last_attempt_at=UTC_TIMESTAMP() WHERE outbox_id=? AND state IN ('pending','transient')");$q->execute([$outboxId]);return;}
        $q=$this->pdo->prepare("UPDATE notification_push_deliveries d SET d.state='gone',d.last_error='subscription_inactive',d.last_attempt_at=UTC_TIMESTAMP() WHERE d.outbox_id=? AND d.state IN ('pending','transient') AND NOT EXISTS (SELECT 1 FROM notification_push_subscriptions s JOIN notification_push_endpoint_owners o ON o.endpoint_hash=s.endpoint_hash AND o.user_id=s.user_id WHERE s.user_id=? AND s.endpoint_hash=d.endpoint_hash AND s.active=1)");$q->execute([$outboxId,$uid]);
    }
    private function pendingPushSubscriptions(int $outboxId,int $uid): array
    {
        if(($this->pushBindingState()['ready']??false)!==true)return [];
        $q=$this->pdo->prepare("SELECT s.endpoint_hash,s.endpoint_url,s.p256dh_key,s.auth_secret FROM notification_push_deliveries d JOIN notification_push_subscriptions s ON s.endpoint_hash=d.endpoint_hash AND s.user_id=? AND s.active=1 JOIN notification_push_endpoint_owners o ON o.endpoint_hash=s.endpoint_hash AND o.user_id=s.user_id WHERE d.outbox_id=? AND d.state IN ('pending','transient') ORDER BY s.id");$q->execute([$uid,$outboxId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    private function persistPushDeliveryResult(int $outboxId,array $parsed,int $status): void
    {
        foreach(['accepted','gone','transient','fatal'] as $state){foreach((array)($parsed[$state]??[]) as $hash){$accepted=$state==='accepted'?'UTC_TIMESTAMP()':'accepted_at';$sql="UPDATE notification_push_deliveries SET state=?,attempts=attempts+1,last_http_status=?,last_error=?,last_attempt_at=UTC_TIMESTAMP(),accepted_at={$accepted} WHERE outbox_id=? AND endpoint_hash=? AND state NOT IN ('accepted','gone','fatal')";$q=$this->pdo->prepare($sql);$q->execute([$state,$status,$state==='transient'?'retryable_push_result':($state==='fatal'?'terminal_push_result':null),$outboxId,(string)$hash]);}}
    }
    private function markPushAttemptTransient(int $outboxId,array $subs,int $status,string $error): void
    {
        $q=$this->pdo->prepare("UPDATE notification_push_deliveries SET state='transient',attempts=attempts+1,last_http_status=?,last_error=?,last_attempt_at=UTC_TIMESTAMP() WHERE outbox_id=? AND endpoint_hash=? AND state IN ('pending','transient')");foreach($subs as $sub){$hash=trim((string)($sub['endpoint_hash']??''));if($hash==='')$hash=hash('sha256',trim((string)($sub['endpoint_url']??'')));if($hash!=='')$q->execute([$status,$error,$outboxId,$hash]);}
    }
    private function pushDeliveryTerminalResult(int $outboxId,int $status=0): array
    {
        $q=$this->pdo->prepare('SELECT endpoint_hash,state FROM notification_push_deliveries WHERE outbox_id=? ORDER BY endpoint_hash');$q->execute([$outboxId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$by=['accepted'=>[],'gone'=>[],'fatal'=>[],'retryable'=>[]];foreach($rows as $row){$state=(string)$row['state'];$hash=(string)$row['endpoint_hash'];if($state==='accepted')$by['accepted'][]=$hash;elseif($state==='gone')$by['gone'][]=$hash;elseif($state==='fatal')$by['fatal'][]=$hash;else $by['retryable'][]=$hash;}$state=$by['accepted']!==[]?'server_accepted':($by['retryable']!==[]?'pending':($by['fatal']!==[]?'failed':'skipped'));$reason=$state==='server_accepted'?($by['fatal']!==[]?'partial_terminal_failure':''):($state==='failed'?'all_live_endpoints_failed':($state==='skipped'?'no_live_subscription':''));return ['state'=>$state,'reason'=>$reason,'accepted_endpoint_hashes'=>$by['accepted'],'gone_endpoint_hashes'=>$by['gone'],'fatal_endpoint_hashes'=>$by['fatal'],'retryable_endpoint_hashes'=>$by['retryable'],'bridge_http_status'=>$status,'device_display'=>'unknown'];
    }
    private function deactivateGoneEndpoint(string $hash): void{$this->pdo->prepare('UPDATE notification_push_subscriptions SET active=0 WHERE endpoint_hash=?')->execute([$hash]);$this->pdo->prepare('DELETE FROM notification_push_endpoint_owners WHERE endpoint_hash=?')->execute([$hash]);}
    private function delivered(int $id,string $token,array $receipt): void{$json=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$q=$this->pdo->prepare("UPDATE notification_outbox SET state='delivered',delivered_at=UTC_TIMESTAMP(),bridge_accepted_at=CASE WHEN channel='web_push' THEN UTC_TIMESTAMP() ELSE bridge_accepted_at END,delivery_receipt_json=?,last_error=NULL,processing_token=NULL,processing_started_at=NULL,lease_expires_at=NULL WHERE id=? AND processing_token=?");$q->execute([$json,$id,$token]);if($q->rowCount()!==1)throw new NotificationException('notification_lease_lost','Notification claim lease was lost before completion.',409);}
    private function finishClaim(int $id,string $token,string $state,string $error,array $receipt=[]): void{$json=$receipt===[]?null:json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$q=$this->pdo->prepare('UPDATE notification_outbox SET state=?,last_error=?,delivery_receipt_json=?,processing_token=NULL,processing_started_at=NULL,lease_expires_at=NULL WHERE id=? AND processing_token=?');$q->execute([$state,$error,$json,$id,$token]);}
    private function recoverStaleClaims(): void{$this->pdo->exec("UPDATE notification_outbox SET state='pending',next_attempt_at=COALESCE(next_attempt_at,UTC_TIMESTAMP()),last_error='stale_processing_recovered',processing_token=NULL,processing_started_at=NULL,lease_expires_at=NULL WHERE state='processing' AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP())");}
    private function ttlRemainingSeconds(array $r): int{$raw=trim((string)($r['ttl_expires_at']??''));if($raw==='')return 300;$ts=\Sokna\Shared\IranTime::epoch($raw,'UTC');return max(1,min(86400,$ts===false?300:$ts-time()));}
    private function remainingMs(int $deadlineEpochMs): int{return max(0,$deadlineEpochMs-(int)floor(microtime(true)*1000));}
    private function queueOperationalEvents(): array
    {
        $rows=$this->pdo->query("SELECT id,event_key,event_type,entity_type,entity_id,occurred_at,payload_json FROM notification_domain_events WHERE dispatched_at IS NULL ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $queued=0;$matrix=[];$processed=0;$failed=0;$expired=0;
        foreach($rows as $event){$id=(int)$event['id'];$type=(string)$event['event_type'];$d=$this->operationalEventDefinition($type);if($d===null){$this->finishDomainEvent($id,'unsupported_event_type');$processed++;continue;}
            try{$occurredAt=(string)$event['occurred_at'];$occurredTs=\Sokna\Shared\IranTime::epoch($occurredAt,'UTC');if($occurredTs!==false&&$occurredTs+(int)$d['ttl']<=time()){$this->finishDomainEvent($id,'ttl_expired_before_queue');$expired++;$processed++;$matrix[$type]['expired']=($matrix[$type]['expired']??0)+1;continue;}
                $payload=json_decode((string)$event['payload_json'],true);if(!is_array($payload))$payload=[];$payload['source']='notification_domain_event';$payload['entity_type']=(string)$event['entity_type'];$payload['entity_id']=(int)$event['entity_id'];$payload['occurred_at']=$occurredAt;$payload=$this->hydrateOperationalEventPayload($payload);
                $title=is_callable($d['title'])?$d['title']($payload):$d['title'];$url=is_callable($d['url'])?$d['url']($payload):$d['url'];$payload['notification_category']=(string)($d['category']??'management');if((string)$url!=='')$payload['notification_actions']=[['action'=>'open','title'=>(string)($d['action']??'مشاهده'),'url'=>(string)$url]];$priority=is_callable($d['priority'])?$d['priority']($payload):$d['priority'];$users=$this->usersForRoles($d['roles']);$count=0;foreach($users as $uid)$count+=$this->queueForUser($uid,(string)$event['event_key'],(string)$title,$d['body']($payload),(string)$url,$payload,(int)$priority,$d['ttl'],$occurredAt);$queued+=$count;$matrix[$type]['events']=($matrix[$type]['events']??0)+1;$matrix[$type]['queued']=($matrix[$type]['queued']??0)+$count;$matrix[$type]['roles']=$d['roles'];$this->finishDomainEvent($id,null);$processed++;
            }catch(Throwable $e){$failed++;$q=$this->pdo->prepare('UPDATE notification_domain_events SET attempts=attempts+1,last_error=? WHERE id=? AND dispatched_at IS NULL');$q->execute([substr($e->getMessage(),0,300),$id]);$matrix[$type]['failed']=($matrix[$type]['failed']??0)+1;}
        }
        return ['queued'=>$queued,'selected'=>count($rows),'processed'=>$processed,'expired'=>$expired,'failed'=>$failed,'matrix'=>$matrix];
    }
    private function operationalEventDefinition(string $type): ?array
    {
        return match($type){
            'guest_order.new'=>['roles'=>['admin','operator','waiter'],'category'=>'operations','action'=>'دیدن سفارش','title'=>'سفارش جدید مهمان','body'=>fn($r)=>'سفارش '.MoneyDisplay::digits((string)(int)($r['business_order_number']??0)).' · '.MoneyDisplay::digits(trim((string)($r['table_name']??''))),'url'=>'/operator/?work=attention&attention_filter=orders#attention','priority'=>95,'ttl'=>300],
            'preparation.accounted'=>['roles'=>['admin','operator'],'category'=>'operations','action'=>'بازکردن آماده‌سازی','title'=>'سفارش وارد آماده‌سازی شد','body'=>fn($r)=>'سفارش '.MoneyDisplay::digits((string)(int)($r['business_order_number']??0)).' · '.MoneyDisplay::digits(trim((string)($r['table_name']??''))),'url'=>'/waiter/','priority'=>80,'ttl'=>600],
            'waiter_call.new'=>['roles'=>['admin','operator','waiter'],'category'=>'operations','action'=>'دیدن فراخوان','title'=>'فراخوان مهمان','body'=>fn($r)=>'میز '.MoneyDisplay::digits(trim((string)($r['table_name']??''))),'url'=>'/operator/?work=attention&attention_filter=calls#attention','priority'=>100,'ttl'=>180],
            'settlement.completed'=>['roles'=>['admin'],'category'=>'management','action'=>'دیدن گزارش','title'=>'تسویه ثبت شد','body'=>fn($r)=>'تسویه حساب #'.MoneyDisplay::digits((string)(int)($r['session_id']??0)).' ثبت شد.','url'=>'/reports/','priority'=>60,'ttl'=>1800],
            'inventory.purchase_price_changed'=>['roles'=>['admin'],'category'=>'management','action'=>'دیدن دریافت خرید','title'=>fn($r)=>$this->purchasePriceNotificationTitle($r),'body'=>fn($r)=>$this->purchasePriceNotificationBody($r),'url'=>fn($r)=>'/operations/?workflow=receive&receipt_id='.(int)($r['receipt_id']??0).'#receipt-'.(int)($r['receipt_id']??0),'priority'=>fn($r)=>(string)($r['notification_level']??'')==='high'?90:65,'ttl'=>86400],
            default=>null,
        };
    }
    private function purchasePriceNotificationTitle(array $r): string
    {
        $name=trim((string)($r['item_name']??'کالا'));$pct=$r['delta_last_percent']??$r['delta_average_percent']??null;
        $direction=(string)($r['direction']??'');$verb=$direction==='decrease'?'کاهش یافت':($direction==='increase'?'افزایش یافت':'تغییر کرد');
        $title='قیمت '.$name.($pct===null?'':' '.MoneyDisplay::number(abs((float)$pct),1).'٪').' '.$verb;
        return function_exists('mb_substr')?mb_substr($title,0,180,'UTF-8'):substr($title,0,180);
    }

    private function purchasePriceNotificationBody(array $r): string
    {
        $mult=in_array((string)($r['base_unit']??''),['g','ml'],true)?1000:1;$unit=match((string)($r['base_unit']??'')){'g'=>'کیلو','ml'=>'لیتر',default=>'عدد'};
        $fmt=static fn($v):string=>$v===null?'—':MoneyDisplay::unitCost((float)$v,$unit,$mult);
        return 'خرید جدید: '.$fmt($r['new_unit_cost']??null).' · خرید قبلی: '.$fmt($r['last_unit_cost']??null).' · میانگین موزون: '.$fmt($r['weighted_average_before']??null);
    }

    private function hydrateOperationalEventPayload(array $payload): array
    {
        $tableId=(int)($payload['table_id']??0);if($tableId>0){$q=$this->pdo->prepare('SELECT name FROM cafe_tables WHERE id=?');$q->execute([$tableId]);$name=$q->fetchColumn();$payload['table_name']=$name===false?'':(string)$name;}return $payload;
    }
    private function finishDomainEvent(int $id,?string $note): void
    {
        $q=$this->pdo->prepare('UPDATE notification_domain_events SET dispatched_at=UTC_TIMESTAMP(),attempts=attempts+1,last_error=? WHERE id=? AND dispatched_at IS NULL');$q->execute([$note,$id]);
    }
    private function usersForRoles(array $roles): array{$ph=implode(',',array_fill(0,count($roles),'?'));$q=$this->pdo->prepare("SELECT id FROM users WHERE active=1 AND role IN($ph)");$q->execute($roles);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);}
    private function safePushActions(array $row): array
    {
        $payload=json_decode((string)($row['payload_json']??''),true);$raw=is_array($payload)?($payload['notification_actions']??[]):[];$out=[];
        foreach(is_array($raw)?$raw:[] as $action){if(!is_array($action))continue;$id=preg_replace('/[^a-z0-9_-]+/i','',trim((string)($action['action']??'')))?:'';$title=trim((string)($action['title']??''));$url=self::normalizeTargetUrl(trim((string)($action['url']??'')));if($id===''||$title===''||$url===''||!str_starts_with($url,'/'))continue;$out[]=['action'=>substr($id,0,32),'title'=>$this->sliceText($title,32),'url'=>$url];if(count($out)>=2)break;}return $out;
    }
    private function sliceText(string $value,int $max): string{return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);}
    private function ensurePreference(int $uid): void{$this->pdo->prepare('INSERT IGNORE INTO notification_preferences(user_id,in_app_enabled,push_enabled) VALUES(?,1,0)')->execute([$uid]);}
    private function uid(array $u): int{$id=(int)($u['id']??0);if($id<1)throw new NotificationException('unauthenticated','کاربر معتبر نیست.',401);return $id;}
    private function text(mixed $v,int $max): string{$v=trim((string)$v);if($v===''||strlen($v)>$max*4)throw new NotificationException('invalid_text','متن اعلان معتبر نیست.',422);return $v;}
    private function currentPublicBindingFingerprint(): string{return $this->bindingFingerprint($this->config?->string('public.base_url','')??'');}
    private function bindingFingerprint(string $baseUrl): string
    {
        $base=trim($baseUrl);if($base==='')return '';$p=parse_url($base);if(!is_array($p)||!in_array(strtolower((string)($p['scheme']??'')),['https','http'],true)||empty($p['host']))return '';$scheme=strtolower((string)$p['scheme']);$host=strtolower(trim((string)$p['host'],'[]'));$authority=str_contains($host,':')?'['.$host.']':$host;$port=isset($p['port'])?(int)$p['port']:null;if($port!==null&&!(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80)))$authority.=':'.$port;$path='/'.trim((string)($p['path']??''),'/');if($path==='/')$path='';$canonical=$scheme.'://'.$authority.$path;return hash('sha256',"sokna-push-binding-v1\n".$canonical);
    }
    private function pushBindingState(): array
    {
        $current=$this->currentPublicBindingFingerprint();if($current==='')return ['ready'=>false,'current'=>'','stored'=>'','pending'=>'','rebind_pending'=>false];$stored=$this->setting(self::PUSH_BINDING_KEY);$pending=$this->setting(self::PUSH_REBIND_PENDING_KEY);if($stored===''){$this->setSetting(self::PUSH_BINDING_KEY,$current);$stored=$current;}$pendingFlag=$pending!=='';return ['ready'=>!$pendingFlag&&hash_equals($stored,$current),'current'=>$current,'stored'=>$stored,'pending'=>$pending,'rebind_pending'=>$pendingFlag];
    }
    private function setSetting(string $k,string $v): void{$q=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$k,$v]);}
    private function setting(string $k): string{$q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$k]);$v=$q->fetchColumn();return $v===false?'':trim((string)$v);}
    private function preparedColumn(string $sql,array $p): array{$q=$this->pdo->prepare($sql);$q->execute($p);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);}
}
