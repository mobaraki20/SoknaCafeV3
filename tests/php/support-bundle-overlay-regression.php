<?php
declare(strict_types=1);

require_once __DIR__.'/../../apps/local-web/src/Domain/System/SupportBundleWriter.php';

use Sokna\Local\Domain\System\SupportBundleWriter;

function check(bool $okay,string $message): void {
    if(!$okay)throw new RuntimeException($message);
}
$root=sys_get_temp_dir().'/sokna-support-overlay-'.bin2hex(random_bytes(8));
$web=$root.'/Web';
$persistent=$root.'/LocalWeb/persistent';
@mkdir($web.'/public',0700,true);
@mkdir($persistent.'/public',0700,true);
$read=static function() use($web,$persistent): array {
    $method=(new ReflectionClass(SupportBundleWriter::class))
        ->getMethod('inspectMachineLocalOverlays');
    return $method->invoke(null,$web,$persistent);
};
$delete=static function(string $directory) use (&$delete): void {
    if(!is_dir($directory))return;
    foreach(scandir($directory)?:[] as $child){
        if($child==='.'||$child==='..')continue;
        $path=$directory.'/'.$child;
        if(is_dir($path)&&!is_link($path))$delete($path);
        else@unlink($path);
    }
    @rmdir($directory);
};
try{
    $privateMarker='<?php // NON_EXPORTABLE_EXECUTABLE_PHP_CREDENTIAL_CANARY';
    file_put_contents($web.'/public/local-recovery.php',$privateMarker);
    file_put_contents($persistent.'/public/local-recovery.php',$privateMarker);
    file_put_contents($persistent.'/public/local-update-runner.php',$privateMarker.' runner');
    $first=$read();
    check(($first['local_recovery']['status']??'')==='MATCH','Recovery parity was lost');
    check(($first['local_update_runner']['status']??'')==='ONE_MISSING',
        'Ordinary Local support must reveal the missing active runner');
    check(($first['local_update_runner']['active']['status']??'')==='missing',
        'Missing active runner not explicit');
    check(($first['local_update_runner']['persistent']['status']??'')==='observed',
        'Persistent runner evidence must be present');
    check(($first['installation_authorized']??true)===false,
        'Support evidence cannot grant installation authority');
    check(strpos(json_encode($first,JSON_THROW_ON_ERROR),'NON_EXPORTABLE_EXECUTABLE')===false,
        'PHP source must never leak into support');

    file_put_contents($web.'/public/local-update-runner.php',$privateMarker.' runner');
    check(($read()['local_update_runner']['status']??'')==='MATCH',
        'Both equal existing Runner files must match');
    file_put_contents($web.'/public/local-update-runner.php','unexpected foreign content');
    check(($read()['local_update_runner']['status']??'')==='DRIFT',
        'Different Runner bytes must not be reported MATCH');
    unlink($web.'/public/local-update-runner.php');
    unlink($persistent.'/public/local-update-runner.php');
    check(($read()['local_update_runner']['status']??'')==='BOTH_MISSING',
        'Both absent Runner paths must not look healthy');

    echo "PASS: Local built-in support captures recovery+runner, missing/match/drift/both absent, no PHP content or install authority\n";
} finally { $delete($root); }
