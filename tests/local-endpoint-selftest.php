<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/src/Core/LocalEndpoint.php';
require_once __DIR__.'/../apps/local-web/src/Setup/SetupException.php';
require_once __DIR__.'/../apps/local-web/src/Setup/BrowserSetupService.php';
use Sokna\Local\Core\LocalEndpoint;
use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\Setup\SetupException;

function ep_assert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
foreach([
    'http://127.0.0.1:18080/'=>'http://127.0.0.1:18080/',
    'http://localhost:23456'=>'http://127.0.0.1:23456/',
    'https://[::1]:18443/'=>'https://127.0.0.1:18443/',
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
    'http://127.0.0.1/',
    'https://127.0.0.1/',
    'http://127.0.0.1:443/',
] as $bad){
    $failed=false;
    try{BrowserSetupService::normalizeLocalBaseUrl($bad);}catch(SetupException $e){$failed=str_starts_with($e->errorCode,'local_endpoint_');}
    ep_assert($failed,'invalid endpoint accepted '.$bad);
}
$server=['HTTPS'=>'off','SERVER_PORT'=>'23456','HTTP_HOST'=>'localhost:23456','REQUEST_URI'=>'/setup/?x=1'];
ep_assert(LocalEndpoint::fromServer($server)==='http://127.0.0.1:23456/','server endpoint derivation failed');
ep_assert(LocalEndpoint::canonicalRedirectTarget($server)==='http://127.0.0.1:23456/setup/?x=1','localhost must redirect to canonical 127.0.0.1');
$server['HTTP_HOST']='127.0.0.1:23456';
ep_assert(LocalEndpoint::canonicalRedirectTarget($server)===null,'canonical origin must not redirect');
$server['SERVER_PORT']='80';
$failed=false;try{LocalEndpoint::fromServer($server);}catch(InvalidArgumentException){$failed=true;}
ep_assert($failed,'privileged Local Web port was accepted');

echo "Local endpoint normalization self-test: OK\n";
