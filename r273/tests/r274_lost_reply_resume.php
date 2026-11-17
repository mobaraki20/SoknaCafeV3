<?php
declare(strict_types=1);
/** R274-only regression, after real HTTP MariaDB E2E on disposable CI. */
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R274_CI_ONLY');
$root=(string)getenv('R273_LOCAL_ROOT');$dataDir=(string)getenv('R273_LOCAL_DATA');
if(!is_file($root.'/bootstrap.php')||!is_dir($dataDir))throw new RuntimeException('R274_FIXTURE_MISSING');
require $root.'/bootstrap.php';
$cfg=require dirname($root).'/config.php';
$core=\Sokna\Local\Core\Bootstrap::fromArray($cfg);
$id=$core->config()->requiredString('installation.id');
$ledger=new \Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger($core->database());
$subject=new \Sokna\Local\Domain\PublicEdge\PublicMoveSimpleReplacement($core,$ledger,$id,$dataDir);
$dir=$dataDir.'/private-move';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('PRIVATE_DIR_UNAVAILABLE');
$statePath=$dir.'/replacement-current.json';
if(file_exists($statePath))throw new RuntimeException('REAL_TEST_JOURNAL_NOT_COMPLETED');
$base=[
 'format'=>'sokna-simple-public-replacement-v1',
 'operation_id'=>'move-123456789012345678901234',
 'source_base_url'=>'https://old-example.invalid',
 'target_base_url'=>(string)$cfg['public']['base_url'],
 'new_secret'=>bin2hex(random_bytes(32)),
 'nonce'=>bin2hex(random_bytes(24)),
 'source_public_hash'=>str_repeat('a',64),
 'kind'=>'existing_paired','expected_epoch'=>2,'stage'=>'pairing_uncertain',
];
try{
 file_put_contents($statePath,json_encode($base,JSON_THROW_ON_ERROR));
 $v=$subject->status();
 if(($v['status']??null)!=='sync_pending'||($v['resume_kind']??null)!=='probe_existing_authority'||
    ($v['operation_id']??null)!==$base['operation_id']||($v['safe_to_cutover']??null)!==false)
   throw new RuntimeException('EXISTING_UNCERTAIN_OPERATION_NOT_RESUMABLE '.json_encode($v));
 $base['kind']='fresh_unpaired';
 file_put_contents($statePath,json_encode($base,JSON_THROW_ON_ERROR));
 $v=$subject->status();
 if(($v['status']??null)!=='support_required')throw new RuntimeException('FRESH_UNCERTAIN_OPERATION_WRONGLY_RESUMABLE');
 echo 'R274_LOST_REPLY_RESUME_STATUS_SAFE_PASS'.PHP_EOL;
}finally{@unlink($statePath);}
if(($subject->status()['status']??null)!=='none')throw new RuntimeException('PRIVATE_TEST_JOURNAL_NOT_CLEANED');
