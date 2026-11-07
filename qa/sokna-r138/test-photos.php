<?php
declare(strict_types=1);
require __DIR__.'/../../apps/local-web/src/Core/CafePhotoBundle.php';
use Sokna\Local\Core\CafePhotoBundle;
if (!function_exists('imagewebp')) throw new RuntimeException('PHP GD/WebP unavailable');
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r138_ci;charset=utf8mb4','root','ci-root-only',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['guest_media_references','guest_media_derivatives','guest_media_assets','items','settings'] as $t) $pdo->exec('DROP TABLE IF EXISTS `'.$t.'`');
$pdo->exec('CREATE TABLE settings(setting_key VARCHAR(130) PRIMARY KEY,setting_value TEXT NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE items(id INT AUTO_INCREMENT PRIMARY KEY,item_code VARCHAR(40) NOT NULL UNIQUE,name VARCHAR(190) NOT NULL,image_path VARCHAR(200),price INT NOT NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO items(item_code,name,image_path,price) VALUES('1005','موهیتو','media:original',120000),('1301','سیب زمینی ساده','media:original',190000),('1112','شیــک توت فرنگی','media:keep',180000)");
$pdo->exec('CREATE TABLE guest_media_assets(id INT AUTO_INCREMENT PRIMARY KEY,media_key VARCHAR(90) UNIQUE NOT NULL,sha256 CHAR(64) UNIQUE NOT NULL,mime VARCHAR(80) NOT NULL,extension VARCHAR(12) NOT NULL,byte_size BIGINT NOT NULL,width_px INT NOT NULL,height_px INT NOT NULL,original_name VARCHAR(255) NOT NULL,alt_text TEXT NOT NULL,original_relpath VARCHAR(255) NOT NULL,active TINYINT NOT NULL,created_by_user_id INT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE guest_media_derivatives(media_id INT NOT NULL,variant_key VARCHAR(80) NOT NULL,sha256 CHAR(64) NOT NULL,mime VARCHAR(80) NOT NULL,extension VARCHAR(12) NOT NULL,byte_size BIGINT NOT NULL,width_px INT NOT NULL,height_px INT NOT NULL,path_relpath VARCHAR(300) NOT NULL,processor VARCHAR(100) NOT NULL,PRIMARY KEY(media_id,variant_key)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE guest_media_references(media_id INT NOT NULL,owner_type VARCHAR(60) NOT NULL,owner_id VARCHAR(40) NOT NULL,slot_key VARCHAR(60) NOT NULL,PRIMARY KEY(owner_type,owner_id,slot_key)) ENGINE=InnoDB');
$base=sys_get_temp_dir().'/sokna-r138-photo-'.bin2hex(random_bytes(5));
$root=$base.'/Web.release.synthetic';$folder=$root.'/resources/cafe-photo-bundle-15aban';
mkdir($folder,0700,true); mkdir($base.'/data/guest-media',0700,true);
file_put_contents($base.'/config.php','<?php return '.var_export(['app'=>['data_dir'=>$base.'/data']],true).';');
$images=[];
for($i=1;$i<=36;$i++){
  $name=sprintf('%02d.webp',$i);
  $im=imagecreatetruecolor(1600,1600);
  imagefill($im,0,0,imagecolorallocate($im,255,255,255));
  imagefilledrectangle($im,500,450,1100,1100,imagecolorallocate($im,100+($i*2)%100,70+$i,140));
  if (!imagewebp($im,$folder.'/'.$name,85)) throw new RuntimeException('Synthetic WebP failed');
  imagedestroy($im);
  $images[]=['file'=>$name,'sha256'=>hash_file('sha256',$folder.'/'.$name),'byte_size'=>filesize($folder.'/'.$name),'width_px'=>1600,'height_px'=>1600];
}
$matches=[
 ['item_code'=>'1005','expected_name'=>'موهیتو','file'=>'01.webp'],
 ['item_code'=>'1301','expected_name'=>'سیب زمینی ساده','file'=>'02.webp'],
 ['item_code'=>'1112','expected_name'=>'شیک توت فرنگی','file'=>'03.webp']
];
$manifest=$folder.'/manifest.json';
file_put_contents($manifest,json_encode(['format'=>'sokna-cafe-photo-bundle-v1','version'=>'15aban-r137','images'=>$images,'matches'=>$matches],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
$check=static function(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException('FAIL '.$msg);echo 'PASS '.$msg.PHP_EOL;};
$run=new CafePhotoBundle($pdo,$manifest);
$check(count($run->validate()['images'])===36,'exact 36 source images pass SHA/dimension audit');
$check(is_dir($run->validateHostReadiness()),'media root preflight succeeds before business mutations');
$out=$run->apply();
$check($out['assigned']===2 && $out['unmatched']===1 && $out['registered']===36,'only exact code+name matches assigned; all photos registered');
$count=(int)$pdo->query('SELECT COUNT(*) FROM guest_media_assets')->fetchColumn();
$check($count===36,'real MariaDB media inventory has precisely 36 records');
$rows=$pdo->query('SELECT item_code,name,image_path,price FROM items ORDER BY item_code')->fetchAll(PDO::FETCH_ASSOC);
$by=[];foreach($rows as $r)$by[$r['item_code']]=$r;
$check(str_starts_with($by['1005']['image_path'],'media:m_') && str_starts_with($by['1301']['image_path'],'media:m_'),'recognized menu items use canonical media keys');
$check($by['1112']['image_path']==='media:keep','mismatching product name kept its original photo');
$check((int)$by['1005']['price']===120000 && (int)$by['1301']['price']===190000,'catalog prices not modified');
$check($run->apply()['status']==='already_applied','import idempotent after successful marker');
$check((int)$pdo->query('SELECT COUNT(*) FROM guest_media_references')->fetchColumn()===2,'only verified item media references mutated');
file_put_contents($folder.'/36.webp','INVALID');$reject=false;
try{$run->validate();}catch(Throwable){$reject=true;}
$check($reject,'tampered original is rejected before DB write');
echo "R138_MARIADB_CAFE_PHOTO_IMPORT_IDEMPOTENCE_AND_MEDIA_HASH_PASS\n";
