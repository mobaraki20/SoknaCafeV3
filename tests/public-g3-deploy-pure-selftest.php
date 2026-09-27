<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

use Sokna\PublicEdge\Http\PublicHttpKernel;

function g31_pure_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,$message.PHP_EOL);exit(1);}}
$core=sokna_public_bootstrap([
    'db'=>['host'=>'invalid.local','port'=>'3306','name'=>'none','charset'=>'utf8mb4','user'=>'none','pass'=>'none'],
    'app'=>['default_installation_id'=>'g31-pure'],
]);
$kernel=new PublicHttpKernel($core,dirname(__DIR__).'/apps/public');
$root=$kernel->handle('GET','/',['table'=>'opaque']);
g31_pure_assert((int)$root['status']===302,'root did not redirect to /menu');
g31_pure_assert((string)($root['headers']['Location']??'')==='/menu?table=opaque','root redirect lost query context');
$css=$kernel->handle('GET','/assets/scds/guest.css');
g31_pure_assert((int)$css['status']===200&&is_file((string)($css['file_path']??'')),'Guest CSS route failed');
g31_pure_assert(str_contains((string)($css['headers']['Content-Security-Policy']??''),"default-src 'self'"),'security headers missing');
$robots=$kernel->handle('GET','/robots.txt');
g31_pure_assert((int)$robots['status']===200&&str_contains((string)$robots['body'],'Disallow: /api/'),'robots policy missing');
$bad=$kernel->handle('GET','/%2e%2e/config.php');
g31_pure_assert((int)$bad['status']===400,'path traversal was not rejected');
$invalid=$kernel->handle('POST','/api/guest/order',[],'not-json');
g31_pure_assert((int)$invalid['status']===400,'invalid guest JSON did not fail closed');
fwrite(STDOUT,"G3.1 Public deploy pure self-test: OK\n");
