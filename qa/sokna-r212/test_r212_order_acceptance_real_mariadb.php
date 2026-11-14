<?php
declare(strict_types=1);
// Real MariaDB, isolated QA runner; never targets an installed cafe database.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R212_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('CI_DISPOSABLE_ONLY');
$web=(string)getenv('SOKNA_R212_WEB');$root=realpath((string)getenv('RUNNER_TEMP'));
if(!$root||!is_file($web.'/bootstrap.php')||!str_starts_with((string)realpath($web),$root.DIRECTORY_SEPARATOR))throw new RuntimeException('R212_REFUSE_NON_TEMP_APP');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r212_disposable;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->beginTransaction();
try {
    $svc=(new ReflectionClass(\Sokna\Local\Domain\Orders\GuestOrderService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(\Sokna\Local\Domain\Orders\GuestOrderService::class,'pdo'))->setValue($svc,$pdo);
    $check=new ReflectionMethod(\Sokna\Local\Domain\Orders\GuestOrderService::class,'assertStationsAvailable');
    $settings=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $change=function(bool $c,bool $bar,bool $kitchen)use($settings):void{foreach(['cafe'=>$c,'bar'=>$bar,'kitchen'=>$kitchen] as $k=>$v)$settings->execute(['orders_accepting.'.$k,$v?'1':'0']);};
    $standaloneBar=['item_name'=>'R212 tea','preparation_station'=>'hot_bar','bundle_components'=>[]];
    $standaloneKitchen=['item_name'=>'R212 cake','preparation_station'=>'kitchen','bundle_components'=>[]];
    $bundle=['item_name'=>'R212 tea+cake bundle','preparation_station'=>'none','bundle_components'=>[['preparation_station'=>'hot_bar'],['preparation_station'=>'kitchen']]];
    $checks=0;
    $run=function(string $label,array $line,?string $blocked)use($check,$svc,&$checks):void{
        $actual=null;
        try{$check->invoke($svc,[$line]);}
        catch(\Sokna\Local\Domain\Orders\GuestOrderException $e){if($e->errorCode!=='service_unavailable')throw $e;$actual=(string)($e->details['scope']??'');}
        if($actual!==$blocked)throw new RuntimeException('FAIL_R212_'.$label.'_EXPECTED_'.(string)$blocked.'_ACTUAL_'.(string)$actual);
        echo 'PASS_R212_REAL_DB_'.$label."\n";++$checks;
    };
    $change(true,true,true);$run('ALL_OPEN_BAR_ACCEPTED',$standaloneBar,null);$run('ALL_OPEN_BUNDLE_ACCEPTED',$bundle,null);
    $change(true,false,true);$run('BAR_CLOSED_BAR_REJECTED',$standaloneBar,'bar');$run('BAR_CLOSED_BUNDLE_REJECTED',$bundle,'bar');
    $change(true,true,false);$run('KITCHEN_CLOSED_KITCHEN_REJECTED',$standaloneKitchen,'kitchen');$run('KITCHEN_CLOSED_BUNDLE_REJECTED',$bundle,'kitchen');
    $change(true,false,false);$run('BOTH_CLOSED_BUNDLE_REJECTED',$bundle,'bar');
    // The full submit entrypoint checks cafe before table lookup. Use a valid
    // schema-independent table token to prove that guard on an actual SQL row.
    $change(false,true,true);
    $ctx=['table_token'=>'R212-TEST-TOKEN','session_token'=>'','device_token'=>'r212-unit','client_token'=>'r212-client-token-12345678','items'=>[]];
    try{$svc->submitTx($ctx);throw new RuntimeException('FAIL_R212_CAFE_PAUSED_BYPASS');}
    catch(\Sokna\Local\Domain\Orders\GuestOrderException $e){if($e->errorCode!=='ordering_paused')throw $e;echo "PASS_R212_REAL_DB_SUBMIT_CAFE_PAUSED_REJECTED\n";++$checks;}
    // rollback keeps ephemeral DB unchanged for this check.
    $pdo->rollBack();
    echo 'PASS_R212_REAL_DB_ACCEPTANCE_GUARDS_'. $checks."_CASES\n";
} catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
