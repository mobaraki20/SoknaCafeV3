<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/apps/local-web/src/Domain/System/CatalogPushReadOnlyAudit.php';

use Sokna\Local\Domain\System\CatalogPushReadOnlyAudit;

final class SpyStatement extends PDOStatement {
    public array $params=[];
    public function __construct(public readonly string $sql,private readonly array $rows){}
    public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->rows[0]??false;}
}
final class SpyPDO extends PDO {
    public array $statements=[];
    public array $familyRows=[];
    public array $itemRows=[];
    public array $menuRows=[];
    public array $settingRows=[];
    public array $subscriptionRows=[['total'=>0,'active'=>0]];
    public array $preferenceRows=[['total'=>0,'enabled'=>0]];
    public bool $fail=false;
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        $this->assertSelect($query);if($this->fail)throw new RuntimeException('redacted DB private failure');
        if(str_contains($query,'FROM item_variant_families'))$rows=$this->familyRows;
        elseif(str_contains($query,'JOIN menu_items'))$rows=$this->menuRows;
        elseif(str_contains($query,'FROM items i'))$rows=$this->itemRows;
        elseif(str_contains($query,'FROM settings'))$rows=$this->settingRows;
        else throw new RuntimeException('unexpected SQL');
        $st=new SpyStatement($query,$rows);$this->statements[]=$st;return $st;
    }
    public function query(string $query,?int $fetchMode=null,...$fetchModeArgs):PDOStatement|false{
        $this->assertSelect($query);if($this->fail)throw new RuntimeException('redacted DB private failure');
        $rows=str_contains($query,'FROM notification_push_subscriptions')?$this->subscriptionRows:$this->preferenceRows;
        $st=new SpyStatement($query,$rows);$this->statements[]=$st;return $st;
    }
    private function assertSelect(string $sql):void{if(!preg_match('/^\s*SELECT\b/i',$sql))throw new RuntimeException('writes forbidden in audit');}
}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$db=new SpyPDO();$a=new CatalogPushReadOnlyAudit($db);
$state=$a->inspect();
check($state['queries']==='SELECT_ONLY','evidence marked read-only');
check($state['catalog']['expected_families']===9,'nine reference families');
check($state['catalog']['complete']===0&&$state['catalog']['needs_review']===9,'missing families are not silently passed');
check(count($state['catalog']['families'])===9,'bounded per-family facts');
check(in_array('canonical_family_missing',$state['catalog']['families'][0]['issues'],true),'missing family reason');
check($state['push']['configuration_present']===false,'missing push config detected');
check(!isset($state['push']['token'])&&!isset($state['push']['endpoint_url']),'no secrets in output');
check(count($db->statements)===6,'six bounded SELECT calls');
$db->familyRows=[
 ['family_key'=>'h297-03','active'=>1,'category_key'=>'tea-herbal','label'=>'تک‌نفره','item_code'=>'801','lifecycle_status'=>'active','available'=>1,'guest_visible'=>1,'price'=>140000],
 ['family_key'=>'h297-03','active'=>1,'category_key'=>'tea-herbal','label'=>'دو نفره','item_code'=>'802','lifecycle_status'=>'active','available'=>1,'guest_visible'=>1,'price'=>240000],
 ['family_key'=>'h297-03','active'=>1,'category_key'=>'tea-herbal','label'=>'بزرگ','item_code'=>'szlarge-b724399f870d48634919','lifecycle_status'=>'active','available'=>1,'guest_visible'=>1,'price'=>320000],
];
$db->menuRows=[['item_code'=>'802','menu_id'=>1],['item_code'=>'szlarge-b724399f870d48634919','menu_id'=>1]];
$db->settingRows=[['setting_key'=>'notifications.vapid_public_key','present'=>1],['setting_key'=>'notifications.push_bridge_url','present'=>1]];
$db->subscriptionRows=[['total'=>4,'active'=>3]];
$state=$a->inspect();
check($state['catalog']['complete']===1,'normalized ZWNJ labels accepted');
check($state['catalog']['needs_review']===8,'eight incomplete families still shown');
check($state['push']['configuration_present']===true,'configured VAPID and bridge reported');
check($state['push']['active_subscriptions']===3,'subscription count only');
// Legacy upgrades use family-03; same product codes must be recognized.
foreach($db->familyRows as &$row)$row['family_key']='family-03';
unset($row);
$legacy=$a->inspect();
check($legacy['catalog']['complete']===1,'legacy family key is recognized as same three-size product');
check($legacy['catalog']['families'][0]['legacy_family_detected']===true,'legacy origin reported');
$db->familyRows[2]['price']=1;
$mispriced=$a->inspect();
check(in_array('price_mismatch:large',$mispriced['catalog']['families'][0]['issues'],true),'wrong large price is diagnosed');
$db->familyRows[2]['price']=320000;
$db->familyRows[2]['guest_visible']=0;$state=$a->inspect();
check(in_array('member_hidden_from_guest',$state['catalog']['families'][0]['issues'],true),'hidden variant diagnosed');
$db->fail=true;$state=$a->inspect();
check($state['catalog']['status']==='unavailable'&&$state['push']['status']==='unavailable','DB error fails closed');
check(strpos(json_encode($state),'redacted DB private failure')===false,'database exception not leaked');