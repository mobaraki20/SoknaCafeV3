<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/src/Core/Observability.php';
require_once dirname(__DIR__).'/apps/local-web/src/Domain/System/SupportBundleWriter.php';
use Sokna\Local\Core\Observability;use Sokna\Local\Domain\System\SupportBundleWriter;
function g22p_fail(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);}function g22p_assert(bool $c,string $m):void{if(!$c)g22p_fail($m);}
$root=sys_get_temp_dir().'/sokna-g22-pure-'.bin2hex(random_bytes(5));$obs=new Observability($root);$writer=new SupportBundleWriter($obs);
$secret='super-secret-password-'.bin2hex(random_bytes(4));$token='token-'.bin2hex(random_bytes(8));
$obs->logEvent('error','g22.secret_probe',['password'=>$secret,'nested'=>['access_token'=>$token],'safe'=>'visible']);
$bundle=$writer->create(['components'=>['local_web'=>['status'=>'ok']],'db_pass'=>$secret,'nested'=>['token'=>$token,'safe'=>'kept']]);
$path=$writer->resolve((string)$bundle['id']);g22p_assert(is_file($path),'bundle file missing');$raw=gzdecode((string)file_get_contents($path));g22p_assert(is_string($raw),'bundle gzip invalid');
g22p_assert(!str_contains($raw,$secret)&&!str_contains($raw,$token),'secret leaked into support bundle');g22p_assert(str_contains($raw,'[REDACTED]'),'redaction marker missing');g22p_assert(str_contains($raw,'g22.secret_probe'),'structured log missing');g22p_assert(str_contains($raw,'visible')&&str_contains($raw,'kept'),'safe diagnostic fields were lost');
for($i=0;$i<7;$i++)$writer->create(['n'=>$i]);$files=glob($root.'/support-bundles/support-*.json.gz')?:[];g22p_assert(count($files)<=5,'support bundle retention is unbounded');
fwrite(STDOUT,"G2.2 support bundle pure self-test: OK\n");
