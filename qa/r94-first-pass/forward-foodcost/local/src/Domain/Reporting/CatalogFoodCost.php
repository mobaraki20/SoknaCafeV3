<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Reporting;

/** Read-only current ingredient-cost estimate, NOT historical cost or net profit. */
final class CatalogFoodCost
{
    /**
     * @param list<array<string,mixed>> $components cost per base unit (g/ml/count)
     * @return array<string,mixed>
     */
    public static function estimate(array $components, ?array $recipe, int $salePrice, bool $isBundle=false): array
    {
        $details=[];$known=0.0;$partial=false;$estimated=false;$oldest=null;
        foreach($components as $component){
            $qty=(int)($component['quantity_base']??0);
            $status=(string)($component['cost_status']??'unknown');
            $unit=$component['average_unit_cost']??null;
            $valid=$qty>0&&((int)($component['inventory_active']??1)===1)&&in_array($status,['known','estimated'],true)&&$unit!==null&&is_numeric($unit)&&is_finite((float)$unit)&&(float)$unit>=0;
            $amount=$valid?$qty*(float)$unit:null;
            if($amount!==null&&is_finite($amount))$known+=$amount;
            else{$amount=null;$partial=true;}
            if($status==='estimated'||(string)($component['quantity_status']??'known')!=='known')$estimated=true;
            if((string)($component['inventory_review_status']??'ready')!=='ready')$estimated=true;
            $at=trim((string)($component['cost_updated_at']??''));
            if($at!==''&&($oldest===null||strcmp($at,$oldest)<0))$oldest=$at;
            $details[]=['inventory_item_id'=>(int)($component['inventory_item_id']??0),'name'=>(string)($component['inventory_item_name']??''),
                'base_unit'=>(string)($component['base_unit']??''),'quantity_base'=>$qty,
                'unit_cost'=>$valid?(float)$unit:null,'cost_status'=>$status,'line_cost'=>$amount===null?null:(int)round($amount)];
        }
        $state='known';
        if($isBundle)$state='bundle_needs_review';
        elseif($recipe===null||$components===[])$state='missing_recipe';
        elseif($partial||($recipe['completeness_status']??'unknown')!=='complete')$state='partial';
        elseif($estimated||($recipe['review_status']??'needs_review')!=='ready')$state='estimated';
        $validTotal=in_array($state,['known','estimated'],true);
        $cost=$validTotal?(int)round($known):null;
        return ['status'=>$state,'ingredient_cost'=>$cost,'known_cost_subtotal'=>(int)round($known),
            'sale_price'=>$salePrice,'price_minus_ingredients'=>$cost===null?null:$salePrice-$cost,
            'food_cost_percent'=>$cost===null||$salePrice<=0?null:round($cost*100/$salePrice,2),
            'ingredient_count'=>count($details),'oldest_cost_updated_at'=>$oldest,'components'=>$details];
    }
}
