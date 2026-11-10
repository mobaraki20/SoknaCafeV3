<?php
declare(strict_types=1);
// NO production credentials, data or I/O: exact R149B release migrator executed
// only in a disposable GitHub Actions MariaDB. R75 and R112 have the exact
// same 76-file SQL history; R112's actual web archive is Library-only.
if(getenv('GITHUB_ACTIONS')!=='true' || getenv('SOKNA_R149B_DISPOSABLE')!=='YES'
   || getenv('SOKNA_R112_DB_MATRIX_ONLY')!=='YES') {
    fwrite(STDERR,"QA_DISPOSABLE_MARIADB_ONLY\n");exit(87);
}
$mode=$argv[1]??'';
$lab=$argv[2]??'';
if(!in_array($mode,['inspect','normalize','upgrade','verify','break','expect-fail','verify-broken','verify-restored'],true)
   || !is_dir($lab))exit(64);
$port=$mode==='verify-restored'?'3307':'3306';
$pass=getenv('SOKNA_R140_PASSWORD')?:'ci-root-only';
$db=new PDO("mysql:host=127.0.0.1;port=$port;dbname=sokna_r140_exact;charset=utf8mb4",'root',$pass,
   [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function must(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('R112_DB_MATRIX_'.$why);}
function val(PDO $p,string $sql):int{return (int)$p->query($sql)->fetchColumn();}
function head(PDO $p):array{return $p->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);}
function baseline(PDO $p):void{
  must(count(head($p))===76,'BASELINE_76_NOT_RECONSTRUCTED');
  must(val($p,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='items'")===1,'ITEMS_MISSING');
}
function legacy(PDO $p):array {
  $rows=$p->query("SELECT f.id,f.family_key,COUNT(m.item_id) AS members
    FROM item_variant_families f LEFT JOIN item_variant_members m ON m.family_id=f.id
    WHERE f.family_key IN ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')
    GROUP BY f.id,f.family_key ORDER BY f.family_key")->fetchAll(PDO::FETCH_ASSOC);
  return $rows;
}
$web=$lab.'/r149b-web';
if(!is_file($web.'/src/Core/Migrations.php'))throw new RuntimeException('R149B_EXACT_WEB_NOT_EXTRACTED');
require_once $web.'/src/Core/MigrationExecutionException.php';
require_once $web.'/src/Core/Migrations.php';
function migrate(PDO $db,string $web):array{
  $m=new Sokna\Local\Core\Migrations($db,$web.'/database/migrations');
  must(count($m->catalog())===80,'TARGET_CATALOG_NOT_80');
  return $m->migrate();
}
if($mode==='inspect'){
 baseline($db);
 $l=legacy($db);
 echo 'QA_SOURCE_LEGACY_FAMILY_SNAPSHOT='.json_encode($l,JSON_UNESCAPED_UNICODE).PHP_EOL;
 must(val($db,'SELECT COUNT(*) FROM items')>=100,'R112_REPRESENTATIVE_SOURCE_MENU_TOO_SMALL');
 echo "PASS QA_R112_EQUIVALENT_76_SQL_BASELINE_AND_REAL_MENU_PRESENT\n";exit;
}
$pair=[
 ['03','801','802'],['04','803','804'],['05','805','806'],['06','807','808'],
 ['07','701','713'],['08','703','712'],['09','705','711'],['10','707','708'],['11','709','710']
];
if($mode==='normalize'){
 baseline($db);
 // Reconstruct exactly the missing/legacy nine family state on a disposable
 // full 0076 schema, instead of pretending the R75 canonical seed itself
 // reproduces an actual Café database. Never run this against customer data.
 $db->beginTransaction();
 try{
   $get=$db->prepare('SELECT id,category_id,name,price,lifecycle_status FROM items WHERE item_code=?');
   $delCanonical=$db->prepare('DELETE FROM item_variant_families WHERE family_key=?');
   $delMember=$db->prepare('DELETE FROM item_variant_members WHERE item_id=?');
   $getFamily=$db->prepare('SELECT id FROM item_variant_families WHERE family_key=?');
   $create=$db->prepare('INSERT INTO item_variant_families(family_key,category_id,name,sort_order,featured,active)
     VALUES(?,?,?,?,0,1)');
   $add=$db->prepare('INSERT INTO item_variant_members(family_id,item_id,label,sort_order) VALUES(?,?,?,?)');
   foreach($pair as [$suffix,$single,$two]){
     $fam='family-'.$suffix;
     $a=$get->execute([$single]) ? $get->fetch(PDO::FETCH_ASSOC) : false;
     $b=$get->execute([$two]) ? $get->fetch(PDO::FETCH_ASSOC) : false;
     must((bool)$a && (bool)$b,'CANONICAL_ITEM_CODE_MISSING_'.$fam);
     must((int)$a['category_id']===(int)$b['category_id'] && (int)$a['price']>0,'CANONICAL_ITEM_INVALID_'.$fam);
     $delCanonical->execute(['h297-'.$suffix]);
     $getFamily->execute([$fam]);$f=$getFamily->fetchColumn();
     if(!$f){$create->execute([$fam,$a['category_id'],$a['name'],(int)$suffix*10]);$f=$db->lastInsertId();}
     // Existing R75 canonical membership is not the historical café pattern.
     $delMember->execute([$a['id']]);$delMember->execute([$b['id']]);
     $add->execute([$f,$a['id'],'تک نفره',10]);
     $add->execute([$f,$b['id'],'دو نفره',20]);
   }
   $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 // A disposable business sentinel, never any real customer records.
 $db->exec("CREATE TABLE IF NOT EXISTS ci_r112_business_sentinel(id INT PRIMARY KEY,qty INT NOT NULL,order_note VARCHAR(128) NOT NULL) ENGINE=InnoDB");
 $db->exec("INSERT INTO ci_r112_business_sentinel(id,qty,order_note) VALUES(1,112,'order-snapshot-preserved')
    ON DUPLICATE KEY UPDATE qty=112,order_note='order-snapshot-preserved'");
 must(val($db,"SELECT COUNT(*) FROM item_variant_families WHERE family_key IN
  ('family-03','family-04','family-05','family-06','family-07','family-08','family-09','family-10','family-11')")===9,'LEGACY_NINE_COUNT');
 echo "PASS QA_R112_NINE_LEGACY_FAMILIES_ON_DISPOSABLE_76_SQL_BASELINE\n";exit;
}
if($mode==='break'){
 baseline($db);
 $db->exec("UPDATE item_variant_members m JOIN item_variant_families f ON f.id=m.family_id
     JOIN items i ON i.id=m.item_id SET m.label='MALFORMED_LEGACY' WHERE f.family_key='family-03' AND i.item_code='801'");
 must(val($db,"SELECT COUNT(*) FROM item_variant_members m JOIN item_variant_families f ON f.id=m.family_id
     WHERE f.family_key='family-03' AND m.label='MALFORMED_LEGACY'")===1,'FAULT_NOT_INJECTED');
 echo "PASS QA_DISPOSABLE_LEGACY_FAMILY_FAULT_INJECTED\n";exit;
}
if($mode==='upgrade'){
 baseline($db);
 $applied=migrate($db,$web);
 must($applied===['0077_incremental_opening_inventory','0078_tea_herbal_three_sizes',
   '0079_cafe_staff_initial_accounts','0080_tea_herbal_legacy_family_repair'],'EXPECTED_4_MIGRATIONS_'.json_encode($applied));
 echo "PASS QA_R112_REPRESENTATIVE_0077_TO_0080_MIGRATED\n";
}
if($mode==='expect-fail'){
 baseline($db);
 $failed=false;
 try{migrate($db,$web);}catch(Throwable $e){$failed=true;echo "QA_EXPECTED_MIGRATION_EXCEPTION_TYPE=".get_class($e).PHP_EOL;}
 must($failed,'MALFORMED_FAMILY_WAS_ACCEPTED');
 must(count(head($db))<80,'MALFORMED_FAMILY_MARKED_COMMITTED');
 must(val($db,"SELECT qty FROM ci_r112_business_sentinel WHERE id=1")===112,'SENTINEL_AFTER_FAILURE_LOST');
 echo "PASS QA_MALFORMED_R112_ABORTS_INSTEAD_OF_UNQUALIFIED_COMMIT\n";
}
if($mode==='verify'||$mode==='verify-broken'||$mode==='verify-restored'){
 $restored=$mode==='verify-restored';
 $broken=$mode==='verify-broken'||$restored;
 must(count(head($db))===($broken?76:80),'LEDGER_UNEXPECTED_'.count(head($db)));
 must(val($db,"SELECT qty FROM ci_r112_business_sentinel WHERE id=1")===112,'BUSINESS_QUANTITY_LOST');
 $note=$db->query("SELECT order_note FROM ci_r112_business_sentinel WHERE id=1")->fetchColumn();
 must($note==='order-snapshot-preserved','BUSINESS_ORDER_NOTE_CHANGED');
 if($broken){
   must(val($db,"SELECT COUNT(*) FROM item_variant_members m JOIN item_variant_families f ON f.id=m.family_id
     WHERE f.family_key='family-03' AND m.label='MALFORMED_LEGACY'")===1,'MALFORMED_SOURCE_NOT_PRESERVED');
   must(val($db,"SELECT COUNT(*) FROM items WHERE item_code LIKE 'szlarge-%'")===0,'PARTIAL_LARGE_ITEM_IN_RECOVERED_SOURCE');
 } else {
   must(val($db,"SELECT COUNT(*) FROM item_variant_families WHERE family_key BETWEEN 'family-03' AND 'family-11'")===9,'LEGACY_FAMILY_KEYS_DROPPED');
   must(val($db,"SELECT COUNT(*) FROM items WHERE item_code LIKE 'szlarge-%'")>=9,'NINE_LARGE_ITEMS_NOT_CREATED');
   $repeat=migrate($db,$web);
   must(count($repeat)===0,'TARGET_MIGRATION_NOT_IDEMPOTENT');
 }
 echo 'PASS QA_R112_'.($restored?'ISOLATED_BACKUP_RESTORE_LEDGER_76':'BUSINESS_SNAPSHOT_AND_LEDGER_'.($broken?'BROKEN':'80')).PHP_EOL;
}
