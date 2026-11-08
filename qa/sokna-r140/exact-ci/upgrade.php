<?php
declare(strict_types=1);

// CI only: disposable MariaDB 11.4, no real café/home data.
// Each phase runs in a new PHP process so R75 and R140 use their OWN Migrations class.
$phase=$argv[1]??'';
$lab=$argv[2]??'';
if(!in_array($phase,['baseline','upgrade','verify'],true) || !is_dir($lab)) exit(64);
$web=$lab.'/'.($phase==='baseline'?'r75-web':'r140-web');
if(!is_file($web.'/src/Core/Migrations.php')) exit(65);
require_once $web.'/src/Core/MigrationExecutionException.php';
require_once $web.'/src/Core/Migrations.php';
$dsn='mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$pdo=new PDO($dsn,'root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if($phase==='baseline') {
    $pdo->exec('DROP DATABASE IF EXISTS sokna_r140_exact');
    $pdo->exec('CREATE DATABASE sokna_r140_exact CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo->exec('USE sokna_r140_exact');
$m=new Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$catalog=$m->catalog();
$head=array_key_last($catalog);
if($phase==='baseline') {
    if($head!=='0076_late_served_accounting')throw new RuntimeException('R75 exact head mismatch');
    // R75 intentionally seeds its 135 canonical menu items before H319/0059.
    // Skipping this ordering causes 0059's real data guard to correctly fail.
    $first=$m->migrate('0059_menu_business_reconciliation_v4');
    require_once $web.'/src/Domain/Sellables/CategoryIconLibrary.php';
    require_once $web.'/src/Domain/Sellables/DefaultContentSeeder.php';
    $icons=new Sokna\\Local\\Domain\\Sellables\\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
    $seeder=new Sokna\\Local\\Domain\\Sellables\\DefaultContentSeeder(
        $pdo,$icons,$web.'/resources/default-content/v1',$lab.'/seed-media');
    $seedResult=$seeder->seed();
    if(($seedResult['seeded']??false)!==true)throw new RuntimeException('R75 canonical menu seed not executed');
    $last=$m->migrate();
    if($m->appliedVersions()!==array_keys($catalog))throw new RuntimeException('R75 ledger not exact');
    echo "PASS R75 canonical menu seed between migration batches".PHP_EOL;
    echo "PASS exact R75 real SQL baseline migrations: ".(count($first)+count($last)).PHP_EOL;
} else {
    if($head!=='0079_cafe_staff_initial_accounts')throw new RuntimeException('R140 exact head mismatch');
    $expected=array_keys($catalog);
    $before=$m->appliedVersions();
    if($phase==='upgrade') {
        if(count($before)!==76)throw new RuntimeException('R75 baseline not exactly 76: '.count($before));
        $result=$m->migrate();
        if(count($result)!==3)throw new RuntimeException('R140 did not apply exactly three migrations');
        echo "PASS exact R140 real 0077/0078/0079 upgrade: ".implode(',',$result).PHP_EOL;
    } else {
        $result=$m->migrate();
        if(count($result)!==0)throw new RuntimeException('R140 was not idempotent');
        echo "PASS exact R140 real MariaDB repeat idempotency".PHP_EOL;
    }
    if($m->appliedVersions()!==$expected)throw new RuntimeException('R140 target ledger unexpected');
    if((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()!==0)
        throw new RuntimeException('R140 stranded migration journal');
    if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_opening_activations'")->fetchColumn()!==1)
        throw new RuntimeException('R140 0077 table missing');
    echo "PASS exact R140 ledger and DDL target".PHP_EOL;
}
