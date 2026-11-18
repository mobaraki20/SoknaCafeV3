<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R283_DISPOSABLE_TEST_ONLY');
$root=(string)(getenv('R283_LOCAL_ROOT')?:'');
if($root===''||!is_file($root.'/bootstrap.php'))throw new RuntimeException('R283_231FQ_SOURCE_MISSING');
require $root.'/bootstrap.php';
require __DIR__.'/../overlay/src/Domain/Recovery/PrivateMediaTransferService.php';
$base=sys_get_temp_dir().'/sokna-r283-media-'.bin2hex(random_bytes(7));
$src=$base.'/old/guest-media';$dest=$base.'/new';$archive=$base.'/encrypted-media.smv';
mkdir($src.'/original',0700,true);mkdir($src.'/derived/m_123',0700,true);mkdir($dest.'/guest-media',0700,true);
file_put_contents($src.'/original/abc.webp',random_bytes(16014));
file_put_contents($src.'/derived/m_123/menu-square.webp',random_bytes(4155));
file_put_contents($dest.'/guest-media/keep.txt','THE_NEW_MACHINE_SEED_FILE');
$backupSha=hash('sha256','source encrypted business backup as fixture');
$sourceId='local-r283-fixture-old-machine';$pass='R283-safe-fixture-password-2026';
$service=new \Sokna\Local\Domain\Recovery\PrivateMediaTransferService();
$n=0;
$assert=static function(bool $ok,string $name)use(&$n):void{if(!$ok)throw new RuntimeException('R283_MEDIA_TEST_FAIL '.$name);$n++;echo 'PASS '.$name.PHP_EOL;};
$export=$service->export($src,$archive,$pass,$sourceId,$backupSha);
$assert($export['media_files']===2 && is_file($archive),'encrypted media export contains both original and derivative');
$assert(hash_file('sha256',$archive)===$export['sha256'],'encrypted file SHA256 readback');
$assert(strpos(file_get_contents($archive),'THE_NEW_MACHINE_SEED_FILE')===false,'encrypted container does not leak destination seed');
$import=$service->importStage($archive,$dest,$pass,$sourceId,$backupSha);
$assert($import['stage_only']===true && $import['activation_authorized']===false,'stage only, activation denied');
$stage=$import['stage_path'];$assert(is_dir($stage),'new isolated media staging root');
foreach(['original/abc.webp','derived/m_123/menu-square.webp'] as $relative){
 $assert(hash_file('sha256',$stage.'/guest-media/'.$relative)===hash_file('sha256',$src.'/'.$relative),
    'byte-perfect staged media '.$relative);
}
$assert(file_get_contents($dest.'/guest-media/keep.txt')==='THE_NEW_MACHINE_SEED_FILE',
    'active new-machine vault remains untouched');
$assert(is_file($stage.'/receipt.json'),'stage-only receipt exists');
$receipt=json_decode((string)file_get_contents($stage.'/receipt.json'),true,32,JSON_THROW_ON_ERROR);
$assert($receipt['activated']===false && $receipt['public_authority_activated']===false &&
    $receipt['printer_activated']===false,'receipt explicitly refuses activation');
$firstStages=count(glob($dest.'/media-stage-*')?:[]);
try{$service->importStage($archive,$dest,'wrong-password-but-long-2026',$sourceId,$backupSha);
 throw new RuntimeException('R283_WRONG_PASS_ACCEPTED');
}catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){$assert($e->errorCode==='media_decrypt_failed','wrong password rejected');}
$assert(count(glob($dest.'/media-stage-*')?:[])===$firstStages,
    'bad password creates no visible completed stage');
try{$service->importStage($archive,$dest,$pass,$sourceId,hash('sha256','wrong business backup'));
 throw new RuntimeException('R283_UNPAIRED_ACCEPTED');
}catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){$assert($e->errorCode==='media_archive_pair_mismatch','media archive cannot mismatch business backup');}
try{$service->importStage($archive,$dest,$pass,'local-r283-another-source-id',$backupSha);
 throw new RuntimeException('R283_OTHER_OWNER_ACCEPTED');
}catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){$assert($e->errorCode==='media_archive_pair_mismatch','media archive cannot switch source installation');}
$corrupt=$base.'/damaged.smv';copy($archive,$corrupt);
$bytes=file_get_contents($corrupt);$pos=max(75,strlen($bytes)-32);$bytes[$pos]=chr(ord($bytes[$pos])^255);file_put_contents($corrupt,$bytes);
try{$service->importStage($corrupt,$dest,$pass,$sourceId,$backupSha);
 throw new RuntimeException('R283_TAMPER_ACCEPTED');
}catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){$assert(in_array($e->errorCode,['media_decrypt_failed','media_zip_invalid','media_archive_truncated'],true),'tampered authenticated vault rejected');}
try{$service->export($src,$archive,$pass,$sourceId,$backupSha);
 throw new RuntimeException('R283_OVERWRITE_ACCEPTED');
}catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){$assert($e->errorCode==='media_export_already_exists','archive not overwritten');}
if(function_exists('symlink')){
 @symlink($src.'/original/abc.webp',$src.'/original/bad-link.webp');
 if(is_link($src.'/original/bad-link.webp')){
  try{$service->export($src,$base.'/symlink.smv',$pass,$sourceId,$backupSha);
   throw new RuntimeException('R283_SYMLINK_ACCEPTED');
  }catch(\Sokna\Local\Domain\Recovery\RecoveryException $e){$assert($e->errorCode==='media_symlink_forbidden','symlinks and reparse points rejected');}
  @unlink($src.'/original/bad-link.webp');
 }
}
echo 'R283_MEDIA_VAULT_AUTHENTICATED_CROSS_MACHINE_STAGE_'.(string)$n.'_CHECKS_PASS'.PHP_EOL;
