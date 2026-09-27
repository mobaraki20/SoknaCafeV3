<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/src/Domain/GuestContent/ThemePackageManager.php';
use Sokna\Local\Domain\GuestContent\ThemePackageManager;
function g42_theme_assert(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,$m.PHP_EOL);exit(1);}}
$root=dirname(__DIR__).'/apps/local-web/resources/guest-themes';
$mgr=new ThemePackageManager($root);
$packages=$mgr->packages();
$keys=array_column($packages,'theme_key');
g42_theme_assert(in_array('sokna-house',$keys,true),'official sokna-house theme missing');
g42_theme_assert(in_array('sokna-classic',$keys,true),'classic compatibility theme missing');
$resolved=$mgr->resolve('sokna-house',['primary'=>'#123456','radius_md'=>'20px']);
g42_theme_assert(($resolved['tokens']['primary']??'')==='#123456','editable color override failed');
g42_theme_assert(($resolved['tokens']['radius_md']??'')==='20px','editable radius override failed');
try{$mgr->resolve('sokna-house',['text'=>'#000000']);g42_theme_assert(false,'non-editable token accepted');}catch(RuntimeException){}
try{$mgr->resolve('sokna-house',['primary'=>'red']);g42_theme_assert(false,'unsafe color accepted');}catch(RuntimeException){}
$tmp=sys_get_temp_dir().'/sokna-g42-theme-'.bin2hex(random_bytes(4));@mkdir($tmp.'/evil',0700,true);
file_put_contents($tmp.'/evil/theme.json',json_encode(['format'=>'sokna-guest-theme-v1','theme_key'=>'evil','name'=>'Evil','version'=>'1.0.0','layout'=>'cards','tokens'=>[],'editable_tokens'=>[]]));
file_put_contents($tmp.'/evil/payload.php','<?php echo 1;');
$evil=new ThemePackageManager($tmp);
g42_theme_assert($evil->packages()===[],'package containing executable PHP entered theme catalog');
@unlink($tmp.'/evil/payload.php');@unlink($tmp.'/evil/theme.json');@rmdir($tmp.'/evil');@rmdir($tmp);
fwrite(STDOUT,"G4.2 Theme package self-test: OK\n");
