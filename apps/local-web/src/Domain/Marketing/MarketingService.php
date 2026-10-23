<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Marketing;
use PDO;
use Throwable;
final class MarketingService
{
    public function __construct(private readonly PDO $pdo){}
    public function snapshot(): array
    {
        $coverCampaign="(SELECT a.media_key FROM guest_media_references r JOIN guest_media_assets a ON a.id=r.media_id AND a.active=1 WHERE r.owner_type='campaign' AND r.owner_id=CAST(marketing_campaigns.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND r.slot_key='cover' LIMIT 1)";
        $coverEvent="(SELECT a.media_key FROM guest_media_references r JOIN guest_media_assets a ON a.id=r.media_id AND a.active=1 WHERE r.owner_type='event' AND r.owner_id=CAST(marketing_events.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND r.slot_key='cover' LIMIT 1)";
        return [
            'campaigns'=>$this->pdo->query("SELECT id,campaign_key,name,headline,body,status,public_visible,starts_at,ends_at,{$coverCampaign} cover_media_key,updated_at FROM marketing_campaigns ORDER BY updated_at DESC,id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[],
            'events'=>$this->pdo->query("SELECT id,event_key,title,description,status,public_visible,starts_at,ends_at,capacity,location_label,{$coverEvent} cover_media_key,updated_at FROM marketing_events ORDER BY starts_at DESC,id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[],
            'media'=>$this->pdo->query("SELECT media_key,original_name,alt_text,width_px,height_px,focal_x,focal_y,created_at FROM guest_media_assets WHERE active=1 ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC)?:[],
        ];
    }
    public function saveCampaign(array $input,array $actor): array
    {
        $this->admin($actor);$id=max(0,(int)($input['id']??0));$name=$this->text($input['name']??'',160,'نام کمپین');$headline=$this->text($input['headline']??'',200,'عنوان کمپین');$body=$this->optional($input['body']??'',5000);$status=$this->choice((string)($input['status']??'draft'),['draft','scheduled','active','paused','completed','archived']);$visible=!empty($input['public_visible'])?1:0;$starts=$this->date($input['starts_at']??null);$ends=$this->date($input['ends_at']??null);if($starts!==null&&$ends!==null&&$ends<=$starts)throw new MarketingException('invalid_window','پایان کمپین باید بعد از شروع باشد.',422);$mediaKey=$this->mediaKey($input['cover_media_key']??'');$uid=(int)$actor['id'];
        $this->pdo->beginTransaction();
        try{
            if($id>0){$q=$this->pdo->prepare('UPDATE marketing_campaigns SET name=?,headline=?,body=?,status=?,public_visible=?,starts_at=?,ends_at=?,updated_by_user_id=? WHERE id=?');$q->execute([$name,$headline,$body,$status,$visible,$starts,$ends,$uid,$id]);if($q->rowCount()===0&&!$this->exists('marketing_campaigns',$id))throw new MarketingException('not_found','کمپین پیدا نشد.',404);}
            else{$key='cmp_'.bin2hex(random_bytes(10));$q=$this->pdo->prepare('INSERT INTO marketing_campaigns(campaign_key,name,headline,body,status,public_visible,starts_at,ends_at,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)');$q->execute([$key,$name,$headline,$body,$status,$visible,$starts,$ends,$uid,$uid]);$id=(int)$this->pdo->lastInsertId();}
            $this->syncCoverReference('campaign',$id,$mediaKey);
            $this->audit($uid,'marketing.campaign.save','campaign',(string)$id,['status'=>$status,'public_visible'=>$visible,'cover_media_key'=>$mediaKey]);
            $this->pdo->commit();return ['id'=>$id,'cover_media_key'=>$mediaKey];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function saveEvent(array $input,array $actor): array
    {
        $this->admin($actor);$id=max(0,(int)($input['id']??0));$title=$this->text($input['title']??'',180,'عنوان رویداد');$description=$this->optional($input['description']??'',5000);$status=$this->choice((string)($input['status']??'draft'),['draft','scheduled','active','completed','cancelled','archived']);$visible=!empty($input['public_visible'])?1:0;$starts=$this->date($input['starts_at']??null);if($starts===null)throw new MarketingException('missing_start','زمان شروع رویداد لازم است.',422);$ends=$this->date($input['ends_at']??null);if($ends!==null&&$ends<=$starts)throw new MarketingException('invalid_window','پایان رویداد باید بعد از شروع باشد.',422);$capacity=($input['capacity']??'')===''?null:max(1,(int)$input['capacity']);$location=$this->optional($input['location_label']??'',180);$mediaKey=$this->mediaKey($input['cover_media_key']??'');$uid=(int)$actor['id'];
        $this->pdo->beginTransaction();
        try{
            if($id>0){$q=$this->pdo->prepare('UPDATE marketing_events SET title=?,description=?,status=?,public_visible=?,starts_at=?,ends_at=?,capacity=?,location_label=?,updated_by_user_id=? WHERE id=?');$q->execute([$title,$description,$status,$visible,$starts,$ends,$capacity,$location,$uid,$id]);if($q->rowCount()===0&&!$this->exists('marketing_events',$id))throw new MarketingException('not_found','رویداد پیدا نشد.',404);}
            else{$key='evt_'.bin2hex(random_bytes(10));$q=$this->pdo->prepare('INSERT INTO marketing_events(event_key,title,description,status,public_visible,starts_at,ends_at,capacity,location_label,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$key,$title,$description,$status,$visible,$starts,$ends,$capacity,$location,$uid,$uid]);$id=(int)$this->pdo->lastInsertId();}
            $this->syncCoverReference('event',$id,$mediaKey);
            $this->audit($uid,'marketing.event.save','event',(string)$id,['status'=>$status,'public_visible'=>$visible,'capacity'=>$capacity,'cover_media_key'=>$mediaKey]);
            $this->pdo->commit();return ['id'=>$id,'cover_media_key'=>$mediaKey];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function publicFeed(): array
    {
        $campaigns=$this->pdo->query("SELECT c.campaign_key,c.name,c.headline,c.body,c.starts_at,c.ends_at,(SELECT a.media_key FROM guest_media_references r JOIN guest_media_assets a ON a.id=r.media_id AND a.active=1 WHERE r.owner_type='campaign' AND r.owner_id=CAST(c.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND r.slot_key='cover' LIMIT 1) cover_media_key FROM marketing_campaigns c WHERE c.public_visible=1 AND c.status IN ('scheduled','active') AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP()) AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP()) ORDER BY COALESCE(c.starts_at,'1970-01-01') DESC,c.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $events=$this->pdo->query("SELECT e.event_key,e.title,e.description,e.starts_at,e.ends_at,e.capacity,e.location_label,(SELECT a.media_key FROM guest_media_references r JOIN guest_media_assets a ON a.id=r.media_id AND a.active=1 WHERE r.owner_type='event' AND r.owner_id=CAST(e.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND r.slot_key='cover' LIMIT 1) cover_media_key FROM marketing_events e WHERE e.public_visible=1 AND e.status IN ('scheduled','active') AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND (e.ends_at IS NULL OR e.ends_at>UTC_TIMESTAMP()) ORDER BY e.starts_at ASC,e.id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($campaigns as &$row){$key=trim((string)($row['cover_media_key']??''));$row['cover_image_path']=$key!==''?'media:'.$key:'';unset($row['cover_media_key']);}unset($row);
        foreach($events as &$row){$key=trim((string)($row['cover_media_key']??''));$row['cover_image_path']=$key!==''?'media:'.$key:'';unset($row['cover_media_key']);}unset($row);
        return ['campaigns'=>$campaigns,'events'=>$events];
    }
    private function syncCoverReference(string $ownerType,int $ownerId,string $mediaKey): void
    {
        if(!in_array($ownerType,['campaign','event'],true))throw new MarketingException('invalid_media_owner','نوع محتوای تصویری معتبر نیست.',422);
        $owner=(string)$ownerId;$del=$this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type=? AND owner_id=? AND slot_key='cover'");$del->execute([$ownerType,$owner]);
        if($mediaKey==='')return;
        $q=$this->pdo->prepare('SELECT id FROM guest_media_assets WHERE media_key=? AND active=1 FOR UPDATE');$q->execute([$mediaKey]);$mediaId=(int)($q->fetchColumn()?:0);if($mediaId<1)throw new MarketingException('media_not_found','تصویر انتخاب‌شده در آلبوم فعال نیست.',422);
        $ins=$this->pdo->prepare("INSERT INTO guest_media_references(media_id,owner_type,owner_id,slot_key) VALUES(?,?,?,'cover')");$ins->execute([$mediaId,$ownerType,$owner]);
    }
    private function mediaKey(mixed $v): string{$v=trim((string)$v);if($v==='')return '';if(preg_match('/^[A-Za-z0-9_-]{3,80}$/D',$v)!==1)throw new MarketingException('invalid_media_key','شناسه تصویر معتبر نیست.',422);return $v;}
    private function admin(array $u): void{if((string)($u['role']??'')!=='admin')throw new MarketingException('forbidden','این عملیات فقط برای مدیر فعال است.',403);}
    private function text(mixed $v,int $max,string $label): string{$v=trim((string)$v);if($v===''||strlen($v)>$max*4)throw new MarketingException('invalid_text',$label.' معتبر نیست.',422);return $v;}
    private function optional(mixed $v,int $max): ?string{$v=trim((string)$v);if($v==='')return null;if(strlen($v)>$max*4)throw new MarketingException('invalid_text','متن بیش از حد طولانی است.',422);return $v;}
    private function choice(string $v,array $allowed): string{if(!in_array($v,$allowed,true))throw new MarketingException('invalid_status','وضعیت معتبر نیست.',422);return $v;}
    private function date(mixed $v): ?string{$v=trim((string)$v);if($v==='')return null;try{return \Sokna\Shared\IranTime::utcDb($v);}catch(\Throwable){throw new MarketingException('invalid_date','زمان معتبر نیست.',422);}}
    private function exists(string $table,int $id): bool{$q=$this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=?");$q->execute([$id]);return $q->fetchColumn()!==false;}
    private function audit(int $uid,string $action,string $type,string $id,array $details): void{$q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)');$q->execute([$uid,$action,$type,$id,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
