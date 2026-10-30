<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/src/Domain/System/SupportBundleWriter.php';
$root=dirname(__DIR__).'/apps/local-web';
$target=$root.'/src/Domain/Update/LocalUpdateService.php';
$writer=(new ReflectionClass(\Sokna\Local\Domain\System\SupportBundleWriter::class))->newInstanceWithoutConstructor();
$m=(new ReflectionClass($writer))->getMethod('localSourceIdentity');
$info=$m->invoke($writer);
if(($info['format']??'')!=='sokna-local-updater-source-fingerprint-v1'
    ||($info['status']??'')!=='verified'
    ||($info['sha256']??'')!==hash_file('sha256',$target)
    ||($info['version']??'')!==trim(file_get_contents($root.'/VERSION.txt'))
    ||($info['file_contents_collected']??true)!==false
    ||($info['machine_identity_collected']??true)!==false)
    throw new RuntimeException('LOCAL_ORDINARY_SUPPORT_IDENTITY_FAILED');
echo "R27_LOCAL_STANDARD_SUPPORT_SOURCE_IDENTITY_PASS\n";
