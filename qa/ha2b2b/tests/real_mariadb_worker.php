<?php
declare(strict_types=1);
require_once __DIR__.'/../source/PdoMariaDbLabReleaseJournalV2.php';
require_once __DIR__.'/../source/LabMariaDbSettings.php';
// Called only by disposable real MariaDB harness. Never takes a DSN or credentials in argv.
function db():PDO { return LabMariaDbSettings::connect(); }

try {
    if(getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION')throw new RuntimeException('LAB_CONSENT_MISSING');
    $db=db();$db->exec('SET SESSION innodb_lock_wait_timeout=1');
    $id=getenv('SOKNA_HA2B_INSTALLATION_ID')?:'';
    $tx=getenv('SOKNA_HA2B_TX_ID')?:'';$plan=getenv('SOKNA_HA2B_PLAN_SHA')?:'';
    $j=new PdoMariaDbLabReleaseJournalV2($db,$id,getenv('SOKNA_HA2B_DB_NAME')?:'',true);
    $r=$j->begin($tx,$plan);
    echo json_encode(['code'=>$r['code'],'ok'=>$r['ok'],'execution_authorized'=>$r['execution_authorized']],JSON_THROW_ON_ERROR);
    exit(0);
}catch(Throwable $e){echo json_encode(['code'=>'WORKER_FAIL_CLOSED','ok'=>false]);exit(1);}
