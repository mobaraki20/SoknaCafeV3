<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Marketing;
use PDO;
final class MarketingService
{
    public function __construct(private readonly PDO $pdo){}
    public function snapshot(): array
    {
        return [
            'campaigns'=>$this->pdo->query("SELECT id,campaign_key,name,headline,body,status,public_visible,starts_at,ends_at,updated_at FROM marketing_campaigns ORDER BY updated_at DESC,id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[],
            'events'=>$this->pdo->query("SELECT id,event_key,title,description,status,public_visible,starts_at,ends_at,capacity,location_label,updated_at FROM marketing_events ORDER BY starts_at DESC,id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[],
        ];
    }
    public function saveCampaign(array $input,array $actor): array
    {
        $this->admin($actor);$id=max(0,(int)($input['id']??0));$name=$this->text($input['name']??'',160,'نام کمپین');$headline=$this->text($input['headline']??'',200,'عنوان کمپین');$body=$this->optional($input['body']??'',5000);$status=$this->choice((string)($input['status']??'draft'),['draft','scheduled','active','paused','completed','archived']);$visible=!empty($input['public_visible'])?1:0;$starts=$this->date($input['starts_at']??null);$ends=$this->date($input['ends_at']??null);if($starts!==null&&$ends!==null&&$ends<=$starts)throw new MarketingException('invalid_window','پایان کمپین باید بعد از شروع باشد.',422);$uid=(int)$actor['id'];
        if($id>0){$q=$this->pdo->prepare('UPDATE marketing_campaigns SET name=?,headline=?,body=?,status=?,public_visible=?,starts_at=?,ends_at=?,updated_by_user_id=? WHERE id=?');$q->execute([$name,$headline,$body,$status,$visible,$starts,$ends,$uid,$id]);if($q->rowCount()===0&&!$this->exists('marketing_campaigns',$id))throw new MarketingException('not_found','کمپین پیدا نشد.',404);}
        else{$key='cmp_'.bin2hex(random_bytes(10));$q=$this->pdo->prepare('INSERT INTO marketing_campaigns(campaign_key,name,headline,body,status,public_visible,starts_at,ends_at,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)');$q->execute([$key,$name,$headline,$body,$status,$visible,$starts,$ends,$uid,$uid]);$id=(int)$this->pdo->lastInsertId();}
        $this->audit($uid,'marketing.campaign.save','campaign',(string)$id,['status'=>$status,'public_visible'=>$visible]);return ['id'=>$id];
    }
    public function saveEvent(array $input,array $actor): array
    {
        $this->admin($actor);$id=max(0,(int)($input['id']??0));$title=$this->text($input['title']??'',180,'عنوان رویداد');$description=$this->optional($input['description']??'',5000);$status=$this->choice((string)($input['status']??'draft'),['draft','scheduled','active','completed','cancelled','archived']);$visible=!empty($input['public_visible'])?1:0;$starts=$this->date($input['starts_at']??null);if($starts===null)throw new MarketingException('missing_start','زمان شروع رویداد لازم است.',422);$ends=$this->date($input['ends_at']??null);if($ends!==null&&$ends<=$starts)throw new MarketingException('invalid_window','پایان رویداد باید بعد از شروع باشد.',422);$capacity=($input['capacity']??'')===''?null:max(1,(int)$input['capacity']);$location=$this->optional($input['location_label']??'',180);$uid=(int)$actor['id'];
        if($id>0){$q=$this->pdo->prepare('UPDATE marketing_events SET title=?,description=?,status=?,public_visible=?,starts_at=?,ends_at=?,capacity=?,location_label=?,updated_by_user_id=? WHERE id=?');$q->execute([$title,$description,$status,$visible,$starts,$ends,$capacity,$location,$uid,$id]);if($q->rowCount()===0&&!$this->exists('marketing_events',$id))throw new MarketingException('not_found','رویداد پیدا نشد.',404);}
        else{$key='evt_'.bin2hex(random_bytes(10));$q=$this->pdo->prepare('INSERT INTO marketing_events(event_key,title,description,status,public_visible,starts_at,ends_at,capacity,location_label,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$key,$title,$description,$status,$visible,$starts,$ends,$capacity,$location,$uid,$uid]);$id=(int)$this->pdo->lastInsertId();}
        $this->audit($uid,'marketing.event.save','event',(string)$id,['status'=>$status,'public_visible'=>$visible]);return ['id'=>$id];
    }
    public function publicFeed(): array
    {
        $campaigns=$this->pdo->query("SELECT campaign_key,name,headline,body,starts_at,ends_at FROM marketing_campaigns WHERE public_visible=1 AND status IN ('scheduled','active') AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP()) ORDER BY COALESCE(starts_at,'1970-01-01') DESC,id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $events=$this->pdo->query("SELECT event_key,title,description,starts_at,ends_at,capacity,location_label FROM marketing_events WHERE public_visible=1 AND status IN ('scheduled','active') AND starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP()) ORDER BY starts_at ASC,id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC)?:[];
        return ['campaigns'=>$campaigns,'events'=>$events];
    }
    private function admin(array $u): void{if((string)($u['role']??'')!=='admin')throw new MarketingException('forbidden','این عملیات فقط برای مدیر فعال است.',403);}
    private function text(mixed $v,int $max,string $label): string{$v=trim((string)$v);if($v===''||strlen($v)>$max*4)throw new MarketingException('invalid_text',$label.' معتبر نیست.',422);return $v;}
    private function optional(mixed $v,int $max): ?string{$v=trim((string)$v);if($v==='')return null;if(strlen($v)>$max*4)throw new MarketingException('invalid_text','متن بیش از حد طولانی است.',422);return $v;}
    private function choice(string $v,array $allowed): string{if(!in_array($v,$allowed,true))throw new MarketingException('invalid_status','وضعیت معتبر نیست.',422);return $v;}
    private function date(mixed $v): ?string{$v=trim((string)$v);if($v==='')return null;$ts=strtotime($v);if($ts===false)throw new MarketingException('invalid_date','زمان معتبر نیست.',422);return gmdate('Y-m-d H:i:s',$ts);}
    private function exists(string $table,int $id): bool{$q=$this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=?");$q->execute([$id]);return $q->fetchColumn()!==false;}
    private function audit(int $uid,string $action,string $type,string $id,array $details): void{$q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)');$q->execute([$uid,$action,$type,$id,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
