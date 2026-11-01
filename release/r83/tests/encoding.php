<?php
declare(strict_types=1);
$root=$argv[1];require $root.'/local/bootstrap.php';
$pdo=new PDO('sqlite::memory:');
$config=Sokna\Local\Core\Config::fromArray(['installation'=>['id'=>'r83-test'],'public'=>['base_url'=>'https://example.invalid/sokna','shared_secret'=>'test']]);
$obs=new Sokna\Local\Core\Observability(sys_get_temp_dir().'/r83-encoding-'.bin2hex(random_bytes(5)));
$client=new Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient($config);
$publisher=new Sokna\Local\Domain\PublicEdge\PublicEdgePublisherService($pdo,$client,new Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder($pdo),$obs);
$rc=new ReflectionClass($publisher);$encode=$rc->getMethod('projectionWirePayload');$remember=$rc->getMethod('rememberProjectionEncoding');
$model=['payload'=>['sample'=>str_repeat('سکنا ',20000)],'source_version'=>str_repeat('a',64)];
$payload=['format'=>'sokna-read-model-v2','up_to_sequence'=>1,'model'=>$model];
$results=[];function ck(bool $b,string $n):void{global $results;$results[]=['test'=>$n,'pass'=>$b];if(!$b)throw new RuntimeException($n);}
ck($encode->invoke($publisher,$payload)===$payload,'no compression before explicit capability');
$remember->invoke($publisher,['ok'=>true,'model_encodings'=>['gzip-base64-v1']]);
$encoded=$encode->invoke($publisher,$payload);
ck(isset($encoded['model_gzip'])&&!isset($encoded['model']),'receiver capability enables single wire representation');
ck(json_decode(gzdecode(base64_decode($encoded['model_gzip'])),true)===$model,'negotiated encoder preserves exact model');
$remember->invoke($publisher,['ok'=>true]);
ck($encode->invoke($publisher,$payload)===$payload,'receiver rollback disables compression');
$remember->invoke($publisher,['ok'=>false,'model_encodings'=>['gzip-base64-v1']]);
ck($encode->invoke($publisher,$payload)===$payload,'failed exchange cannot enable compression');
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
