<?php
declare(strict_types=1);
// R149B exact payload only. This script REFUSES anything but the isolated CI runner MariaDB.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R149B_DISPOSABLE') !== 'YES') {
    fwrite(STDERR, "ISOLATED_GITHUB_CI_ONLY\n"); exit(87);
}
$phase=$argv[1]??'';
$lab=$argv[2]??'';
if (!in_array($phase,['upgrade','verify'],true) || !is_dir($lab)) exit(64);
$web=$lab.'/r149b-web';
require_once $web.'/src/Core/MigrationExecutionException.php';
require_once $web.'/src/Core/Migrations.php';
$port=getenv('SOKNA_R140_PORT')?:'3306';
$pass=getenv('SOKNA_R140_PASSWORD');if($pass===false)$pass='ci-root-only';
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=sokna_r140_exact;charset=utf8mb4', 'root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$dataDir=$lab.'/r149b-data';
if(!is_dir($dataDir.'/guest-media')&&!mkdir($dataDir.'/guest-media',0700,true))throw new RuntimeException('Failed disposable media vault');
$cfg=['app'=>['data_dir'=>$dataDir],'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>'sokna_r140_exact','user'=>'root','pass'=>$pass]];
file_put_contents($lab.'/config.php','<?php return '.var_export($cfg,true).';');
$m=new Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$catalog=$m->catalog();
if(array_key_last($catalog)!=='0080_tea_herbal_legacy_family_repair')throw new RuntimeException('Incorrect exact package head');
$before=$m->appliedVersions();
if($phase==='upgrade' && count($before)!==79)throw new RuntimeException('Expected R140 source schema 79 actual '.count($before));
if($phase==='verify' && count($before)!==80)throw new RuntimeException('Expected R149B target schema 80 actual '.count($before));
$applied=$m->migrate();
if($phase==='upgrade' && $applied!==['0080_tea_herbal_legacy_family_repair'])throw new RuntimeException('Unexpected new migration set '.json_encode($applied));
if($phase==='verify' && count($applied)!==0)throw new RuntimeException('R149B migration repeated unexpectedly');
if(count($m->appliedVersions())!==80)throw new RuntimeException('Exactly 80 migrations not recorded');
if((int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state <> 'applied'")->fetchColumn()!==0)throw new RuntimeException('Unresolved statement ledger');
// Canonical codes are immutable: both legacy items must still exist and share the new Large variant family.
$families=[
 ['03','801','802',140000],['04','803','804',190000],['05','805','806',155000],
 ['06','807','808',170000],['07','701','713',190000],['08','703','712',190000],
 ['09','705','711',180000],['10','707','708',180000],['11','709','710',195000],
];
$covered=0;
foreach($families as [$part,$single,$two,$singlePrice]){
    $large='szlarge-'.substr(hash('sha256','h297-'.$part),0,20);
    $stmt=$pdo->prepare('SELECT f.family_key,m.label,i.item_code,i.price,i.name FROM item_variant_families f JOIN item_variant_members m ON m.family_id=f.id JOIN items i ON i.id=m.item_id WHERE i.item_code IN(?,?,?) ORDER BY m.sort_order');
    $stmt->execute([$single,$two,$large]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==3)throw new RuntimeException('Not 3 variants '.$part.' actual='.count($rows));
    $names=[];$codes=[];$price=[];$keys=[];
    foreach($rows as $row){$code=(string)$row['item_code'];$codes[$code]=true;$price[$code]=(int)$row['price'];$names[$code]=(string)$row['name'];$keys[$row['family_key']]=true;}
    if(count($codes)!==3 || count($keys)!==1)throw new RuntimeException('Family mismatch '.$part);
    $expectedTwo=(int)(round($singlePrice*1.7/5000)*5000);
    $expectedLarge=(int)(round($singlePrice*2.3/5000)*5000);
    if($price[$single]!==$singlePrice || $price[$two]!==$expectedTwo || $price[$large]!==$expectedLarge)throw new RuntimeException('Exact prices mismatch '.$part);
    if(!str_contains($names[$single],'تک‌نفره') || !str_contains($names[$two],'دو نفره') || !str_contains($names[$large],'بزرگ'))throw new RuntimeException('Size title mismatch '.$part);
    $linked=$pdo->prepare('SELECT COUNT(*) FROM menu_items mi JOIN items i ON i.id=mi.item_id WHERE i.item_code=?');$linked->execute([$large]);
    if((int)$linked->fetchColumn()<1)throw new RuntimeException('Large missing menu relationship '.$part);
    $covered++;
}
if($covered!==9)throw new RuntimeException('Incomplete family test');
echo "PASS EXACT R149B UPGRADE {$phase} 9 families, 27 exact variant prices/names, menu links, 80 migrations\n";