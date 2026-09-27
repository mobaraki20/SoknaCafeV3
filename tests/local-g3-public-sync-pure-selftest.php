<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/src/Core/Config.php';
require_once dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncClient.php';
require_once dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php';
require_once dirname(__DIR__).'/apps/public/src/Remote/RemoteStaffPageRenderer.php';
use Sokna\Local\Core\Config;use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;use Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder;use Sokna\PublicEdge\Remote\RemoteStaffPageRenderer;
function g32_assert(bool $v,string $m):void{if(!$v){fwrite(STDERR,$m."\n");exit(1);}}
$seen=[];$cfg=Config::fromArray(['installation'=>['id'=>'local-g32'],'public'=>['base_url'=>'http://127.0.0.1:9999','shared_secret'=>'abc123']]);
$client=new PublicEdgeSyncClient($cfg,function(string $url,string $method,array $headers,string $body)use(&$seen){$seen=compact('url','method','headers','body');return ['status'=>200,'body'=>json_encode(['ok'=>true])];});
$r=$client->post('/api/v1/local/heartbeat',['local_version'=>'x']);g32_assert($r['status']===200,'signed transport failed');g32_assert(($seen['headers']['X-Sokna-Installation']??'')==='local-g32','installation header missing');g32_assert(strlen((string)($seen['headers']['X-Sokna-Signature']??''))===64,'signature missing');
$cfg2=Config::fromArray(['installation'=>['id'=>'x'],'public'=>['base_url'=>'http://example.com','shared_secret'=>'s']]);g32_assert((new PublicEdgeSyncClient($cfg2))->configured()===false,'insecure non-loopback HTTP was accepted');
$a=['z'=>1,'a'=>['b'=>2,'a'=>3]];$b=['a'=>['a'=>3,'b'=>2],'z'=>1];g32_assert(PublicProjectionBuilder::hash($a)===PublicProjectionBuilder::hash($b),'canonical hash is not stable');
$renderer=new RemoteStaffPageRenderer();$html=$renderer->dashboard(['display_name'=>'Remote CI','capabilities'=>['operations.read']]);g32_assert(str_contains($html['body'],'data-model="operations"'),'operations tab missing');g32_assert(!str_contains($html['body'],'data-model="inventory_cost"'),'unauthorized cost tab exposed');
fwrite(STDOUT,"G3.2 Public sync/remote staff pure self-test: OK\n");
