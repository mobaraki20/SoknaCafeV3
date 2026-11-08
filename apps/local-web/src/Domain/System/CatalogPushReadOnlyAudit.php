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
    public function __construct(private readonly PDO $pdo) {}

    public function inspect(): array
    {
        $out = ['format'=>'sokna-catalog-push-readonly-v1',
            'scope'=>'installed_local_only_no_public_browser_probe',
            'queries'=>'SELECT_ONLY',
            'catalog'=>$this->catalog(), 'push'=>$this->push()];
        return $out;
    }

    private function catalog(): array
    {
        try {
            $catalogPath = dirname(__DIR__, 3).'/resources/default-content/v1/catalog.json';
            $reference = json_decode((string)file_get_contents($catalogPath), true, 128, JSON_THROW_ON_ERROR);
            $expected = [];
            foreach ((array)($reference['variant_families']??[]) as $f) {
                $key=(string)($f['family_key']??'');
                if (!preg_match('/^h297-(?:0[3-9]|1[01])$/D',$key)) continue;
                $codes=[];
                foreach ((array)($f['members']??[]) as $m) {
                    $codes[]=(string)($m['item_code']??'');
                }
                if(count($codes)!==3||in_array('',$codes,true)) throw new \RuntimeException('reference_incomplete');
                $expected[$key]=['codes'=>$codes,'name'=>(string)($f['name']??'')];
            }
            if(count($expected)!==9) throw new \RuntimeException('reference_family_count');
            $keys=array_keys($expected);
            $ph=implode(',',array_fill(0,count($keys),'?'));
            $sql="SELECT f.family_key,f.active,c.category_key,m.label,i.item_code,i.lifecycle_status,i.available,i.guest_visible,i.price
                FROM item_variant_families f
                LEFT JOIN categories c ON c.id=f.category_id
                LEFT JOIN item_variant_members m ON m.family_id=f.id
                LEFT JOIN items i ON i.id=m.item_id
                WHERE f.family_key IN ($ph)
                ORDER BY f.family_key,m.sort_order,m.item_id";
            $st=$this->pdo->prepare($sql);$st->execute($keys);
            $members=[];
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r) {
                $key=(string)($r['family_key']??'');
                if(!isset($expected[$key]))continue;
                $members[$key][]=$r;
            }
            $candidateCodes=[];
            foreach($expected as $family)foreach($family['codes'] as $code)$candidateCodes[$code]=true;
            $codes=array_keys($candidateCodes);$ph=implode(',',array_fill(0,count($codes),'?'));
            $st=$this->pdo->prepare("SELECT i.item_code,i.lifecycle_status,i.available,i.guest_visible,c.category_key
                FROM items i LEFT JOIN categories c ON c.id=i.category_id
                WHERE i.item_code IN ($ph)");$st->execute($codes);
            $items=[];
            foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$items[(string)$r['item_code']]=$r;
            $statusCounts=[];$families=[];
            foreach($expected as $key=>$source) {
                $rows=$members[$key]??[];$first=$rows[0]??null;
                $memberList=[];$issues=[];$labels=[];
                foreach($rows as $r){
                    if($r['item_code']===null)continue;
                    $label=$this->label((string)($r['label']??''));$labels[$label]=($labels[$label]??0)+1;
                    $memberList[]=['label'=>$label,'item_code'=>(string)$r['item_code'],
                        'active'=>(string)($r['lifecycle_status']??'')==='active',
                        'available'=>(int)($r['available']??0)===1,
                        'guest_visible'=>(int)($r['guest_visible']??0)===1,
                        'price_positive'=>(int)($r['price']??0)>0];
                }
                if($first===null)$issues[]='canonical_family_missing';
                else {
                    if((int)$first['active']!==1)$issues[]='canonical_family_disabled';
                    if((string)$first['category_key']!=='tea-herbal')$issues[]='wrong_category';
                    if(count($memberList)!==3)$issues[]='member_count_not_three';
                    foreach(['تک نفره','دو نفره','بزرگ'] as $label)if(($labels[$label]??0)!==1)$issues[]='label_missing_or_duplicate:'.($label==='تک نفره'?'single':($label==='دو نفره'?'two':'large'));
                    foreach($memberList as $item) {
                        if(!$item['active']){$issues[]='member_inactive';break;}
                    }
                    foreach($memberList as $item)if(!$item['guest_visible']){$issues[]='member_hidden_from_guest';break;}
                }
                $sourceRows=[];
                foreach($source['codes'] as $code){
                    $c=$items[$code]??null;
                    $sourceRows[]=['code'=>$code,'exists'=>$c!==null,'active'=>$c!==null&&(string)$c['lifecycle_status']==='active',
                       'guest_visible'=>$c!==null&&(int)$c['guest_visible']===1,'category_ok'=>$c!==null&&(string)$c['category_key']==='tea-herbal'];
                }
                $state=$issues===[]?'complete':'needs_review';$statusCounts[$state]=($statusCounts[$state]??0)+1;
                $families[]=['family_key'=>$key,'reference_name'=>$source['name'],'status'=>$state,
                    'issues'=>array_values(array_unique($issues)),'members'=>$memberList,'expected_reference_items'=>$sourceRows];
            }
            return ['status'=>'checked','expected_families'=>9,
                'complete'=>(int)($statusCounts['complete']??0),'needs_review'=>(int)($statusCounts['needs_review']??0),
                'families'=>$families,
                'interpretation'=>'Migration ledger alone does not prove that any three-size family is present or visible. Reference codes are identifiers, not proof that live catalog must be overwritten.'];
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