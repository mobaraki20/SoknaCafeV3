<?php
declare(strict_types=1);
namespace Sokna\Shared;
/** Same field policy for Local HTTP, LAN and Public projections. */
final class OperationsSnapshotPolicy
{
    private const COST_FIELDS=['purchase_price_change','average_unit_cost','last_purchase_base_unit_cost','last_purchase_unit_price','last_base_unit_cost','last_receipt_total_cost','unit_cost_snapshot','actual_total_cost','total_cost','total_cost_delta','raw_total_cost','previous_total_cost','corrected_total_cost','normalized_unit_cost','last_unit_cost','weighted_average_before','weighted_average_after','delta_last_percent','delta_average_percent','metadata_json'];
    public static function result(array $value,bool $canViewCost): array
    {
        if($canViewCost)return $value;
        $costKeys=self::COST_FIELDS;
        foreach($value as $key=>$child){if(in_array((string)$key,$costKeys,true))$value[$key]=null;elseif(is_array($child))$value[$key]=self::result($child,false);}
        return $value;
    }

    public static function apply(array $snapshot,bool $canViewCost): array
    {
        $snapshot['permissions']['can_view_cost']=$canViewCost;
        if($canViewCost)return $snapshot;
        $fields=self::COST_FIELDS;
        foreach(['items','purchase_units','recent_movements'] as $key){
            foreach(($snapshot['inventory'][$key]??[]) as $i=>$row){foreach($fields as $field)if(array_key_exists($field,$row))$snapshot['inventory'][$key][$i][$field]=null;$snapshot['inventory'][$key][$i]['cost_hidden']=true;}
        }
        foreach(($snapshot['inventory']['open_count']['lines']??[]) as $i=>$row){foreach($fields as $field)if(array_key_exists($field,$row))$snapshot['inventory']['open_count']['lines'][$i][$field]=null;}
        foreach(($snapshot['supply']['recent_receipts']??[]) as $i=>$row){foreach($fields as $field)if(array_key_exists($field,$row))$snapshot['supply']['recent_receipts'][$i][$field]=null;$snapshot['supply']['recent_receipts'][$i]['cost_hidden']=true;}
        $snapshot['supply']['purchases']=self::result((array)($snapshot['supply']['purchases']??[]),false);
        $snapshot['daily_queue']=self::result((array)($snapshot['daily_queue']??[]),false);
        $snapshot['supply']['purchase_price_policy']=null;
        $snapshot['data_quality']=['cost_hidden'=>true,'missing_cost_receipt_count'=>0,'missing_cost_receipts'=>[]];
        return $snapshot;
    }
}
