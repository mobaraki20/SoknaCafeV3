<?php
if(PHP_SAPI!=='cli'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);require $root.'/bootstrap.php';$base=dirname($root);$core=sokna_local_bootstrap(require $base.'/config.php');$client=$core->publicEdgeSyncClient();$publisher=$core->publicEdgePublisher();
if(!$client->configured())throw new RuntimeException('fixture connection unavailable');
if(($argv[2]??'')==='init'){
 $models=$core->publicProjectionBuilder()->remoteModelsFor(['operations','operations_workspace','reports']);$r=$client->postRaw('/api/v1/local/read-models',['models'=>$models],5);if(($r['status']??0)!==200||((int)($r['body']['synced']??0)+(int)($r['body']['unchanged']??0))!==3)throw new RuntimeException('signed sync '.json_encode(['status'=>$r['status']??0,'error'=>$r['body']['error']??'','synced'=>$r['body']['synced']??null]));echo 'PASS signed_projection_initial'.PHP_EOL;
 $h=$publisher->syncHeartbeat(5);echo 'SIGNED HEARTBEAT '.json_encode(['success'=>$h['success']??null,'status'=>$h['status']??null]).PHP_EOL;exit;
}
