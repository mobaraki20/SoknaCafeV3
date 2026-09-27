<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\StaffConsumption\StaffBenefitCalculator;

function f12_fail(string $m): never { fwrite(STDERR,$m.PHP_EOL); exit(1); }
function f12_assert(bool $c,string $m): void { if(!$c)f12_fail($m); }

$calc=new StaffBenefitCalculator();
$lines=[
    ['item_id'=>11,'category_id'=>2,'item_name'=>'Tea','unit_price'=>100000,'quantity'=>2],
    ['item_id'=>12,'category_id'=>3,'item_name'=>'Food','unit_price'=>300000,'quantity'=>1],
];
$default=['id'=>1,'policy_key'=>'default','active'=>1];
$base=[
    'evaluation_at'=>'2026-09-27 12:00:00','personnel_id'=>7,
    'profile_present'=>false,'profile'=>null,'assigned_policy'=>null,'default_policy'=>$default,
    'stored_overrides'=>[],'runtime_override'=>null,
    'policy_rules'=>[
        ['id'=>1,'active'=>1,'scope_type'=>'all','scope_id'=>null,'benefit_type'=>'percent','percent_bps'=>1000,'fixed_amount'=>null,'priority'=>100],
        ['id'=>2,'active'=>1,'scope_type'=>'category','scope_id'=>2,'benefit_type'=>'free','percent_bps'=>null,'fixed_amount'=>null,'priority'=>100],
        ['id'=>3,'active'=>1,'scope_type'=>'item','scope_id'=>11,'benefit_type'=>'fixed','percent_bps'=>null,'fixed_amount'=>25000,'priority'=>100],
    ],
];
$r=$calc->calculate($lines,$base);
f12_assert($r['profile_resolution']==='default_policy','default policy not selected');
f12_assert($r['lines'][0]['benefit_resolution']['source_id']===3,'item rule must outrank category/all rules');
f12_assert($r['lines'][0]['benefit_amount']===50000,'fixed benefit must be per unit');
f12_assert($r['lines'][1]['benefit_amount']===30000,'all percent rule calculation incorrect');
f12_assert($r['menu_value_amount']===500000&&$r['benefit_amount']===80000&&$r['payable_before_discount']===420000,'document totals incorrect');

$profileNone=$base;
$profileNone['profile_present']=true;$profileNone['profile']=['id'=>90,'policy_id'=>null,'active'=>1];$profileNone['default_policy']=$default;
$rNone=$calc->calculate($lines,$profileNone);
f12_assert($rNone['profile_resolution']==='profile_explicit_no_benefit','profile without policy must suppress default policy');
f12_assert($rNone['benefit_amount']===0,'explicit no-benefit profile granted benefit');

$assigned=$base;
$assigned['profile_present']=true;$assigned['profile']=['id'=>91,'policy_id'=>5,'active'=>1];$assigned['assigned_policy']=['id'=>5,'policy_key'=>'assigned','active'=>1];$assigned['default_policy']=null;
$assigned['policy_rules']=[['id'=>9,'active'=>1,'scope_type'=>'all','scope_id'=>null,'benefit_type'=>'percent','percent_bps'=>5000,'fixed_amount'=>null,'priority'=>100]];
$assigned['stored_overrides']=[['id'=>50,'active'=>1,'scope_type'=>'item','scope_id'=>11,'benefit_type'=>'none','percent_bps'=>null,'fixed_amount'=>null,'valid_from'=>'2026-09-01 00:00:00','reason'=>'manager exception']];
$rStored=$calc->calculate($lines,$assigned);
f12_assert($rStored['lines'][0]['benefit_resolution']['source']==='personnel_override'&&$rStored['lines'][0]['benefit_amount']===0,'personnel override must outrank policy');
f12_assert($rStored['lines'][1]['benefit_amount']===150000,'assigned policy rule incorrect');

$runtime=$assigned;
$runtime['runtime_override']=['active'=>1,'scope_type'=>'all','scope_id'=>null,'benefit_type'=>'percent','percent_bps'=>2500,'fixed_amount'=>null,'reason'=>'shift manager','actor_user_id'=>3];
$rRuntime=$calc->calculate($lines,$runtime);
f12_assert($rRuntime['lines'][0]['benefit_resolution']['source']==='runtime_override','runtime override must outrank personnel override');
f12_assert($rRuntime['benefit_amount']===125000,'runtime override totals incorrect');
f12_assert($rRuntime['lines'][0]['benefit_resolution']['actor_user_id']===3,'runtime override actor snapshot missing');

$rRuntime2=$calc->calculate($lines,$runtime);
f12_assert($rRuntime['snapshot_sha256']===$rRuntime2['snapshot_sha256'],'same inputs must produce same snapshot hash');
f12_assert(!array_key_exists('waiver_amount',$rRuntime),'waiver must not be part of pre-post benefit calculation');

fwrite(STDOUT,"F1.2 Staff Benefit calculation self-test: OK\n");
