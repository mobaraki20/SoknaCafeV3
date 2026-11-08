<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use PDO;
use Throwable;

/**
 * Bounded, read-only support evidence for the R140->R141 catalog/push follow-up.
 * Never returns push endpoints, VAPID keys, bridge URL, tokens, staff identities or DB errors.
 */
final class CatalogPushReadOnlyAudit
{
    /** Canonical nine families from exact R140 release; never inferred from live mutable catalog. */
    private const REFERENCE = [
        'h297-03'=>['name'=>'چای ساده','codes'=>['801','802','szlarge-b724399f870d48634919']],
        'h297-04'=>['name'=>'چای مراکش','codes'=>['803','804','szlarge-f175529f9377c369796f']],
        'h297-05'=>['name'=>'چای هل و دارچین','codes'=>['805','806','szlarge-4bea3602d814e040394b']],
        'h297-06'=>['name'=>'چای زعفران','codes'=>['807','808','szlarge-c09968a088cc7c85f35c']],
        'h297-07'=>['name'=>'دمنوش فرش قرمز','codes'=>['701','713','szlarge-a49ad2e7ad488144cc37']],
        'h297-08'=>['name'=>'دمنوش آرام دل','codes'=>['703','712','szlarge-0057052b652ae5fe9d62']],
        'h297-09'=>['name'=>'دمنوش آویشن','codes'=>['705','711','szlarge-3abe1f688ec6e9ea96b4']],
        'h297-10'=>['name'=>'دمنوش گل نوش','codes'=>['707','708','szlarge-5dc06120c9e6d741e617']],
        'h297-11'=>['name'=>'دمنوش طلا','codes'=>['709','710','szlarge-6f471f8f80e872d1788a']],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function inspect(): array
    {
        $out = ['format'=>'sokna-catalog-push-readonly-v1',
            'scope'=>'installed_local_only_no_public_browser_probe',
            'queries'=>'SELECT_ONLY',
            'catalog'=>$this->catalog(), 'push'=>$this->push()];
        return $out;
    }

    /**
     * Both upgrade paths are first-class: 0055 seeded family-03..11; clean
     * installs use h297-03..11. Never infer family identity from a localized
     * name or overwrite a manually edited item.
     */
    private function catalog(): array
    {
        try {
            $expected=self::REFERENCE;
            $keys=array_keys($expected);
            $allKeys=array_merge($keys,array_map(static fn(string $key):string=>'family-'.substr($key,5),$keys));
            $ph=implode(',',array_fill(0,count($allKeys),'?'));
            $sql="SELECT f.family_key,f.active,c.category_key,m.label,i.item_code,i.lifecycle_status,i.available,i.guest_visible,i.price
                FROM item_variant_families f
                LEFT JOIN categories c ON c.id=f.category_id
                LEFT JOIN item_variant_members m ON m.family_id=f.id
                LEFT JOIN items i ON i.id=m.item_id
                WHERE f.family_key IN ($ph)
                ORDER BY f.family_key,m.sort_order,m.item_id";
            $st=$this->pdo->prepare($sql);$st->execute($allKeys);
            $members=[];
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r) {
                $actual=(string)($r['family_key']??'');
                $key=str_starts_with($actual,'family-') ? 'h297-'.substr($actual,7) : $actual;
                if(isset($expected[$key]))$members[$key][$actual][]=$r;
            }
            $codes=[];
            foreach($expected as $family)foreach($family['codes'] as $code)$codes[$code]=true;
            $codes=array_keys($codes);$ph=implode(',',array_fill(0,count($codes),'?'));
            $st=$this->pdo->prepare("SELECT i.item_code,i.lifecycle_status,i.available,i.guest_visible,i.price,c.category_key
                FROM items i LEFT JOIN categories c ON c.id=i.category_id
                WHERE i.item_code IN ($ph)");$st->execute($codes);
            $items=[];
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$items[(string)$r['item_code']]=$r;
            $st=$this->pdo->prepare("SELECT i.item_code,mi.menu_id
                FROM items i JOIN menu_items mi ON mi.item_id=i.id WHERE i.item_code IN ($ph)");
            $st->execute($codes);$menus=[];
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$menus[(string)$r['item_code']][(int)$r['menu_id']]=true;
            $statusCounts=[];$families=[];
            foreach($expected as $key=>$source) {
                $variants=$members[$key]??[];
                $actualKeys=array_keys($variants);
                $actualKey=count($actualKeys)===1?$actualKeys[0]:null;
                $rows=$actualKey!==null?($variants[$actualKey]??[]):[];
                $first=$rows[0]??null;
                $issues=[];$memberList=[];$labels=[];$membersByCode=[];
                if(count($actualKeys)>1)$issues[]='duplicate_legacy_and_canonical_family';
                foreach($rows as $r){
                    if($r['item_code']===null)continue;
                    $label=$this->label((string)($r['label']??''));
                    $code=(string)$r['item_code'];
                    $labels[$label]=($labels[$label]??0)+1;
                    $membersByCode[$code]=['label'=>$label,'price'=>(int)($r['price']??0)];
                    $memberList[]=['label'=>$label,'item_code'=>$code,'price_toman'=>(int)($r['price']??0),
                        'active'=>(string)($r['lifecycle_status']??'')==='active',
                        'available'=>(int)($r['available']??0)===1,
                        'guest_visible'=>(int)($r['guest_visible']??0)===1,
                        'price_positive'=>(int)($r['price']??0)>0];
                }
                if($first===null)$issues[]='canonical_family_missing';
                else {
                    if((int)$first['active']!==1)$issues[]='family_disabled';
                    if((string)$first['category_key']!=='tea-herbal')$issues[]='wrong_category';
                    if(count($memberList)!==3)$issues[]='member_count_not_three';
                    foreach(['تک نفره','دو نفره','بزرگ'] as $label){
                        if(($labels[$label]??0)!==1)$issues[]='label_missing_or_duplicate:'.($label==='تک نفره'?'single':($label==='دو نفره'?'two':'large'));
                    }
                    foreach($source['codes'] as $idx=>$code){
                        if(!isset($membersByCode[$code]))$issues[]='expected_member_code_missing:'.['single','two','large'][$idx];
                    }
                    foreach($memberList as $item){
                        if(!$item['active']){$issues[]='member_inactive';break;}
                    }
                    foreach($memberList as $item){
                        if(!$item['guest_visible']){$issues[]='member_hidden_from_guest';break;}
                    }
                }
                $single=(int)($membersByCode[$source['codes'][0]]['price']??0);
                $expectedPrices=$single>0?[$single,(int)(round($single*1.7/5000)*5000),(int)(round($single*2.3/5000)*5000)]:[];
                if($single>0){
                    foreach([1,2] as $i){
                        $code=$source['codes'][$i];
                        if(isset($membersByCode[$code]) && $membersByCode[$code]['price']!==$expectedPrices[$i])$issues[]='price_mismatch:'.($i===1?'two':'large');
                    }
                }
                $two=$source['codes'][1];$large=$source['codes'][2];
                if(isset($membersByCode[$large])){
                    $twoMenus=array_keys($menus[$two]??[]);
                    $largeMenus=array_keys($menus[$large]??[]);
                    if($twoMenus===[]||array_diff($twoMenus,$largeMenus)!==[])$issues[]='large_menu_link_missing';
                }
                $referenceItems=[];
                foreach($source['codes'] as $code){
                    $item=$items[$code]??null;
                    $referenceItems[]=['code'=>$code,'exists'=>$item!==null,
                        'active'=>$item!==null&&(string)$item['lifecycle_status']==='active',
                        'guest_visible'=>$item!==null&&(int)$item['guest_visible']===1,
                        'category_ok'=>$item!==null&&(string)$item['category_key']==='tea-herbal',
                        'price_toman'=>$item!==null?(int)$item['price']:null];
                }
                $state=$issues===[]?'complete':'needs_review';
                $statusCounts[$state]=($statusCounts[$state]??0)+1;
                $families[]=['family_key'=>$key,'actual_family_key'=>$actualKey,
                    'legacy_family_detected'=>$actualKey!==null&&str_starts_with($actualKey,'family-'),
                    'reference_name'=>$source['name'],'status'=>$state,
                    'issues'=>array_values(array_unique($issues)),'members'=>$memberList,
                    'expected_price_toman'=>$expectedPrices===[]?null:[
                        'single'=>$expectedPrices[0],'two'=>$expectedPrices[1],'large'=>$expectedPrices[2]],
                    'expected_reference_items'=>$referenceItems];
            }
            return ['status'=>'checked','expected_families'=>9,
                'complete'=>(int)($statusCounts['complete']??0),
                'needs_review'=>(int)($statusCounts['needs_review']??0),
                'families'=>$families,
                'interpretation'=>'Checks legacy family-03..11 and canonical h297-03..11. An applied migration is not evidence of 27 visible, linked and correctly priced items.'];
        }catch(Throwable){return ['status'=>'unavailable','reason'=>'catalog_readonly_query_failed'];}
    }

    private function push(): array
    {
        try{
            $keys=['notifications.vapid_public_key','notifications.push_bridge_url',
                   'notifications.push_public_binding_sha256','notifications.push_public_rebind_pending_sha256'];
            $ph=implode(',',array_fill(0,count($keys),'?'));
            $st=$this->pdo->prepare("SELECT setting_key, CASE WHEN setting_value IS NOT NULL AND TRIM(setting_value)<>'' THEN 1 ELSE 0 END AS present FROM settings WHERE setting_key IN ($ph)");
            $st->execute($keys);$flags=array_fill_keys($keys,false);
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$key=(string)($r['setting_key']??'');if(array_key_exists($key,$flags))$flags[$key]=(int)($r['present']??0)===1;}
            $counts=$this->pdo->query('SELECT COUNT(*) total,COALESCE(SUM(active=1),0) active FROM notification_push_subscriptions')->fetch(PDO::FETCH_ASSOC)?:[];
            $prefs=$this->pdo->query('SELECT COUNT(*) total,COALESCE(SUM(push_enabled=1),0) enabled FROM notification_preferences')->fetch(PDO::FETCH_ASSOC)?:[];
            $ready=$flags['notifications.vapid_public_key']&&$flags['notifications.push_bridge_url'];
            return ['status'=>'checked','vapid_key_configured'=>$flags['notifications.vapid_public_key'],
              'bridge_url_configured'=>$flags['notifications.push_bridge_url'],
              'saved_binding_present'=>$flags['notifications.push_public_binding_sha256'],
              'origin_rebind_pending'=>$flags['notifications.push_public_rebind_pending_sha256'],
              'configuration_present'=>$ready,
              'active_subscriptions'=>(int)($counts['active']??0),'subscription_count'=>(int)($counts['total']??0),
              'preferences_with_push_enabled'=>(int)($prefs['enabled']??0),'preference_count'=>(int)($prefs['total']??0),
              'browser_permission'=>'not_observable_from_local_support',
              'service_worker'=>'not_observable_from_local_support',
              'public_bridge_delivery'=>'not_proven_by_configuration',
              'note'=>'No push secrets, endpoint URLs or staff names are included.'];
        }catch(Throwable){return ['status'=>'unavailable','reason'=>'push_readonly_query_failed'];}
    }

    private function label(string $label): string
    {
        return preg_replace('/\s+/u',' ',str_replace(["\u{200C}","\u{00A0}",'ي','ك'],[' ',' ','ی','ک'],trim($label)))??trim($label);
    }
}