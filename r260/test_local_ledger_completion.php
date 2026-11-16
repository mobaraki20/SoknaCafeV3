<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||!getenv('R260_LEDGER_DSN'))
    throw new RuntimeException('R260_DISPOSABLE_LOCAL_DB_ONLY');
$base=dirname(__DIR__);
foreach(['PublicMoveDecisionEngine','PublicMoveApprovalMac','PublicMoveIdentityVerifier','PublicMoveApprovalLedger'] as $name)
 require_once $base.'/apps/local-web/src/Domain/PublicEdge/'.$name.'.php';
$db=new PDO((string)getenv('R260_LEDGER_DSN'),'root',(string)getenv('R260_DB_PASSWORD'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$sql=(string)file_get_contents($base.'/apps/local-web/database/migrations/0075_public_move_owner_approval.sql');
$sql=preg_replace('/^--.*$/m','',$sql);
foreach(explode(';',$sql) as $part)if(trim($part)!=='')$db->exec($part);
$now=time();
use Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger as Ledger;
use Sokna\Local\Domain\PublicEdge\PublicMoveIdentityVerifier as Verify;
$local='local-delta-001';
$a=Verify::challenge('move-delta-prev-001',$local,'','https://old.delta.example','https://target.delta.example', $now);
$ledger=new Ledger($db);
$ledger->begin($a,$now);
$db->prepare("UPDATE public_move_auth_operations SET status='claimed' WHERE operation_id=?")->execute([$a['operation_id']]);
$done=$ledger->completeAfterActivation($local,$a['operation_id']);
if(($done['status']??null)!=='completed')throw new RuntimeException('failed_to_complete_previous');
if($ledger->completeAfterActivation($local,$a['operation_id'])['status']!=='completed')
 throw new RuntimeException('completion_not_idempotent');
$b=Verify::challenge('move-delta-next-002',$local,'','https://target.delta.example','https://later.delta.example',$now+1);
$new=$ledger->begin($b,$now+1);
if(($new['status']??null)!=='issued')throw new RuntimeException('successful_move_blocked_future_replacement');
echo "R260_LOCAL_LEDGER_COMPLETION_NEXT_REPLACEMENT_PASS\n";
