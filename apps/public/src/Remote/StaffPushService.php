<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Remote;

use PDO;
use Sokna\PublicEdge\Core\Config;
use RuntimeException;
use Throwable;

/**
 * Public-only transport for Local-authored staff notifications.
 * This service never creates canonical business notifications; it only stores
 * device subscriptions and transports notification rows already projected by Local.
 */
final class StaffPushService
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config) {}

    public function snapshot(array $session): array
    {
        [$installationId,$projectionId]=$this->identity($session);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM staff_push_subscriptions WHERE installation_id=? AND projection_id=? AND active=1');
        $q->execute([$installationId,$projectionId]);
        return [
            'configured'=>$this->configured(),
            'vapid_public_key'=>trim($this->config->string('push.vapid_public_key')),
            'subscriptions'=>(int)$q->fetchColumn(),
            'secure_context_required'=>true,
        ];
    }

    public function register(array $session,array $subscription,string $userAgent='',string $sessionToken=''): array
    {
        [$installationId,$projectionId]=$this->identity($session);
        $endpoint=trim((string)($subscription['endpoint']??''));
        $keys=is_array($subscription['keys']??null)?$subscription['keys']:[];
        $p256dh=trim((string)($keys['p256dh']??''));$auth=trim((string)($keys['auth']??''));
        if($endpoint===''||!str_starts_with(strtolower($endpoint),'https://')||$p256dh===''||$auth==='')throw new RuntimeException('invalid_subscription');
        if(strlen($endpoint)>3000||strlen($p256dh)>255||strlen($auth)>255)throw new RuntimeException('invalid_subscription');
        $ua=trim($userAgent);if(strlen($ua)>255)$ua=substr($ua,0,255);$sessionHash=$this->sessionHash($sessionToken);
        $hash=hash('sha256',$endpoint);
        $q=$this->pdo->prepare('INSERT INTO staff_push_subscriptions(installation_id,projection_id,endpoint_hash,endpoint_url,p256dh_key,auth_secret,session_token_hash,user_agent,active,last_seen_at) VALUES(?,?,?,?,?,?,?,?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE endpoint_url=VALUES(endpoint_url),p256dh_key=VALUES(p256dh_key),auth_secret=VALUES(auth_secret),session_token_hash=VALUES(session_token_hash),user_agent=VALUES(user_agent),active=1,last_seen_at=UTC_TIMESTAMP()');
        $q->execute([$installationId,$projectionId,$hash,$endpoint,$p256dh,$auth,$sessionHash,$ua!==''?$ua:null]);
        $this->processPending(25,$installationId,$projectionId);
        return ['registered'=>true,'endpoint_hash'=>$hash];
    }

    public function unregister(array $session,string $endpoint): array
    {
        [$installationId,$projectionId]=$this->identity($session);$hash=hash('sha256',trim($endpoint));
        $q=$this->pdo->prepare('UPDATE staff_push_subscriptions SET active=0 WHERE installation_id=? AND projection_id=? AND endpoint_hash=?');
        $q->execute([$installationId,$projectionId,$hash]);
        return ['removed'=>$q->rowCount()];
    }

    public function deactivateSession(string $sessionToken): int
    {
        $hash=$this->sessionHash($sessionToken);$q=$this->pdo->prepare('UPDATE staff_push_subscriptions SET active=0 WHERE session_token_hash=? AND active=1');$q->execute([$hash]);return $q->rowCount();
    }

    public function queueProjectedNotifications(string $installationId,array $payload): int
    {
        $installationId=trim($installationId);if($installationId==='')return 0;
        $items=is_array($payload['items']??null)?$payload['items']:[];$queued=0;
        $target=$this->staffUrl('notifications');
        $stmt=$this->pdo->prepare("INSERT IGNORE INTO staff_push_outbox(installation_id,projection_id,event_key,title,body,target_url,state,next_attempt_at) VALUES(?,?,?,?,?,?,'pending',UTC_TIMESTAMP())");
        foreach($items as $row){
            if(!is_array($row))continue;$projection=trim((string)($row['projection_id']??''));$id=(int)($row['id']??0);
            if($id<1||preg_match('/^user:[1-9][0-9]*$/D',$projection)!==1)continue;
            $title=$this->bounded((string)($row['title']??'اعلان سکنا'),180);$body=$this->bounded((string)($row['body']??''),600);
            if($title===''||$body==='')continue;
            $stmt->execute([$installationId,$projection,'notification:'.$id,$title,$body,$target]);$queued+=$stmt->rowCount();
        }
        return $queued;
    }

    public function test(array $session): array
    {
        [$installationId,$projectionId]=$this->identity($session);$event='test:'.bin2hex(random_bytes(8));
        $q=$this->pdo->prepare("INSERT INTO staff_push_outbox(installation_id,projection_id,event_key,title,body,target_url,state,next_attempt_at) VALUES(?,?,?,?,?,?,'pending',UTC_TIMESTAMP())");
        $q->execute([$installationId,$projectionId,$event,'تست اعلان سکنا','اگر این پیام را می‌بینید، مسیر Push این دستگاه فعال است.',$this->staffUrl('notifications')]);
        $result=$this->processPending(10,$installationId,$projectionId);
        $st=$this->pdo->prepare('SELECT state,last_error FROM staff_push_outbox WHERE installation_id=? AND projection_id=? AND event_key=?');$st->execute([$installationId,$projectionId,$event]);$row=$st->fetch(PDO::FETCH_ASSOC)?:[];
        return ['queued'=>true,'state'=>(string)($row['state']??'pending'),'last_error'=>(string)($row['last_error']??''),'delivery'=>$result];
    }

    public function processPending(int $limit=50,?string $installationId=null,?string $projectionId=null): array
    {
        $limit=max(1,min(100,$limit));$params=[];$where="state IN ('pending','waiting_subscription') AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())";
        if($installationId!==null){$where.=' AND installation_id=?';$params[]=$installationId;}
        if($projectionId!==null){$where.=' AND projection_id=?';$params[]=$projectionId;}
        $q=$this->pdo->prepare("SELECT * FROM staff_push_outbox WHERE {$where} ORDER BY id LIMIT {$limit}");$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $delivered=0;$failed=0;$waiting=0;
        foreach($rows as $row){
            $id=(int)$row['id'];$subs=$this->subscriptions((string)$row['installation_id'],(string)$row['projection_id']);
            if($subs===[]){$this->retry($id,'waiting_subscription','no_active_subscription',600,false);$waiting++;continue;}
            if(!$this->configured()){$this->retry($id,'pending','push_bridge_not_configured',300,false);$waiting++;continue;}
            $this->pdo->prepare("UPDATE staff_push_outbox SET state='processing',attempts=attempts+1 WHERE id=?")->execute([$id]);
            try{$this->deliver($row,$subs);$this->pdo->prepare("UPDATE staff_push_outbox SET state='delivered',delivered_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?")->execute([$id]);$delivered++;}
            catch(Throwable $e){$attempts=(int)$row['attempts']+1;if($attempts>=5){$this->pdo->prepare("UPDATE staff_push_outbox SET state='failed',last_error=? WHERE id=?")->execute([$this->bounded($e->getMessage(),300),$id]);$failed++;}else{$this->retry($id,'pending',$e->getMessage(),min(3600,30*(2**max(0,$attempts-1))),true);}}
        }
        return ['processed'=>count($rows),'delivered'=>$delivered,'failed'=>$failed,'waiting'=>$waiting];
    }

    private function deliver(array $row,array $subs): void
    {
        $bridge=trim($this->config->string('push.bridge_url'));if(!str_starts_with(strtolower($bridge),'https://'))throw new RuntimeException('push_bridge_not_configured');
        $payload=['notification'=>['title'=>(string)$row['title'],'body'=>(string)$row['body'],'url'=>(string)$row['target_url'],'tag'=>'sokna-staff-'.$row['event_key']],'subscriptions'=>$subs,'idempotency_key'=>hash('sha256',(string)$row['installation_id'].'|'.(string)$row['projection_id'].'|'.(string)$row['event_key'])];
        $token=trim($this->config->string('push.bridge_token'));$headers="Content-Type: application/json\r\nAccept: application/json\r\n".($token!==''?"Authorization: Bearer {$token}\r\n":'');
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'timeout'=>8,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $res=@file_get_contents($bridge,false,$ctx);$status=0;foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$h,$m))$status=(int)$m[1];
        if($res===false||$status<200||$status>=300)throw new RuntimeException('push_bridge_delivery_failed');
    }

    private function subscriptions(string $installationId,string $projectionId): array
    {
        $q=$this->pdo->prepare('SELECT endpoint_url endpoint,p256dh_key p256dh,auth_secret auth FROM staff_push_subscriptions WHERE installation_id=? AND projection_id=? AND active=1 ORDER BY id');$q->execute([$installationId,$projectionId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        return array_map(static fn(array $r):array=>['endpoint'=>(string)$r['endpoint'],'keys'=>['p256dh'=>(string)$r['p256dh'],'auth'=>(string)$r['auth']]],$rows);
    }

    private function retry(int $id,string $state,string $error,int $seconds,bool $attempted): void
    {
        $next=gmdate('Y-m-d H:i:s',time()+max(30,$seconds));$this->pdo->prepare("UPDATE staff_push_outbox SET state=?,next_attempt_at=?,last_error=? WHERE id=?")->execute([$state,$next,$this->bounded($error,300),$id]);
    }

    private function sessionHash(string $token): string
    {
        $token=trim($token);if(strlen($token)<20)throw new RuntimeException('invalid_staff_session');return hash('sha256',$token);
    }

    private function identity(array $session): array
    {
        $installationId=trim((string)($session['installation_id']??''));$projectionId=trim((string)($session['projection_id']??''));
        if($installationId===''||preg_match('/^user:[1-9][0-9]*$/D',$projectionId)!==1)throw new RuntimeException('invalid_staff_session');
        return [$installationId,$projectionId];
    }

    private function configured(): bool{$bridge=trim($this->config->string('push.bridge_url'));$key=trim($this->config->string('push.vapid_public_key'));return str_starts_with(strtolower($bridge),'https://')&&!str_contains(strtolower($bridge),'example.com')&&preg_match('/^[A-Za-z0-9_-]{80,120}$/D',$key)===1;}
    private function staffUrl(string $model=''): string{$base='/' . trim(str_replace('\\','/',$this->config->string('app.base_path')),'/');$base=$base==='/'?'':rtrim($base,'/');return $base.'/staff'.($model!==''?'?model='.rawurlencode($model):'');}
    private function bounded(string $value,int $max): string{$value=trim($value);if(function_exists('mb_substr'))return mb_substr($value,0,$max,'UTF-8');return substr($value,0,$max*4);}
}
