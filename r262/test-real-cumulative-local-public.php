<?php
declare(strict_types=1);
/** A single real Local publisher -> independently installed Public HTTP/SQL
 * smoke, with the old Public permanently absent.
 * The test never touches the operator's machines or published packages.
 */
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R261_CI_ONLY');
$localRoot=(string)getenv('R261_LOCAL_ROOT');
$configPath=dirname($localRoot).'/config.php';
$codePath=(string)getenv('R261_CODE_PATH');
$dsnPass=(string)getenv('R261_DB_PASS');
if(!is_file($localRoot.'/bootstrap.php')||!is_file($codePath))throw new RuntimeException('R261_DISPOSABLE_SOURCE_MISSING');
require_once $localRoot.'/bootstrap.php';
$code=trim((string)file_get_contents($codePath));
$base='http://127.0.0.1:18881/new-public';
$installation='local-cafe-e2e-001';
$newSecret=bin2hex(random_bytes(32));
$oldUrl='https://dead-old-public.example';
$dataDir=(string)getenv('R261_DATA_DIR');
$values=[
 'app'=>['timezone'=>'Asia/Tehran','data_dir'=>$dataDir],
 'installation'=>['id'=>$installation],
 'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_r261_local',
       'charset'=>'utf8mb4','user'=>'root','pass'=>$dsnPass],
 'public'=>['base_url'=>$oldUrl,'shared_secret'=>str_repeat('f',64)],
];
file_put_contents($configPath,"<?php\nreturn ".var_export($values,true).";\n");
$core=sokna_local_bootstrap($values);
$pre=$core->migrations()->migrate('0059_menu_business_reconciliation_v4');
$seed=$core->defaultContentSeeder()->seed();
echo 'REAL_LOCAL_CLEAN_INSTALL_CATALOG_SEEDED='.json_encode($seed)."\n";
$applied=$core->migrations()->migrate();
echo 'REAL_LOCAL_MIGRATIONS='.(count($pre)+count($applied))."\n";
$pdo=$core->database();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('all','منوی آزمایشی','active',1)");
$menuId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name,sort_order) VALUES('test-coffee','قهوه',1)");
$categoryId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id) VALUES(?,?)')->execute([$menuId,$categoryId]);
$pdo->prepare("INSERT INTO items(item_code,category_id,name,description,price,available,preparation_station) VALUES(?,?,'قهوهٔ تست','صرفاً آزمایش',150000,1,'bar')")
    ->execute(['E2E-COFFEE',$categoryId]);
$itemId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES('میز آزمایشی',1,'QA-1','table-token-for-qa-001')");
$original=require $configPath;
if($original['public']['base_url']!==$oldUrl)throw new RuntimeException('R261_ORIGINAL_LOCAL_CONFIG_MISMATCH');
$client=$core->publicEdgeSyncClient();
$paired=$client->initialPair($base,$code,$newSecret,'Cafe E2E',true);
$body=(array)($paired['body']??[]);
if(($body['paired']??false)!==true||
   ($body['guest_intake_enabled']??true)!==false||
   ($body['replacement_mode']??false)!==true) {
 throw new RuntimeException('R261_REAL_SETUP_PAIR_CONTRACT_FAILED '.json_encode($body));
}
echo "R261_PUBLIC_PAIRED_INTAKE_PAUSED\n";
$values['public']=['base_url'=>$base,'shared_secret'=>$newSecret];
$preview=\Sokna\Local\Core\Bootstrap::fromArray($values);
try{
 $premature=$preview->publicEdgeSyncClient()->postRaw('/api/v1/local/move/activate',[]);
 if((int)$premature['status']!==409)throw new RuntimeException('PREMATURE_INTAKE_NOT_DENIED');
 echo "R261_INTAKE_CLOSED_BEFORE_LOCAL_SYNC\n";
}catch(\Throwable $e){throw new RuntimeException('PREMATURE_CHECK: '.$e->getMessage(),0,$e);}
$ready=false;$result=[];
for($i=0;$i<12;$i++){
 $result=$preview->publicEdgePublisher()->syncAll(10);
 $media=(array)($result['channels']['guest_media']??[]);
 $ready=($result['success']??false)===true &&
   ($result['degraded']??true)===false &&
   ($media['ok']??false)===true &&
   ($media['complete']??false)===true;
 echo 'REAL_LOCAL_SYNC_PASS_'.($i+1).'='.($ready?'YES':'NO').
   ' failed='.json_encode($result['critical_failed_channels']??[]). ' remaining_media='.json_encode($media['missing_count']??$media['remaining']??null)."\n";
 if($ready)break;
}
if(!$ready){
 foreach((array)($result['channels']??[]) as $name=>$value){
   if(!is_array($value)||!empty($value['ok']))continue;
   echo 'FAILED_CHANNEL '.$name.' '.json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
 }
 throw new RuntimeException('R261_REAL_LOCAL_PUBLISHER_DID_NOT_COMPLETE');
}
if((require $configPath)['public']['base_url']!==$oldUrl)
 throw new RuntimeException('OLD_LOCAL_BINDING_CHANGED_BEFORE_COMMIT');
$activation=$preview->publicEdgeSyncClient()->post('/api/v1/local/move/activate',[]);
if(($activation['body']['guest_intake_enabled']??null)!==true)throw new RuntimeException('R261_SIGNED_ACTIVATION_FAILED');
echo "R261_REAL_FULL_LOCAL_SYNC_AND_SIGNED_PUBLIC_ACTIVATION_PASS\n";
$guestJson=json_encode([
 'installation_id'=>$installation,'table_token'=>'table-token-for-qa-001',
 'device_token'=>'guest-device-qa-001','client_token'=>'guest-client-qa-00000001',
 'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>150000]]
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$ctx=stream_context_create(['http'=>[
 'method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",
 'content'=>$guestJson,'timeout'=>10,'ignore_errors'=>true
]]);
$response=file_get_contents($base.'/api/guest/order',false,$ctx);
$reply=is_string($response)?json_decode($response,true):null;
echo "PUBLIC_GUEST_ORDER_RESPONSE=".json_encode($reply,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
if(!is_array($reply)||!in_array((int)($reply['status']??0),[200,202],true)&&
   !in_array((string)($reply['code']??''),['accepted','pending','queued'],true)){
 // The HTTP JSON envelope may contain success:true and a different response code.
 if(!is_array($reply)||($reply['success']??null)!==true)throw new RuntimeException('R261_GUEST_ORDER_NOT_ACCEPTED');
}
try{
 $synced=$preview->publicEdgeRelay()->syncRealtimeOnly(5,5);
 echo "REAL_LOCAL_GUEST_RELAY=".json_encode($synced,JSON_UNESCAPED_UNICODE)."\n";
}catch(\Throwable $e){
 throw new RuntimeException('R261_REAL_GUEST_RELAY_FAILED: '.$e->getMessage(),0,$e);
}
$count=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_source='guest'")->fetchColumn();
if($count<1){
 $pubDb=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r261_public;charset=utf8mb4',
   'root',$dsnPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $requests=$pubDb->query("SELECT request_id,state,error_code,result_json FROM realtime_requests ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
 echo 'REAL_PUBLIC_GUEST_REQUEST_RESULT='.json_encode($requests,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
 throw new RuntimeException('R261_PUBLIC_GUEST_ORDER_NOT_RECEIVED_BY_LOCAL');
}
echo "R261_FULL_LOCAL_PUBLISHER_TO_PUBLIC_AND_GUEST_ORDER_RECEIPT_PASS orders=$count\n";
