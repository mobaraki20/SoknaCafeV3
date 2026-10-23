<?php
declare(strict_types=1);
namespace Sokna\Shared;

/** Pure Public preview; stock and accounting are changed only by the Local domain. */
final class DailySupply
{
    public const ACTIONS=['supply_need','supply_prepare','supply_return','supply_cancel','supply_purchase_record','supply_purchase_deliver','supply_purchase_cancel','inventory_waste','expense_create'];
    public static function number(mixed $v): float
    {
        $v=strtr(trim((string)$v),['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.']);
        $v=str_replace([',','٬',' ',"\xc2\xa0","\xe2\x80\xaf"],'',$v);
        if(!preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/D',$v)||!is_finite((float)$v)||(float)$v>9007199254740)throw new \DomainException('مقدار عددی معتبر نیست.');
        return (float)$v;
    }
    public static function base(mixed $v,string $unit): int
    {
        $n=self::number($v);if($unit==='count'&&abs($n-round($n))>0.000001)throw new \DomainException('مقدار تعدادی باید عدد صحیح باشد.');
        $q=(int)round($n*($unit==='count'?1:1000));if($q<1)throw new \DomainException('مقدار باید بیشتر از صفر باشد.');return $q;
    }
    public static function group(array $s,string $key): ?array {foreach($s['supply']['groups']??[] as $g)if($g['group_key']===$key)return $g;return null;}
    public static function purchase(array $s,string $id): ?array {foreach($s['supply']['purchases']??[] as $p)if($p['purchase_id']===$id)return $p;return null;}
    public static function item(array $s,int $id): ?array {foreach($s['inventory']['items']??[] as $i)if((int)$i['id']===$id)return $i;return null;}
    public static function key(array $d): string
    {
        if($d['action']==='supply_purchase_record'&&empty($d['group_key']))return 'direct:'.(int)($d['item_id']??0);
        if($d['action']==='inventory_waste')return 'waste:'.(int)($d['item_id']??0);
        if($d['action']==='expense_create')return 'expense';
        if(in_array($d['action'],['supply_purchase_deliver','supply_purchase_cancel'],true))return 'purchase:'.(string)($d['purchase_id']??'');
        if(!empty($d['group_key']))return (string)$d['group_key'];
        $id=(int)($d['inventory_item_id']??$d['item_id']??0);
        return $id>0?'item:'.$id:'free:'.($d['base_unit']??'count').':'.rtrim(strtr(base64_encode(trim((string)($d['free_name']??''))),'+/','-_'),'=');
    }
    public static function describe(array $s,array $d): array
    {
        $g=self::group($s,(string)($d['group_key']??''));$id=(int)($d['inventory_item_id']??$d['item_id']??$g['item_id']??0);
        if($id<1)$id=(int)($d['target_item_id']??0);$item=self::item($s,$id);
        if($id>0&&(!$item||(int)$item['active']!==1))throw new \DomainException('کالای انتخاب‌شده فعال نیست.');
        $unit=(string)($item['base_unit']??$g['base_unit']??$d['base_unit']??'count');
        if(!in_array($unit,['g','ml','count'],true))throw new \DomainException('واحد معتبر نیست.');
        if($g&&$g['base_unit']!==$unit)throw new \DomainException('واحد مقصد با نیاز یکسان نیست.');
        $name=trim((string)($item['name']??$g['name']??$d['free_name']??''));if($name===''||(preg_match_all('/./us',$name)?:strlen($name))>160)throw new \DomainException('نام کالا را وارد کن.');
        if($d['action']==='supply_need')$qty=self::base($d['quantity_major']??'',$unit);
        else {
            $count=self::number($d['unit_count']??'');if($count<=0)throw new \DomainException('مقدار باید بیشتر از صفر باشد.');
            $pu=(int)($d['purchase_unit_id']??0);$u=null;
            if($pu>0){foreach($s['inventory']['purchase_units']??[] as $r)if((int)$r['id']===$pu&&(int)$r['inventory_item_id']===$id&&!empty($r['active'])){$u=$r;break;}if(!$u)throw new \DomainException('واحد خرید فعال نیست.');}
            $qty=$u?((($u['conversion_mode']??'')==='actual_quantity'||($u['review_status']??'')==='needs_review')?self::base($d['actual_major_quantity']??'',$unit):(int)round($count*(int)$u['base_quantity'])):self::base($d['unit_count']??'',$unit);
            if($qty<1||$qty>9007199254740000)throw new \DomainException('مقدار معتبر نیست.');
        }
        return ['item_id'=>$id,'item_name'=>$name,'base_unit'=>$unit,'quantity_base'=>$qty];
    }
    public static function validate(array $s,array $d): void
    {
        $a=(string)($d['action']??'');if(isset($d['delivered_to_cafe'])&&!is_bool($d['delivered_to_cafe']))throw new \DomainException('وضعیت تحویل معتبر نیست.');if(!in_array($a,self::ACTIONS,true))throw new \DomainException('این عملیات برای ثبت معوق فعال نیست.');
        if(empty($s['module_state']['supply_enabled'])&&str_starts_with($a,'supply_'))throw new \DomainException('تأمین غیرفعال است.');
        if(in_array($a,['supply_need','supply_purchase_record','inventory_waste'],true))self::describe($s,$d);
        if($a==='inventory_waste'&&!in_array($d['reason']??'',['expired','damaged','prep_loss','quality','other'],true))throw new \DomainException('دلیل ضایعات را انتخاب کن.');
        if($a==='inventory_waste'&&empty($s['module_state']['inventory_runtime_ready']))throw new \DomainException('انبار هنوز آماده نیست.');
        if($a==='expense_create'){
            $amount=self::number($d['amount']??'');if($amount<1||floor($amount)!==$amount||$amount>9007199254740991)throw new \DomainException('مبلغ هزینه معتبر نیست.');
            $valid=false;foreach($s['expenses']['categories']??[] as $c)if($c['category_key']===($d['category_key']??'')&&!empty($c['active']))$valid=true;if(!$valid)throw new \DomainException('دسته هزینه معتبر نیست.');
        }
        $key=(string)($d['group_key']??'');$g=self::group($s,$key);
        if(in_array($a,['supply_prepare','supply_return','supply_cancel'],true)||($a==='supply_purchase_record'&&$key!=='')){
            if(!$g)throw new \DomainException('نیاز باز پیدا نشد؛ صفحه را تازه کن.');
            $pre=in_array($a,['supply_return','supply_purchase_record'],true);$qty=(int)$g[$pre?'preparing_quantity_base':'uncommitted_quantity_base'];
            $expected=$d[$a==='supply_purchase_record'?'expected_preparing_quantity_base':($pre?'expected_preparing':'expected_uncommitted')]??null;
            if($qty<1||$expected===null||(int)$expected!==$qty)throw new \DomainException('مقدار نیاز تغییر کرده است؛ صفحه را تازه کن.');
            $pending=[];$purchased=0;foreach($s['supply']['purchases']??[] as $p)if(($p['group_key']??'')===$key&&empty($p['delivered_at'])&&empty($p['cancelled_at'])){$pending[]=(string)$p['purchase_id'];$purchased+=(int)$p['quantity_base'];}
            if($a==='supply_return'&&$pending!==[])throw new \DomainException('برای این قلم خرید ثبت شده و منتظر تحویل است.');
            if($a==='supply_purchase_record'){$expectedIds=$d['expected_purchase_ids']??[];if(is_string($expectedIds))$expectedIds=json_decode($expectedIds,true);if(!is_array($expectedIds))throw new \DomainException('نسخه فهرست خرید معتبر نیست.');sort($expectedIds);sort($pending);if($pending!==$expectedIds||$qty<=$purchased)throw new \DomainException('خرید دیگری برای این نیاز ثبت شده است؛ مانده خرید را تازه کن.');}

        }
        if($a==='supply_return'&&!in_array($d['outcome']??'',['returned','unavailable'],true))throw new \DomainException('وضعیت معتبر نیست.');
        if($a==='supply_purchase_record'&&trim((string)($d['total_cost']??''))!==''){
            $cost=self::number($d['total_cost']);if(floor($cost)!==$cost||$cost>9007199254740991)throw new \DomainException('مبلغ باید عدد صحیح معتبر باشد.');
        }
        if($a==='supply_purchase_cancel'){
            $p=self::purchase($s,(string)($d['purchase_id']??''));if(!$p||!empty($p['delivered_at'])||!empty($p['cancelled_at']))throw new \DomainException('این خرید قابل لغو نیست.');
            if(trim((string)($d['reason']??''))==='')throw new \DomainException('دلیل لغو ثبت اشتباه را بنویس.');
        }
        if($a==='supply_purchase_deliver'){
            $p=self::purchase($s,(string)($d['purchase_id']??''));if(!$p||!empty($p['delivered_at'])||!empty($p['cancelled_at']))throw new \DomainException('خرید قبلاً تحویل شده یا پیدا نشد.');
            if(empty($s['module_state']['inventory_runtime_ready']))throw new \DomainException('ابتدا موجودی اولیه انبار را تکمیل کن.');
        }
        if(!empty($d['delivered_to_cafe'])&&empty($s['module_state']['inventory_runtime_ready']))throw new \DomainException('خرید را بدون تحویل ثبت کن؛ انبار هنوز آماده نیست.');
    }
    public static function apply(array $s,array $d,string $id,string $actor,string $at): array
    {
        $a=$d['action'];$key=self::key($d);$groups=$s['supply']['groups']??[];
        if($a==='supply_need'){
            $v=self::describe($s,$d);$found=false;foreach($groups as &$g)if($g['group_key']===$key){$found=true;$g['uncommitted_quantity_base']+=$v['quantity_base'];$g['remaining_quantity_base']+=$v['quantity_base'];$dep=$d['department'];$g['departments']=array_values(array_unique([...$g['departments'],$dep]));$g['department_quantities'][$dep]=($g['department_quantities'][$dep]??0)+$v['quantity_base'];}unset($g);
            if(!$found)$groups[]=['group_key'=>$key,'item_id'=>$v['item_id'],'name'=>$v['item_name'],'base_unit'=>$v['base_unit'],'quantity_base'=>(int)(self::item($s,$v['item_id'])['quantity_base']??0),'inventory_active'=>$v['item_id']>0?1:null,'uncommitted_quantity_base'=>$v['quantity_base'],'preparing_quantity_base'=>0,'remaining_quantity_base'=>$v['quantity_base'],'departments'=>[$d['department']],'department_quantities'=>[$d['department']=>$v['quantity_base']],'preparing_users'=>[],'need_ids'=>[]];
        }
        foreach($groups as &$g)if($g['group_key']===$key){
            if($a==='supply_prepare'){$g['preparing_quantity_base']+=$g['uncommitted_quantity_base'];$g['uncommitted_quantity_base']=0;$g['preparing_users']=array_values(array_unique([...$g['preparing_users'],$actor]));}
            if($a==='supply_return'){$g['uncommitted_quantity_base']+=$g['preparing_quantity_base'];$g['preparing_quantity_base']=0;$g['preparing_users']=[];}
            if($a==='supply_cancel'){$g['department_breakdown_pending']=true;$g['remaining_quantity_base']-=$g['uncommitted_quantity_base'];$g['uncommitted_quantity_base']=0;}
        }unset($g);$s['supply']['groups']=$groups;
        if($a==='supply_purchase_record'){$v=self::describe($s,$d);$s['supply']['purchases'][]=$v+['purchase_id'=>$id,'group_key'=>$d['group_key']??null,'supplier'=>$d['supplier']??'','note'=>$d['note']??'','total_cost'=>trim((string)($d['total_cost']??''))===''?null:(int)self::number($d['total_cost']),'actor_name'=>$actor,'purchased_at'=>$at,'delivered_at'=>null];if(!empty($d['delivered_to_cafe']))$s=self::deliverPreview($s,$id,$at);}
        if($a==='supply_purchase_cancel')foreach($s['supply']['purchases'] as &$p){if($p['purchase_id']===$d['purchase_id']){$p['cancelled_at']=$at;$p['cancellation_reason']=$d['reason'];}}unset($p);
        if($a==='supply_purchase_deliver')$s=self::deliverPreview($s,(string)$d['purchase_id'],$at);
        if($a==='expense_create')array_unshift($s['expenses']['recent'],['id'=>'pending:'.$id,'amount'=>(int)self::number($d['amount']),'category_name'=>$d['category_key'],'description'=>$d['description']??'','occurred_at'=>$at,'period_title'=>'منتظر همگام‌سازی','status'=>'pending']);
        return $s;
    }
    private static function deliverPreview(array $s,string $id,string $at): array
    {
        foreach($s['supply']['purchases']??[] as $i=>$p)if($p['purchase_id']===$id){$s['supply']['purchases'][$i]['delivered_at']=$at;foreach($s['supply']['groups']??[] as $j=>$g)if($g['group_key']===($p['group_key']??'')){$allocated=min((int)$g['preparing_quantity_base'],(int)$p['quantity_base']);$s['supply']['groups'][$j]['department_breakdown_pending']=true;$s['supply']['groups'][$j]['preparing_quantity_base']-=$allocated;$s['supply']['groups'][$j]['remaining_quantity_base']-=$allocated;}}
        // Do not present projected stock as authoritative stock before Local accepts the movement.
        return $s;
    }
}
