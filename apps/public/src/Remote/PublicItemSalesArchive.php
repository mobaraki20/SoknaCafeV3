<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Remote;

/** Aggregate authoritative Local daily SKU facts for a Public Staff user. */
final class PublicItemSalesArchive
{
    public static function validated(array $data,array $meta): bool
    {
        if(($meta['stale']??true)!==false||($data['format']??'')!=='sokna-item-sales-daily-archive-v1'
          ||($data['status']??'')!=='available'||($data['basis']??'')!=='completed_and_reversal_settlement_lines_by_item_id_snapshot_session_open_day')return false;
        $range=(array)($data['range']??[]);
        return self::day((string)($range['from']??''))&&self::day((string)($range['to']??''))
          &&is_array($data['catalog']??null)&&is_array($data['families']??null)
          &&is_array($data['members']??null)&&is_array($data['daily']??null);
    }
    public static function lookup(array $data,string $q): array
    {
        $q=self::fold(trim($q));if($q==='')return [];
        $result=[];foreach($data['catalog'] as $r){if(!is_array($r))continue;
            if(str_contains(self::fold((string)($r['name']??'').' '.(string)($r['item_code']??'')),$q)){
                $result[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'item_code'=>(string)($r['item_code']??'')];
                if(count($result)>=40)break;
            }
        }return $result;
    }
    /** Search the bounded synced catalog; never infer a financial total from a text search alone. */
    public static function keywordIds(array $data,string $query): ?array
    {
        $query=self::fold(trim($query));
        $length=preg_match_all('/./us',$query);
        if($length===false||$length<2||$length>60)return null;
        $ids=[];foreach($data['catalog'] as $row){if(!is_array($row))continue;
          if(str_contains(self::fold((string)($row['name']??'')),$query)){
             $id=(int)($row['id']??0);if($id>0)$ids[$id]=$id;
             if(count($ids)>300)return null;
          }
        }
        return array_values($ids);
    }
    public static function groups(array $data): array
    {
        $categories=[];$families=[];$count=[];
        foreach($data['catalog'] as $r){if(!is_array($r))continue;
          $c=(int)($r['category_id']??0);if($c<=0)continue;
          if(!isset($categories[$c]))$categories[$c]=['id'=>$c,'name'=>(string)($r['category_name']??''),'item_count'=>0];
          $categories[$c]['item_count']++;
        }
        foreach($data['members'] as $m){if(!is_array($m))continue;$fid=(int)($m['family_id']??0);
          $count[$fid]=($count[$fid]??0)+1;}
        foreach($data['families'] as $f){if(!is_array($f))continue;$fid=(int)$f['id'];
          $families[]=['id'=>$fid,'name'=>(string)$f['name'],'category_id'=>(int)$f['category_id'],'item_count'=>$count[$fid]??0];}
        return ['categories'=>array_values($categories),'families'=>$families,'source'=>'current_catalog_membership'];
    }
    public static function groupIds(array $data,string $type,int $id): array
    {
        if($id<=0||!in_array($type,['category','family'],true))return [];
        $ids=[];
        if($type==='category')foreach($data['catalog'] as $i){if(is_array($i)&&(int)($i['category_id']??0)===$id)$ids[]=(int)$i['id'];}
        else foreach($data['members'] as $m){if(is_array($m)&&(int)($m['family_id']??0)===$id)$ids[]=(int)$m['item_id'];}
        return array_values(array_unique(array_filter($ids,static fn(int $x):bool=>$x>0)));
    }
    public static function groupName(array $data,string $type,int $id): string
    {
        $rows=$type==='category'?self::groups($data)['categories']:$data['families'];
        foreach($rows as $r)if(is_array($r)&&(int)($r['id']??0)===$id)return (string)($r['name']??'');
        return '';
    }
    public static function report(array $data,string $from,string $to,array $ids,?array $group=null): ?array
    {
        $range=(array)($data['range']??[]);
        if(!self::day($from)||!self::day($to)||$from>$to
          ||$from<(string)$range['from']||$to>(string)$range['to'])return null;
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
        if(!$ids||count($ids)>300)return null;
        $catalog=[];foreach($data['catalog'] as $row)if(is_array($row))$catalog[(int)($row['id']??0)]=$row;
        foreach($ids as $id)if(!isset($catalog[$id]))return null;
        $byId=array_fill_keys($ids,['quantity'=>0,'gross'=>0,'discount'=>0,'net_sales'=>0]);
        foreach($data['daily'] as $row){if(!is_array($row))continue;
            $day=(string)($row['day']??'');$id=(int)($row['item_id']??0);
            if($day<$from||$day>$to||!isset($byId[$id]))continue;
            foreach(['quantity','gross','discount','net_sales'] as $field)$byId[$id][$field]+=(float)($row[$field]??0);
        }
        $rows=[];$total=['quantity'=>0,'gross'=>0,'discount'=>0,'net_sales'=>0];
        foreach($ids as $id){$c=$catalog[$id];$vals=$byId[$id];
          $r=['item_id'=>$id,'name'=>(string)$c['name'],'item_code'=>(string)($c['item_code']??''),'available_in_catalog'=>true,
              'quantity'=>$vals['quantity'],'gross'=>(int)$vals['gross'],'discount'=>(int)$vals['discount'],'net_sales'=>(int)$vals['net_sales']];
          foreach($total as $field=>$_)$total[$field]+=$r[$field];$rows[]=$r;
        }
        $result=['range'=>['from'=>$from,'to'=>$to],'rows'=>$rows,'totals'=>$total,
          'basis'=>(string)$data['basis'],'membership'=>'current_catalog','currency'=>'IRT',
          'excludes'=>'unpaid_open_orders'];if($group)$result['group']=$group;
        return $result;
    }
    private static function day(string $s):bool
    {return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$s,$m)===1&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);}
    private static function fold(string $s):string
    {$s=strtr($s,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','ي'=>'ی','ك'=>'ک']);
     return function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);}
}
