<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true') {http_response_code(503);exit('CI_ONLY');}
$root=(string)getenv('R273_PUBLIC_ROOT');
if($root===''||!is_file($root.'/config.php')){http_response_code(503);exit('NO_FIXTURE');}
$path=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';
$_SERVER['DOCUMENT_ROOT']=$root;
// Trusted CI TLS terminator: Production HTTPS frontends set this before PHP.
$_SERVER['HTTPS']='on';
$_SERVER['HTTP_X_FORWARDED_PROTO']='https';
$_SERVER['SCRIPT_NAME']='/new-public/index.php';
if($path==='/new-public/setup/api.php'){
 $_SERVER['SCRIPT_NAME']='/new-public/setup/api.php';require $root.'/setup/api.php';return;
}
if($path==='/new-public/authority-recovery.php'){
 $_SERVER['SCRIPT_NAME']='/new-public/authority-recovery.php';require $root.'/authority-recovery.php';return;
}
if($path==='/new-public/deployment-binding.php'){
 $_SERVER['SCRIPT_NAME']='/new-public/deployment-binding.php';require $root.'/deployment-binding.php';return;
}
require $root.'/index.php';
