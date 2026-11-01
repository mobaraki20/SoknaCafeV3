<?php
declare(strict_types=1);
$root=$argv[1];require $root.'/local/bootstrap.php';
$core=sokna_local_bootstrap(['app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/r85-settings-'.bin2hex(random_bytes(4))],'installation'=>['id'=>'r85-test'],'db'=>['host'=>'127.0.0.1','port'=>(string)(getenv('DB_PORT')?:3306),'name'=>(string)(getenv('DB_NAME')?:'r83_local'),'charset'=>'utf8mb4','user'=>'root','pass'=>(string)(getenv('DB_PASSWORD')?:'r85-test')],'integrations'=>['accommodation'=>['enabled'=>true]]]);
$p=$core->database();$actor=$p->query("SELECT id,role,display_name FROM users WHERE active=1 AND role='admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);if(!$actor)throw new RuntimeException('missing fixture');
$results=[];function check(bool $pass,string $test):void{global $results;$results[]=['test'=>$test,'pass'=>$pass];}
$last=fn():int=>(int)$p->query('SELECT COALESCE(MAX(sequence_id),0) FROM public_projection_outbox')->fetchColumn();
$events=function(int $after)use($p):int{$q=$p->prepare("SELECT COUNT(*) FROM public_projection_outbox WHERE sequence_id>? AND FIND_IN_SET('operations',model_keys)>0 AND event_type LIKE 'hall.policy.%'");$q->execute([$after]);return (int)$q->fetchColumn();};
$core->orderAcceptance()->update(['cafe'=>true,'kitchen'=>true,'bar'=>true],$actor);
$at=$last();$core->orderAcceptance()->update(['cafe'=>false,'kitchen'=>false,'bar'=>false],$actor);
check($events($at)===1,'acceptance mutation queues operations invalidation in owner transaction');
$at=$last();$core->orderAcceptance()->update(['cafe'=>false,'kitchen'=>false,'bar'=>false],$actor);
check($events($at)===0,'unchanged acceptance does not create redundant policy event');
$at=$last();$core->orderStaffActions()->updatePrintQuickPreferences(['preparation'=>false,'direct'=>false,'itemized'=>true,'subscriber'=>true,'accommodation'=>false],$actor);
check($events($at)===1,'print policy mutation queues operations invalidation');
$model=$core->publicProjectionBuilder()->remoteModelsFor(['operations'])[0];$hall=$model['payload']['hall'];
check(($hall['order_acceptance']??null)===$core->orderAcceptance()->snapshot(),'projection reuses canonical Local acceptance owner');
check(($hall['print_quick_preferences']??null)===$core->orderStaffActions()->printQuickPreferences($actor),'projection reuses canonical print preferences owner');
check(($hall['accommodation_enabled']??null)===true&&($hall['hall_settings_available']??null)===true,'projection carries actual configuration and explicit availability');
check($model['source_version']===Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder::hash($model['payload']),'settings covered by canonical model hash');
$p->exec("CREATE TRIGGER r85_reject_policy BEFORE INSERT ON public_projection_outbox FOR EACH ROW BEGIN IF NEW.event_type LIKE 'hall.policy.%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='R85 injected outbox failure'; END IF; END");
try{
 $before=$core->orderAcceptance()->snapshot();$at=$last();$rejected=false;
 try{$core->orderAcceptance()->update(['cafe'=>true,'kitchen'=>false,'bar'=>false],$actor);}catch(Throwable){$rejected=true;}
 check($rejected,'outbox failure prevents acceptance success');check($core->orderAcceptance()->snapshot()===$before,'outbox failure rolls back settings');check($last()===$at,'outbox failure rolls back all related invalidations');
 $before=$core->orderStaffActions()->printQuickPreferences($actor);$rejected=false;
 try{$core->orderStaffActions()->updatePrintQuickPreferences(['preparation'=>true],$actor);}catch(Throwable){$rejected=true;}
 check($rejected&&$core->orderStaffActions()->printQuickPreferences($actor)===$before,'print policy and invalidation rollback together');
}finally{$p->exec('DROP TRIGGER r85_reject_policy');}
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
exit(count(array_filter($results,fn($x)=>!$x['pass']))?1:0);
