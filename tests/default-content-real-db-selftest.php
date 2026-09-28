<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';

function dcr(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');
$name=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2');
$user=(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna');
$pass=(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna');
$data=sys_get_temp_dir().'/sokna-default-content-'.bin2hex(random_bytes(5));
@mkdir($data,0700,true);
$config=[
 'app'=>['timezone'=>'UTC','data_dir'=>$data],
 'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
 'installation'=>['id'=>'default-content-test'],
 'runtime'=>['local_token'=>'default-content'],
 'public'=>['base_url'=>'','shared_secret'=>''],
 'integrations'=>['accommodation'=>['base_url'=>'','secret'=>'']],
];
$core=sokna_local_bootstrap($config);$core->migrations()->migrate();$pdo=$core->database();
$pw=password_hash('DefaultContentPass!',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['default-content-admin',$pw,'Default Content Admin','admin']);
$admin=(int)$pdo->lastInsertId();
$first=$core->defaultContentSeeder()->seed($admin);
dcr(($first['seeded']??null)===true,'First seed did not run');
dcr((int)($first['menus']??0)===3,'Seed menu count mismatch');
dcr((int)($first['categories']??0)===14,'Seed category count mismatch');
dcr((int)($first['items']??0)===131,'Seed item count mismatch');
dcr((int)($first['media_assets']??0)===24,'Seed media count mismatch');
dcr((int)($first['media_references']??0)===81,'Seed media reference count mismatch');
$second=$core->defaultContentSeeder()->seed($admin);
dcr(($second['seeded']??null)===false && ($second['status']??'')==='already_complete','Second seed was not idempotent');
$scalar=static fn(string $sql):int=>(int)$pdo->query($sql)->fetchColumn();
dcr($scalar('SELECT COUNT(*) FROM menus')===3,'Database menu count mismatch');
dcr($scalar('SELECT COUNT(*) FROM categories')===14,'Database category count mismatch');
dcr($scalar('SELECT COUNT(*) FROM items')===131,'Database item count mismatch');
dcr($scalar('SELECT COUNT(*) FROM items WHERE active=1')===112,'Active item count mismatch');
dcr($scalar('SELECT COUNT(*) FROM items WHERE available=1')===131,'Available item count mismatch');
dcr($scalar('SELECT COUNT(*) FROM items WHERE staff_only=1')===3,'Staff-only item count mismatch');
dcr($scalar('SELECT COUNT(*) FROM items WHERE featured=1')===8,'Featured item count mismatch');
dcr($scalar('SELECT COUNT(*) FROM menu_categories')===31,'Menu/category membership count mismatch');
dcr($scalar('SELECT COUNT(*) FROM menu_items')===289,'Menu/item membership count mismatch');
dcr($scalar('SELECT COUNT(*) FROM guest_media_assets')===24,'Media asset count mismatch');
dcr($scalar("SELECT COUNT(*) FROM guest_media_derivatives WHERE variant_key='guest-card'")===24,'Media derivative count mismatch');
dcr($scalar("SELECT COUNT(*) FROM guest_media_references WHERE slot_key='card'")===81,'Media reference DB count mismatch');
dcr($scalar("SELECT COUNT(*) FROM settings WHERE setting_key='default_content.v2' AND setting_value='complete'")===1,'Seed marker missing');
dcr((string)$pdo->query("SELECT preparation_station FROM items WHERE item_code='1211'")->fetchColumn()==='cold_bar','Legacy other station was not normalized to cold_bar');
dcr($scalar("SELECT COUNT(*) FROM categories WHERE image_path LIKE 'media:%'")===11,'Category media reference count mismatch');
dcr($scalar("SELECT COUNT(*) FROM items WHERE image_path LIKE 'media:%'")===70,'Item media reference count mismatch');
dcr($scalar("SELECT COUNT(*) FROM categories WHERE icon_key IS NOT NULL AND icon_key<>''")===14,'Seeded category icons missing');
dcr($scalar("SELECT COUNT(DISTINCT icon_key) FROM categories WHERE active=1 AND icon_key IS NOT NULL AND icon_key<>''")===14,'Seeded category icons are not unique');
dcr($scalar("SELECT COUNT(*) FROM items WHERE item_code IN ('1219','1317','1220')")===3,'New spreadsheet menu items missing');
$missing=$scalar("SELECT COUNT(*) FROM guest_media_assets a LEFT JOIN guest_media_derivatives d ON d.media_id=a.id AND d.variant_key='guest-card' WHERE d.media_id IS NULL");
dcr($missing===0,'A seeded media asset is missing its guest-card derivative');
$badFiles=0;
foreach($pdo->query("SELECT original_relpath FROM guest_media_assets")->fetchAll(PDO::FETCH_COLUMN) as $rel){if(!is_file($data.'/guest-media/'.str_replace(['/', '\\'],DIRECTORY_SEPARATOR,(string)$rel)))$badFiles++;}
dcr($badFiles===0,'Seeded media original file missing from data directory');
echo "Default content MariaDB seed selftest: OK\n";
