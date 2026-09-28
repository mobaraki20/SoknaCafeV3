<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Sellables;

use PDO;
use RuntimeException;
use Throwable;

final class DefaultContentSeeder
{
    private const MARKER_KEY = 'default_content.v1';
    private const MARKER_VALUE = 'complete';

    public function __construct(
        private readonly PDO $pdo,
        private readonly CategoryIconLibrary $icons,
        private readonly string $resourceRoot,
        private readonly string $mediaRoot,
    ) {}

    /** @return array<string,int|string|bool> */
    public function seed(?int $actorUserId = null): array
    {
        if ($this->setting(self::MARKER_KEY) === self::MARKER_VALUE) {
            return ['seeded'=>false,'status'=>'already_complete'];
        }
        $catalog = $this->catalog();
        $mediaMap = $this->prepareMedia($catalog, $actorUserId);
        $stats = $this->seedCatalog($catalog, $mediaMap, $actorUserId);
        $this->setSetting(self::MARKER_KEY, self::MARKER_VALUE);
        return ['seeded'=>true,'status'=>'complete',...$stats,'media_assets'=>count($mediaMap)];
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        $path = $this->resourceRoot . DIRECTORY_SEPARATOR . 'catalog.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($raw)) throw new RuntimeException('Default catalog resource is missing.');
        $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($doc)) throw new RuntimeException('Default catalog resource is invalid.');
        foreach (['menus','categories','items'] as $key) if (!is_array($doc[$key] ?? null)) throw new RuntimeException("Default catalog '$key' is invalid.");
        if (count($doc['menus']) !== 3 || count($doc['categories']) !== 15 || count($doc['items']) !== 128) {
            throw new RuntimeException('Default catalog counts do not match the audited legacy content baseline.');
        }
        return $doc;
    }

    /** @return array<string,array{media_key:string,media_id:int}> */
    private function prepareMedia(array $catalog, ?int $actorUserId): array
    {
        $paths = [];
        foreach (['categories','items'] as $scope) {
            foreach ($catalog[$scope] as $row) {
                $rel = trim((string)($row['image_path'] ?? ''));
                if ($rel !== '') $paths[$rel] = true;
            }
        }
        $map = [];
        foreach (array_keys($paths) as $legacyPath) {
            if (!preg_match('#^assets/menu/default/([A-Za-z0-9._-]+\.webp)$#D', $legacyPath, $m)) {
                throw new RuntimeException('Default catalog references an unsupported media path: '.$legacyPath);
            }
            $source = $this->resourceRoot . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $m[1];
            if (!is_file($source) || !is_readable($source)) throw new RuntimeException('Default media file is missing: '.$m[1]);
            $size = (int)filesize($source);
            $sha = hash_file('sha256',$source);
            $dim = @getimagesize($source);
            if (!is_string($sha) || !is_array($dim) || (int)($dim[0]??0)<1 || (int)($dim[1]??0)<1 || ($dim['mime']??'') !== 'image/webp') {
                throw new RuntimeException('Default media file is invalid: '.$m[1]);
            }
            $mediaKey = 'm_' . substr($sha,0,24);
            $originalRel = 'original/'.$sha.'.webp';

            $q=$this->pdo->prepare('SELECT id,media_key FROM guest_media_assets WHERE sha256=? LIMIT 1');
            $q->execute([$sha]);$existing=$q->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                $id=(int)$existing['id'];
                $mediaKey=(string)$existing['media_key'];
            } else {
                $ins=$this->pdo->prepare('INSERT INTO guest_media_assets(media_key,sha256,mime,extension,byte_size,width_px,height_px,original_name,alt_text,original_relpath,active,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,1,?)');
                $ins->execute([$mediaKey,$sha,'image/webp','webp',$size,(int)$dim[0],(int)$dim[1],$m[1],'',$originalRel,$actorUserId]);
                $id=(int)$this->pdo->lastInsertId();
            }

            $derivedRel = 'derived/'.$mediaKey.'/guest-card.webp';
            $this->copySeedFile($source,$this->mediaPath($originalRel));
            $this->copySeedFile($source,$this->mediaPath($derivedRel));
            $dsha=hash_file('sha256',$this->mediaPath($derivedRel));
            if(!is_string($dsha))throw new RuntimeException('Default media derivative hash failed.');
            $up=$this->pdo->prepare("INSERT INTO guest_media_derivatives(media_id,variant_key,sha256,mime,extension,byte_size,width_px,height_px,path_relpath,processor) VALUES(?,'guest-card',?,'image/webp','webp',?,?,?,?,'seed-passthrough') ON DUPLICATE KEY UPDATE sha256=VALUES(sha256),byte_size=VALUES(byte_size),width_px=VALUES(width_px),height_px=VALUES(height_px),path_relpath=VALUES(path_relpath),processor=VALUES(processor)");
            $up->execute([$id,$dsha,(int)filesize($this->mediaPath($derivedRel)),(int)$dim[0],(int)$dim[1],$derivedRel]);
            $map[$legacyPath]=['media_key'=>$mediaKey,'media_id'=>$id];
        }
        if (count($map)!==24) throw new RuntimeException('Default media baseline must contain exactly 24 referenced images.');
        return $map;
    }

    /** @return array<string,int> */
    private function seedCatalog(array $catalog,array $mediaMap,?int $actorUserId): array
    {
        $stats=['menus'=>0,'categories'=>0,'items'=>0,'menu_categories'=>0,'menu_items'=>0,'media_references'=>0];
        $this->pdo->beginTransaction();
        try {
            $menuMap=[];
            $menuUp=$this->pdo->prepare('INSERT INTO menus(menu_key,name,status,sort_order,schedule_days,daily_start,daily_end) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),status=VALUES(status),sort_order=VALUES(sort_order),schedule_days=VALUES(schedule_days),daily_start=VALUES(daily_start),daily_end=VALUES(daily_end)');
            $menuId=$this->pdo->prepare('SELECT id FROM menus WHERE menu_key=?');
            foreach($catalog['menus'] as $row){$key=trim((string)($row['menu_key']??''));if($key==='')continue;$menuUp->execute([$key,(string)$row['name'],$this->menuStatus((string)($row['status']??'draft')),(int)($row['sort_order']??0),$row['schedule_days']??null,$row['daily_start']??null,$row['daily_end']??null]);$menuId->execute([$key]);$menuMap[$key]=(int)$menuId->fetchColumn();$stats['menus']++;}

            $categoryMap=[];
            $catUp=$this->pdo->prepare('INSERT INTO categories(category_key,name,audience,image_path,icon_key,sort_order,active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),audience=VALUES(audience),image_path=VALUES(image_path),icon_key=VALUES(icon_key),sort_order=VALUES(sort_order)');
            $catId=$this->pdo->prepare('SELECT id FROM categories WHERE category_key=?');
            foreach($catalog['categories'] as $row){$key=trim((string)($row['category_key']??''));if($key==='')continue;$icon=trim((string)($row['icon_key']??''));if($icon!==''&&!$this->icons->isAllowed($icon))throw new RuntimeException('Default category icon is not in the V3 icon library: '.$icon);$source=trim((string)($row['image_path']??''));$image=$source!==''?'media:'.($mediaMap[$source]['media_key']??throw new RuntimeException('Category media is missing')):null;$catUp->execute([$key,(string)$row['name'],$this->audience((string)($row['audience']??'guest_staff')),$image,$icon!==''?$icon:null,(int)($row['sort_order']??0)]);$catId->execute([$key]);$categoryMap[$key]=(int)$catId->fetchColumn();$stats['categories']++;}

            if($categoryMap!==[]){$ids=implode(',',array_map('intval',array_values($categoryMap)));$this->pdo->exec('DELETE FROM menu_categories WHERE category_id IN ('.$ids.')');}
            $mc=$this->pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,?)');
            foreach($catalog['categories'] as $row){$cid=$categoryMap[(string)($row['category_key']??'')]??0;if($cid<1)continue;foreach(array_values(array_unique(array_map('strval',(array)($row['menus']??[])))) as $menuKey){$mid=$menuMap[$menuKey]??0;if($mid<1)continue;$mc->execute([$mid,$cid,(int)($row['sort_order']??0)]);$stats['menu_categories']++;}}

            $itemMap=[];
            $itemUp=$this->pdo->prepare('INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,' . "'menu_item',1" . ',?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),name=VALUES(name),description=VALUES(description),price=VALUES(price),image_path=VALUES(image_path),available=VALUES(available),active=VALUES(active),featured=VALUES(featured),staff_only=VALUES(staff_only),sellable_kind=VALUES(sellable_kind),takeaway_allowed=VALUES(takeaway_allowed),preparation_station=VALUES(preparation_station),sort_order=VALUES(sort_order)');
            $itemId=$this->pdo->prepare('SELECT id FROM items WHERE item_code=?');
            foreach($catalog['items'] as $row){$code=trim((string)($row['item_code']??''));$cid=$categoryMap[(string)($row['category_key']??'')]??0;if($code===''||$cid<1)continue;$source=trim((string)($row['image_path']??''));$image=$source!==''?'media:'.($mediaMap[$source]['media_key']??throw new RuntimeException('Item media is missing')):null;$itemUp->execute([$code,$cid,(string)$row['name'],(string)($row['description']??''),(int)($row['price']??0),$image,(int)($row['available']??1),(int)($row['active']??1),(int)($row['featured']??0),(int)($row['staff_only']??0),$this->station((string)($row['preparation_station']??'cold_bar')),(int)($row['sort_order']??0)]);$itemId->execute([$code]);$itemMap[$code]=(int)$itemId->fetchColumn();$stats['items']++;}

            if($itemMap!==[]){$ids=implode(',',array_map('intval',array_values($itemMap)));$this->pdo->exec('DELETE FROM menu_items WHERE item_id IN ('.$ids.')');}
            $mi=$this->pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)');
            foreach($catalog['items'] as $row){$iid=$itemMap[(string)($row['item_code']??'')]??0;if($iid<1)continue;foreach(array_values(array_unique(array_map('strval',(array)($row['menus']??[])))) as $menuKey){$mid=$menuMap[$menuKey]??0;if($mid<1)continue;$mi->execute([$mid,$iid]);$stats['menu_items']++;}}

            $refDelete=$this->pdo->prepare("DELETE FROM guest_media_references WHERE owner_type=? AND owner_id=? AND slot_key='card'");
            $refInsert=$this->pdo->prepare("INSERT IGNORE INTO guest_media_references(media_id,owner_type,owner_id,slot_key) VALUES(?,?,?,'card')");
            foreach($catalog['categories'] as $row){$source=trim((string)($row['image_path']??''));$cid=$categoryMap[(string)($row['category_key']??'')]??0;if($source===''||$cid<1)continue;$refDelete->execute(['category',(string)$cid]);$refInsert->execute([(int)$mediaMap[$source]['media_id'],'category',(string)$cid]);$stats['media_references']++;}
            foreach($catalog['items'] as $row){$source=trim((string)($row['image_path']??''));$iid=$itemMap[(string)($row['item_code']??'')]??0;if($source===''||$iid<1)continue;$refDelete->execute(['item',(string)$iid]);$refInsert->execute([(int)$mediaMap[$source]['media_id'],'item',(string)$iid]);$stats['media_references']++;}

            foreach(array_values(array_filter(array_map('strval',(array)($catalog['retired_item_codes']??[])))) as $code){$q=$this->pdo->prepare('UPDATE items SET active=0,available=0 WHERE item_code=?');$q->execute([$code]);}
            if($actorUserId!==null&&$actorUserId>0){$q=$this->pdo->prepare('SELECT display_name FROM users WHERE id=?');$q->execute([$actorUserId]);$name=$q->fetchColumn();$this->pdo->prepare("INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,'setup.default_content_seeded','installation','default-content-v1',?)")->execute([$actorUserId,$name!==false?$name:null,json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
            $this->pdo->commit();
            return $stats;
        } catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function copySeedFile(string $source,string $target): void
    {
        $dir=dirname($target);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Default media directory cannot be created.');
        $sourceHash=hash_file('sha256',$source);if(!is_string($sourceHash))throw new RuntimeException('Default media source hash failed.');
        if(is_file($target)){ $targetHash=hash_file('sha256',$target); if(is_string($targetHash)&&hash_equals($sourceHash,$targetHash))return; }
        $tmp=$target.'.tmp-'.bin2hex(random_bytes(4));if(!copy($source,$tmp)){@unlink($tmp);throw new RuntimeException('Default media copy failed.');}@chmod($tmp,0640);if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Default media atomic replace failed.');}
    }
    private function mediaPath(string $rel): string { $rel=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$rel);return rtrim($this->mediaRoot,"\\/").DIRECTORY_SEPARATOR.$rel; }
    private function setting(string $key): string{$q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?'':(string)$v;}
    private function setSetting(string $key,string $value): void{$q=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$key,$value]);}
    private function menuStatus(string $v): string{return in_array($v,['active','draft','inactive'],true)?$v:'draft';}
    private function audience(string $v): string{return in_array($v,['guest_staff','staff_only'],true)?$v:'guest_staff';}
    private function station(string $v): string{return $v==='other'?'cold_bar':(in_array($v,['kitchen','hot_bar','cold_bar','none'],true)?$v:'cold_bar');}
}
