<?php
declare(strict_types=1);

require_once __DIR__.'/../apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once __DIR__.'/../apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
require_once __DIR__.'/../apps/local-web/src/Domain/PublicEdge/PublicMoveUiInspectionService.php';

use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException as E;
use Sokna\Local\Domain\PublicEdge\PublicMoveUiInspectionService as S;

$checks=0;
function checkR258(bool $ok,string $name):void {
    global $checks;
    if(!$ok)throw new RuntimeException('R258_TEST_FAILED: '.$name);
    $checks++;
    echo 'PASS '.$name."\n";
}
$called=0;
$probe=static function(string $url)use(&$called):array {
    $called++;
    return [
        'ok'=>true,'target_health_verified'=>true,'public_instance_id'=>'public-fixture-4',
        'current_base_url'=>'https://cafe-one.example/public',
        'target_base_url'=>$url,
    ];
};
$service=new S('https://cafe-one.example/public','active',$probe);
foreach ([
    'http://unsafe.example',
    'https://127.0.0.1',
    'https://localhost',
    'https://admin:secret@unsafe.example',
    'https://target.example/a/../b',
    'https://target.example/%2e%2e/private',
    'https://target.example/?token=test',
    'https://target.example/#move',
    'https://target.example\\private',
] as $bad) {
    $result=$service->inspect($bad);
    checkR258($result['route']==='blocked' && $result['safe_to_cutover']===false,'invalid URL blocked without writes');
}
checkR258($called===0,'no network request for invalid targets');
$same=$service->inspect('https://cafe-one.example/public/');
checkR258($same['route']==='same_destination' && $called===0,'same target does not trigger probe');
$recovery=(new S('https://cafe-one.example/public','resync_required',$probe))
    ->inspect('https://new-cafe.example/subdir');
checkR258($recovery['route']==='blocked' && $called===0,'recovery phase fails before network');
$unpaired=(new S('','active',$probe))->inspect('https://new-cafe.example');
checkR258($unpaired['reason_code']==='connection_not_configured' && $called===0,'unpaired Local stays safe');

$ok=$service->inspect('https://cafe-one-new.example/edge');
checkR258($ok['route']==='ready_for_review','signed same-instance preflight is recognized');
checkR258($ok['safe_to_cutover']===false && $ok['writes_performed']===false &&
    $ok['local_connection_preserved']===true,'verified target cannot trigger cutover');
checkR258($ok['target_base_url']==='https://cafe-one-new.example/edge','subfolder path preserved');
checkR258(!isset($ok['public_instance_id'])&&!isset($ok['nonce']), 'browser receives no identity secrets or nonce');

$mismatch=(new S('https://cafe-one.example/public','active',
    static function():never {throw new E('public_origin_instance_mismatch','different instance',409);}
))->inspect('https://other-cafe.example');
checkR258($mismatch['route']==='awaiting_owner' && $mismatch['safe_to_cutover']===false,
    'unrecognized independent target needs owner confirmation, not rebind');
$wrongProof=(new S('https://a.example','active',
    static fn()=>['ok'=>true,'target_health_verified'=>true,'public_instance_id'=>'untrusted',
        'current_base_url'=>'https://a.example','target_base_url'=>'https://wrong.example']
))->inspect('https://b.example');
checkR258($wrongProof['reason_code']==='signed_preflight_incomplete',
    'mismatched server evidence rejected');
$missingHealth=(new S('https://a.example','active',
    static fn()=>['ok'=>true,'public_instance_id'=>'id','current_base_url'=>'https://a.example',
        'target_base_url'=>'https://b.example']
))->inspect('https://b.example');
checkR258($missingHealth['reason_code']==='signed_preflight_incomplete',
    'no verified health flag cannot promote target');
$network=(new S('https://a.example','active',
    static function():never {throw new RuntimeException('private SSL details must never leak');}
))->inspect('https://b.example');
checkR258($network['reason_code']==='target_check_failed' &&
    !str_contains($network['message'],'private SSL details'),
    'transport exceptions fail closed without error leak');
echo "R258_LOCAL_MOVE_UI_INSPECTION_QA_PASS count=$checks\n";
