<?php
declare(strict_types=1);
$root=getenv('SOKNA_QA_ROOT')?:dirname(__DIR__).'/source';
require_once $root.'/src/Domain/Tax/TaxException.php';
require_once $root.'/src/Domain/Tax/TaxService.php';
require_once $root.'/src/Domain/Finance/SettlementException.php';
require_once $root.'/src/Domain/Finance/SettlementService.php';
use Sokna\Local\Domain\Tax\TaxService;
use Sokna\Local\Domain\Finance\SettlementService;
use Sokna\Local\Domain\Finance\SettlementException;
$svc=(new ReflectionClass(SettlementService::class))->newInstanceWithoutConstructor();
$method=(new ReflectionClass(SettlementService::class))->getMethod('remainingDiscountPlanTx');
$raw=[['order_item_id'=>101,'order_id'=>1,'quantity'=>2,'unit_price'=>100,'tax_policy_snapshot'=>'taxable','tax_rate_bps_snapshot'=>1000,'accounting_origin'=>'regular'],['order_item_id'=>102,'order_id'=>1,'quantity'=>3,'unit_price'=>80,'tax_policy_snapshot'=>'taxable','tax_rate_bps_snapshot'=>1000,'accounting_origin'=>'regular']];
$original=TaxService::calculateAccountLinesByOrigin($raw,'percent',10);
$paid=['gross'=>100,'discount'=>10,'taxable'=>90,'tax'=>9,'total'=>99];
$lines=[];
foreach($original['lines'] as $line){$id=(int)$line['order_item_id'];$paidQty=$id===101?1:0;$line['id']=$id;$line['paid_quantity']=$paidQty;$line['remaining_quantity']=$line['quantity']-$paidQty;$line['paid_discount_amount']=$id===101?$paid['discount']:0;$line['paid_tax_amount']=$id===101?$paid['tax']:0;$line['paid_taxable_amount']=$id===101?$paid['taxable']:0;$lines[]=$line;}
$account=['session'=>['id'=>1],'receipt_count'=>1,'discount'=>$original['discount'],'remaining_subtotal'=>$original['subtotal']-$paid['gross'],'remaining_discount'=>$original['discount']-$paid['discount'],'items'=>$lines];
$tests=[];
$plan=$method->invoke($svc,$account,'percent',10);
$expectedBase=(int)$account['remaining_subtotal']-(int)$account['remaining_discount'];
assert($expectedBase===306);
assert($plan['preview']['subtotal']===$expectedBase);
assert($plan['preview']['discount_amount']===31);
assert(array_sum(array_column($plan['allocations'],'discount_amount'))===31);
$tests['second_discount_applies_to_unpaid_net']=true;
$planFixed=$method->invoke($svc,$account,'fixed',25);
assert($planFixed['preview']['subtotal']===306&&$planFixed['preview']['discount_amount']===25);
$tests['fixed_only_to_unpaid_net']=true;
foreach($planFixed['allocations'] as $alloc){$id=$alloc['order_item_id'];$line=array_values(array_filter($lines,fn($l)=>$l['id']===$id))[0];assert($alloc['original_discount_snapshot']===$line['invoice_discount_amount']);assert($alloc['invoice_tax_after_grant']>=$line['paid_tax_amount']);}
$tests['paid_tax_and_discount_snapshots_retained']=true;
$planFull=$method->invoke($svc,$account,'percent',100);
assert($planFull['preview']['discount_amount']===306&&$planFull['preview']['total']===0&&$planFull['preview']['tax']===0);
$tests['full_discount_on_unpaid_net']=true;
try{$method->invoke($svc,$account,'fixed',307);throw new RuntimeException('Over discount permitted');}catch(SettlementException $ex){assert($ex->errorCode==='invalid_discount');}
$tests['over_discount_refused']=true;
$already=$account;$already['session']['remaining_discount_grant']=['id'=>9,'amount'=>4];
try{$method->invoke($svc,$already,'percent',5);throw new RuntimeException('Multiple remaining grants permitted');}catch(SettlementException $ex){assert($ex->errorCode==='remaining_discount_exists');}
$tests['third_discount_still_requires_revision_ledger']=true;
foreach($planFull['allocations'] as $a){assert($a['invoice_tax_after_grant']>=(int)($a['order_item_id']===101?$paid['tax']:0));}
$tests['full_discount_keeps_paid_tax']=true;
$plain=$account;$plain['discount']=0;$plain['remaining_discount']=0;foreach($plain['items'] as &$line){$line['invoice_discount_amount']=0;$line['paid_discount_amount']=0;}unset($line);
$planPlain=$method->invoke($svc,$plain,'percent',10);
assert($planPlain['preview']['subtotal']===340&&$planPlain['preview']['discount_amount']===34);
$tests['legacy_no_prior_discount_kept']=true;
echo json_encode(['status'=>'PASS','tests'=>$tests,'preview'=>['original_discount'=>$original['discount'],'paid_total'=>$paid['total'],'additional_fixed'=>$planFixed['preview'],'additional_100'=>$planFull['preview']]],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),'\n';
