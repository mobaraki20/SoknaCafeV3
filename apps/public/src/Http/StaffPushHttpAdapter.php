<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;
use Throwable;

final class StaffPushHttpAdapter
{
    public function __construct(private readonly Bootstrap $core) {}

    public function snapshot(string $token): array
    {
        $session=$this->core->publicSessions()->resolve($token);if(!is_array($session))return $this->error(401,'unauthorized');
        try{return ['status'=>200,'body'=>['ok'=>true,'push'=>$this->core->staffPush()->snapshot($session)]];}catch(Throwable){return $this->error(500,'push_state_failed');}
    }

    public function mutate(string $token,string $rawBody,string $userAgent=''): array
    {
        $session=$this->core->publicSessions()->resolve($token);if(!is_array($session))return $this->error(401,'unauthorized');
        $data=json_decode($rawBody,true);if(!is_array($data))return $this->error(400,'invalid_json');$action=trim((string)($data['action']??''));
        try{
            if($action==='register')return ['status'=>200,'body'=>['ok'=>true,'result'=>$this->core->staffPush()->register($session,is_array($data['subscription']??null)?$data['subscription']:[],$userAgent,$token)]];
            if($action==='unregister')return ['status'=>200,'body'=>['ok'=>true,'result'=>$this->core->staffPush()->unregister($session,(string)($data['endpoint']??''))]];
            if($action==='test')return ['status'=>200,'body'=>['ok'=>true,'result'=>$this->core->staffPush()->test($session)]];
            return $this->error(422,'invalid_action');
        }catch(Throwable $e){$code=$e->getMessage()==='invalid_subscription'?'invalid_subscription':($e->getMessage()==='invalid_staff_session'?'unauthorized':'push_action_failed');return $this->error($code==='unauthorized'?401:($code==='invalid_subscription'?422:503),$code);}
    }

    private function error(int $status,string $error): array{return ['status'=>$status,'body'=>['ok'=>false,'error'=>$error]];}
}
