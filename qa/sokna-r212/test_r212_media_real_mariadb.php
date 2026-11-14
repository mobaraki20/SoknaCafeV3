<?php
declare(strict_types=1);
// QA-only. Refuses any non-disposable GitHub Actions environment.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R212_DISPOSABLE_ONLY') !== 'YES') throw new RuntimeException('R212_CI_ONLY');
$web = trim((string)getenv('SOKNA_R212_WEB'));
$temp = realpath((string)getenv('RUNNER_TEMP'));
if (!$temp || !is_file($web.'/bootstrap.php') || !str_starts_with((string)realpath($web),$temp.DIRECTORY_SEPARATOR)) throw new RuntimeException('R212_REFUSE_NON_TEMP_WEB');
$root=dirname($web);$data=$root.'/Data';$media=$data.'/guest-media';
if (!is_dir($media) && !mkdir($media,0700,true) && !is_dir($media)) throw new RuntimeException('R212_MEDIA_DIR_FAILED');
if (file_exists($root.'/config.php')) throw new RuntimeException('R212_EXISTING_CONFIG_REFUSED');
file_put_contents($root.'/config.php','<?php return '.var_export(['app'=>['data_dir'=>$data]],true).';');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('SOKNA_R212_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE sokna_r212_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r212_disposable');
$migrations=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$migrations->migrate('0059_menu_business_reconciliation_v4');
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['qa.r212',password_hash('only-in-ci',PASSWORD_DEFAULT),'R212 Admin','admin']);
$uid=(int)$pdo->lastInsertId();$admin=['id'=>$uid,'role'=>'admin'];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$seeder=new \Sokna\Local\Domain\Sellables\DefaultContentSeeder($pdo,$icons,$web.'/resources/default-content/v1',$media);
if (($seeder->seed($uid)['status']??'')!=='complete') throw new RuntimeException('R212_SEED_FAILED');
$migrations->migrate();
$count=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ($count!==81) throw new RuntimeException('R212_MIGRATION_COUNT_'.$count);
$id=new \Sokna\Local\Core\PdoIdentityRepository($pdo);
$theme=new \Sokna\Local\Domain\GuestContent\ThemePackageManager($web.'/resources/guest-themes');
$service=new \Sokna\Local\Domain\GuestContent\GuestContentService($pdo,$id,$theme,$media);
function assertR212(bool $yes,string $label):void {if (!$yes)throw new RuntimeException('R212_FAIL_'.$label);echo 'PASS_R212_'.$label."\n";}
function findR212(PDO $pdo,string $key):array {$q=$pdo->prepare('SELECT id,active,archived_at,original_relpath FROM guest_media_assets WHERE media_key=?');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('R212_MISSING_ROW');return $r;}
if(!function_exists('imagecreatetruecolor'))throw new RuntimeException('R212_PHP_GD_REQUIRED');
$image=$root.'/unique-r212.png';$im=imagecreatetruecolor(4,4);$rgb=unpack('C3',random_bytes(3));$color=imagecolorallocate($im,$rgb[1],$rgb[2],$rgb[3]);imagefill($im,0,0,$color);imagepng($im,$image);imagedestroy($im);
$before=(int)$pdo->query('SELECT COUNT(*) FROM guest_media_assets')->fetchColumn();
$first=$service->importUpload($image,'R212-qa.png','QA',$admin);
assertR212($first['deduplicated']===false,'FRESH_UPLOAD');$key=(string)$first['media_key'];
$row=findR212($pdo,$key);assertR212((int)$row['active']===1 && is_file($media.'/'.$row['original_relpath']),'FRESH_VISIBLE_ON_DISK');
$library=$service->mediaLibrary($admin);assertR212(in_array($key,array_column($library,'media_key'),true),'FRESH_VISIBLE_IN_LIBRARY');
$again=$service->importUpload($image,'R212-qa.png','QA',$admin);assertR212($again['deduplicated']===true && $again['media_key']===$key,'ACTIVE_DEDUP');
assertR212((int)$pdo->query('SELECT COUNT(*) FROM guest_media_assets')->fetchColumn()===$before+1,'NO_DUPLICATE_DB_ROW');
$service->archiveMedia($key,$admin);assertR212((int)findR212($pdo,$key)['active']===0,'ARCHIVE_INACTIVE');
assertR212(!in_array($key,array_column($service->mediaLibrary($admin),'media_key'),true),'ARCHIVE_HIDDEN');
$restore=$service->importUpload($image,'R212-qa.png','QA',$admin);
assertR212(($restore['restored']??false)===true && $restore['media_key']===$key,'ARCHIVE_REUPLOAD_RESTORES');
$row=findR212($pdo,$key);assertR212((int)$row['active']===1 && $row['archived_at']===null,'RESTORE_ACTIVE_NO_ARCHIVED_TIMESTAMP');
assertR212(in_array($key,array_column($service->mediaLibrary($admin),'media_key'),true),'RESTORE_VISIBLE_IN_LIBRARY');
$q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action='guest.media_restored_on_upload' AND entity_id=?");$q->execute([$key]);
assertR212((int)$q->fetchColumn()===1,'RESTORE_AUDIT');
$service->archiveMedia($key,$admin);$path=$media.'/'.$row['original_relpath'];$backup=$path.'.r212backup';rename($path,$backup);
try {$service->importUpload($image,'R212-qa.png','QA',$admin);throw new RuntimeException('R212_MISSING_FILE_NOT_REJECTED');}
catch(\Sokna\Local\Domain\GuestContent\GuestContentException $e){assertR212($e->errorCode==='media_archived_file_missing','MISSING_ARCHIVED_FILE_REJECTED');}
assertR212((int)findR212($pdo,$key)['active']===0,'ROLLBACK_PRESERVES_ARCHIVE');rename($backup,$path);
$service->importUpload($image,'R212-qa.png','QA',$admin);assertR212((int)findR212($pdo,$key)['active']===1,'RESTORE_AFTER_ERROR');
assertR212((int)$pdo->query('SELECT COUNT(*) FROM guest_media_assets')->fetchColumn()===$before+1,'NO_EXTRA_ROWS_AFTER_RETRIES');
echo 'PASS_R212_REAL_MARIADB_81_MIGRATIONS_ARCHIVE_REUPLOAD_END_TO_END\n';