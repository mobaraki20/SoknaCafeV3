<?php
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES')exit(2);
$w=realpath($argv[1]);require $w.'/local/bootstrap.php';$cfg=require $w.'/config.php';
if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150')exit(2);
$core=sokna_local_bootstrap($cfg);$p=$core->database();$ids=[];
foreach(['qa-supervisor'=>['shift_supervision','remote_access'],'qa-cost-supervisor'=>['shift_supervision','inventory_cost_view','inventory_view','remote_access'],'qa-report-only-staff'=>['staff_consumption_reports','remote_access']] as $name=>$caps){
 $hash=password_hash('synthetic-member-only',PASSWORD_DEFAULT);
 $p->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',1)")->execute([$name,$hash,$name]);$id=(int)$p->lastInsertId();$ids[$name]=$id;
 foreach($caps as $cap)$p->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')->execute([$id,$cap]);
 $p->prepare("INSERT INTO remote_user_credentials(user_id,password_hash,credential_version,source_mode) VALUES(?,?,1,'local_password')")->execute([$id,$hash]);
}
$f=json_decode(file_get_contents($w.'/cp144-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$byName=array_column($core->publicProjectionBuilder()->authProjections(),null,'username');
foreach($ids as $name=>$id){$v=$byName[$name];$f['users'][]=['id'=>$id,'name'=>$name,'role'=>$v['role'],'password'=>'synthetic-member-only','caps'=>$v['capabilities'],'areas'=>$v['preparation_areas'],'hash'=>$v['password_hash']];}
file_put_contents($w.'/cp144-fixture.json',json_encode($f,JSON_THROW_ON_ERROR));
