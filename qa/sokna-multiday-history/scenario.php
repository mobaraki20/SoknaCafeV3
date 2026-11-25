
require getenv('RUNNER_TEMP').'/public/src/Http/PublicReportArchiveRange.php';
require getenv('RUNNER_TEMP').'/public/src/Remote/PublicInvoiceHistoryArchive.php';
$a($one('SELECT DATABASE()')==='sokna_ci_batch_f2','disposable DB only');
$clock=$core->businessClock()->assignment();
$today=$clock['business_date'];
$d1=(new DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');
$d2=(new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
$d3=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$svc=$core->settlements();
$make=function($suffix,$saleDay,$number,$qty,$discount)use($run,$admin,$clock,$core,$user,$item,$p,$svc,$a){
 $tid=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA receipt '.$suffix,$number,'ci-history-table-'.$suffix,'ci-history-access-'.$suffix]);
 $sid=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-history-session-'.$suffix,$tid,$tid,$admin,$saleDay,$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);
 $o=$core->orders()->commit(['source'=>'staff','table_id'=>$tid,'session_id'=>$sid,'client_token'=>'ci-history-order-000-'.$suffix,'actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>$qty]]]);
 $p->prepare('UPDATE orders SET business_date=? WHERE id=?')->execute([$saleDay,(int)$o['order_id']]);
 $svc->setDiscount($sid,'percent',$discount,$user,$svc->account($sid)['signature']);
 $ac=$svc->account($sid);$req=['session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>'full','destination'=>'direct','request_id'=>'ci-history-pay-000-'.$suffix,'print_receipt'=>false];
 $review=$svc->review($req,$user);$req['tenders']=['pos'=>$review['total']];
 $paid=$svc->settle($req,$user);$a($paid['closes_session']===true,'real closed receipt '.$suffix);
 return $paid;
};
$kept=$make('kept',$d1,64771,3,10);
$paid=$make('returned',$d3,64772,2,5);
$reversed=$svc->reverse($paid['settlement_id'],'isolated QA reversal','ci-history-reverse-0001',$user);
$ids=[$kept['settlement_id'],$paid['settlement_id'],$reversed['settlement_id']];
$records=$rows("SELECT r.id,r.invoice_number,r.status,r.subtotal,r.discount,r.total,r.tax_amount,r.business_date,ts.business_date sale_date,r.reverses_settlement_id FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id WHERE r.id IN (?,?,?) ORDER BY r.id",$ids);
$a(count($records)===3,'three real receipts');
$a((int)$records[2]['reverses_settlement_id']===$ids[1]&&$records[2]['status']==='reversal','immutable reversal reference');
$a((int)$records[1]['total']===(int)$records[2]['total'],'full reversal amount');
$a((int)$records[0]['discount']>0,'real discount');
foreach($records as $r)$a($r['business_date']===$today,'receipt belongs to current business day');
$archive=$core->reporting()->remoteArchive();
$proof=[];
foreach([[$d1,$d1],[$d2,$d2],[$d3,$d3],[$today,$today],[$d1,$today]] as [$from,$to]){
 $r=$core->reporting()->report($from,$to);
 $public=\Sokna\PublicEdge\Http\PublicReportArchiveRange::aggregate(['archive'=>$archive],$from,$to);
 $a(is_array($public),'archive range '.$from.' '.$to);
 $want=array_fill_keys(['net_sales','gross_sales','discount_total','completed_settlements','reversals','completed_amount','reversed_amount','cash_net_payment_day'],0);
 foreach($records as $doc){
   $sign=$doc['status']==='completed'?1:-1;
   if($doc['sale_date']>=$from&&$doc['sale_date']<=$to){
     $want['net_sales']+=$sign*(int)$doc['total'];$want['gross_sales']+=$sign*(int)$doc['subtotal'];$want['discount_total']+=$sign*(int)$doc['discount'];
   }
   if($doc['business_date']>=$from&&$doc['business_date']<=$to){
     if($sign===1){$want['completed_settlements']++;$want['completed_amount']+=(int)$doc['total'];}
     else{$want['reversals']++;$want['reversed_amount']+=(int)$doc['total'];}
   }
 }
 $want['cash_net_payment_day']=$want['completed_amount']-$want['reversed_amount'];
 foreach($want as $key=>$value)$a($r['summary'][$key]===$value&&$public['summary'][$key]===$value,'independent amount '.$from.' '.$to.' '.$key);
 $a($public['daily']===$r['daily'],'daily parity '.$from.' '.$to);
 $a($public['summary']['invoice_count']===$r['summary']['invoice_count'],'invoice count parity');
 $proof[]=['from'=>$from,'to'=>$to,'expected'=>$want,'daily'=>$r['daily']];
}
$snapshot=\Sokna\Local\Domain\Reporting\PublicInvoiceHistorySnapshot::build($p,$today);
$history=\Sokna\PublicEdge\Remote\PublicInvoiceHistoryArchive::class;
$a($history::validated($snapshot,['stale'=>false]),'valid positive invoice archive');
$list=$history::browse($snapshot,'',$today,$today);
$a(is_array($list),'receipt date search');
foreach($records as $record){
 $id=(int)$record['id'];$doc=$history::detail($snapshot,$id);
 $a(is_array($doc),'historical document '.$id);
 $a($doc['receipt_business_date']===$record['business_date']&&$doc['sale_business_date']===$record['sale_date'],'independent dates in history '.$id);
 foreach(['subtotal','discount','total','tax_amount'] as $k)$a($doc[$k]===(int)$record[$k],'document '.$id.' '.$k);
 $a($doc['status']===$record['status']&&$doc['invoice_number']===$record['invoice_number'],'document status and number');
 $matches=array_values(array_filter($list['rows'],fn($x)=>$x['id']===$id));
 $a(count($matches)===1&&!array_key_exists('items',$matches[0]),'bounded list without line items');
 $detail=$core->reporting()->historicalSettlement($id);
 $a($detail['read_only']===true&&$detail['document']['id']===$id&&$detail['document']['total']===$doc['total'],'Local historical document');
 $a(count($detail['lines'])>0&&$doc['item_lines_complete']&&count($doc['items'])===count($detail['lines']),'real line counts');
 foreach($doc['items'] as $i=>$line){
   $source=$detail['lines'][$i];$a($line['name']===$source['name']&&(float)$line['quantity']===(float)$source['quantity']&&$line['unit_price']===$source['unit_price']&&$line['line_final']===$source['final_amount'],'canonical line '.$id.' '.$i);
 }
}
$rev=$history::detail($snapshot,$ids[2]);
$a($rev['reversal_kind']==='full'&&$rev['reversal_source_id']===$ids[1],'full reversal source identity');
$old=$history::browse($snapshot,'',$d1,$d1);
$a(is_array($old)&&count(array_filter($old['rows'],fn($x)=>$x['id']===$ids[0]))===0,'sale day is not receipt filter');
$a(!$history::validated($snapshot,['stale'=>true]),'stale archive not accepted');
$eventDay=(string)$one("SELECT DATE(created_at) FROM audit_log WHERE action='settlement.completed' AND entity_type='settlement_record' AND entity_id=? ORDER BY id DESC LIMIT 1",[(string)$ids[0]]);
$a($eventDay!=='','real activity event');
$activity=$core->reporting()->activityReport($eventDay,$eventDay,null,'settlement',(string)$ids[0],100,0);
$event=array_values(array_filter($activity['rows'],fn($x)=>$x['action']==='settlement.completed'&&$x['entity_type']==='settlement_record'&&(int)$x['entity_id']===$ids[0]));
$a(count($event)===1,'immutable original settlement audit reference');
$doc=$core->reporting()->historicalSettlement((int)$event[0]['entity_id']);
$a($doc['document']['id']===$ids[0]&&$doc['read_only']===true,'activity to historical original');
file_put_contents(getenv('RUNNER_TEMP').'/finance-history-result.json',json_encode(['status'=>'PASS','receipt_ids'=>$ids,'windows'=>$proof,'activity_event_id'=>$event[0]['id']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS multiday finance, independent receipt dates, real invoice archive, reversal and audit document\n";
