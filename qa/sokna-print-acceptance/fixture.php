<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('CI only');
$tmp=getenv('RUNNER_TEMP');$root=$tmp.'/local';
require $root.'/bootstrap.php';$cfg=require $tmp.'/config.php';$core=sokna_local_bootstrap($cfg);$p=$core->database();
if($p->query('SELECT DATABASE()')->fetchColumn()!=='sokna_ci_print')throw new RuntimeException('wrong database');
$pw=bin2hex(random_bytes(18));
$p->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)")->execute(['qa-print-admin',password_hash($pw,PASSWORD_DEFAULT),'QA Print']);
$admin=['id'=>(int)$p->lastInsertId(),'role'=>'admin'];
$p->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',1)")->execute(['qa-print-operator',password_hash($pw,PASSWORD_DEFAULT),'QA Floor']);
$svc=$core->printTemplates();$ids=[];
foreach(['preparation','customer'] as $kind){$r=$svc->installStandard($kind,$admin);$id=(int)$r['package_id'];$svc->activate($id,$admin);$ids[$kind]=$id;}
$originals=[];foreach($ids as $kind=>$id)$originals[$kind]=$svc->details($id,$admin)['package'];
$p->prepare("INSERT INTO print_agents(name,token_hash,token_hint,active,agent_version,bridge_protocol_version,bridge_port,bridge_pairing_id,bridge_origin,last_heartbeat_at) VALUES(?,?,?,1,'6.2.10',1,23548,'qa-disposable-pair','http://127.0.0.1:18083',NOW())")->execute(['QA preview contract only',hash('sha256',random_bytes(32)),'QA']);
file_put_contents($tmp.'/install.lock',json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>$cfg['installation']['id']],JSON_THROW_ON_ERROR));
file_put_contents($root.'/.sokna-installation.json',json_encode(['format'=>'sokna-local-installation-binding-v1','installation_id'=>$cfg['installation']['id'],'web_version'=>trim(file_get_contents($root.'/VERSION.txt'))],JSON_THROW_ON_ERROR));
$fixture=['origin'=>'http://127.0.0.1:18083','username'=>'qa-print-admin','password'=>$pw,'operator'=>'qa-print-operator','base_ids'=>$ids,'originals'=>$originals,'initial_jobs'=>(int)$p->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn(),'mode'=>getenv('SOKNA_PRINT_QA_MODE'),'admin'=>$admin];
file_put_contents($tmp.'/print-fixture.json',json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX);chmod($tmp.'/print-fixture.json',0600);
echo "PASS full 93-migration schema and real standard template fixtures\n";
