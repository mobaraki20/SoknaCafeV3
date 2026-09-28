<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/src/Setup/SetupException.php';
require_once __DIR__.'/../apps/local-web/src/Setup/BrowserSetupService.php';
use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\Setup\SetupException;

function ep_assert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
foreach([
    'http://127.0.0.1:18080/'=>'http://127.0.0.1:18080/',
    'http://localhost:23456'=>'http://127.0.0.1:23456/',
    'https://[::1]:18443/'=>'https://127.0.0.1:18443/',
    'http://127.0.0.1'=>'http://127.0.0.1:80/',
] as $input=>$expected){
    ep_assert(BrowserSetupService::normalizeLocalBaseUrl($input)===$expected,'normalize mismatch '.$input);
}
foreach([
    'http://192.168.1.10:18080/',
    'http://example.com:18080/',
    'ftp://127.0.0.1:18080/',
    'http://127.0.0.1:18080/path',
    'http://user:pass@127.0.0.1:18080/',
    'http://127.0.0.1:18080/?x=1',
] as $bad){
    $failed=false;
    try{BrowserSetupService::normalizeLocalBaseUrl($bad);}catch(SetupException $e){$failed=str_starts_with($e->errorCode,'local_endpoint_');}
    ep_assert($failed,'invalid endpoint accepted '.$bad);
}
echo "Local endpoint normalization self-test: OK\n";
