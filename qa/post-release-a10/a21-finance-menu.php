<?php
declare(strict_types=1);
// Synthetic acceptance data only; this script must not touch a deployed system.
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]??'');if(!$root||basename($root)!=='local')exit(2);$w=dirname($root);$cfg=require $w.'/config.php';
if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150'||($cfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD')||($cfg['public']['base_url']??'')!=='http://127.0.0.1:18766/public')exit(2);
require $root.'/bootstrap.php';$c=sokna_local_bootstrap($cfg);$p=$c->database();$f=json_decode(file_get_contents($w.'/cp144-fixture.json'),true,64,JSON_THROW_ON_ERROR);$actor=$p->query("SELECT id,role FROM users WHERE username='qa-target' AND role='admin'")->fetch(PDO::FETCH_ASSOC);$clock=$c->businessClock()->assignment();$s=$c->settlements();
function ck($v,$n){if(!$v)throw new RuntimeException($n);echo "PASS $n\n";}
function query(PDO $p,string $sql,array $a=[]){$q=$p->prepare($sql);$q->execute($a);return $q;}
$mode=$argv[2]??'';
if($mode==='finance'){
 $first=(int)query($p,'SELECT id FROM settlement_records WHERE request_id=?',['qa160-subscriber-settlement'])->fetchColumn();ck($first>0,'actual subscriber document');$sub=(int)query($p,"SELECT id FROM subscribers WHERE name='QA160 subscriber'")->fetchColumn();
 $ids=[];$tables=[];for($i=1;$i<=23;$i++){
  $code='qa-a21-final-'.$i;$amount=100000+$i*1000;
  query($p,'INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)',['QA A21 Archive '.$i,800+$i,$code,$code.'-table']);$tid=(int)$p->lastInsertId();
  query($p,"INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?)",[$code.'-session',$tid,$tid,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);$sid=(int)$p->lastInsertId();
  query($p,"INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff','accounted',?,?,?,?,?)",[$code,$code.'-order',$tid,$sid,$amount,28000+$i,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);$oid=(int)$p->lastInsertId();
  query($p,'INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,preparation_station,line_total) VALUES(?,?,?,?,?,?,?,?)',[$oid,(int)$f['tax_item'],'QA A21 historical item',$amount,1,1,'hot_bar',$amount]);
  $a=$s->account($sid);$r=$s->settle(['session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'mode'=>'full','destination'=>'direct','request_id'=>$code.'-receipt','print_receipt'=>false,'tenders'=>[['type'=>'cash','amount'=>30000],['type'=>'pos','amount'=>$amount-30000]]],$actor);
  $id=(int)($r['settlement_id']??0);ck($id>0,'real settlement '.$i);$ids[]=$id;$tables[]=$tid;
 }
 $reversal=$s->reverse($ids[0],'QA isolated reversal','qa-a21-final-reverse',$actor);$rev=(int)($reversal['settlement_id']??0);ck($rev>0,'actual full reversal');
 $rows=query($p,'SELECT id,invoice_number,table_name_snapshot,total,status,business_date FROM settlement_records WHERE id IN (?,?)',[$ids[0],$rev])->fetchAll(PDO::FETCH_ASSOC);ck(count($rows)===2&&((int)$rows[0]['total']===(int)$rows[1]['total']),'historical reversal amount');
 $tenders=query($p,'SELECT tender_type,amount FROM settlement_tender_allocations WHERE settlement_id=? ORDER BY tender_type',[$ids[0]])->fetchAll(PDO::FETCH_ASSOC);ck(count($tenders)===2&&array_sum(array_column($tenders,'amount'))===101000,'actual multiple payment methods');
 // Change only the synthetic product price after settlement, then verify stored invoice totals stay historical.
 $price=(int)query($p,'SELECT price FROM items WHERE id=?',[(int)$f['tax_item']])->fetchColumn();query($p,'UPDATE items SET price=? WHERE id=?',[$price+12345,(int)$f['tax_item']]);
 $d=$c->financeWorkspace()->settlementDetail($ids[0]);ck((int)$d['record']['total']===101000,'historical total independent of current catalog price');
 $cat=(string)query($p,'SELECT category_key FROM expense_categories WHERE active=1 ORDER BY category_key LIMIT 1')->fetchColumn();$e=$c->expenses();$x=$e->create(['category_key'=>$cat,'amount'=>123456,'description'=>'QA A21 expense','occurred_at'=>$c->time()->localNow()->modify('-1 minute')->format('Y-m-d H:i:s'),'source_request_id'=>'qa-a21-final-expense'],$actor);$rr=$e->reverse((int)$x['id'],'QA isolated reversal','qa-a21-final-expense-reverse',$actor);ck((int)$rr['id']>0,'expense and reversal history');
 $pub=$c->publicEdgePublisher();$keys=['reports','subscribers','operations','operations_workspace','deferred_context'];$pub->seedProjectionInvalidations($keys,'qa.a21.final.finance');$r=$pub->syncProjectionModelsV2(250,8,20,null,$keys);foreach($keys as $key)ck(($r['models'][$key]['ok']??false)===true,'signed '.$key.' ACK');ck(($pub->syncHeartbeat(5)['success']??false)===true,'heartbeat');
 file_put_contents($w.'/final-finance.json',json_encode(['ids'=>$ids,'reversal'=>$rev,'subscriber'=>$sub,'subscriber_invoice'=>$first,'documents'=>$rows],JSON_THROW_ON_ERROR));
}elseif($mode==='guest'){
 $cat=$c->catalogAdmin();$menu=(int)$cat->saveMenu(['name'=>'QA A21 guest media','status'=>'active','sort_order'=>-1000],$actor)['id'];$category=(int)$cat->saveCategory(['name'=>'QA A21 guest pictures','menu_ids'=>[$menu]],$actor)['id'];$ids=[];
 $assets=$c->guestContent();foreach([[640,320],[320,640],[480,480],[0,0]] as $i=>[$x,$y]){
  $media='';if($x>0){$im=imagecreatetruecolor($x,$y);$color=imagecolorallocate($im,20+30*$i,110,130);imagefill($im,0,0,$color);imagefilledrectangle($im,0,0,(int)($x/4),(int)($y/4),imagecolorallocate($im,255,220,70));$file=$w.'/synthetic-media-'.$i.'.png';imagepng($im,$file);imagedestroy($im);$media=(string)$assets->importUpload($file,'QA synthetic picture '.$i.'.png','QA synthetic picture',$actor)['media_key'];ck($media!=='','actual image imported '.$i);}
  $ids[]=(int)$cat->saveItem(['name'=>'QA A21 guest item '.$i,'description'=>str_repeat('Synthetic description. ',1+$i),'category_id'=>$category,'menu_ids'=>[$menu],'price'=>100000+$i*1000,'featured'=>false,'image_media_key'=>$media],$actor)['id'];
 }
 $feature=(int)$cat->saveItem(['name'=>'QA A21 featured','description'=>'Synthetic feature','category_id'=>$category,'menu_ids'=>[$menu],'price'=>120000,'featured'=>true],$actor)['id'];
 $key=(string)query($p,'SELECT menu_key FROM menus WHERE id=?',[$menu])->fetchColumn();$table=(string)query($p,'SELECT access_token FROM cafe_tables WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn();
 $pub=$c->publicEdgePublisher();$r=$pub->syncGuestPublication(20);ck(($r['success']??false)===true,'real signed guest publication');ck(($r['state']['media_ready']??false)===true,'all real synthetic image bytes acknowledged');
 file_put_contents($w.'/final-guest.json',json_encode(['menu_key'=>$key,'table'=>$table,'ids'=>$ids,'featured'=>$feature],JSON_THROW_ON_ERROR));
}else exit(2);
