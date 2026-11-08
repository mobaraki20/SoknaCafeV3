<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_PHASE1_DISPOSABLE')!=='1'){
    fwrite(STDERR,"DISPOSABLE_CI_ONLY\n");exit(65);
}
require dirname(__DIR__,2).'/apps/local-web/src/Domain/System/CatalogPushReadOnlyAudit.php';
use Sokna\Local\Domain\System\CatalogPushReadOnlyAudit;
function pass(bool $value,string $message):void{if(!$value)throw new RuntimeException('FAIL '.$message);echo "PASS $message\n";}
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','phase1-ci-root',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db='sokna_phase1_audit_ci';
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$db`");
try{
  $ddl=[
  'CREATE TABLE categories(id INT PRIMARY KEY,category_key VARCHAR(60) NOT NULL)',
  'CREATE TABLE items(id INT PRIMARY KEY,item_code VARCHAR(80) NOT NULL,lifecycle_status VARCHAR(30),available TINYINT,guest_visible TINYINT,price BIGINT,category_id INT)',
  'CREATE TABLE item_variant_families(id INT PRIMARY KEY,family_key VARCHAR(80) NOT NULL,name VARCHAR(160),active TINYINT,category_id INT)',
  'CREATE TABLE item_variant_members(family_id INT,item_id INT,label VARCHAR(80),sort_order INT)',
  'CREATE TABLE menu_items(menu_id INT,item_id INT)',
  'CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT)',
  'CREATE TABLE notification_push_subscriptions(id INT PRIMARY KEY,active TINYINT)',
  'CREATE TABLE notification_preferences(id INT PRIMARY KEY,push_enabled TINYINT)'];
  foreach($ddl as $sql)$pdo->exec($sql);
  $audit=new CatalogPushReadOnlyAudit($pdo);
  $empty=$audit->inspect();
  pass($empty['catalog']['needs_review']===9,'empty legacy families are not marked migrated');
  pass($empty['push']['configuration_present']===false,'missing VAPID and bridge recognized');
  $pdo->exec("INSERT INTO categories VALUES(1,'tea-herbal')");
  $pdo->exec("INSERT INTO items VALUES(1,'801','active',1,1,140000,1),(2,'802','active',1,1,240000,1),(3,'szlarge-b724399f870d48634919','active',1,1,320000,1)");
  $pdo->exec("INSERT INTO item_variant_families VALUES(1,'h297-03','چای ساده',1,1)");
  $pdo->exec("INSERT INTO item_variant_members VALUES(1,1,'تک‌نفره',10),(1,2,'دو نفره',20),(1,3,'بزرگ',30)");
  $pdo->exec("INSERT INTO menu_items VALUES(1,2),(1,3)");
  $pdo->exec("INSERT INTO settings VALUES('notifications.vapid_public_key','test-public-vapid'),('notifications.push_bridge_url','https://ci.invalid/bridge')");
  $pdo->exec("INSERT INTO notification_push_subscriptions VALUES(1,1),(2,0)");
  $pdo->exec("INSERT INTO notification_preferences VALUES(1,1),(2,0)");
  $before=$pdo->query('SELECT COUNT(*) FROM item_variant_members')->fetchColumn();
  $good=$audit->inspect();
  pass($good['catalog']['complete']===1,'one valid 3-size family recognized');
  $pdo->exec("UPDATE item_variant_families SET family_key='family-03' WHERE id=1");
  $legacy=$audit->inspect();
  pass($legacy['catalog']['complete']===1,'legacy family-03 recognized');
  pass($legacy['catalog']['families'][0]['legacy_family_detected']===true,'legacy key reported');
  pass($good['catalog']['needs_review']===8,'remaining missing families preserved');
  pass($good['push']['configuration_present']===true,'bridge and VAPID availability recognized');
  pass($good['push']['active_subscriptions']===1,'only subscription counts returned');
  pass(strpos(json_encode($good),'test-public-vapid')===false,'VAPID content not leaked');
  pass(strpos(json_encode($good),'ci.invalid')===false,'bridge URL not leaked');
  pass((int)$pdo->query('SELECT COUNT(*) FROM item_variant_members')->fetchColumn()===(int)$before,'audit does not modify catalog');
  $pdo->exec('UPDATE items SET guest_visible=0 WHERE id=3');
  $hidden=$audit->inspect();
  pass(in_array('member_hidden_from_guest',$hidden['catalog']['families'][0]['issues'],true),'hidden large size detected');
}finally{
  $pdo->exec('DROP DATABASE IF EXISTS `sokna_phase1_audit_ci`');
}