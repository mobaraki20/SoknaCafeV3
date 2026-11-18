<?php
declare(strict_types=1);

/**
 * R279 read-only move classifier regression. No database/network/host writes.
 * Source classes are the real app entrypoints, not rewritten test doubles.
 */
$localRoot=(string)(getenv('R279_LOCAL_SOURCE_ROOT')?:dirname(__DIR__,2).'/apps/local-web');
require_once $localRoot.'/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once $localRoot.'/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
require_once $localRoot.'/src/Domain/PublicEdge/PublicMoveUiInspectionService.php';

use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\Local\Domain\PublicEdge\PublicMoveUiInspectionService;

$old='https://old.example.org';
$target='https://new.example.org/cafe/public';
$local='QA-LOCAL-A';
$healthy=['ok'=>true,'component'=>'public-edge','database'=>'ready',
    'pending_migrations'=>0,'version'=>'3.0.0-qa'];
$fresh=['installed'=>true,'paired'=>false,'installation_id'=>'','base_url'=>$target];
$same=['installed'=>true,'paired'=>true,'installation_id'=>$local,'base_url'=>$target];
$foreign=['installed'=>true,'paired'=>true,'installation_id'=>'QA-LOCAL-B','base_url'=>$target];

$badProbe=static function():array{
    throw new PublicEdgeSyncException('deployment_probe_proof_invalid','Cannot authenticate same-instance probe.',409);
};
$service=static function(
    ?callable $probe=null, ?callable $health=null, ?callable $setup=null,
    string $id='QA-LOCAL-A',string $phase='active',string $old='https://old.example.org'
)use($badProbe,$healthy,$fresh):PublicMoveUiInspectionService {
    return new PublicMoveUiInspectionService(
        $old,$phase,$probe??$badProbe,
        $health??static fn():array=>$healthy,
        $setup??static fn():array=>$fresh,
        $id
    );
};
$count=0;
$check=static function(string $name,array $value,string $code,string $route)use(&$count):void {
    if(($value['reason_code']??'')!==$code||($value['route']??'')!==$route||
       ($value['read_only']??null)!==true||
       ($value['writes_performed']??null)!==false||
       ($value['safe_to_cutover']??null)!==false||
       ($value['local_connection_preserved']??null)!==true) {
        throw new RuntimeException('R279_ASSERT_FAILED '.$name.' got='.json_encode($value,JSON_UNESCAPED_UNICODE));
    }
    $count++;
    echo 'PASS '.$name.' '.$code.'\n';
};
$run=static fn(PublicMoveUiInspectionService $s):array=>$s->inspect($target);
$check('fresh installed owner', $run($service()),'fresh_unpaired_public','awaiting_owner');
$check('paired same local owner',$run($service(setup:static fn():array=>$same)),
    'same_local_existing_public','awaiting_owner');
$check('foreign local blocked',$run($service(setup:static fn():array=>$foreign)),
    'target_paired_to_other_local','blocked');
$check('paired identity unknown',$run($service(id:'' ,setup:static fn():array=>$same)),
    'target_paired_identity_unknown','blocked');

$check('target TLS or network unreachable', $run($service(health:static function():array{
    throw new PublicEdgeSyncException('deployment_target_health_failed','unreachable',503);
})), 'target_health_unreachable','blocked');
$check('target database not ready', $run($service(health:static fn():array=>array_replace($healthy,['database'=>'failed']))),
    'target_health_not_ready','blocked');
$check('target migration pending', $run($service(health:static fn():array=>array_replace($healthy,['pending_migrations'=>1]))),
    'target_health_not_ready','blocked');
$check('target health forged', $run($service(health:static fn():array=>['ok'=>true,'component'=>'other'])) ,
    'target_health_not_ready','blocked');
$check('setup endpoint unavailable', $run($service(setup:static function():array{throw new RuntimeException('404');})),
    'target_setup_unavailable','blocked');
$check('setup missing installed bool', $run($service(setup:static fn():array=>['paired'=>false])),
    'target_setup_invalid','blocked');
$check('target not installed', $run($service(setup:static fn():array=>array_replace($fresh,['installed'=>false]))),
    'target_not_installed','blocked');
$check('target base path mismatch', $run($service(setup:static fn():array=>array_replace($fresh,['base_url'=>'https://new.example.org/other']))),
    'target_base_url_mismatch','blocked');
$check('broken same-instance PWA', $run($service(probe:static function():array{
    throw new PublicEdgeSyncException('public_origin_target_pwa_incomplete','PWA incomplete',409);
})), 'target_deployment_incomplete','blocked');
$check('health verification missing', $run(new PublicMoveUiInspectionService($old,'active',$badProbe)),
    'target_verification_not_configured','blocked');

$realProof=['ok'=>true,'current_base_url'=>$old,'target_base_url'=>$target,
    'target_health_verified'=>true,'public_instance_id'=>'PUB-ABC'];
$check('same instance signed preflight',
    $run($service(probe:static fn():array=>$realProof)),'same_instance_verified','ready_for_review');
$check('same instance incomplete proof',
    $run($service(probe:static fn():array=>array_replace($realProof,['target_health_verified'=>false]))),
    'signed_preflight_incomplete','blocked');
$check('same URL no mutation',
    $service()->inspect($old),'already_selected','same_destination');
$check('invalid URL no mutation',
    $service()->inspect('http://not-https.example.org'),'invalid_target','blocked');
$check('Local recovery blocked',
    $run($service(phase:'reenroll_required')),'local_recovery_required','blocked');

echo 'R279_INSPECTION_FAIL_CLOSED_'.(string)$count.'_CASES_PASS'.PHP_EOL;
