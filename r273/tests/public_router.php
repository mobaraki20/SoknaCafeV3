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
$basePath=getenv('R273_PUBLIC_BASE_PATH');$basePath=$basePath===false?'/new-public':$basePath;if(!in_array($basePath,['','/new-public'],true)){http_response_code(503);exit('INVALID_QA_PUBLIC_BASE_PATH');}$_SERVER['SCRIPT_NAME']=$basePath.'/index.php';
if($path===$basePath.'/setup/api.php'){
 $_SERVER['SCRIPT_NAME']=$basePath.'/setup/api.php';require $root.'/setup/api.php';return;
}
if($path===$basePath.'/authority-recovery.php'){
 $_SERVER['SCRIPT_NAME']=$basePath.'/authority-recovery.php';require $root.'/authority-recovery.php';return;
}
if($path===$basePath.'/deployment-binding.php'){
 $_SERVER['SCRIPT_NAME']=$basePath.'/deployment-binding.php';require $root.'/deployment-binding.php';return;
}
require $root.'/index.php';
