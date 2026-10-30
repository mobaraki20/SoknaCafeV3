<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/public/src/Http/EmergencyLocalHttpAdapter.php';
$root=dirname(__DIR__).'/apps/public';
$instance=(new ReflectionClass(\Sokna\PublicEdge\Http\EmergencyLocalHttpAdapter::class))->newInstanceWithoutConstructor();
$result=(new ReflectionClass($instance))->getMethod('stableOwnerIdentity')->invoke($instance);
$expected=[
    'PublicUpdateException.php','EmergencyAccessService.php',
    'PairingSecretStore.php','PublicTakeoverService.php',
    'PublicUpdateService.php'
];
$got=$result['files']??null;
if(($result['format']??'')!=='sokna-public-stable-emergency-owner-fingerprints-v1'
   ||($result['status']??'')!=='verified'
   ||!is_array($got)||count($got)!==5
   ||($result['content_collected']??true)!==false
   ||($result['machine_identity_collected']??true)!==false)
    throw new RuntimeException('PUBLIC_STANDARD_OWNER_INVENTORY_INCOMPLETE');
foreach($expected as $name){
    $row=null;
    foreach($got as $x)if(($x['path']??'')==='src/Emergency/'.$name){$row=$x;break;}
    if(!is_array($row)||($row['status']??'')!=='verified'
      ||!hash_equals(hash_file('sha256',$root.'/src/Emergency/'.$name),(string)($row['sha256']??'')))
        throw new RuntimeException('PUBLIC_OWNER_HASH_INCORRECT: '.$name);
}
echo "R27_PUBLIC_SIGNED_STANDARD_DIAGNOSTICS_FIVE_OWNER_SHA_PASS\n";
