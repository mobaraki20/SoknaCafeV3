<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Account;
use PDO;
final class UserUiPreferenceService
{
    private const GROUPS=['daily','operations','guest','management'];
    public function __construct(private readonly PDO $pdo){}
    public function forUserContext(array $user): array{return $this->forUser((int)($user['id']??0));}
    public function forUser(int $userId): array
    {
        if($userId<1)return [];$q=$this->pdo->prepare('SELECT preferences_json FROM user_ui_preferences WHERE user_id=? LIMIT 1');$q->execute([$userId]);
        $raw=$q->fetchColumn();if($raw===false)return [];$decoded=json_decode((string)$raw,true);return is_array($decoded)?$this->normalize($decoded):[];
    }
    public function forUsers(array $userIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$userIds),static fn(int $id):bool=>$id>0)));if($ids===[])return [];
        $marks=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare('SELECT user_id,preferences_json FROM user_ui_preferences WHERE user_id IN ('.$marks.')');$q->execute($ids);$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$d=json_decode((string)($row['preferences_json']??''),true);$out[(int)$row['user_id']]=is_array($d)?$this->normalize($d):[];}return $out;
    }
    public function savePatch(int $userId,array $patch): array
    {
        if($userId<1)throw new \InvalidArgumentException('user_required');$current=$this->forUser($userId);$valid=$this->normalize($patch);
        foreach($valid as $key=>$value)$current[$key]=$value;$json=json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $q=$this->pdo->prepare('INSERT INTO user_ui_preferences(user_id,preferences_json) VALUES(?,?) ON DUPLICATE KEY UPDATE preferences_json=VALUES(preferences_json),updated_at=CURRENT_TIMESTAMP');$q->execute([$userId,$json]);return $current;
    }
    private function normalize(array $input): array
    {
        $out=[];$theme=(string)($input['theme']??'');if(in_array($theme,['light','dark','system'],true))$out['theme']=$theme;
        if(array_key_exists('nav_compact',$input)){$compact=filter_var($input['nav_compact'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($compact!==null)$out['nav_compact']=$compact;}
        if(is_array($input['nav_groups']??null)){$groups=[];foreach($input['nav_groups'] as $g){$g=(string)$g;if(in_array($g,self::GROUPS,true)&&!in_array($g,$groups,true))$groups[]=$g;}$out['nav_groups']=$groups;}
        $sort=(string)($input['hall_sort']??'');if(in_array($sort,['number','time'],true))$out['hall_sort']=$sort;
        $density=(string)($input['hall_density']??'');if(in_array($density,['normal','compact','overview'],true))$out['hall_density']=$density;
        return $out;
    }
}
