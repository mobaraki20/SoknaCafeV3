<?php
declare(strict_types=1);
/** One real-kernel GET smoke: no proxy/DB mutation, root or subdirectory. */
$root=(string)getenv('R256_QA_PUBLIC_ROOT');
if(getenv('GITHUB_ACTIONS')!=='true'||!is_dir($root)||!is_file($root.'/config.php'))
    throw new RuntimeException('disposable_installed_public_required');
require $root.'/bootstrap.php';
$config=require $root.'/config.php';
$core=sokna_public_bootstrap($config);
$kernel=new \Sokna\PublicEdge\Http\PublicHttpKernel($core,$root);
$base=(string)$core->config()->string('app.base_url');
$sub=(string)(parse_url($base,PHP_URL_PATH)?:'');
$checks=0;
function check259(bool $cond,string $reason):void{
 global $checks;if(!$cond)throw new RuntimeException('R259_PUBLIC_HTTP_FAILED_'.$reason);
 $checks++;echo "PASS ".$reason."\n";
}
$page=$kernel->handle('GET',$sub.'/move/approve');
check259(($page['status']??0)===200,'real_kernel_owner_page_http200');
check259(str_contains((string)($page['body']??''),$base.'/assets/sokna-public-move.js'),
 'base_path_asset_visible_on_owner_page');
check259(str_contains((string)($page['headers']['Content-Security-Policy']??''),"script-src 'self'"),
 'page_disallows_inline_scripts');
foreach(['sokna-public-move.js','sokna-public-move.css'] as $name){
 $asset=$kernel->handle('GET',$sub.'/assets/'.$name);
 check259(($asset['status']??0)===200&&
   is_file((string)($asset['file_path']??'')),
   'real_kernel_serves_'.$name);
}
echo "R259_REAL_PUBLIC_BROWSER_SURFACE_PASS count=$checks\n";
