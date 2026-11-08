<?php
declare(strict_types=1);
/** Isolated integration test; never connect to an installed cafe database. */
if (getenv('GITHUB_ACTIONS')!=='true' || getenv('SOKNA_R148_DISPOSABLE')!=='1') {
    fwrite(STDERR, "DISPOSABLE_CI_ONLY\n"); exit(65);
}
require __DIR__.'/../../apps/local-web/src/Core/Migrations.php';
use Sokna\Local\Core\Migrations;

$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','phase1-ci-root',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db='sokna_r148_disposable_ci';
$pdo->exec("DROP DATABASE IF EXISTS `$db`");
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$db`");
$path=__DIR__.'/../../apps/local-web/database/migrations/0080_tea_herbal_legacy_family_repair.sql';
$sql=(string)file_get_contents($path);
$statements=Migrations::splitStatements($sql);
function ok(bool $condition,string $msg):void{if(!$condition)throw new RuntimeException('FAIL '.$msg); echo "PASS $msg\n";}
function scalar(PDO $pdo,string $sql):int{return (int)$pdo->query($sql)->fetchColumn();}
function run(PDO $pdo,array $statements):void{
    $pdo->beginTransaction();
    try{
        foreach($statements as $statement){
            $stmt=$pdo->prepare($statement);$stmt->execute();
            do {while($stmt->fetch(PDO::FETCH_NUM)!==false){}} while ($stmt->nextRowset());
            $stmt->closeCursor();
        }
        $pdo->commit();
    }
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function fixture(PDO $pdo,array $families):void{
    foreach(['schema_migration_statements','schema_migrations','guest_media_references','menu_items','item_variant_members','item_variant_families','audit_log','items','categories','order_items'] as $t){
        try{$pdo->exec("DROP TABLE IF EXISTS `$t`");}catch(Throwable){}
    }
    $ddl=[
        'CREATE TABLE categories(id INT UNSIGNED PRIMARY KEY,category_key VARCHAR(80) UNIQUE NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_code VARCHAR(80) NOT NULL UNIQUE,category_id INT UNSIGNED NOT NULL,name VARCHAR(160) NOT NULL,description TEXT NULL,price BIGINT NOT NULL,image_path VARCHAR(255),available TINYINT NOT NULL DEFAULT 1,active TINYINT NOT NULL DEFAULT 1,lifecycle_status VARCHAR(20) NOT NULL DEFAULT "active",guest_visible TINYINT NOT NULL DEFAULT 1,staff_visible TINYINT NOT NULL DEFAULT 1,featured TINYINT NOT NULL DEFAULT 0,staff_only TINYINT NOT NULL DEFAULT 0,sellable_kind VARCHAR(30) NOT NULL DEFAULT "menu_item",takeaway_allowed TINYINT NOT NULL DEFAULT 1,preparation_station VARCHAR(30) NOT NULL DEFAULT "hot_bar",sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB',
        'CREATE TABLE item_variant_families(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,family_key VARCHAR(80) NOT NULL UNIQUE,category_id INT UNSIGNED NOT NULL,name VARCHAR(160) NOT NULL,active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE item_variant_members(family_id INT UNSIGNED NOT NULL,item_id INT UNSIGNED NOT NULL,label VARCHAR(80) NOT NULL,sort_order INT NOT NULL,PRIMARY KEY(family_id,item_id),UNIQUE KEY uq_member_item(item_id)) ENGINE=InnoDB',
        'CREATE TABLE menu_items(menu_id INT UNSIGNED NOT NULL,item_id INT UNSIGNED NOT NULL,PRIMARY KEY(menu_id,item_id)) ENGINE=InnoDB',
        'CREATE TABLE guest_media_references(media_id INT UNSIGNED NOT NULL,owner_type VARCHAR(20) NOT NULL,owner_id VARCHAR(40) NOT NULL,slot_key VARCHAR(20) NOT NULL,PRIMARY KEY(media_id,owner_type,owner_id,slot_key)) ENGINE=InnoDB',
        'CREATE TABLE audit_log(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_user_id INT NULL,actor_display_name_snapshot VARCHAR(100) NULL,action VARCHAR(120) NOT NULL,entity_type VARCHAR(40) NOT NULL,entity_id VARCHAR(80) NOT NULL,details_json JSON NULL) ENGINE=InnoDB',
        'CREATE TABLE order_items(id BIGINT UNSIGNED PRIMARY KEY,item_name_snapshot VARCHAR(160),unit_price_snapshot BIGINT) ENGINE=InnoDB'
    ];
    foreach($ddl as $query)$pdo->exec($query);
    $pdo->exec("INSERT INTO categories VALUES(1,'tea-herbal'),(2,'other')");
    $pdo->exec("INSERT INTO order_items VALUES(1,'گذشته',160000)");
    $pairs=[['03','801','802',140000,'چای ساده'],['04','803','804',190000,'چای مراکش'],['05','805','806',155000,'چای هل و دارچین'],['06','807','808',170000,'چای زعفران'],['07','701','713',190000,'دمنوش فرش قرمز'],['08','703','712',190000,'دمنوش آرام دل'],['09','705','711',180000,'دمنوش آویشن'],['10','707','708',180000,'دمنوش گل نوش'],['11','709','710',195000,'دمنوش طلا']];
    foreach($pairs as $idx=>$v){
        [$suffix,$single,$two,$price,$name]=$v;
        $type=$families[$idx]??'legacy';
        if($type==='missing')continue;
        $familyKey=($type==='canonical'?'h297-':'family-').$suffix;
        $pdo->prepare('INSERT INTO item_variant_families(family_key,category_id,name,active) VALUES(?,1,?,1)')->execute([$familyKey,$name]);
        $fid=(int)$pdo->lastInsertId();
        foreach([[$single,$name.' - کوچک',$price,'تک‌نفره',10],[$two,$name.' - بزرگ',100000,'دو نفره',20]] as $m){
            [$code,$itemName,$amount,$label,$ord]=$m;
            $pdo->prepare('INSERT INTO items(item_code,category_id,name,description,price,image_path,preparation_station) VALUES(?,1,?,?,?,?,?)')->execute([$code,$itemName,'قوری بزرگ.',$amount,'assets/menu/default/herbal-tea.webp','hot_bar']);
            $iid=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO item_variant_members VALUES(?,?,?,?)')->execute([$fid,$iid,$label,$ord]);
            $pdo->prepare('INSERT INTO menu_items VALUES(1,?)')->execute([$iid]);
            if($code===$two)$pdo->prepare("INSERT INTO guest_media_references VALUES(10,'item',?,'card')")->execute([(string)$iid]);
        }
        if($type==='canonical'){
            $code='szlarge-'.substr(hash('sha256','h297-'.$suffix),0,20);
            $pdo->prepare('INSERT INTO items(item_code,category_id,name,price) VALUES(?,1,?,?)')->execute([$code,$name.' - بزرگ',(int)(round($price*2.3/5000)*5000)]);
            $iid=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO item_variant_members VALUES(?,?,?,30)')->execute([$fid,$iid,'بزرگ']);
            $pdo->prepare('INSERT INTO menu_items VALUES(1,?)')->execute([$iid]);
        }
    }
}
try{
    ok(count($statements)===10,'10 data-only statements with both preflight and postflight');
    foreach($statements as $stmt)ok((bool)preg_match('/^(?:SELECT|INSERT|UPDATE|DELETE)\b/i',$stmt),'statement is transactional DML or guard');
    fixture($pdo,[]);
    $ids=$pdo->query("SELECT id,item_code FROM items WHERE item_code IN('801','802') ORDER BY item_code")->fetchAll();
    run($pdo,$statements);
    ok(scalar($pdo,'SELECT COUNT(*) FROM items')===27,'nine new large items created');
    ok(scalar($pdo,'SELECT COUNT(*) FROM item_variant_members')===27,'nine families have three linked sizes');
    ok(scalar($pdo,'SELECT COUNT(*) FROM item_variant_families')===9,'existing family identities preserved');
    ok(scalar($pdo,'SELECT COUNT(*) FROM menu_items')===27,'large sizes copied to same menu');
    ok(scalar($pdo,"SELECT COUNT(*) FROM guest_media_references WHERE slot_key='card'")===18,'card media references copied');
    ok(scalar($pdo,"SELECT COUNT(*) FROM audit_log WHERE action='catalog.legacy_tea_size_expansion_r148'")===9,'every old two-person price recorded');
    ok(scalar($pdo,"SELECT unit_price_snapshot FROM order_items WHERE id=1")===160000,'historical order price unmodified');
    ok($ids===$pdo->query("SELECT id,item_code FROM items WHERE item_code IN('801','802') ORDER BY item_code")->fetchAll(),'existing item identities unchanged');
    ok(scalar($pdo,"SELECT price FROM items WHERE item_code='802'")===240000,'two-person price rounded from live single price');
    ok(scalar($pdo,"SELECT price FROM items WHERE item_code='szlarge-b724399f870d48634919'")===320000,'canonical large code and price match');

    $expected=[['03','801','802',140000],['04','803','804',190000],['05','805','806',155000],
        ['06','807','808',170000],['07','701','713',190000],['08','703','712',190000],
        ['09','705','711',180000],['10','707','708',180000],['11','709','710',195000]];
    foreach($expected as [$suffix,$singleCode,$twoCode,$singlePrice]){
        $largeCode='szlarge-'.substr(hash('sha256','h297-'.$suffix),0,20);
        $twoPrice=(int)(round($singlePrice*1.7/5000)*5000);
        $largePrice=(int)(round($singlePrice*2.3/5000)*5000);
        $prices=$pdo->prepare('SELECT item_code,price FROM items WHERE item_code IN(?,?,?)');
        $prices->execute([$singleCode,$twoCode,$largeCode]);
        $found=[];foreach($prices->fetchAll() as $row)$found[$row['item_code']]=(int)$row['price'];
        ok($found===[$singleCode=>$singlePrice,$twoCode=>$twoPrice,$largeCode=>$largePrice] ||
            count($found)===3 && $found[$singleCode]===$singlePrice && $found[$twoCode]===$twoPrice && $found[$largeCode]===$largePrice,
            'all exact prices '.$suffix);
        $q=$pdo->prepare("SELECT COUNT(*) FROM item_variant_families f JOIN item_variant_members m ON m.family_id=f.id JOIN items i ON i.id=m.item_id WHERE f.family_key=? AND i.item_code=? AND m.label='بزرگ'");
        $q->execute(['family-'.$suffix,$largeCode]);ok((int)$q->fetchColumn()===1,'large linked to original family '.$suffix);
    }

    $pdo->exec("UPDATE items SET price=246000 WHERE item_code='802'");
    run($pdo,$statements);
    ok(scalar($pdo,'SELECT COUNT(*) FROM items')===27,'rerun creates no duplicates');
    ok(scalar($pdo,"SELECT price FROM items WHERE item_code='802'")===246000,'rerun preserves subsequently customized price');
    fixture($pdo,['canonical','canonical','canonical','canonical','canonical','canonical','canonical','canonical','canonical']);
    run($pdo,$statements);
    ok(scalar($pdo,'SELECT COUNT(*) FROM items')===27,'clean canonical families remain unchanged');
    ok(scalar($pdo,'SELECT COUNT(*) FROM audit_log')===0,'no unnecessary repricing or audit on clean catalog');
    fixture($pdo,['legacy','canonical','legacy','canonical','legacy','canonical','legacy','canonical','legacy']);
    run($pdo,$statements);
    ok(scalar($pdo,'SELECT COUNT(*) FROM items')===27,'mixed upgrade repairs only the five legacy families');
    ok(scalar($pdo,'SELECT COUNT(*) FROM audit_log')===5,'only five legacy families audited');
    fixture($pdo,[]);
    $pdo->exec("UPDATE item_variant_members SET label='نامشخص' WHERE item_id=(SELECT id FROM items WHERE item_code='804')");
    try{run($pdo,$statements);throw new RuntimeException('Expected guarded mismatch failure');}
    catch(PDOException $e){ok(true,'bad legacy family aborts transaction');}
    ok(scalar($pdo,'SELECT COUNT(*) FROM items')===18,'failed preflight created no items');
    fixture($pdo,[]);
    $pdo->exec("INSERT INTO items(item_code,category_id,name,price) VALUES('szlarge-b724399f870d48634919',2,'ناهماهنگ',1)");
    try{run($pdo,$statements);throw new RuntimeException('Expected large-code collision');}
    catch(PDOException $e){ok(true,'foreign-item-code collision aborts transaction');}
    ok(scalar($pdo,'SELECT COUNT(*) FROM item_variant_members')===18,'collision did not edit families');
    fixture($pdo,[]);
    $pdo->exec("CREATE TRIGGER stop_new_large BEFORE INSERT ON items FOR EACH ROW BEGIN IF NEW.item_code LIKE 'szlarge-%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CI_ONLY_INJECTED_FAULT'; END IF; END");
    try{run($pdo,$statements);throw new RuntimeException('Expected injected DB failure');}
    catch(PDOException $e){ok(true,'write failure rolls back whole transaction');}
    ok(scalar($pdo,'SELECT COUNT(*) FROM audit_log')===0,'audit log rollback verified');
    ok(scalar($pdo,'SELECT COUNT(*) FROM items')===18,'item creation rollback verified');
    ok(scalar($pdo,'SELECT COUNT(*) FROM item_variant_members')===18,'member records rollback verified');
    $pdo->exec('DROP TRIGGER IF EXISTS stop_new_large');
    fixture($pdo,[]);
    $tmp=sys_get_temp_dir().'/sokna-r148-'.bin2hex(random_bytes(5));
    mkdir($tmp,0700,true);
    $migrationFile=$tmp.'/0080_tea_herbal_legacy_family_repair.sql';
    copy($path,$migrationFile);
    try{
        $migrations=new Migrations($pdo,$tmp);
        $applied=$migrations->migrate();
        ok($applied===['0080_tea_herbal_legacy_family_repair'],'real Migrations lifecycle applies 0080');
        ok(scalar($pdo,"SELECT COUNT(*) FROM schema_migration_statements WHERE state='applied'")===10,'ten migration statements journaled atomically');
        ok($migrations->migrate()===[],'real migration ledger rerun is no-op');
        ok(scalar($pdo,'SELECT COUNT(*) FROM item_variant_members')===27,'ledger pathway fully expanded nine families');
    }finally{unlink($migrationFile);rmdir($tmp);}
    echo "R148 MariaDB 11.4 integration tests PASS\n";
}finally{$pdo->exec("DROP DATABASE IF EXISTS `$db`");}