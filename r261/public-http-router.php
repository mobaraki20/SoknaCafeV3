<?php
declare(strict_types=1);
/**
 * Disposable CI HTTP router for the REAL R249-derived Public, under a subpath.
 * Does not change product code or pretend a self-signed TLS certificate is
 * production qualification; TLS and owner signatures passed earlier R260 gate.
 */
$root=(string)getenv('R261_PUBLIC_ROOT');
if(getenv('GITHUB_ACTIONS')!=='true'||$root===''||!is_file($root.'/install.lock'))
    {http_response_code(503);echo "QA_PUBLIC_NOT_INSTALLED";return;}
$path=(string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
$_SERVER['DOCUMENT_ROOT']=$root;
$_SERVER['HTTP_HOST']=$_SERVER['HTTP_HOST']??'127.0.0.1:18881';
if($path==='/new-public/setup/api.php'){
    $_SERVER['SCRIPT_NAME']='/new-public/setup/api.php';
    require $root.'/setup/api.php';
    return;
}
$_SERVER['SCRIPT_NAME']='/new-public/index.php';
require $root.'/public/index.php';
