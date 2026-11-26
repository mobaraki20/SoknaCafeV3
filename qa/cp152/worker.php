<?php
if(PHP_SAPI!=='cli'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);require $root.'/bootstrap.php';$base=dirname($root);$core=sokna_local_bootstrap(require $base.'/config.php');$client=$core->publicEdgeSyncClient();$publisher=$core->publicEdgePublisher();
if(!$client->configured())throw new RuntimeException('fixture connection unavailable');
if(($argv[2]??'')==='init'){
 $models=$core->publicProjectionBuilder()->remoteModelsFor(['operations','preparation','operations_workspace','reports','inventory','inventory_cost']);$r=$client->postRaw('/api/v1/local/read-models',['models'=>$models],5);if(($r['status']??0)!==200||((int)($r['body']['synced']??0)+(int)($r['body']['unchanged']??0))!==6)throw new RuntimeException('signed sync '.json_encode(['status'=>$r['status']??0,'error'=>$r['body']['error']??'','synced'=>$r['body']['synced']??null]));echo 'PASS signed_projection_initial'.PHP_EOL;
 $h=$publisher->syncHeartbeat(5);echo 'SIGNED HEARTBEAT '.json_encode(['success'=>$h['success']??null,'status'=>$h['status']??null]).PHP_EOL;exit;
}
$end=microtime(true)+210;$last=0;while(microtime(true)<$end){try{$r=$core->publicEdgeRelay()->syncRealtimeExchange(8,4,(int)(microtime(true)*1000)+5500);if(($r['processed']??0)>0||($r['acked']??0)>0)echo 'WORKER '.json_encode(['processed'=>$r['processed']??0,'acked'=>$r['acked']??0]).PHP_EOL;if(microtime(true)-$last>2){$publisher->syncProjectionModelsV2(250,4,4,(int)(microtime(true)*1000)+5000,['operations','preparation','guest_operational','operations_workspace','reports','inventory','inventory_cost']);$last=microtime(true);}}catch(Throwable $e){echo 'WORKER ERROR '.get_class($e).' '.($e->errorCode??'').PHP_EOL;}usleep(350000);}
